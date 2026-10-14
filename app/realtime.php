<?php
declare(strict_types=1);

function lc_realtime_emit(string $scope,?int $userId,string $eventType,?string $entityType=null,?int $entityId=null,array $extra=[]): void {
    if(!in_array($scope,['admin','user'],true)||($scope==='user'&&(!$userId||$userId<1))||($scope==='admin'&&$userId!==null)){
        throw new InvalidArgumentException('Destinatário realtime inválido');
    }
    if(!preg_match('/^[a-z][a-z0-9_.]{1,69}$/',$eventType))throw new InvalidArgumentException('Tipo de evento inválido');
    $payload=json_encode($extra,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if(strlen($payload)>2048)throw new InvalidArgumentException('Evento muito grande');
    // Uses the SAME PDO connection and caller's transaction as business writes.
    lc_query('INSERT INTO realtime_events(scope,user_id,event_type,entity_type,entity_id,payload_json) VALUES(?,?,?,?,?,?)',
      [$scope,$userId,$eventType,$entityType,$entityId,$payload]);
}
function lc_realtime_key(): string {
    $key=base64_decode(lc_env('APP_KEY'),true);
    if($key===false||strlen($key)!==32)throw new RuntimeException('APP_KEY indisponível para autenticação realtime');
    return $key;
}
function lc_realtime_base64url(string $data): string {
    return rtrim(strtr(base64_encode($data),'+/','-_'),'=');
}
function lc_realtime_ticket(array $user): string {
    $now=time();
    $payload=lc_realtime_base64url(json_encode([
       'uid'=>(int)$user['id'],'role'=>(string)$user['role'],
       'iat'=>$now,'exp'=>$now+60,'nonce'=>bin2hex(random_bytes(12))
    ],JSON_THROW_ON_ERROR));
    return $payload.'.'.lc_realtime_base64url(hash_hmac('sha256',$payload,lc_realtime_key(),true));
}
function lc_realtime_authorize(string $ticket): ?array {
    if(strlen($ticket)>900||!preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})$/D',$ticket,$m))return null;
    $signature=lc_realtime_base64url(hash_hmac('sha256',$m[1],lc_realtime_key(),true));
    if(!hash_equals($signature,$m[2]))return null;
    $input=base64_decode(strtr($m[1],'-_','+/'),true);
    if($input===false)return null;
    $data=json_decode($input,true);
    $now=time();
    if(!is_array($data)||!is_int($data['uid']??null)||$data['uid']<1||
       !in_array($data['role']??null,['admin','clipador'],true)||
       !is_int($data['iat']??null)||!is_int($data['exp']??null)||
       $data['iat']>$now+5||$data['iat']<$now-70||$data['exp']<$now||$data['exp']>$now+70)return null;
    return ['id'=>$data['uid'],'role'=>$data['role']];
}
function lc_realtime_state(array $user): array {
    if($user['role']==='admin'){
        return [
          'pending_users'=>(int)(lc_one("SELECT COUNT(*) AS n FROM users WHERE role='clipador' AND status='pending'")['n']??0),
          'total_users'=>(int)(lc_one("SELECT COUNT(*) AS n FROM users WHERE role='clipador'")['n']??0),
          'pending_clips'=>(int)(lc_one("SELECT COUNT(*) AS n FROM clips WHERE status='pending'")['n']??0),
          'active_campaigns'=>(int)(lc_one("SELECT COUNT(*) AS n FROM campaigns WHERE status='active'")['n']??0),
          'pending_withdrawals'=>(int)(lc_one("SELECT COUNT(*) AS n FROM withdrawals WHERE status IN ('requested','processing')")['n']??0)
        ];
    }
    $balance=lc_balance((int)$user['id']);
    $stats=lc_one("SELECT COUNT(*) AS total,COALESCE(SUM(status='approved'),0) AS approved,
        COALESCE(SUM(status='pending'),0) AS pending,COALESCE(SUM(verified_views),0) AS views
        FROM clips WHERE user_id=?",[$user['id']])??[];
    return [
        'available_cents'=>$balance['available'],
        'total_clips'=>(int)($stats['total']??0),
        'approved_clips'=>(int)($stats['approved']??0),
        'pending_clips'=>(int)($stats['pending']??0),
        'verified_views'=>(int)($stats['views']??0)
    ];
}
function lc_realtime_events(array $user,int $since=0): array {
    $filter=$user['role']==='admin'?"scope='admin'":"scope='user' AND user_id=?";
    $params=$user['role']==='admin'?[]:[(int)$user['id']];
    if($since>0){
        $sql="SELECT id,event_type,entity_type,entity_id,created_at FROM realtime_events WHERE $filter AND id>? ORDER BY id ASC LIMIT 50";
        $params[]=$since;
    }else{
        $sql="SELECT id,event_type,entity_type,entity_id,created_at FROM realtime_events WHERE $filter ORDER BY id DESC LIMIT 10";
    }
    $events=lc_all($sql,$params);
    if($since===0)$events=array_reverse($events);
    return array_map(static fn($e)=>[
        'id'=>(int)$e['id'],'type'=>$e['event_type'],'entity'=>$e['entity_type'],
        'entity_id'=>$e['entity_id']===null?null:(int)$e['entity_id'],
        'created_at'=>$e['created_at']
    ],$events);
}
function lc_realtime_route(string $method,string $path): bool {
    if(!in_array($path,['/api/realtime/snapshot','/api/realtime/token'],true))return false;
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    $user=lc_user();
    if(!$user){http_response_code(401);echo '{"error":"auth_required"}';return true;}
    if($user['status']!=='approved'){http_response_code(403);echo '{"error":"access_denied"}';return true;}
    if($path==='/api/realtime/token'){
        if($method!=='POST'){http_response_code(405);header('Allow: POST');echo '{"error":"method_not_allowed"}';return true;}
        echo json_encode(['ticket'=>lc_realtime_ticket($user),'ttl'=>60],JSON_THROW_ON_ERROR);
        return true;
    }
    if($method!=='GET'){http_response_code(405);header('Allow: GET');echo '{"error":"method_not_allowed"}';return true;}
    $raw=(string)($_GET['since']??'0');
    $since=preg_match('/^[0-9]{1,19}$/D',$raw)?max(0,min(PHP_INT_MAX,(int)$raw)):0;
    $events=lc_realtime_events($user,$since);
    $max=(int)(lc_one('SELECT COALESCE(MAX(id),0) AS cursor FROM realtime_events')['cursor']??0);
    echo json_encode(['cursor'=>$max,'state'=>lc_realtime_state($user),'events'=>$events],
       JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    return true;
}
