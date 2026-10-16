<?php
declare(strict_types=1);

/** B6D2a: foreground notices do not require VAPID or background push. */
function lc_foreground_notices_routes(string $method,string $path): bool {
    if($path!=='/notificacoes'||$method!=='GET')return false;
    $user=lc_login_required(false);
    lc_page('Notificações',function(){?>
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
<section class="panel"><h2>Notificações com o aplicativo fechado</h2>
<p>O envio Web Push será ativado no B6D2b, após configuração segura das chaves VAPID e do servidor. Esta etapa não envia notificações com o site fechado.</p></section>
</div>
<?php },$user['role']==='admin'?'admin':($user['role']==='criador'?'creator':'dashboard'));
    return true;
}
