<?php
declare(strict_types=1);

function lc_clipper_routes(string $method,string $path): bool {
    if($method==='GET' && $path==='/dashboard'){
        $u=lc_login_required();
        if($u['role']==='admin')lc_redirect('/admin');
        $balance=lc_balance((int)$u['id']);
        $stats=lc_one("SELECT COUNT(*) AS total,SUM(status='approved') AS approved,COALESCE(SUM(verified_views),0) AS views FROM clips WHERE user_id=?",[$u['id']]);
        $campaigns=lc_all("SELECT * FROM campaigns WHERE status='active' ORDER BY created_at DESC LIMIT 8");
        lc_page('Minha área',function()use($u,$balance,$stats,$campaigns){?>
<div class="container"><div class="profile-banner"><span class="big-avatar"><?=lc_e(mb_strtoupper(mb_substr($u['full_name'],0,1)))?></span><div><span class="eyebrow">Painel do clipador</span><h1>Olá, <?=lc_e(explode(' ',$u['full_name'])[0])?>!</h1><p>Explore novas campanhas e acompanhe seus resultados.</p></div></div>
<div class="stats-grid">
<div class="metric"><small>Saldo disponível</small><strong><?=lc_money($balance['available'])?></strong></div>
<div class="metric"><small>Clipes enviados</small><strong><?=(int)$stats['total']?></strong></div>
<div class="metric"><small>Clipes aprovados</small><strong><?=(int)$stats['approved']?></strong></div>
<div class="metric"><small>Views verificadas</small><strong><?=number_format((int)$stats['views'],0,',','.')?></strong></div></div>
<div class="section-head"><h2>Campanhas para você</h2><a class="section-link" href="/explorar">Explorar catálogo <?=lc_icon("arrow-right")?></a></div>
<?php if($campaigns):?><div class="catalog"><?php foreach($campaigns as $c)lc_tile($c);?></div><?php else:lc_empty('Aguarde novas campanhas','A equipe está preparando as próximas oportunidades.');endif;?></div>
<?php },'dashboard');return true;
    }
    if($method==='GET' && $path==='/redes'){
        $u=lc_login_required();$accounts=lc_all('SELECT * FROM social_accounts WHERE user_id=? ORDER BY id DESC',[$u['id']]);
        lc_page('Minhas redes',function()use($accounts){?>
<div class="container container-md"><?php lc_heading('Perfis de publicação','Minhas redes','Adicione seus perfis de Instagram e TikTok. Você pode cadastrar quantos quiser.'); ?>
<div class="alert">Por enquanto, os perfis são declarados manualmente. A propriedade da conta e as métricas ainda não são verificadas por OAuth.</div>
<div class="panel"><h2>Adicionar perfil</h2><form method="post" action="/redes"><?=lc_csrf()?><div class="form-inline">
<label class="field"><span>Rede</span><select name="network"><option value="instagram">Instagram</option><option value="tiktok">TikTok</option></select></label>
<?php lc_field('Usuário (sem @)','username','text','',true,'seuperfil'); ?>
<button class="btn" type="submit">Adicionar</button></div></form></div>
<div class="panel"><h2>Perfis cadastrados</h2>
<?php if(!$accounts):lc_empty('Nenhuma rede cadastrada','Cadastre uma conta para começar a enviar publicações.');
else:?><div class="table-wrap"><table><thead><tr><th>Rede</th><th>Perfil</th><th>Integração</th></tr></thead><tbody><?php foreach($accounts as $a):?><tr>
<td><?=lc_e(ucfirst($a['network']))?></td><td><a href="<?=lc_e($a['profile_url'])?>" target="_blank" rel="noopener noreferrer">@<?=lc_e($a['username'])?> ↗</a></td><td><span class="tag"><?= $a['oauth_verified']?'Verificada':'Informada manualmente' ?></span></td>
</tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
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
        $title=lc_post('title',180);$url=lc_post('published_url',800);
        $campaign=lc_one("SELECT * FROM campaigns WHERE id=? AND status='active'",[$campaignId]);
        $account=lc_one('SELECT * FROM social_accounts WHERE id=? AND user_id=?',[$accountId,$u['id']]);
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        $validHost=$account && ($account['network']==='instagram'?in_array($host,['instagram.com','www.instagram.com'],true):in_array($host,['tiktok.com','www.tiktok.com','vm.tiktok.com','vt.tiktok.com'],true));
        if(!$campaign||!$account||mb_strlen($title)<4||!lc_valid_https($url)||!$validHost){
            lc_flash('Verifique a campanha, o perfil e o link HTTPS da publicação na rede selecionada.','error');lc_redirect('/');
        }
        if(lc_one('SELECT id FROM clips WHERE user_id=? AND published_url=?',[$u['id'],$url])){
            lc_flash('Esse link já foi enviado.','error');lc_redirect('/biblioteca');
        }
        $fileId=null;$filename=null;
        if(isset($_FILES['video']) && ($_FILES['video']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            try {$uploaded=lc_drive_upload($_FILES['video'],(int)$u['id']);$fileId=$uploaded['id'];$filename=$uploaded['filename'];}
            catch(Throwable $e){lc_flash($e->getMessage(),'error');lc_redirect('/campanhas/'.$campaign['slug']);}
        }
        lc_query("INSERT INTO clips(user_id,campaign_id,social_account_id,title,published_url,drive_file_id,original_filename)
                  VALUES(?,?,?,?,?,?,?)",[$u['id'],$campaignId,$accountId,$title,$url,$fileId,$filename]);
        lc_flash('Publicação enviada para conferência. Acompanhe sua biblioteca.');lc_redirect('/biblioteca');
    }
    if($method==='GET' && $path==='/biblioteca'){
        $u=lc_login_required();
        $clips=lc_all('SELECT c.*,p.title AS campaign_title,s.network,s.username FROM clips c JOIN campaigns p ON p.id=c.campaign_id JOIN social_accounts s ON s.id=c.social_account_id WHERE c.user_id=? ORDER BY c.submitted_at DESC LIMIT 150',[$u['id']]);
        lc_page('Minha biblioteca',function()use($clips){?>
<div class="container"><?php lc_heading('Seu conteúdo','Minha biblioteca','Suas publicações, vídeos armazenados e resultados verificados.'); ?>
<div class="panel"><div class="panel-head"><h2>Clipes enviados</h2><a href="/explorar" class="btn btn-sm">Nova publicação <?=lc_icon('plus')?></a></div>
<?php if(!$clips):lc_empty('Sua biblioteca está vazia','Escolha uma campanha e envie a primeira publicação.');
else:?><div class="table-wrap"><table><thead><tr><th>Vídeo</th><th>Campanha</th><th>Rede</th><th>Views conferidas</th><th>Status</th><th>Drive</th></tr></thead><tbody>
<?php foreach($clips as $c):?><tr><td><a href="<?=lc_e($c['published_url'])?>" target="_blank" rel="noopener noreferrer"><?=lc_e($c['title'])?> ↗</a><small><?=date('d/m/Y',strtotime($c['submitted_at']))?></small></td>
<td><?=lc_e($c['campaign_title'])?></td><td><?=lc_e(ucfirst($c['network']))?> @<?=lc_e($c['username'])?></td><td><?=number_format((int)$c['verified_views'],0,',','.')?></td>
<td><span class="tag tag-<?=lc_e($c['status'])?>"><?=lc_e($c['status'])?></span></td><td><?= $c['drive_file_id']?'Armazenado':'Não enviado' ?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
<?php },'library');return true;
    }
    if($method==='GET' && $path==='/financeiro'){
        $u=lc_login_required();$balance=lc_balance((int)$u['id']);
        $entries=lc_all('SELECT amount_cents,kind,description,created_at FROM ledger_entries WHERE user_id=? ORDER BY id DESC LIMIT 60',[$u['id']]);
        $withdrawals=lc_all('SELECT id,amount_cents,status,created_at,processed_at FROM withdrawals WHERE user_id=? ORDER BY id DESC LIMIT 30',[$u['id']]);
        lc_page('Financeiro',function()use($u,$balance,$entries,$withdrawals){?>
<div class="container"><?php lc_heading('Suas recompensas','Financeiro','Veja seus créditos confirmados, saldo disponível e pedidos de saque.'); ?>
<div class="stats-grid"><div class="metric"><small>Ganhos creditados</small><strong><?=lc_money($balance['earned'])?></strong></div><div class="metric"><small>Saldo disponível</small><strong><?=lc_money($balance['available'])?></strong></div>
<div class="metric"><small>Reservado / já sacado</small><strong><?=lc_money($balance['reserved'])?></strong></div><div class="metric"><small>Chave Pix</small><strong style="font-size:1rem">Cadastrada <?=lc_icon('lock-keyhole')?></strong></div></div>
<div class="panel"><h2>Solicitar saque</h2><p>O saldo só considera recompensas liberadas. A transferência Pix é conferida e realizada manualmente até a homologação do provedor de pagamentos.</p>
<form method="post" action="/financeiro/saques"><?=lc_csrf()?><div class="form-inline"><?php lc_field('Valor (R$)','amount','text','',true,'Ex.: 35,00'); ?><button class="btn" type="submit">Solicitar saque →</button></div></form></div>
<div class="panel"><h2>Extrato de créditos</h2><?php if(!$entries):lc_empty('Nenhum crédito liberado','A aprovação e as metas semanais determinam quando os créditos entram no saldo.');else:?>
<div class="table-wrap"><table><thead><tr><th>Descrição</th><th>Data</th><th>Valor</th></tr></thead><tbody><?php foreach($entries as $e):?><tr><td><?=lc_e($e['description'])?><small><?=lc_e($e['kind'])?></small></td><td><?=date('d/m/Y',strtotime($e['created_at']))?></td><td><?=lc_money($e['amount_cents'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div>
<div class="panel"><h2>Solicitações de saque</h2><?php if(!$withdrawals):lc_empty('Nenhuma solicitação','Quando tiver saldo disponível, você poderá solicitar um saque.');else:?>
<div class="table-wrap"><table><thead><tr><th>Pedido</th><th>Valor</th><th>Data</th><th>Status</th></tr></thead><tbody><?php foreach($withdrawals as $w):?><tr><td>#<?=(int)$w['id']?></td><td><?=lc_money($w['amount_cents'])?></td><td><?=date('d/m/Y',strtotime($w['created_at']))?></td><td><span class="tag tag-<?=lc_e($w['status'])?>"><?=lc_e($w['status'])?></span></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
<?php },'finance');return true;
    }
    if($method==='POST'&&$path==='/financeiro/saques'){
        $u=lc_login_required();
        $amount=lc_cents('amount',0);
        if($amount<100){lc_flash('O valor mínimo é R$ 1,00.','error');lc_redirect('/financeiro');}
        lc_db()->beginTransaction();
        try {
            lc_one('SELECT id FROM users WHERE id=? FOR UPDATE',[$u['id']]);
            $balance=lc_balance((int)$u['id']);
            if($amount>$balance['available']){lc_db()->rollBack();lc_flash('Saldo insuficiente.','error');lc_redirect('/financeiro');}
            lc_query('INSERT INTO withdrawals(user_id,amount_cents) VALUES(?,?)',[$u['id'],$amount]);
            lc_db()->commit();
        }catch(Throwable $e){if(lc_db()->inTransaction())lc_db()->rollBack();throw $e;}
        lc_flash('Solicitação registrada. O pagamento será conferido pelo administrador.');lc_redirect('/financeiro');
    }
    return false;
}
