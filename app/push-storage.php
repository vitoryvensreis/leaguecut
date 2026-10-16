<?php
declare(strict_types=1);

/** Backend registration is OFF until the complete VAPID sender is deployed. */
function lc_push_registration_enabled(): bool {
    return lc_env('PUSH_REGISTRATION_ENABLED','0')==='1';
}
function lc_push_endpoint_safe(string $url): bool {
    if(strlen($url)<35||strlen($url)>2000||!lc_valid_https($url))return false;
    $parts=parse_url($url);
    if(!is_array($parts)||isset($parts['user'])||isset($parts['pass'])||
       isset($parts['port'])||isset($parts['fragment']))return false;
    $host=strtolower((string)($parts['host']??''));
    return in_array($host,[
        'fcm.googleapis.com','fcm-xm.googleapis.com',
        'updates.push.services.mozilla.com','web.push.apple.com'
    ],true) ||
        str_ends_with($host,'.push.apple.com') ||
        str_ends_with($host,'.notify.windows.com') ||
        str_ends_with($host,'.wns.windows.com');
}
function lc_push_check_subscription(mixed $data): array {
    if(!is_array($data))throw new InvalidArgumentException('Assinatura inválida.');
    $endpoint=$data['endpoint']??null;
    $keys=$data['keys']??null;
    if(!is_string($endpoint)||!lc_push_endpoint_safe($endpoint)||
       !is_array($keys)||!is_string($keys['p256dh']??null)||!is_string($keys['auth']??null)||
       !preg_match('/^[A-Za-z0-9_-]{80,100}$/D',$keys['p256dh'])||
       !preg_match('/^[A-Za-z0-9_-]{16,40}$/D',$keys['auth']))
       throw new InvalidArgumentException('Assinatura Web Push inválida.');
    return ['endpoint'=>$endpoint,'keys'=>['p256dh'=>$keys['p256dh'],'auth'=>$keys['auth']]];
}
function lc_push_store_subscription(int $userId,array $submitted): void {
    $sub=lc_push_check_subscription($submitted);
    $hash=hash('sha256',$sub['endpoint']);
    $db=lc_db();$db->beginTransaction();
    try{
        lc_one('SELECT id FROM users WHERE id=? FOR UPDATE',[$userId]);
        $existing=lc_one('SELECT id,user_id FROM push_subscriptions WHERE endpoint_hash=? FOR UPDATE',[$hash]);
        if($existing&&(int)$existing['user_id']!==$userId)
            throw new InvalidArgumentException('Esta assinatura já pertence a outra conta.');
        if(!$existing){
            $count=(int)(lc_one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id=?',[$userId])['n']??0);
            if($count>=8)throw new InvalidArgumentException('Limite de oito dispositivos atingido.');
            lc_query('INSERT INTO push_subscriptions(user_id,endpoint_hash,endpoint_enc,keys_enc) VALUES(?,?,?,?)',
              [$userId,$hash,lc_encrypt($sub['endpoint']),lc_encrypt(json_encode($sub['keys'],JSON_THROW_ON_ERROR))]);
        }else{
            lc_query('UPDATE push_subscriptions SET keys_enc=? WHERE id=?',
              [lc_encrypt(json_encode($sub['keys'],JSON_THROW_ON_ERROR)),$existing['id']]);
        }
        lc_query('INSERT INTO push_preferences(user_id,enabled) VALUES(?,1) ON DUPLICATE KEY UPDATE enabled=1',[$userId]);
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function lc_push_remove_subscription(int $userId,string $endpoint): void {
    if(!lc_push_endpoint_safe($endpoint))throw new InvalidArgumentException('Assinatura inválida.');
    $db=lc_db();$db->beginTransaction();
    try{
        lc_query('DELETE FROM push_subscriptions WHERE user_id=? AND endpoint_hash=?',[$userId,hash('sha256',$endpoint)]);
        if((int)(lc_one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id=?',[$userId])['n']??0)===0)
            lc_query('INSERT INTO push_preferences(user_id,enabled) VALUES(?,0) ON DUPLICATE KEY UPDATE enabled=0',[$userId]);
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function lc_push_subscription_routes(string $method,string $path): bool {
    if(!in_array($path,['/api/push/subscribe','/api/push/unsubscribe','/api/push/status'],true))return false;
    $user=lc_login_required(false);
    $uid=(int)$user['id'];
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    if(!lc_push_send_ready()){
        http_response_code(503);
        echo '{"ok":false,"message":"Notificações em segundo plano ainda não estão disponíveis."}';
        return true;
    }
    if($method==='GET' && $path==='/api/push/status'){
        $row=lc_one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id=?',[$uid]);
        echo json_encode(['ok'=>true,'devices'=>(int)($row['n']??0)],JSON_THROW_ON_ERROR);
        return true;
    }
    if($method!=='POST'){http_response_code(405);header('Allow: POST');echo '{"ok":false}';return true;}
    try{
        if($path==='/api/push/subscribe'){
            $json=(string)($_POST['subscription_json']??'');
            if(strlen($json)>6500)throw new InvalidArgumentException('Assinatura grande demais.');
            lc_push_store_subscription($uid,json_decode($json,true,12,JSON_THROW_ON_ERROR));
        }elseif($path==='/api/push/unsubscribe'){
            lc_push_remove_subscription($uid,(string)($_POST['endpoint']??''));
        }else{
            http_response_code(405);echo '{"ok":false}';return true;
        }
        echo '{"ok":true}';
    }catch(InvalidArgumentException|JsonException $e){
        http_response_code(422);echo '{"ok":false,"message":"Assinatura inválida ou limite de dispositivos."}';
    }catch(Throwable $e){
        error_log('LEAGUECUT_PUSH_STORAGE_FAILED');
        http_response_code(500);echo '{"ok":false,"message":"Não foi possível atualizar a assinatura."}';
    }
    return true;
}
