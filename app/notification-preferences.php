<?php
declare(strict_types=1);

/** B6D2a: foreground notices do not require VAPID or background push. */
function lc_foreground_notices_routes(string $method,string $path): bool {
    if($path!=='/notificacoes'||$method!=='GET')return false;
    $user=lc_login_required(false);
    $pushReady=lc_push_send_ready();
    $pushPublic=$pushReady?lc_env('PUSH_VAPID_PUBLIC'):'';
    $deviceCount=$pushReady?(int)(lc_one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id=?',[(int)$user['id']])['n']??0):0;
    lc_page('Notificações',function()use($pushReady,$pushPublic,$deviceCount){?>
<div class="container container-md" style="max-width:850px">
<?php lc_heading('Sua central','Notificações e sons','Personalize os avisos quando a LeagueCut estiver aberta. O envio em segundo plano chegará em uma etapa separada.');?>
<section class="panel" id="lc-notification-settings">
<h2>Avisos no navegador</h2>
<p class="hint">Receba notificações do navegador enquanto esta aba estiver aberta e você estiver usando a plataforma. A permissão só será solicitada após seu clique.</p>
<p><button type="button" class="btn" data-notify-enable>Ativar avisos no navegador</button>
<button type="button" class="btn btn-ghost" data-notify-disable>Desativar avisos</button></p>
<p data-notify-status role="status" aria-live="polite"></p>
</section>
<section class="panel">
<h2>Som dos avisos</h2>
<p class="hint">Um toque curto nos novos avisos, sem arquivo externo. Funciona com o site aberto e depois de sua interação. Sons de push em segundo plano são controlados pelo sistema operacional.</p>
<button class="btn btn-ghost" type="button" data-notify-sound aria-pressed="false">Ativar som</button>
<p data-notify-sound-status role="status" aria-live="polite"></p>
</section>
<section class="panel" id="lc-push-background" data-vapid="<?=lc_e($pushPublic)?>" data-csrf="<?=lc_e(lc_token())?>">
<h2>Push com a LeagueCut fechada</h2>
<p class="hint">Ative somente se quiser receber notificações deste dispositivo. Seus eventos continuam protegidos, e os avisos enviados não exibem dados pessoais nem financeiros. É necessário usar HTTPS e autorizar o navegador.</p>
<div class="form-actions">
<button class="btn" data-background-on type="button" <?=$pushReady?'':'disabled'?>>Ativar neste dispositivo</button>
<button class="btn btn-ghost" data-background-off type="button" <?=$pushReady?'':'disabled'?>>Desativar neste dispositivo</button>
</div>
<p data-background-status role="status" aria-live="polite">
<?=$pushReady?'Web Push disponível; '.$deviceCount.' dispositivo(s) cadastrado(s) na conta.':'Web Push aguardando a ativação segura de VAPID na VPS. Os avisos com o site aberto continuam disponíveis.'?>
</p>
<p class="hint">O navegador e o sistema operacional controlam som e apresentação dos avisos com o app fechado. No iPhone, instale o PWA pela Tela de Início antes de permitir o push. Nunca é solicitado consentimento automaticamente.</p>
</section>
</div>
<?php },$user['role']==='admin'?'admin':($user['role']==='criador'?'creator':'dashboard'));
    return true;
}
