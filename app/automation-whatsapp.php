<?php
declare(strict_types=1);

/** Server-only WPPConnect access. Never send authorization data to the browser. */
function lc_wpp_admin_request(string $endpoint,string $method='GET',?array $payload=null): array {
    if(lc_env('WPP_SESSION','leaguecut')!=='leaguecut')
        throw new RuntimeException('Esta conexão está reservada à sessão leaguecut.');
    $token=lc_env('WPP_AUTH_TOKEN');
    if($token==='')throw new RuntimeException('Token privado do WPPConnect não configurado.');
    $allowed=['check-connection-session','qrcode-session','start-session','close-session'];
    if(!in_array($endpoint,$allowed,true)||
       (($method==='POST')!==in_array($endpoint,['start-session','close-session'],true)))
        throw new InvalidArgumentException('Rota de sessão não permitida.');
    $url=lc_wpp_base_url().'/api/leaguecut/'.$endpoint;
    $ch=curl_init($url);
    if(!$ch)throw new RuntimeException('cURL não inicializou.');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,
        CURLOPT_TIMEOUT=>$endpoint==='start-session'?27:9,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json','Content-Type: application/json'],
        CURLOPT_USERAGENT=>'LeagueCutAdmin/1.0'
    ]);
    if($method==='POST'){
        curl_setopt_array($ch,[CURLOPT_POST=>true,
          CURLOPT_POSTFIELDS=>json_encode($payload??[],JSON_THROW_ON_ERROR)]);
    }
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if($raw===false||$status<200||$status>=300)
        throw new RuntimeException('WPPConnect indisponível ou solicitação recusada (HTTP '.$status.').');
    if(strlen($raw)>3000000)throw new RuntimeException('Resposta de sessão maior que o permitido.');
    return ['body'=>$raw,'json'=>json_decode($raw,true),'type'=>$type];
}
/**
 * WPPConnect Server v2.10.27 check-connection-session returns
 * {"status":true,"message":"Connected"} when authenticated.
 * The old code cast true to "1", incorrectly showing "Not connected · 1".
 * Interpret the boolean first; HTTP 200 alone never means connected.
 */
