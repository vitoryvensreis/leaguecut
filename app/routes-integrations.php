<?php
declare(strict_types=1);

/** Admin-only connection setup; refresh tokens are never rendered in HTML. */
function lc_integrations_routes(string $method,string $path): bool {
    if(!str_starts_with($path,'/admin/integracoes'))return false;
    $admin=lc_admin();
    if($method==='GET'&&$path==='/admin/integracoes'){
        $connected=lc_drive_connection();
        $clientConfigured=lc_env('GOOGLE_CLIENT_ID')!==''&&lc_env('GOOGLE_CLIENT_SECRET')!=='';
        $legacy=lc_env('GOOGLE_REFRESH_TOKEN')!==''&&lc_env('GOOGLE_DRIVE_FOLDER_ID')!=='';
        $active=lc_drive_ready();
        $folderPresent=$connected?(string)$connected['folder_id']!=='':lc_env('GOOGLE_DRIVE_FOLDER_ID')!=='';
        $oauthRedirect=rtrim(lc_env('APP_URL','https://leaguecut.com'),'/').'/admin/integracoes/google/callback';
        $count=(int)(lc_one('SELECT COUNT(*) AS n FROM clips WHERE drive_file_id IS NOT NULL')['n']??0);
        $apifyConfig=lc_apify_config();
        $apifyUsage=lc_apify_usage();
        $todayFortaleza=(new DateTimeImmutable('now',new DateTimeZone('America/Fortaleza')))->format('Y-m-d');
        $dailyProgress=lc_one("SELECT COUNT(*) AS total,
           SUM(EXISTS(SELECT 1 FROM clip_daily_views d WHERE d.clip_id=c.id AND d.snapshot_date=?)) AS updated
           FROM clips c JOIN social_accounts s ON s.id=c.social_account_id
           WHERE c.status='approved' AND c.drive_file_id IS NOT NULL AND s.network='instagram'",
           [$todayFortaleza])??['total'=>0,'updated'=>0];
        $apifyRuns=lc_all('SELECT attempted_at,status,matched_clips,result_count,max_charge_usd FROM apify_profile_runs ORDER BY id DESC LIMIT 8');
        lc_page('Integrações',function()use($admin,$connected,$clientConfigured,$legacy,$active,$folderPresent,$oauthRedirect,$count,$apifyConfig,$apifyUsage,$apifyRuns,$dailyProgress){?>
<div class="container container-md">
 <?php lc_heading('Configurações do sistema','Integrações','Conecte serviços externos sem salvar tokens ou senhas no banco de configurações públicas.'); ?>
 <div class="panel">
   <div class="panel-head"><h2><?=lc_icon('folder-open')?> Google Drive — vídeos privados</h2>
     <span class="tag <?=$active?'tag-approved':'tag-pending'?>"><?=$active?'Configurado':'Aguardando configuração'?></span></div>
   <p>Os vídeos originais são enviados a uma pasta privada da conta Google autorizada. O acesso pelo Drive respeita as permissões da conta, sem links públicos automáticos.</p>
   <div class="stats-grid">
    <div class="metric"><small>Credenciais OAuth</small><strong><?=$clientConfigured?'Configuradas':'Pendentes'?></strong></div>
    <div class="metric"><small>Conta conectada</small><strong><?=$connected?'Via painel':($legacy?'Via .env':'Não')?></strong></div>
    <div class="metric"><small>Pasta de destino</small><strong><?=$folderPresent?'Definida':'Pendente'?></strong></div>
    <div class="metric"><small>Vídeos enviados</small><strong><?=$count?></strong></div>
   </div>
   <?php if(!$clientConfigured): ?>
     <div class="alert">Configure <code>GOOGLE_CLIENT_ID</code> e <code>GOOGLE_CLIENT_SECRET</code> no arquivo privado <code>/home/leaguecut/shared/.env</code>. Depois retorne aqui e conecte sua conta.</div>
   <?php endif; ?>
   <div class="panel integration-instructions">
     <h3>Redirecionamento OAuth autorizado no Google Cloud</h3>
     <p>Crie um cliente OAuth do tipo <strong>Aplicativo da Web</strong>, habilite a Google Drive API e adicione o endereço abaixo aos URIs de redirecionamento autorizados:</p>
     <div class="integration-copy"><code><?=lc_e($oauthRedirect)?></code></div>
     <p class="hint">A autorização utiliza o escopo <code>drive.file</code> e cria uma pasta específica do LeagueCut. Você poderá revogar o acesso pela sua conta Google.</p>
   </div>
   <div class="form-actions">
    <?php if($clientConfigured):?>
      <form method="post" action="/admin/integracoes/google/conectar"><?=lc_csrf()?><button type="submit" class="btn"><?=lc_icon('external-link')?> <?=$connected?'Reconectar':'Conectar conta Google'?></button></form>
    <?php endif;?>
    <?php if($active):?>
      <form method="post" action="/admin/integracoes/google/testar"><?=lc_csrf()?><button type="submit" class="btn btn-ghost"><?=lc_icon('circle-check')?> Testar acesso à pasta</button></form>
    <?php endif;?>
    <?php if($connected):?>
      <form method="post" action="/admin/integracoes/google/desconectar" onsubmit="return confirm('Desconectar a conta Google do LeagueCut? Arquivos existentes continuarão no Google Drive.')"><?=lc_csrf()?><button type="submit" class="btn btn-ghost">Desconectar</button></form>
    <?php endif;?>
   </div>
   <p class="hint">Nenhum arquivo é excluído ao desconectar. A conexão antiga via variáveis do .env, se houver, permanece disponível até ser desativada manualmente.</p>
 </div>
 <div class="panel"><h2><?=lc_icon('chart-column')?> Métricas Instagram · Apify</h2>
   <span class="tag <?=$apifyConfig['token']!==''?'tag-approved':'tag-pending'?>"><?=$apifyConfig['token']!==''?'Token configurado':'Ainda não configurado'?></span>

   <?php if(lc_env('APIFY_SYNC_ENABLED','1')!=='1'): ?>
      <p class="hint" role="status"><strong>Agendamento pausado.</strong> O token está guardado, mas nenhuma consulta programada será executada até confirmarmos os contadores de views do Actor oficial em um teste controlado.</p>
   <?php endif; ?>
   <p>Consultas periódicas com cache e limite de custo. O sistema consulta URLs exatas de clipes Instagram aprovados, com vídeo original armazenado e em campanhas ativas, registrando visualizações públicas observadas, sem aprovar recompensas automaticamente.</p>
   <p class="hint"><strong>Atualização automática:</strong> diariamente às 02:00 de Fortaleza (05:00 UTC). São consultadas publicações de clipes aprovados com original; o alcance depende do limite de consumo e da disponibilidade do provedor. Os dados são observacionais, sem crédito financeiro automático.</p>
   <div class="stats-grid">
     <div class="metric"><small>Clipes monitorados hoje</small><strong><?=(int)$dailyProgress['updated']?> / <?=(int)$dailyProgress['total']?></strong></div>
     <div class="metric"><small>Execuções hoje (UTC)</small><strong><?=$apifyUsage['runs']?> / <?=$apifyConfig['daily_runs']?></strong></div>
     <div class="metric"><small>Teto reservado hoje (USD)</small><strong>$<?=number_format($apifyUsage['max_reserved_usd'],3,'.','')?> / $<?=number_format($apifyConfig['daily_usd'],2,'.','')?></strong></div>
     <div class="metric"><small>Links por execução</small><strong><?=min(20,(int)$apifyConfig['max_posts'])?></strong></div>
     <div class="metric"><small>Intervalo por publicação</small><strong><?=(int)$apifyConfig['min_hours']?>h</strong></div>
   </div>
   <p class="hint">Actor oficial: <strong>apify/instagram-post-scraper</strong>, entrada por link e <code>detailedData</code> (pode ter custo adicional). Valores observados não geram crédito automaticamente.</p>
   <p class="hint">Para ativar: adicione <code>APIFY_API_TOKEN</code> no arquivo privado <code>/home/leaguecut/shared/.env</code>. Após salvar, verifique o token gratuitamente. O agendamento executa uma vez por dia às 02h (Fortaleza, UTC−3; cron 05:00 UTC), mas respeita as cotas. A API não fornece métricas em tempo real nem garante views de todos os posts; números e gastos reais dependem do provedor.</p>
   <div class="form-actions">
     <form method="post" action="/admin/integracoes/apify/verificar"><?=lc_csrf()?>
       <button class="btn btn-ghost" type="submit" <?=$apifyConfig['token']===''?'disabled':''?>><?=lc_icon('circle-check')?> Verificar token (sem cobrança)</button>
     </form>
   </div>
   <?php if($apifyRuns): ?>
     <h3>Últimas consultas</h3>
     <div class="table-wrap" tabindex="0" aria-label="Histórico recente das consultas Apify">
       <table><thead><tr><th>Data (UTC)</th><th>Situação</th><th>Posts retornados</th><th>Clipes atualizados</th><th>Teto reservado</th></tr></thead><tbody>
       <?php foreach($apifyRuns as $run): ?>
       <tr><td><?=lc_e($run['attempted_at'])?></td><td><?=lc_e($run['status']==='completed'?'Concluída':($run['status']==='failed'?'Falhou':'Reservada'))?></td>
       <td><?=(int)$run['result_count']?></td><td><?=(int)$run['matched_clips']?></td>
       <td>US$ <?=number_format((float)$run['max_charge_usd'],3,',','.')?></td></tr>
       <?php endforeach;?></tbody></table>
     </div>
   <?php else: ?><p class="hint">Ainda não existem consultas registradas. Nenhum custo foi reservado.</p><?php endif;?>
   <p class="hint">TikTok e clipes rejeitados não estão incluídos no coletor Instagram. Se o total de clipes ultrapassar a capacidade do orçamento diário, o restante aparecerá como aguardando coleta, sem inventar dados.</p>
   <a href="/admin/clipes" class="btn btn-ghost">Ver views dos clipes <?=lc_icon('arrow-right')?></a>
   <a href="https://apify.com/apify/instagram-post-scraper" target="_blank" rel="noopener noreferrer" class="btn btn-ghost">Ver Actor e custos <?=lc_icon('external-link')?></a>
 </div>
 <div class="panel"><h2>Fluxo de envio</h2>
   <p>Quando configurado, cada clipador poderá anexar MP4, MOV ou WebM à publicação. O administrador poderá abrir o vídeo na conta Google com acesso à pasta, diretamente da página de revisão de clipes.</p>
   <p>Limite lógico: 1 GB por vídeo. O limite real depende também do PHP e Nginx, que podem recusar arquivos menores. Para cargas grandes será necessária uma etapa posterior de upload direto e retomável.</p>
   <a class="btn btn-ghost" href="/admin/clipes">Conferir clipes <?=lc_icon('arrow-right')?></a>
 </div>
</div>
<?php },'admin');return true;
    }
    if($method==='POST'&&$path==='/admin/integracoes/apify/verificar'){
        try {
            if(lc_apify_check_connection())lc_flash('Apify autenticada com sucesso. Esta verificação não iniciou Actors e não consumiu o orçamento de consultas.');
            else lc_flash('Não foi possível autenticar na Apify. Confira o token no .env e as permissões da conta.','error');
        }catch(Throwable $e){
            error_log('LeagueCut Apify: consulta de diagnóstico indisponível');
            lc_flash('Não foi possível contactar a Apify agora. Tente novamente mais tarde.','error');
        }
        lc_redirect('/admin/integracoes');
    }
    if($method==='POST'&&$path==='/admin/integracoes/google/conectar'){
        try {
            $url=lc_drive_start_oauth((int)$admin['id']);
            header('Location: '.$url,true,303);exit;
        }catch(Throwable $e){
            lc_flash('Não foi possível iniciar a conexão Google. Verifique as credenciais OAuth do .env.','error');
            lc_redirect('/admin/integracoes');
        }
    }
    if($method==='GET'&&$path==='/admin/integracoes/google/callback'){
        try{
            if(isset($_GET['error']))throw new RuntimeException('A autorização foi cancelada ou negada.');
            lc_drive_finish_oauth((int)$admin['id'],(string)($_GET['code']??''),(string)($_GET['state']??''));
            lc_flash('Google Drive conectado. Uma pasta privada foi criada para os novos clipes.');
        }catch(Throwable $e){
            unset($_SESSION['drive_oauth']);
            lc_flash('Não foi possível concluir a conexão Google. Verifique o URI autorizado, a Drive API e tente novamente.','error');
        }
        lc_redirect('/admin/integracoes');
    }
    if($method==='POST'&&$path==='/admin/integracoes/google/testar'){
        try{
            if(!lc_drive_check_folder())throw new RuntimeException('Pasta não encontrada.');
            lc_flash('Conexão com Google Drive confirmada. A pasta está acessível.');
        }catch(Throwable $e){
            lc_flash('Não foi possível verificar a pasta do Drive. Reconecte sua conta e confira as permissões.','error');
        }
        lc_redirect('/admin/integracoes');
    }
    if($method==='POST'&&$path==='/admin/integracoes/google/desconectar'){
        lc_query('DELETE FROM google_drive_connections WHERE id=1');
        lc_flash('Conta conectada pelo painel desconectada. Os vídeos armazenados foram preservados.');
        lc_redirect('/admin/integracoes');
    }
    return false;
}
