<?php
declare(strict_types=1);

function lc_save_campaign(?int $id): void {
    lc_admin();
    $title=lc_post('title',180);$description=lc_post('description',15000);
    $category=lc_post('category',70)?:'Destaques';$cover=lc_post('cover_url',800);
    $status=lc_post('status',20);$start=lc_post('starts_at',10);$end=lc_post('ends_at',10);
    $allowed=['draft','active','paused','ended'];
    if(mb_strlen($title)<3||mb_strlen($description)<10||!in_array($status,$allowed,true)||($cover!==''&&!lc_valid_https($cover))||($start!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start))||($end!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end))){
        lc_flash('Confira título, descrição, capa HTTPS, status e datas.','error');lc_redirect('/admin/campanhas');
    }
    $fields=[$title,$description,$category,$cover?:null,$status,
        lc_int('min_videos_week',5,100),lc_cents('payment_per_video',1500),
        lc_int('milestone_views',1000000,2000000000),lc_cents('milestone_bonus',2000),
        lc_cents('monthly_leader_bonus',2000),$start?:null,$end?:null];
    if($id){
        $fields[]=$id;
        lc_query('UPDATE campaigns SET title=?,description=?,category=?,cover_url=?,status=?,min_videos_week=?,payment_per_video_cents=?,milestone_views=?,milestone_bonus_cents=?,monthly_leader_bonus_cents=?,starts_at=?,ends_at=? WHERE id=?',$fields);
        lc_flash('Campanha atualizada.');
    }else{
        $fields[] = lc_slug($title);$fields[] = lc_admin()['id'];
        lc_query('INSERT INTO campaigns(title,description,category,cover_url,status,min_videos_week,payment_per_video_cents,milestone_views,milestone_bonus_cents,monthly_leader_bonus_cents,starts_at,ends_at,slug,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',$fields);
        lc_flash('Campanha criada.');
    }
    lc_redirect('/admin/campanhas');
}
function lc_campaign_form(?array $c): void {
    $get=fn(string $k,mixed $fallback='')=>(string)($c[$k]??$fallback);
    $id=$c?(int)$c['id']:null;
?><div class="panel"><h2><?= $id?'Editar campanha #'.$id:'Criar nova campanha' ?></h2>
<form method="post" action="<?= $id?'/admin/campanhas/'.$id.'/editar':'/admin/campanhas'?>"><?=lc_csrf()?>
<div class="form-grid">
<?php lc_field('Nome da campanha','title','text',$get('title'),true,'Ex.: Cortes da semana'); ?>
<?php lc_field('Categoria','category','text',$get('category','Destaques'),true,'Ex.: Podcasts'); ?>
<label class="field full"><span>Briefing e regras</span><textarea name="description" maxlength="15000" required><?=lc_e($get('description'))?></textarea></label>
<?php lc_field('Capa (URL HTTPS)','cover_url','url',$get('cover_url'),false,'https://...'); ?>
<label class="field"><span>Status</span><select name="status"><?php foreach(['draft'=>'Rascunho','active'=>'Ativa','paused'=>'Pausada','ended'=>'Encerrada'] as $value=>$label):?><option value="<?=$value?>" <?=$get('status','draft')===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></label>
<?php lc_field('Mínimo de vídeos aprovados/semana','min_videos_week','number',$get('min_videos_week','5'),true,'5','min="0" max="100"'); ?>
<?php lc_field('Pagamento por vídeo (R$)','payment_per_video','text',number_format((int)$get('payment_per_video_cents','1500')/100,2,',','.'),true,'15,00'); ?>
<?php lc_field('Meta de views por vídeo','milestone_views','number',$get('milestone_views','1000000'),true,'1000000','min="0" max="2000000000"'); ?>
<?php lc_field('Bônus por meta de views (R$)','milestone_bonus','text',number_format((int)$get('milestone_bonus_cents','2000')/100,2,',','.'),true,'20,00'); ?>
<?php lc_field('Bônus líder do mês (R$)','monthly_leader_bonus','text',number_format((int)$get('monthly_leader_bonus_cents','2000')/100,2,',','.'),true,'20,00'); ?>
<?php lc_field('Início (opcional)','starts_at','date',$get('starts_at')); ?>
<?php lc_field('Fim (opcional)','ends_at','date',$get('ends_at')); ?>
</div><div class="form-actions"><button class="btn" type="submit"><?= $id?'Salvar alterações':'Publicar campanha' ?> →</button><a href="/admin/campanhas">Voltar</a></div>
</form></div><?php
}
function lc_admin_routes(string $method,string $path): bool {
    if(!str_starts_with($path,'/admin'))return false;
    $admin=lc_admin();
    if($method==='GET'&&$path==='/admin'){
        $pending=(int)(lc_one("SELECT COUNT(*) AS n FROM users WHERE role='clipador' AND status='pending'")['n']??0);
        $total=(int)(lc_one("SELECT COUNT(*) AS n FROM users WHERE role='clipador'")['n']??0);
        $campaigns=(int)(lc_one("SELECT COUNT(*) AS n FROM campaigns WHERE status='active'")['n']??0);
        $clips=(int)(lc_one("SELECT COUNT(*) AS n FROM clips WHERE status='pending'")['n']??0);
        lc_page('Administração',function()use($pending,$total,$campaigns,$clips){?>
<div class="container"><?php lc_heading('Central de operações','Painel administrativo','Gerencie pessoas, campanhas, conferências e pagamentos.'); ?>
<div class="stats-grid"><div class="metric"><small>Cadastros pendentes</small><strong><?=$pending?></strong></div><div class="metric"><small>Clipadores</small><strong><?=$total?></strong></div>
<div class="metric"><small>Campanhas ativas</small><strong><?=$campaigns?></strong></div><div class="metric"><small>Clipes aguardando</small><strong><?=$clips?></strong></div></div>
<div class="panel"><h2>Gestão do LeagueCut</h2><div class="pills">
<a class="pill" href="/admin/clipadores">👥 Aprovar clipadores →</a>
<a class="pill" href="/admin/campanhas">🎬 Campanhas →</a>
<a class="pill" href="/admin/clipes">📊 Conferir clipes e views →</a>
<a class="pill" href="/admin/financeiro">💸 Solicitações de saque →</a>
<a class="pill" href="/admin/bonus-mensal">🏆 Fechamento mensal →</a>
<a class="pill" href="/admin/configuracoes">🎨 Personalização →</a>
</div></div></div>
<?php },'admin');return true;
    }
    if($method==='GET'&&$path==='/admin/clipadores'){
        $users=lc_all("SELECT id,full_name,email,instagram,whatsapp,portfolio_url,status,consent_whatsapp,created_at FROM users WHERE role='clipador' ORDER BY FIELD(status,'pending','approved','rejected','suspended'),created_at DESC LIMIT 200");
        lc_page('Clipadores',function()use($users){?>
<div class="container"><?php lc_heading('Aprovações','Clipadores','Aprovação manual com registro e notificações em fila.'); ?><div class="panel"><h2>Cadastros</h2>
<?php if(!$users):lc_empty('Nenhum cadastro','Os próximos clipadores vão aparecer aqui.');else:?>
<div class="table-wrap"><table><thead><tr><th>Clipador</th><th>Instagram</th><th>WhatsApp</th><th>Status</th><th>Ação</th></tr></thead><tbody>
<?php foreach($users as $u):?><tr>
<td><?=lc_e($u['full_name'])?><small><?=lc_e($u['email'])?></small><?php if($u['portfolio_url']):?><a href="<?=lc_e($u['portfolio_url'])?>" target="_blank" rel="noopener noreferrer">Portfólio ↗</a><?php endif;?></td>
<td>@<?=lc_e($u['instagram'])?></td><td><?=lc_e($u['whatsapp'])?></td><td><span class="tag tag-<?=lc_e($u['status'])?>"><?=lc_e($u['status'])?></span></td>
<td><?php if($u['status']==='pending'):?><form method="post" action="/admin/clipadores/<?=(int)$u['id']?>/aprovar"><?=lc_csrf()?><button class="btn btn-sm" type="submit">Aprovar ✓</button></form><?php else:?>—<?php endif;?></td>
</tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
<?php },'admin');return true;
    }
    if($method==='POST'&&preg_match('~^/admin/clipadores/([0-9]+)/aprovar$~',$path,$m)){
        lc_db()->beginTransaction();
        try{
            $u=lc_one("SELECT * FROM users WHERE id=? AND role='clipador' FOR UPDATE",[(int)$m[1]]);
            if(!$u || $u['status']!=='pending'){lc_db()->rollBack();lc_flash('Cadastro já processado.','error');lc_redirect('/admin/clipadores');}
            lc_query("UPDATE users SET status='approved',approved_at=NOW(),approved_by=? WHERE id=?",[$admin['id'],$u['id']]);
            lc_outbox((int)$u['id'],'email','creator_approved',['name'=>$u['full_name'],'email'=>$u['email']]);
            if((int)$u['consent_whatsapp']===1)lc_outbox((int)$u['id'],'whatsapp','creator_approved',['name'=>$u['full_name'],'phone'=>$u['whatsapp']]);
            lc_db()->commit();
        }catch(Throwable $e){if(lc_db()->inTransaction())lc_db()->rollBack();throw $e;}
        lc_flash('Clipador aprovado. Notificações enfileiradas; o envio depende da configuração dos canais.');lc_redirect('/admin/clipadores');
    }
    if($method==='GET'&&$path==='/admin/campanhas'){
        $campaigns=lc_all('SELECT * FROM campaigns ORDER BY id DESC LIMIT 120');
        lc_page('Campanhas',function()use($campaigns){?><div class="container"><?php lc_heading('Operação de campanhas','Campanhas','Crie e ajuste regras de entrega, valores e bônus por campanha.');lc_campaign_form(null); ?>
<div class="panel"><h2>Campanhas criadas</h2>
<?php if(!$campaigns):lc_empty('Sem campanhas ainda','Crie a primeira campanha no formulário acima.');else:?>
<div class="table-wrap"><table><thead><tr><th>Campanha</th><th>Status</th><th>Vídeos/semana</th><th>Valor</th><th>Editar</th></tr></thead><tbody><?php foreach($campaigns as $c):?>
<tr><td><a href="/campanhas/<?=lc_e($c['slug'])?>"><?=lc_e($c['title'])?> ↗</a></td><td><span class="tag tag-<?=lc_e($c['status'])?>"><?=lc_e($c['status'])?></span></td>
<td><?=(int)$c['min_videos_week']?></td><td><?=lc_money($c['payment_per_video_cents'])?></td>
<td><a href="/admin/campanhas/<?=(int)$c['id']?>/editar">Editar →</a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
<?php },'admin');return true;
    }
    if($method==='POST'&&$path==='/admin/campanhas'){lc_save_campaign(null);}
    if(preg_match('~^/admin/campanhas/([0-9]+)/editar$~',$path,$m)){
        $c=lc_one('SELECT * FROM campaigns WHERE id=?',[(int)$m[1]]);
        if(!$c){http_response_code(404);exit('Campanha não encontrada');}
        if($method==='POST')lc_save_campaign((int)$c['id']);
        if($method==='GET'){lc_page('Editar campanha',function()use($c){?><div class="container container-md"><?php lc_heading('Administração','Editar campanha',$c['title']);lc_campaign_form($c);?></div><?php },'admin');return true;}
    }
    if($method==='GET'&&$path==='/admin/clipes'){
        $clips=lc_all('SELECT c.*,u.full_name,p.title AS campaign_title,s.network FROM clips c JOIN users u ON u.id=c.user_id JOIN campaigns p ON p.id=c.campaign_id JOIN social_accounts s ON s.id=c.social_account_id ORDER BY FIELD(c.status,"pending","approved","rejected"),c.id DESC LIMIT 150');
        lc_page('Clipes e visualizações',function()use($clips){?>
<div class="container"><?php lc_heading('Curadoria','Conferir clipes','Confira a publicação e informe as visualizações verificadas antes de aprovar.'); ?>
<div class="alert">Views são informadas manualmente nesta versão. Não consideramos perfis informados como contas OAuth conectadas. Valores aprovados anteriormente não podem ser reduzidos por este formulário.</div>
<div class="panel"><h2>Publicações para revisar</h2>
<?php if(!$clips):lc_empty('Tudo em dia','Nenhum vídeo recebido até agora.');else:?>
<div class="table-wrap"><table><thead><tr><th>Vídeo</th><th>Criador / campanha</th><th>Atual</th><th>Views verificadas</th><th>Revisão</th></tr></thead><tbody>
<?php foreach($clips as $c):?><tr>
<td><a href="<?=lc_e($c['published_url'])?>" target="_blank" rel="noopener noreferrer"><?=lc_e($c['title'])?> ↗</a><small><?=lc_e(ucfirst($c['network']))?> <?=date('d/m/Y',strtotime($c['submitted_at']))?> · <?= $c['drive_file_id']?'Drive ✓':'sem arquivo'?></small></td>
<td><?=lc_e($c['full_name'])?><small><?=lc_e($c['campaign_title'])?></small></td>
<td><span class="tag tag-<?=lc_e($c['status'])?>"><?=lc_e($c['status'])?></span></td>
<td colspan="2"><form class="mini-form" method="post" action="/admin/clipes/<?=(int)$c['id']?>/revisar"><?=lc_csrf()?>
<input name="verified_views" type="number" min="<?=(int)$c['verified_views']?>" max="2000000000" value="<?=(int)$c['verified_views']?>" required aria-label="Views verificadas">
<select name="decision" aria-label="Decisão"><?php if($c['status']!=='approved'):?><option value="approved">Aprovar</option><option value="rejected">Rejeitar</option><?php else:?><option value="approved">Atualizar views</option><?php endif;?></select>
<button class="btn btn-sm" type="submit">Confirmar</button></form></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
<?php },'admin');return true;
    }
    if($method==='POST'&&preg_match('~^/admin/clipes/([0-9]+)/revisar$~',$path,$m)){
        $decision=lc_post('decision',20);$views=lc_int('verified_views',0,2000000000);
        if(!in_array($decision,['approved','rejected'],true)){lc_flash('Decisão inválida.','error');lc_redirect('/admin/clipes');}
        lc_db()->beginTransaction();
        try{
            $clip=lc_one('SELECT * FROM clips WHERE id=? FOR UPDATE',[(int)$m[1]]);
            if(!$clip)throw new RuntimeException('Clipe não encontrado.');
            if($clip['status']==='approved'&&$decision!=='approved')throw new RuntimeException('Clipe já creditado. Ajustes exigem estorno auditável.');
            if($views<(int)$clip['verified_views'])throw new RuntimeException('Visualizações não podem ser reduzidas sem ajuste auditável.');
            lc_query('UPDATE clips SET status=?,verified_views=?,reviewed_at=NOW(),reviewed_by=? WHERE id=?',[$decision,$views,$admin['id'],$clip['id']]);
            if($decision==='approved'){
                lc_settle_week((int)$clip['campaign_id'],(int)$clip['user_id'],$clip['submitted_at']);
                lc_credit_views((int)$clip['id']);
            }
            lc_db()->commit();
        }catch(Throwable $e){if(lc_db()->inTransaction())lc_db()->rollBack();lc_flash($e->getMessage(),'error');lc_redirect('/admin/clipes');}
        lc_flash('Clipe revisado. Créditos gerados conforme as regras e a meta semanal.');lc_redirect('/admin/clipes');
    }
    if($method==='GET'&&$path==='/admin/financeiro'){
        $withdrawals=lc_all('SELECT w.*,u.full_name,u.email,u.pix_key_enc FROM withdrawals w JOIN users u ON u.id=w.user_id ORDER BY FIELD(w.status,"requested","processing","paid","rejected"),w.id DESC LIMIT 120');
        lc_page('Saques',function()use($withdrawals){?><div class="container"><?php lc_heading('Controle financeiro','Solicitações de saque','Registre pagamentos externos somente depois de confirmar o Pix.'); ?>
<div class="alert">Nenhum Pix é enviado automaticamente. Para marcar pago, informe o identificador real da transferência. Não compartilhe chaves Pix em logs públicos.</div><div class="panel">
<?php if(!$withdrawals):lc_empty('Sem saques pendentes','Os pedidos dos clipadores vão aparecer aqui.');else:?>
<div class="table-wrap"><table><thead><tr><th>Clipador</th><th>Valor</th><th>Chave Pix</th><th>Status</th><th>Processar</th></tr></thead><tbody>
<?php foreach($withdrawals as $w):?><tr><td><?=lc_e($w['full_name'])?><small><?=lc_e($w['email'])?></small></td><td><?=lc_money($w['amount_cents'])?></td>
<td><?=lc_e(lc_decrypt($w['pix_key_enc']))?></td><td><span class="tag tag-<?=lc_e($w['status'])?>"><?=lc_e($w['status'])?></span></td>
<td><?php if(in_array($w['status'],['requested','processing'],true)):?><form method="post" action="/admin/saques/<?=(int)$w['id']?>/decidir" class="mini-form"><?=lc_csrf()?>
<input type="text" name="reference" placeholder="ID real do Pix" maxlength="180">
<select name="decision"><option value="paid">Confirmar pago</option><option value="rejected">Rejeitar</option></select>
<button class="btn btn-sm" type="submit">Salvar</button></form><?php else:?><?=lc_e($w['pix_provider_reference']?:'—')?><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
<?php },'admin');return true;
    }
    if($method==='POST'&&preg_match('~^/admin/saques/([0-9]+)/decidir$~',$path,$m)){
        $decision=lc_post('decision',20);$reference=lc_post('reference',180);
        if(!in_array($decision,['paid','rejected'],true)||($decision==='paid'&&mb_strlen($reference)<5)){
            lc_flash('Informe uma decisão e o identificador real do Pix para confirmar pagamento.','error');lc_redirect('/admin/financeiro');
        }
        lc_query("UPDATE withdrawals SET status=?,pix_provider_reference=?,processed_at=NOW()
                  WHERE id=? AND status IN ('requested','processing')",[$decision,$decision==='paid'?$reference:null,(int)$m[1]]);
        lc_flash('Solicitação atualizada.');lc_redirect('/admin/financeiro');
    }
    if($method==='GET'&&$path==='/admin/bonus-mensal'){
        $campaigns=lc_all('SELECT id,title,monthly_leader_bonus_cents FROM campaigns WHERE monthly_leader_bonus_cents>0 ORDER BY id DESC');
        lc_page('Bônus mensal',function()use($campaigns){?><div class="container container-md"><?php lc_heading('Fechamento','Bônus mensal','Premie a maior soma de views verificadas no mês para cada campanha.'); ?>
<div class="panel"><h2>Fechar mês</h2><p>Este processo só aceita meses finalizados. Em empate, vence o menor ID de usuário (critério provisório). A operação é idempotente.</p>
<form method="post" action="/admin/bonus-mensal"><?=lc_csrf()?><div class="form-inline">
<label class="field"><span>Campanha</span><select name="campaign_id"><?php foreach($campaigns as $c):?><option value="<?=(int)$c['id']?>"><?=lc_e($c['title'])?> · <?=lc_money($c['monthly_leader_bonus_cents'])?></option><?php endforeach;?></select></label>
<label class="field"><span>Mês encerrado</span><input type="month" name="month" max="<?=date('Y-m',strtotime('first day of previous month'))?>" required></label>
<button class="btn" type="submit">Apurar vencedor →</button></div></form></div></div>
<?php },'admin');return true;
    }
    if($method==='POST'&&$path==='/admin/bonus-mensal'){
        lc_db()->beginTransaction();
        try{$winner=lc_credit_monthly_leader(lc_int('campaign_id',0,2147483647),lc_post('month',7));lc_db()->commit();}
        catch(Throwable $e){if(lc_db()->inTransaction())lc_db()->rollBack();lc_flash($e->getMessage(),'error');lc_redirect('/admin/bonus-mensal');}
        lc_flash($winner?'Apuração realizada. Repetições não geram pagamento duplo.':'Sem vídeos aprovados com views nesse período.');lc_redirect('/admin/bonus-mensal');
    }
    if($method==='GET'&&$path==='/admin/configuracoes'){
        $s=lc_settings();
        lc_page('Personalização',function()use($s){?><div class="container container-md"><?php lc_heading('Sua marca','Personalização','Ajuste nome, cor primária e logo por URL HTTPS.'); ?>
<div class="panel"><form method="post" action="/admin/configuracoes"><?=lc_csrf()?><div class="form-grid">
<?php lc_field('Nome da plataforma','brand_name','text',(string)($s['brand_name']??'LeagueCut'),true); ?>
<?php lc_field('Cor principal','primary_color','color',(string)($s['primary_color']??'#e50914'),true); ?>
<div class="full"><?php lc_field('URL HTTPS da logo','logo_url','url',(string)($s['logo_url']??''),false,'https://.../logo.svg'); ?></div>
</div><div class="form-actions"><button class="btn" type="submit">Salvar identidade visual →</button></div></form></div></div>
<?php },'admin');return true;
    }
    if($method==='POST'&&$path==='/admin/configuracoes'){
        $name=lc_post('brand_name',80);$color=lc_post('primary_color',7);$logo=lc_post('logo_url',800);
        if(mb_strlen($name)<2||!preg_match('/^#[a-fA-F0-9]{6}$/',$color)||($logo!==''&&!lc_valid_https($logo))){lc_flash('Verifique os dados de personalização.','error');lc_redirect('/admin/configuracoes');}
        foreach(['brand_name'=>$name,'primary_color'=>$color,'logo_url'=>$logo] as $key=>$value){
            lc_query('INSERT INTO platform_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,$value]);
        }
        lc_flash('Marca atualizada em toda a plataforma.');lc_redirect('/admin/configuracoes');
    }
    return false;
}