function lc_wpp_parse_connection(array $data): array {
    $raw=$data['status']??null;
    $message=strtoupper(trim((string)($data['message']??'')));
    $statesConnected=['CONNECTED','ISLOGGED','OPEN','INCHAT'];
    $statesOffline=['CLOSED','DISCONNECTED','QRCODE','INITIALIZING','UNKNOWN','NOT CONNECTED','LOGOUT','ERROR'];
    $text=is_string($raw)?strtoupper(trim($raw)):'';
    // An explicit false from the official endpoint takes precedence over other fields.
    if($raw===false || $raw===0 || in_array($text,$statesOffline,true) ||
        $text==='FALSE' || $text==='0'){
        return ['connected'=>false,'status'=>'DISCONNECTED'];
    }
    $connected=$raw===true || $raw===1 || $text==='TRUE' || $text==='1'
        || in_array($text,$statesConnected,true);
    if($raw===null){
        $connected=($data['connected']??null)===true
            || ($data['isConnected']??null)===true
            || in_array($message,$statesConnected,true);
    }
    if($connected)return ['connected'=>true,'status'=>'CONNECTED'];
    return ['connected'=>false,'status'=>in_array($text,['STARTING','PAIRING','CONNECTING'],true)
        ? 'CONNECTING' : 'DISCONNECTED'];
}
function lc_wpp_admin_status(): array {
    $result=lc_wpp_admin_request('check-connection-session');
    $data=$result['json'];
    return is_array($data) ? lc_wpp_parse_connection($data)
        : ['connected'=>false,'status'=>'UNKNOWN'];
}
function lc_wpp_admin_qr(): ?string {
    $response=lc_wpp_admin_request('qrcode-session');
    $raw=$response['body'];$json=$response['json'];
    if(str_starts_with($response['type'],'image/png') && str_starts_with($raw,"\x89PNG"))
        return 'data:image/png;base64,'.base64_encode($raw);
    if(!is_array($json))return null;
    $candidates=[
       $json['qrcode']??null,$json['qrCode']??null,$json['base64']??null,
       $json['qrcodeBase64']??null,$json['data']['qrcode']??null,
       $json['data']['base64']??null,$json['data']['qrCode']??null
    ];
    foreach($candidates as $value){
        if(!is_string($value)||strlen($value)>2500000)continue;
        if(preg_match('~^data:image/png;base64,([A-Za-z0-9+/=]+)$~D',$value))
            return $value;
        if(preg_match('~^[A-Za-z0-9+/]+={0,2}$~D',$value)&&strlen($value)>100){
            $bytes=base64_decode($value,true);
            if($bytes!==false && str_starts_with($bytes,"\x89PNG"))
                return 'data:image/png;base64,'.$value;
        }
    }
    return null;
}
function lc_wpp_admin_json(array $data,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}
function lc_wpp_admin_routes(string $method,string $path): bool {
    if(!str_starts_with($path,'/admin/whatsapp'))return false;
    lc_admin();
    if(str_starts_with($path,'/admin/whatsapp/api/')){
        try{
            if($method==='GET'&&$path==='/admin/whatsapp/api/status'){
                $state=lc_wpp_admin_status();
                lc_wpp_admin_json($state+['enabled'=>lc_wpp_ready()]);
            }
            if($method==='GET'&&$path==='/admin/whatsapp/api/qr'){
                $qr=lc_wpp_admin_qr();
                lc_wpp_admin_json(['qr'=>$qr]);
            }
            if($method==='POST'&&$path==='/admin/whatsapp/api/start'){
                lc_wpp_admin_request('start-session','POST',['webhook'=>'','waitQrCode'=>false]);
                lc_wpp_admin_json(['ok'=>true]);
            }
            if($method==='POST'&&$path==='/admin/whatsapp/api/pause'){
                lc_query('UPDATE automation_whatsapp_settings SET enabled=0 WHERE id=1');
                lc_wpp_admin_json(['ok'=>true]);
            }
            if($method==='POST'&&$path==='/admin/whatsapp/api/enable'){
                $state=lc_wpp_admin_status();
                if(!$state['connected'])lc_wpp_admin_json(['error'=>'Conecte o WhatsApp primeiro.'],409);
                lc_query('UPDATE automation_whatsapp_settings SET enabled=1 WHERE id=1');
                lc_wpp_admin_json(['ok'=>true]);
            }
            if($method==='POST'&&$path==='/admin/whatsapp/api/close'){
                lc_query('UPDATE automation_whatsapp_settings SET enabled=0 WHERE id=1');
                // Close ONLY the dedicated LeagueCut session; do not log out or erase tokens.
                lc_wpp_admin_request('close-session','POST');
                lc_wpp_admin_json(['ok'=>true]);
            }
        }catch(Throwable $e){
            error_log('LeagueCut WPP admin error: '.get_class($e));
            lc_wpp_admin_json(['error'=>$e->getMessage()],503);
        }
        lc_wpp_admin_json(['error'=>'Operação indisponível'],404);
    }
    if($method!=='GET'||$path!=='/admin/whatsapp')return false;
    lc_page('WhatsApp • Integrações',function(){ ?>
    <div class="container lc-wpp-admin">
      <header class="lc-flow-top"><div><a href="/admin/automacoes" class="lc-flow-back">← Automações</a>
        <span class="eyebrow">INTEGRAÇÕES · WHATSAPP</span>
        <h1>Conecte seu WhatsApp.</h1>
        <p>Gerencie a sessão exclusiva da LeagueCut sem abrir o terminal ou afetar o TaskEsc.</p></div>
        <span class="tag">Sessão: leaguecut</span>
      </header>
      <div class="lc-wpp-grid">
      <section class="panel lc-wpp-main">
        <div class="lc-wpp-heading"><h2>WhatsApp Business</h2><span class="lc-wpp-status" id="wpp-state">Verificando...</span></div>
        <div class="lc-wpp-qr" id="wpp-qr-area">
          <p id="wpp-qr-info">Clique em “Conectar” para gerar o QR Code.</p>
          <img id="wpp-qr-image" alt="QR Code temporário para vincular o WhatsApp à LeagueCut" hidden>
        </div>
        <div class="lc-wpp-actions">
           <button class="btn" type="button" id="wpp-start">Conectar / novo QR Code</button>
           <button class="btn btn-ghost" type="button" id="wpp-refresh">Atualizar estado</button>
           <button class="btn btn-ghost" type="button" id="wpp-close">Encerrar sessão da LeagueCut</button>
        </div>
        <p class="hint" id="wpp-feedback" role="status" aria-live="polite">A conexão precisa ser autorizada no celular.</p>
      </section>
      <aside class="panel lc-wpp-guide">
         <h2>Como conectar</h2>
         <ol><li>Abra o WhatsApp no celular que será usado pela LeagueCut.</li>
         <li>Entre em <strong>Aparelhos conectados → Conectar aparelho</strong>.</li>
         <li>Clique em <strong>Conectar</strong> e escaneie o QR Code exibido ao lado.</li>
         <li>Quando o status estiver conectado, clique em <strong>Ativar automações</strong>.</li></ol>
         <div class="lc-wpp-switch">
            <h3>Envio automático</h3>
            <p>Mesmo após parear, as automações só poderão enviar mensagens se você ativar esta opção.</p>
            <button class="btn" type="button" id="wpp-enable">Ativar automações</button>
            <button class="btn btn-ghost" type="button" id="wpp-pause">Pausar disparos</button>
         </div>
         <p class="hint">Utilize apenas números com autorização para receber mensagens. A API é não oficial e o WhatsApp pode impor restrições.</p>
      </aside></div>
    </div>
    <?php },'admin');
    return true;
}
