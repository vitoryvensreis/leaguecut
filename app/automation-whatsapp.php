<?php
declare(strict_types=1);

/** Server-only WPPConnect access. Never send authorization data to the browser. */
function lc_wpp_admin_request(string $endpoint,string $method='GET',?array $payload=null): array {
    if(lc_env('WPP_SESSION','leaguecut')!=='leaguecut')
        throw new RuntimeException('Esta conexão está reservada à sessão leaguecut.');
    $token=lc_env('WPP_AUTH_TOKEN');
    if($token==='')throw new RuntimeException('Token privado do WPPConnect não configurado.');
    $allowed=['check-connection-session','status-session','qrcode-session','start-session','close-session'];
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
    if($raw===false)throw new RuntimeException('Não foi possível acessar o WPPConnect na VPS. Verifique o serviço e a porta local.');
    if($status===401||$status===403)throw new RuntimeException('Token da sessão leaguecut recusado pelo WPPConnect (HTTP '.$status.').');
    if($status<200||$status>=300)
        throw new RuntimeException('WPPConnect recusou '.$endpoint.' (HTTP '.$status.').');
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
/**
 * The session state is the WPPConnect lifecycle source of truth. The
 * check-connection endpoint can temporarily report false while pairing.
 * Unknown/unsupported states fall back to the classic connection check.
 */
function lc_wpp_parse_session_state(array $data): array {
    $raw=$data['status']??null;
    $value=is_string($raw)?strtoupper(trim($raw)):'';
    if(in_array($value,['CONNECTED','ISLOGGED','INCHAT','OPEN'],true))
        return ['connected'=>true,'status'=>'CONNECTED','provider_status'=>$value,'source'=>'session'];
    if(in_array($value,['INITIALIZING','STARTING','QRCODE','PHONECODE','CONNECTING','PAIRING'],true))
        return ['connected'=>false,'status'=>'CONNECTING','provider_status'=>$value,'source'=>'session'];
    if(in_array($value,['CLOSED','CLOSING','DISCONNECTED','ERROR','LOGOUT'],true))
        return ['connected'=>false,'status'=>'DISCONNECTED','provider_status'=>$value,'source'=>'session'];
    return ['connected'=>false,'status'=>'UNKNOWN','provider_status'=>'UNKNOWN','source'=>'session'];
}
function lc_wpp_admin_status(): array {
    $stateError=null;
    try{
        $response=lc_wpp_admin_request('status-session');
        if(is_array($response['json'])){
            $state=lc_wpp_parse_session_state($response['json']);
            if($state['status']!=='UNKNOWN')return $state;
        }
    }catch(Throwable $e){$stateError=$e;}
    try{
        $result=lc_wpp_admin_request('check-connection-session');
        $data=$result['json'];
        if(!is_array($data))throw new RuntimeException('O WPPConnect retornou um estado inválido.');
        $normalized=lc_wpp_parse_connection($data);
        return $normalized+['provider_status'=>'UNKNOWN','source'=>'connection-check'];
    }catch(Throwable $e){
        if($stateError!==null)throw $stateError;
        throw $e;
    }
}
/**
 * WPPConnect returns a PNG for /qrcode-session, but start-session and
 * status-session may return a base64 QR in JSON. Never expose urlcode.
 */
function lc_wpp_admin_extract_qr(array $response): ?string {
    $raw=$response['body']??'';
    if(is_string($raw) && str_starts_with(strtolower((string)($response['type']??'')),'image/png')
        && str_starts_with($raw,"\x89PNG\r\n\x1a\n") && strlen($raw)<2500000)
        return 'data:image/png;base64,'.base64_encode($raw);
    $json=$response['json']??null;
    if(!is_array($json))return null;
    $data=is_array($json['data']??null)?$json['data']:[];
    $candidates=[$json['qrcode']??null,$json['qrCode']??null,
        $json['base64']??null,$json['qrcodeBase64']??null,
        $data['qrcode']??null,$data['qrCode']??null,$data['base64']??null];
    foreach($candidates as $value){
        if(!is_string($value)||strlen($value)>2500000)continue;
        if(str_starts_with($value,'data:image/png;base64,')){
            $value=substr($value,22);
        }
        if(!preg_match('~^[A-Za-z0-9+/]+={0,2}$~D',$value)||strlen($value)<100)continue;
        $bytes=base64_decode($value,true);
        if($bytes!==false && str_starts_with($bytes,"\x89PNG\r\n\x1a\n"))
            return 'data:image/png;base64,'.$value;
    }
    return null;
}
function lc_wpp_admin_qr(): array {
    $sessionError=null;
    $currentStatus='UNKNOWN';
    // First inspect session state. Once connected, never serve a cached
    // pairing QR even if the WPPConnect process still retains its urlcode.
    try{
        $session=lc_wpp_admin_request('status-session');
        if(is_array($session['json'])){
            $state=lc_wpp_parse_session_state($session['json']);
            $currentStatus=$state['provider_status'];
            if($state['connected'])return ['qr'=>null,'status'=>'CONNECTED'];
            $qr=lc_wpp_admin_extract_qr($session);
            if($qr!==null)return ['qr'=>$qr,'status'=>$currentStatus];
        }
    }catch(Throwable $e){$sessionError=$e;}
    try{
        $response=lc_wpp_admin_request('qrcode-session');
        $qr=lc_wpp_admin_extract_qr($response);
        if($qr!==null)return ['qr'=>$qr,'status'=>'QRCODE'];
        if($currentStatus==='UNKNOWN'){
            $raw=$response['json']['status']??null;
            if(is_string($raw))$currentStatus=lc_wpp_parse_session_state(['status'=>$raw])['provider_status'];
        }
        return ['qr'=>null,'status'=>$currentStatus];
    }catch(Throwable $e){
        if($sessionError!==null)throw $sessionError;
        if($currentStatus!=='UNKNOWN')return ['qr'=>null,'status'=>$currentStatus];
        throw $e;
    }
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
                lc_wpp_admin_json(lc_wpp_admin_qr());
            }
            if($method==='POST'&&$path==='/admin/whatsapp/api/start'){
                $before=lc_wpp_admin_status();
                if($before['connected']){
                    lc_wpp_admin_json(['ok'=>true,'connected'=>true,'status'=>'CONNECTED']);
                }
                // A running WPPConnect session must never be closed implicitly:
                // close-session can fail with HTTP 500 when Chromium is pairing.
                // Reuse the existing session/QR instead of disrupting it.
                $provider=(string)($before['provider_status']??'UNKNOWN');
                if(in_array($provider,['QRCODE','INITIALIZING','STARTING','CONNECTING','PAIRING','PHONECODE'],true)){
                    $qr=lc_wpp_admin_qr();
                    lc_wpp_admin_json(['ok'=>true,'qr'=>$qr['qr'],
                        'status'=>$qr['status'],'reused'=>true]);
                }
                if($provider==='ERROR')
                    lc_wpp_admin_json(['error'=>'A sessão leaguecut falhou no WPPConnect. É necessário diagnosticar o serviço antes de reiniciar.'],503);
                $started=lc_wpp_admin_request('start-session','POST',['webhook'=>'','waitQrCode'=>false]);
                $state=strtoupper((string)($started['json']['status']??''));
                if($state==='ERROR')throw new RuntimeException('WPPConnect não conseguiu iniciar a sessão leaguecut. Confira os logs do serviço.');
                lc_wpp_admin_json(['ok'=>true,'qr'=>lc_wpp_admin_extract_qr($started),
                    'status'=>in_array($state,['INITIALIZING','QRCODE','CONNECTED','CLOSED','STARTING'],true)?$state:'UNKNOWN']);
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
