<?php
declare(strict_types=1);

/** Browser consent and VAPID are mandatory. Never send to arbitrary URLs from user input. */
function lc_push_vapid_ready(): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]{86,90}$/D',lc_env('PUSH_VAPID_PUBLIC')) &&
      (bool)preg_match('/^[A-Za-z0-9_-]{42,48}$/D',lc_env('PUSH_VAPID_PRIVATE')) &&
      str_starts_with(lc_env('PUSH_VAPID_SUBJECT'),'mailto:') &&
      (bool)filter_var(substr(lc_env('PUSH_VAPID_SUBJECT'),7),FILTER_VALIDATE_EMAIL) &&
      class_exists(\Minishlink\WebPush\WebPush::class);
}
function lc_push_endpoint_valid(string $endpoint): bool {
    if(strlen($endpoint)<35||strlen($endpoint)>2000||!lc_valid_https($endpoint))return false;
    $url=parse_url($endpoint);
    if(!is_array($url)||isset($url['user'])||isset($url['pass'])||isset($url['port'])||isset($url['fragment']))return false;
    $host=strtolower((string)($url['host']??''));
    return in_array($host,[
       'fcm.googleapis.com','fcm-xm.googleapis.com','updates.push.services.mozilla.com',
       'web.push.apple.com'
    ],true) ||
      str_ends_with($host,'.push.apple.com') ||
      str_ends_with($host,'.notify.windows.com') ||
      str_ends_with($host,'.wns.windows.com');
}
function lc_push_subscription_validate(array $body): array {
    $endpoint=$body['endpoint']??'';
    $keys=$body['keys']??null;
    if(!is_string($endpoint)||!lc_push_endpoint_valid($endpoint)||
      !is_array($keys)||!is_string($keys['p256dh']??null)||!is_string($keys['auth']??null)||
      !preg_match('/^[A-Za-z0-9_-]{70,100}$/D',$keys['p256dh']) ||
      !preg_match('/^[A-Za-z0-9_-]{16,40}$/D',$keys['auth']))
       throw new InvalidArgumentException('Assinatura de navegador inválida.');
    return ['endpoint'=>$endpoint,'keys'=>['p256dh'=>$keys['p256dh'],'auth'=>$keys['auth']]];
}
function lc_push_save(int $uid,array $subscription): void {
    $body=lc_push_subscription_validate($subscription);
    $hash=hash('sha256',$body['endpoint']);
    lc_query("INSERT INTO push_subscriptions(user_id,endpoint_hash,endpoint_enc,keys_enc)
       VALUES(?,?,?,?)
       ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),
          endpoint_enc=VALUES(endpoint_enc),keys_enc=VALUES(keys_enc),created_at=CURRENT_TIMESTAMP",
       [$uid,$hash,lc_encrypt($body['endpoint']),lc_encrypt(json_encode($body['keys'],JSON_THROW_ON_ERROR))]);
    lc_query("INSERT INTO push_preferences(user_id,enabled) VALUES(?,1)
       ON DUPLICATE KEY UPDATE enabled=1",[$uid]);
}
function lc_push_disable(int $uid,string $endpoint): void {
    if($endpoint!==''&&lc_push_endpoint_valid($endpoint)){
        lc_query('DELETE FROM push_subscriptions WHERE user_id=? AND endpoint_hash=?',[$uid,hash('sha256',$endpoint)]);
    }else{
        lc_query('DELETE FROM push_subscriptions WHERE user_id=?',[$uid]);
    }
    $remain=(int)(lc_one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id=?',[$uid])['n']??0);
    if($remain===0)lc_query("INSERT INTO push_preferences(user_id,enabled) VALUES(?,0)
       ON DUPLICATE KEY UPDATE enabled=0",[$uid]);
}
function lc_push_routes(string $method,string $path): bool {
    if(!in_array($path,['/notificacoes','/notificacoes/assinar','/notificacoes/desativar'],true))return false;
    $user=lc_login_required(false);$uid=(int)$user['id'];
    if($method==='POST'){
       header('Content-Type: application/json; charset=UTF-8');
       header('Cache-Control: no-store');
       if($path==='/notificacoes/assinar'){
          if(!lc_push_vapid_ready()){http_response_code(503);echo '{"ok":false,"message":"Servidor Web Push ainda não configurado."}';return true;}
          $raw=(string)($_POST['subscription_json']??'');
          try{
              if(strlen($raw)>6000)throw new InvalidArgumentException('Assinatura muito grande.');
              $body=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
              if(!is_array($body))throw new InvalidArgumentException('Assinatura inválida.');
              lc_push_save($uid,$body);
              echo '{"ok":true}';return true;
          }catch(Throwable $e){
              http_response_code(422);echo '{"ok":false,"message":"Assinatura inválida. Tente novamente."}';return true;
          }
       }
       if($path==='/notificacoes/desativar'){
          lc_push_disable($uid,trim((string)($_POST['endpoint']??'')));
          echo '{"ok":true}';return true;
       }
       return false;
    }
    if($method!=='GET'||$path!=='/notificacoes')return false;
    $enabled=(int)(lc_one('SELECT enabled FROM push_preferences WHERE user_id=?',[$uid])['enabled']??0);
    $count=(int)(lc_one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id=?',[$uid])['n']??0);
    $public=lc_push_vapid_ready()?lc_env('PUSH_VAPID_PUBLIC'):'';
    lc_page('Notificações',function()use($public,$count,$enabled){?>
<div class="container container-md" style="max-width:860px">
<?php lc_heading('Central de notificações','Notificações e som',
    'Escolha como receber avisos sobre campanhas, aprovações, clipes e andamento da conta.'); ?>
<div class="panel" id="lc-push-panel" data-vapid="<?=lc_e($public)?>">
<h2>Notificações push neste dispositivo</h2>
<p class="hint">Web Push envia avisos mesmo com o aplicativo fechado, quando autorizado pelo navegador e configurado no servidor. Você pode desativar quando quiser.</p>
<div class="form-actions">
<button class="btn" type="button" data-push-on <?=$public===''?'disabled':''?>>Ativar notificações</button>
<button class="btn btn-ghost" type="button" data-push-off>Desativar neste dispositivo</button>
</div>
<p data-push-state role="status" aria-live="polite"><?=$public===''?'O envio push aguarda configuração das chaves VAPID na VPS.':($enabled?'Notificações permitidas na conta, '.$count.' dispositivo(s) cadastrado(s).':'Notificações desativadas nesta conta.')?></p>
<p class="hint">A permissão é controlada também nas configurações do navegador. Avisos dependem de internet e da disponibilidade dos serviços de push.</p>
</div>
<div class="panel">
<h2>Som dos avisos</h2>
<p class="hint">Som opcional para eventos recebidos enquanto esta aba está aberta. O toque de notificações em segundo plano é controlado pelo navegador e sistema operacional.</p>
<button class="btn btn-ghost" type="button" data-push-sound aria-pressed="false">Ativar som nesta sessão</button>
<p data-push-sound-state role="status" aria-live="polite"></p>
</div></div>
<?php },$user['role']==='admin'?'admin':($user['role']==='criador'?'creator':'dashboard'));
    return true;
}
