<?php
declare(strict_types=1);

function lc_clip_ajax(): bool {
    return ($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest';
}
function lc_clip_submission_error(string $message,string $back): never {
    if(lc_clip_ajax()){
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');http_response_code(422);
        echo json_encode(['ok'=>false,'error'=>$message],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;
    }
    lc_flash($message,'error');lc_redirect($back);
}
/**
 * Caller owns the transaction. Re-check balance with locked user row, so the
 * emailed/WhatsApp-verified amount cannot be replaced at confirmation time.
 */
function lc_withdrawal_apply(array $u,int $amount): int {
    if($amount<100||$amount>100000000000)
        throw new InvalidArgumentException('Valor de saque inválido (mínimo R$ 1,00).');
    lc_one('SELECT id FROM users WHERE id=? FOR UPDATE',[(int)$u['id']]);
    $balance=lc_balance((int)$u['id']);
    if($amount>$balance['available'])throw new InvalidArgumentException('Saldo insuficiente.');
    lc_query('INSERT INTO withdrawals(user_id,amount_cents) VALUES(?,?)',[$u['id'],$amount]);
    $withdrawalId=(int)lc_db()->lastInsertId();
    lc_realtime_emit('admin',null,'withdrawal.requested','withdrawal',$withdrawalId);
    lc_realtime_emit('user',(int)$u['id'],'withdrawal.requested','withdrawal',$withdrawalId);
    lc_automation_emit('withdrawal.requested','withdrawal.requested:'.$withdrawalId,(int)$u['id'],
       ['role'=>'clipador','status'=>'requested','link'=>'/admin/financeiro']);
    return $withdrawalId;
}

function lc_clipper_routes(string $method,string $path): bool {
    // A buyer/creator must never access creator-of-clips submissions, Pix or payouts.
    $visitor=lc_user();
    if($visitor && $visitor['role']==='criador'){
        if($path==='/dashboard')lc_redirect('/criador');
        if(in_array($path,['/biblioteca','/redes','/financeiro','/clipes'],true) ||
           str_starts_with($path,'/clipes/') || str_starts_with($path,'/financeiro/')){
            http_response_code(403);exit('Esta área pertence aos clipadores, não aos criadores assinantes.');
        }
    }
    if($method==='GET' && $path==='/dashboard'){
        $u=lc_login_required();
        if($u['role']==='admin')lc_redirect('/admin');
        $balance=lc_balance((int)$u['id']);
        $stats=lc_one("SELECT COUNT(*) AS total,COALESCE(SUM(status='approved'),0) AS approved,COALESCE(SUM(status='pending'),0) AS pending,COALESCE(SUM(verified_views),0) AS views FROM clips WHERE user_id=?",[$u['id']]);
        $networks=(int)(lc_one("SELECT COUNT(*) AS total FROM social_accounts WHERE user_id=?",[$u['id']])['total']??0);
        $recent=lc_all("SELECT c.title,c.status,c.submitted_at,c.verified_views,p.title AS campaign_title FROM clips c JOIN campaigns p ON p.id=c.campaign_id WHERE c.user_id=? ORDER BY c.id DESC LIMIT 5",[$u['id']]);
        $campaigns=lc_all("SELECT * FROM campaigns WHERE status='active' AND (starts_at IS NULL OR starts_at<=CURRENT_DATE()) AND (ends_at IS NULL OR ends_at>=CURRENT_DATE()) ORDER BY created_at DESC LIMIT 4");
        $infoBanners=lc_all("SELECT * FROM clipper_info_banners WHERE is_active=1 ORDER BY sort_order,id LIMIT 8");
        $participating=lc_all("SELECT DISTINCT p.* FROM campaigns p JOIN clips c ON c.campaign_id=p.id WHERE c.user_id=? ORDER BY p.created_at DESC LIMIT 12",[(int)$u['id']]);
        lc_page('Minha área',function()use($u,$balance,$stats,$networks,$recent,$campaigns,$infoBanners,$participating){?>
<div class="container ws-dashboard">
  <?php lc_clipper_dashboard_hero($campaigns,$infoBanners); ?>
  <div class="ws-kpi-grid" aria-label="Indicadores da conta">
  <?php foreach([
     ['Saldo disponível',lc_money($balance['available']),'wallet','Créditos liberados para saque'],
     ['Vídeos enviados',(string)(int)$stats['total'],'video','Publicações registradas'],
     ['Clipes aprovados',(string)(int)$stats['approved'],'circle-check','Entregas já conferidas'],
     ['Views verificadas',number_format((int)$stats['views'],0,',','.'),'chart-column','Conferidas manualmente no momento']
    ] as $index=>[$name,$value,$icon,$help]):?>
    <div class="ws-kpi"><div class="ws-kpi-top"><span><?=lc_e($name)?></span><span class="ws-kpi-icon"><?=lc_icon($icon)?></span></div><div class="ws-kpi-value" <?=$index===0?'':'data-live="'.lc_e(['available_cents','total_clips','approved_clips','verified_views'][$index]).'"'?>>
<?php if($index===0):?><span class="lc-private" data-lc-private><span class="lc-private-value" data-live="available_cents"><?=lc_e($value)?></span></span>
<?php else:?><?=lc_e($value)?><?php endif;?></div><p class="ws-kpi-foot"><?=lc_e($help)?></p></div>
  <?php endforeach;?>
  </div>
  <div class="ws-dual">
    <section class="panel" aria-labelledby="recent-creator-title">
      <div class="ws-section-title"><h2 id="recent-creator-title">Últimas publicações</h2><a href="/biblioteca">Ver biblioteca <?=lc_icon('arrow-right')?></a></div>
      <?php if(!$recent):?><div class="ws-empty-note">Você ainda não enviou nenhum clipe. Escolha uma campanha, leia as regras e publique seu primeiro vídeo.</div>
      <?php else:?><div class="ws-recent"><?php foreach($recent as $clip):?>
        <div class="ws-recent-item">
          <div class="ws-recent-leading"><span class="ws-recent-icon"><?=lc_icon('video')?></span>
           <div class="ws-recent-main"><strong><?=lc_e($clip['title'])?></strong><small><?=lc_e($clip['campaign_title'])?> · <?=date('d/m/Y',strtotime($clip['submitted_at']))?></small></div>
          </div>
          <span class="tag tag-<?=lc_e($clip['status'])?>"><?=lc_e(lc_status_label($clip['status']))?></span>
        </div>
      <?php endforeach;?></div><?php endif;?>
    </section>
    <section class="panel" aria-labelledby="next-creator-title">
      <div class="ws-section-title"><h2 id="next-creator-title">Seu próximo passo</h2></div>
      <div class="ws-queue">
        <a class="ws-queue-link" href="/redes"><span><?=lc_icon('share-2')?></span><div><strong>Perfis de publicação</strong><small><?=$networks?> <?= $networks===1?'rede cadastrada':'redes cadastradas'?></small></div><?=lc_icon($networks?'check':'arrow-right')?></a>
        <a class="ws-queue-link" href="/explorar"><span><?=lc_icon('compass')?></span><div><strong>Encontrar campanha</strong><small>Veja briefings e condições de participação</small></div><?=lc_icon('arrow-right')?></a>
        <a class="ws-queue-link" href="/biblioteca"><span><?=lc_icon('clock-3')?></span><div><strong>Clipes em análise</strong><small><span data-live="pending_clips"><?=(int)$stats['pending']?></span> publicações aguardando</small></div><?=lc_icon('arrow-right')?></a>
      </div>
      <div class="ws-inline-notice">Os créditos são liberados somente quando os vídeos são aprovados e as metas da campanha são atingidas.</div>
    </section>
  </div>
  <section class="ws-participating">
   <div class="ws-section-title"><h2>Campanhas que você participa</h2><a href="/explorar">Ver todas <?=lc_icon('arrow-right')?></a></div>
   <?php if($participating):?><div class="catalog"><?php foreach($participating as $c)lc_tile($c);?></div>
   <?php else:?><div class="ws-participating-empty"><strong>Você ainda não participa de nenhuma campanha.</strong>
    <p>Conheça as campanhas, leia as regras e envie seu primeiro clipe para começar.</p>
    <a href="/explorar" class="btn">Participar de uma campanha <?=lc_icon('arrow-right')?></a></div><?php endif;?>
  </section>
  <p class="ws-trace"><?=lc_icon('clock-3')?> Conexão: <strong data-realtime-connection>Conectando</strong> · Visualizações e créditos dependem de conferência.</p>
</div>
<?php },'dashboard');return true;
    }
    if($method==='GET' && $path==='/redes'){
        $u=lc_login_required();$accounts=lc_all('SELECT a.*,o.access_token_enc,o.expires_at,o.revoked_at FROM social_accounts a LEFT JOIN social_oauth_connections o ON o.social_account_id=a.id AND o.provider COLLATE utf8mb4_general_ci = CAST(a.network AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci WHERE a.user_id=? ORDER BY a.id DESC',[$u['id']]);
        $oauthEnabled=lc_env('HYBRID_SOCIAL_OAUTH_ENABLED','0')==='1';
        lc_page('Minhas redes',function()use($accounts,$oauthEnabled){?>
<div class="container container-md"><?php lc_heading('Perfis de publicação','Minhas redes','Adicione seus perfis de Instagram e TikTok. Você pode cadastrar quantos quiser.'); ?>
<div class="alert">Instagram: informe seu perfil normalmente, sem conectar à Meta. As visualizações públicas dos clipes elegíveis são consultadas pela Apify diariamente às <strong>02:00 (Fortaleza)</strong>, respeitando o orçamento configurado. O cadastro do @ não comprova titularidade.</div>
<div class="panel"><h2>Adicionar perfil</h2><form method="post" action="/redes"><?=lc_csrf()?><div class="form-inline">
<label class="field"><span>Rede</span><select name="network"><option value="instagram">Instagram</option><option value="tiktok">TikTok</option></select></label>
<?php lc_field('Usuário (sem @)','username','text','',true,'seuperfil'); ?>
<button class="btn" type="submit">Adicionar</button></div></form></div>
<div class="panel"><h2>Perfis cadastrados</h2>
<?php if(!$accounts):lc_empty('Nenhuma rede cadastrada','Cadastre uma conta para começar a enviar publicações.');
else:?><div class="table-wrap"><table><thead><tr><th>Rede</th><th>Perfil</th><th>Integração</th></tr></thead><tbody><?php foreach($accounts as $a):?><tr>
<td><?=lc_e(ucfirst($a['network']))?></td><td><a href="<?=lc_e($a['profile_url'])?>" target="_blank" rel="noopener noreferrer">@<?=lc_e($a['username'])?> ↗</a></td><td><span class="tag"><?=lc_e($a['network']==='instagram'?'Perfil cadastrado · Apify':lc_social_oauth_connection_label($a))?></span>
<?php if($oauthEnabled && $a['network']==='tiktok' && lc_social_oauth_provider_enabled((string)$a['network']) && lc_social_oauth_status($a)!=='connected' && in_array($a['network'],['instagram','tiktok'],true) && LcSocialOAuth::config((string)$a['network'])['client']!=='' && LcSocialOAuth::config((string)$a['network'])['secret']!==''): ?>
<form method="post" action="/redes/oauth/<?=lc_e($a['network'])?>/iniciar">
<?=lc_csrf()?><input type="hidden" name="account_id" value="<?=(int)$a['id']?>">
<button class="btn btn-ghost" type="submit">Conectar <?=lc_e(ucfirst($a['network']))?></button>
</form>
<?php endif; ?>
<?php if($oauthEnabled && $a['network']==='tiktok' && lc_social_oauth_provider_enabled((string)$a['network']) && lc_social_oauth_status($a)==='connected'): ?>
<form method="post" action="/redes/oauth/<?=lc_e($a['network'])?>/desconectar">
<?=lc_csrf()?><input type="hidden" name="account_id" value="<?=(int)$a['id']?>">
<button class="btn btn-ghost" type="submit">Desconectar</button>
</form>
<?php endif; ?>
</td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
<?php },'social');return true;
    }
    if($method==='POST' && $path==='/redes'){
        $u=lc_login_required();
        $network=lc_post('network',20);$username=ltrim(lc_post('username',100),'@');
        if(!in_array($network,['tiktok','instagram'],true)||!preg_match('/^[a-zA-Z0-9._]{2,100}$/',$username)){
            lc_flash('Informe uma rede e um @ válido.','error');lc_redirect('/redes');
        }
        if(lc_one('SELECT id FROM social_accounts WHERE user_id=? AND network=? AND username=?',[$u['id'],$network,$username])){
            lc_flash('Este perfil já está cadastrado.','error');lc_redirect('/redes');
        }
        lc_query('INSERT INTO social_accounts(user_id,network,username,profile_url) VALUES(?,?,?,?)',[$u['id'],$network,$username,lc_network_url($network,$username)]);
        lc_flash('Perfil cadastrado.');lc_redirect('/redes');
    }
    if($method==='POST' && $path==='/clipes'){
        $u=lc_login_required();
        if($u['role']==='admin'){http_response_code(403);exit('Disponível apenas para clipadores.');}
        $campaignId=lc_int('campaign_id',0,2147483647);$accountId=lc_int('social_account_id',0,2147483647);
        $title=lc_post('title',180);if($title==='')$title='Clipe '.date('Ymd-His').'-'.bin2hex(random_bytes(3));
        $url=lc_post('published_url',800);
        $campaign=lc_one("SELECT * FROM campaigns WHERE id=? AND status='active'",[$campaignId]);
        $account=lc_one('SELECT * FROM social_accounts WHERE id=? AND user_id=?',[$accountId,$u['id']]);
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        $validHost=$account && ($account['network']==='instagram'?in_array($host,['instagram.com','www.instagram.com'],true):in_array($host,['tiktok.com','www.tiktok.com','vm.tiktok.com','vt.tiktok.com'],true));
        if(!$campaign||!$account||mb_strlen($title)<4||!lc_valid_https($url)||!$validHost){
            lc_flash('Verifique a campanha, o perfil e o link HTTPS da publicação na rede selecionada.','error');lc_redirect('/explorar');
        }
        if(lc_one('SELECT id FROM clips WHERE user_id=? AND published_url=?',[$u['id'],$url])){
            lc_flash('Esse link já foi enviado.','error');lc_redirect('/biblioteca');
        }
        // A link alone must NEVER create a clip: original is mandatory.
        if(!isset($_FILES['video']) || ($_FILES['video']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE){
            lc_clip_submission_error('Anexe o vídeo original. O envio do arquivo é obrigatório.','/campanhas/'.$campaign['slug']);
        }
        if(!lc_drive_ready()){
            lc_clip_submission_error('O Google Drive não está conectado. Contate o administrador.','/campanhas/'.$campaign['slug']);
        }
        try {
            $uploaded=lc_drive_upload($_FILES['video'],(int)$u['id']);
            $fileId=$uploaded['id'];$filename=$uploaded['filename'];
        }catch(Throwable $e){
            lc_clip_submission_error($e->getMessage(),'/campanhas/'.$campaign['slug']);
        }
        lc_db()->beginTransaction();
        try{
            lc_query("INSERT INTO clips(user_id,campaign_id,social_account_id,title,published_url,drive_file_id,original_filename)
                  VALUES(?,?,?,?,?,?,?)",[$u['id'],$campaignId,$accountId,$title,$url,$fileId,$filename]);
            $clipId=(int)lc_db()->lastInsertId();
            lc_hybrid_submission($clipId,(string)$account['network'],$url);
            lc_realtime_emit('admin',null,'clip.submitted','clip',$clipId);
            lc_realtime_emit('user',(int)$u['id'],'clip.submitted','clip',$clipId);
            lc_db()->commit();
        }catch(Throwable $e){if(lc_db()->inTransaction())lc_db()->rollBack();throw $e;}
        if(lc_clip_ajax()){
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok'=>true,'redirect'=>'/biblioteca'],JSON_THROW_ON_ERROR);return true;
        }
        lc_flash('Publicação enviada para conferência. Acompanhe sua biblioteca.');lc_redirect('/biblioteca');
    }
    // A creator may edit/delete only BEFORE approval. Both GET and POST
    // require the owner; status is rechecked under a DB lock on every mutation.
    if(preg_match('~^/clipes/([0-9]+)/editar$~D',$path,$matches)){
        $u=lc_login_required();
        if($u['role']!=='clipador'){http_response_code(403);exit('Acesso restrito.');}
        $id=(int)$matches[1];
        if($method==='POST'){
            try{
                $message=lc_clipper_update_clip($id,(int)$u['id'],$_POST,$_FILES);
                if(lc_clip_ajax()){
                    header('Content-Type: application/json; charset=UTF-8');
                    header('Cache-Control: no-store');
                    echo json_encode(['ok'=>true,'redirect'=>'/biblioteca'],JSON_THROW_ON_ERROR);
                    return true;
                }
                lc_flash($message);
                lc_redirect('/biblioteca');
            }catch(Throwable $e){
                lc_clip_submission_error($e->getMessage(),'/clipes/'.$id.'/editar');
            }
        }
        $clip=lc_clipper_editable_clip($id,(int)$u['id']);
        $accounts=lc_all('SELECT id,network,username FROM social_accounts WHERE user_id=? ORDER BY network,username',[(int)$u['id']]);
        $campaign=lc_one('SELECT title FROM campaigns WHERE id=?',[(int)$clip['campaign_id']]);
        lc_page('Editar envio',function()use($clip,$accounts,$campaign){?>
<div class="container container-md">
<?php lc_heading('Biblioteca','Editar envio','Você pode corrigir os dados e substituir o vídeo original enquanto o clipe não for aprovado.'); ?>
<div class="panel">
<p class="hint">Campanha: <strong><?=lc_e($campaign['title']??'Campanha')?></strong>. Ao salvar, o clipe ficará pendente de nova conferência. Após aprovação, edição e exclusão são bloqueadas.</p>
<form id="lc-clip-form" data-optional-video="<?=$clip['drive_file_id']?'1':'0'?>" method="post" action="/clipes/<?=(int)$clip['id']?>/editar" enctype="multipart/form-data">
<?=lc_csrf()?>
<div class="form-grid">
<input type="hidden" name="title" value="<?=lc_e($clip['title'])?>">
<div class="full"><?php lc_field('Link da publicação','published_url','url',(string)$clip['published_url'],true,'https://www.instagram.com/reel/...'); ?></div>
<label class="field full"><span>Perfil usado para publicar</span>
<select name="social_account_id" required>
<?php foreach($accounts as $a):?>
<option value="<?=(int)$a['id']?>" <?=(int)$a['id']===(int)$clip['social_account_id']?'selected':''?>><?=lc_e(ucfirst($a['network']).' @'.$a['username'])?></option>
<?php endforeach;?>
</select></label>
<label class="field full"><span>Substituir vídeo original <?=$clip['drive_file_id']?'(opcional)':'(obrigatório)'?></span>
<input id="lc-clip-file" name="video" type="file" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm" <?=$clip['drive_file_id']?'':'required'?>>
<small>MP4, MOV ou WebM (até 1 GB). Se não selecionar um arquivo, manteremos o original armazenado.</small></label>
<div id="lc-upload-error" class="alert alert-error full" role="alert" hidden></div>
<div class="full clip-upload-progress" id="lc-upload-progress" hidden role="status" aria-live="polite">
  <div class="clip-upload-head"><span id="lc-upload-stage">Preparando vídeo…</span><strong id="lc-upload-percent">0%</strong></div>
  <progress id="lc-upload-meter" max="100" value="0"></progress>
  <small id="lc-upload-detail">Transferência do navegador até a VPS.</small>
</div>
<div class="full clipper-edit-buttons">
<button id="lc-clip-submit" type="submit" class="btn"><?=lc_icon('save')?> Salvar alterações</button>
<a class="btn btn-ghost" href="/biblioteca">Cancelar</a>
</div>
</div>
</form>
</div>
<?php if($clip['drive_file_id']):?><div class="panel">
<h2>Original armazenado</h2><button type="button" class="btn btn-ghost btn-sm clip-preview-trigger" data-preview-url="/clipes/<?=(int)$clip['id']?>/video" data-preview-name="<?=lc_e($clip['title'])?>"><?=lc_icon('play')?> Assistir vídeo atual</button>
</div><?php endif;?>
</div>
<script defer src="/assets/clip-upload.js?v=9"></script>
<?php lc_clip_preview_modal();},'library');
        return true;
    }
    if($method==='POST' && preg_match('~^/clipes/([0-9]+)/excluir$~D',$path,$matches)){
        $u=lc_login_required();
        if($u['role']!=='clipador'){http_response_code(403);exit('Acesso restrito.');}
        try{
            lc_flash(lc_clipper_delete_clip((int)$matches[1],(int)$u['id']));
        }catch(Throwable $e){lc_flash($e->getMessage(),'error');}
        lc_redirect('/biblioteca');
    }
    if($method==='GET' && $path==='/biblioteca'){
        $u=lc_login_required();
        $clips=lc_all('SELECT c.*,p.title AS campaign_title,s.network,s.username,
 (SELECT o.views FROM clip_daily_views o WHERE o.clip_id=c.id ORDER BY o.snapshot_date DESC LIMIT 1) AS observed_views,
 (SELECT o.snapshot_date FROM clip_daily_views o WHERE o.clip_id=c.id ORDER BY o.snapshot_date DESC LIMIT 1) AS snapshot_date
 FROM clips c JOIN campaigns p ON p.id=c.campaign_id JOIN social_accounts s ON s.id=c.social_account_id WHERE c.user_id=? ORDER BY c.submitted_at DESC,c.id DESC LIMIT 150',[$u['id']]);
        lc_page('Minha biblioteca',function()use($clips){?>
<div class="container"><?php lc_heading('Seu conteúdo','Minha biblioteca','Suas publicações, vídeos armazenados e resultados verificados.'); ?>
<div class="panel"><div class="panel-head"><h2>Clipes enviados</h2><a href="/explorar" class="btn btn-sm">Nova publicação <?=lc_icon('plus')?></a></div>
<?php if(!$clips):lc_empty('Sua biblioteca está vazia','Escolha uma campanha e envie a primeira publicação.');
else:?><div class="table-wrap"><table><thead><tr><th>Vídeo</th><th>Campanha</th><th>Rede</th><th>Views conferidas</th><th>Status</th><th>Drive</th><th>Ações</th></tr></thead><tbody>
<?php foreach($clips as $c):?><tr><td><a href="<?=lc_e($c['published_url'])?>" target="_blank" rel="noopener noreferrer"><?=lc_e($c['title'])?> ↗</a><small><?=date('d/m/Y',strtotime($c['submitted_at']))?></small></td>
<td><?=lc_e($c['campaign_title'])?></td><td><?=lc_e(ucfirst($c['network']))?> @<?=lc_e($c['username'])?></td>
 <td><?=number_format((int)$c['verified_views'],0,',','.')?>
 <?php if($c['observed_views']!==null):?><small>Views públicas: <?=number_format((int)$c['observed_views'],0,',','.')?> (Apify, <?=lc_e($c['snapshot_date'])?>; sem confirmação financeira)</small><?php endif;?></td>
<td><span class="tag tag-<?=lc_e($c['status'])?>"><?=lc_e(lc_status_label($c['status']))?></span></td><td>
<?php if($c['drive_file_id']):?><button type="button" class="btn btn-ghost btn-sm clip-preview-trigger" data-preview-url="/clipes/<?=(int)$c['id']?>/video" data-preview-name="<?=lc_e($c['title'])?>"><?=lc_icon('play')?> Assistir</button><?php else:?>Sem original<?php endif;?>
</td><td>
<?php if($c['status']==='approved'):?>
<span class="clip-edit-locked"><?=lc_icon('lock-keyhole')?> Bloqueado após aprovação</span>
<?php else:?>
<div class="clipper-edit-buttons">
<a class="btn btn-ghost btn-sm" href="/clipes/<?=(int)$c['id']?>/editar"><?=lc_icon('pencil')?> Editar</a>
<form method="post" action="/clipes/<?=(int)$c['id']?>/excluir" onsubmit="return confirm('Excluir definitivamente este envio e o original armazenado no Google Drive?');">
<?=lc_csrf()?><button type="submit" class="btn btn-sm btn-delete-clip"><?=lc_icon('trash-2')?> Excluir</button>
</form>
</div>
<?php endif;?>
</td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
<?php lc_clip_preview_modal(); },'library');return true;
    }
    if($method==='GET' && $path==='/financeiro'){
        $u=lc_login_required();$balance=lc_balance((int)$u['id']);
        $entries=lc_all('SELECT amount_cents,kind,description,created_at FROM ledger_entries WHERE user_id=? ORDER BY id DESC LIMIT 60',[$u['id']]);
        $withdrawals=lc_all('SELECT id,amount_cents,status,created_at,processed_at FROM withdrawals WHERE user_id=? ORDER BY id DESC LIMIT 30',[$u['id']]);
        $contact=lc_one('SELECT email,whatsapp,consent_whatsapp FROM users WHERE id=?',[$u['id']])?:[];
        $channels=lc_mfa_enabled((int)$u['id'])?[]:lc_otp_available_channels($contact);
        lc_page('Financeiro',function()use($u,$balance,$entries,$withdrawals,$channels){?>
<div class="container"><?php lc_heading('Suas recompensas','Financeiro','Veja seus créditos confirmados, saldo disponível e pedidos de saque.'); ?>
<div class="stats-grid"><div class="metric"><small>Ganhos creditados</small><strong><?=lc_private(lc_money($balance['earned']))?></strong></div><div class="metric"><small>Saldo disponível</small><strong><?=lc_private(lc_money($balance['available']))?></strong></div>
<div class="metric"><small>Reservado / já sacado</small><strong><?=lc_private(lc_money($balance['reserved']))?></strong></div><div class="metric"><small>Chave Pix</small><strong style="font-size:1rem">Cadastrada <?=lc_icon('lock-keyhole')?></strong></div></div>
<div class="panel"><h2>Solicitar saque</h2><p>O saldo só considera recompensas liberadas. A transferência Pix é conferida e realizada manualmente até a homologação do provedor de pagamentos.</p>
<form method="post" action="/financeiro/saques"><?=lc_csrf()?>
<div class="form-inline"><?php lc_field('Valor (R$)','amount','text','',true,'Ex.: 35,00'); ?>
<button class="btn" type="submit">Solicitar saque →</button></div>
<?php if(lc_mfa_enabled((int)$u['id'])):?>
<label class="field"><span>Código do Google Authenticator ou recuperação</span><input name="mfa_code" maxlength="60" autocomplete="one-time-code" required></label>
<?php else:?>
<label class="field"><span>Confirme sua senha atual</span><input type="password" name="current_password" autocomplete="current-password" required></label>
<?php if($channels):?>
<label class="field"><span>Receber código de segurança por</span><select name="verification_channel" required>
<?php foreach($channels as $ch):?><option value="<?=lc_e($ch)?>"><?=$ch==='email'?'E-mail cadastrado':'WhatsApp cadastrado'?></option><?php endforeach; ?>
</select></label>
<p class="hint">O saque só será solicitado depois da confirmação do código recebido.</p>
<?php else:?>
<p class="hint">Nenhum canal para códigos está configurado. Temporariamente é necessária sua senha atual. Recomendamos <a href="/seguranca/2fa">ativar o Google Authenticator</a>.</p>
<?php endif;?>
<?php endif;?></form></div>
<div class="panel"><h2>Extrato de créditos</h2><?php if(!$entries):lc_empty('Nenhum crédito liberado','A aprovação e as metas semanais determinam quando os créditos entram no saldo.');else:?>
<div class="table-wrap"><table><thead><tr><th>Descrição</th><th>Data</th><th>Valor</th></tr></thead><tbody><?php foreach($entries as $e):?><tr><td><?=lc_e($e['description'])?><small><?=lc_e($e['kind'])?></small></td><td><?=date('d/m/Y',strtotime($e['created_at']))?></td><td><?=lc_private(lc_money($e['amount_cents']))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div>
<div class="panel"><h2>Solicitações de saque</h2><?php if(!$withdrawals):lc_empty('Nenhuma solicitação','Quando tiver saldo disponível, você poderá solicitar um saque.');else:?>
<div class="table-wrap"><table><thead><tr><th>Pedido</th><th>Valor</th><th>Data</th><th>Status</th></tr></thead><tbody><?php foreach($withdrawals as $w):?><tr><td>#<?=(int)$w['id']?></td><td><?=lc_private(lc_money($w['amount_cents']))?></td><td><?=date('d/m/Y',strtotime($w['created_at']))?></td><td><span class="tag tag-<?=lc_e($w['status'])?>"><?=lc_e(lc_status_label($w['status']))?></span></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
<?php },'finance');return true;
    }
    if($method==='POST'&&$path==='/financeiro/saques'){
        $u=lc_login_required();
        $amount=lc_cents('amount',0);
        if($amount<100){lc_flash('O valor mínimo é R$ 1,00.','error');lc_redirect('/financeiro');}
        $contact=lc_one('SELECT email,whatsapp,consent_whatsapp FROM users WHERE id=?',[$u['id']])?:[];
        $channels=lc_mfa_enabled((int)$u['id'])?[]:lc_otp_available_channels($contact);
        try{
            if($channels){
                if(!lc_otp_require_password($u,(string)($_POST['current_password']??'')))
                    throw new InvalidArgumentException('Sua senha atual não foi confirmada.');
                lc_otp_begin($u,'withdrawal',['amount_cents'=>$amount],
                    (string)($_POST['verification_channel']??''));
                lc_flash('Código solicitado. O saque só será criado depois da confirmação.');
                lc_redirect('/seguranca/codigo');
            }
            if(!lc_mfa_stepup_or_password($u,lc_post('mfa_code',60),
                 (string)($_POST['current_password']??'')))
                throw new InvalidArgumentException('Confirmação de segurança inválida ou bloqueada.');
            $db=lc_db();$db->beginTransaction();
            try{lc_withdrawal_apply($u,$amount);$db->commit();}
            catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            lc_flash('Solicitação registrada. O pagamento será conferido pelo administrador.');
        }catch(InvalidArgumentException|RuntimeException $e){
            lc_flash($e->getMessage(),'error');
        }
        lc_redirect('/financeiro');
    }
    return false;
}
