<?php
declare(strict_types=1);

function lc_save_campaign(?int $id): void {
    $admin=lc_admin();
    $title=lc_post('title',180);$description=lc_post('description',15000);
    $category=lc_post('category',70)?:'Destaques';
    $status=lc_post('status',20);$start=lc_post('starts_at',10);$end=lc_post('ends_at',10);
    $allowed=['draft','active','paused','ended'];
    $returnTo=$id?'/admin/campanhas/'.$id.'/editar':'/admin/campanhas';
    if(mb_strlen($title)<3||mb_strlen($description)<10||!in_array($status,$allowed,true)||
       ($start!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start))||
       ($end!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end))){
        lc_flash('Confira título, descrição, status e datas.','error');lc_redirect($returnTo);
    }
    $previous=$id?lc_one('SELECT cover_url FROM campaigns WHERE id=?',[$id]):null;
    if($id&&!$previous){http_response_code(404);exit('Campanha não encontrada.');}
    $oldCover=(string)($previous['cover_url']??'');
    $cover=$oldCover;$uploaded='';
    $file=$_FILES['cover_upload']??null;
    if($file!==null&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
        try{$uploaded=lc_campaign_cover_store($file);$cover=$uploaded;}
        catch(Throwable $e){
            lc_flash($e instanceof InvalidArgumentException?$e->getMessage():'Falha ao enviar a capa. Tente novamente.','error');
            lc_redirect($returnTo);
        }
    }
    $fields=[$title,$description,$category,$cover?:null,$status,
        lc_int('min_videos_week',5,100),lc_cents('payment_per_video',1500),
        lc_int('milestone_views',1000000,2000000000),lc_cents('milestone_bonus',2000),
        lc_cents('monthly_leader_bonus',2000),$start?:null,$end?:null];
    try {
        lc_db()->beginTransaction();
        if($id){
            $fields[]=$id;
            lc_query('UPDATE campaigns SET title=?,description=?,category=?,cover_url=?,status=?,min_videos_week=?,payment_per_video_cents=?,milestone_views=?,milestone_bonus_cents=?,monthly_leader_bonus_cents=?,starts_at=?,ends_at=? WHERE id=?',$fields);
        }else{
            $fields[]=lc_slug($title);$fields[]=(int)$admin['id'];
            lc_query('INSERT INTO campaigns(title,description,category,cover_url,status,min_videos_week,payment_per_video_cents,milestone_views,milestone_bonus_cents,monthly_leader_bonus_cents,starts_at,ends_at,slug,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',$fields);
            $id=(int)lc_db()->lastInsertId();
        }
        lc_realtime_emit('admin',null,'campaign.updated','campaign',$id);
        lc_db()->commit();
    }catch(Throwable $e){
        if(lc_db()->inTransaction())lc_db()->rollBack();
        if($uploaded!=='')lc_campaign_cover_discard($uploaded);
        throw $e;
    }
    if($uploaded!==''&&$oldCover!==''&&$oldCover!==$uploaded){
        // Only remove an old private file if no other campaign references it.
        if(!lc_one('SELECT id FROM campaigns WHERE cover_url=? LIMIT 1',[$oldCover]))
            lc_campaign_cover_discard($oldCover);
    }
    lc_flash($previous?'Campanha atualizada.':'Campanha criada.');
    lc_redirect('/admin/campanhas');
}
function lc_campaign_form(?array $c): void {
    $get=fn(string $k,mixed $fallback='')=>(string)($c[$k]??$fallback);
    $id=$c?(int)$c['id']:null;
?><div class="panel"><h2><?= $id?'Editar campanha #'.$id:'Criar nova campanha' ?></h2>
<form method="post" enctype="multipart/form-data" action="<?= $id?'/admin/campanhas/'.$id.'/editar':'/admin/campanhas'?>"><?=lc_csrf()?>
<div class="form-grid">
<?php lc_field('Nome da campanha','title','text',$get('title'),true,'Ex.: Cortes da semana'); ?>
<?php lc_field('Categoria','category','text',$get('category','Destaques'),true,'Ex.: Podcasts'); ?>
<label class="field full"><span>Briefing e regras</span><textarea name="description" maxlength="15000" required><?=lc_e($get('description'))?></textarea></label>
<label class="field campaign-cover-field">
 <span>Imagem de capa</span>
 <input type="file" name="cover_upload" id="campaign-cover-upload" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="campaign-cover-help">
 <small id="campaign-cover-help">Envie JPG, PNG ou WebP (até 6 MB). Recomendado: 16:9, como 1280 × 720 px.</small>
 <?php $currentCover=$get('cover_url'); ?>
 <img class="campaign-cover-preview" id="campaign-cover-preview" src="<?=lc_campaign_cover_valid($currentCover)?lc_e($currentCover):''?>" alt="Prévia da capa da campanha" <?=lc_campaign_cover_valid($currentCover)?'':'hidden'?>>
 <?php if($id&&lc_campaign_cover_valid($currentCover)):?><small>Escolha outra imagem para substituir a capa atual. Sem novo arquivo, a capa será mantida.</small><?php endif;?>
</label>
<label class="field"><span>Status</span><select name="status"><?php foreach(['draft'=>'Rascunho','active'=>'Ativa','paused'=>'Pausada','ended'=>'Encerrada'] as $value=>$label):?><option value="<?=$value?>" <?=$get('status','draft')===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></label>
<?php lc_field('Mínimo de vídeos aprovados/semana','min_videos_week','number',$get('min_videos_week','5'),true,'5','min="0" max="100"'); ?>
<?php lc_field('Pagamento por vídeo (R$)','payment_per_video','text',number_format((int)$get('payment_per_video_cents','1500')/100,2,',','.'),true,'15,00'); ?>
<?php lc_field('Meta de views por vídeo','milestone_views','number',$get('milestone_views','1000000'),true,'1000000','min="0" max="2000000000"'); ?>
<?php lc_field('Bônus por meta de views (R$)','milestone_bonus','text',number_format((int)$get('milestone_bonus_cents','2000')/100,2,',','.'),true,'20,00'); ?>
<?php lc_field('Bônus líder do mês (R$)','monthly_leader_bonus','text',number_format((int)$get('monthly_leader_bonus_cents','2000')/100,2,',','.'),true,'20,00'); ?>
<?php lc_field('Início (opcional)','starts_at','date',$get('starts_at')); ?>
<?php lc_field('Fim (opcional)','ends_at','date',$get('ends_at')); ?>
</div><div class="form-actions"><button class="btn" type="submit"><?= $id?'Salvar alterações':'Publicar campanha' ?> →</button><a href="/admin/campanhas">Voltar</a></div>
</form></div>
<script src="/assets/campaign-cover-upload.js?v=1" defer></script><?php
}
function lc_admin_routes(string $method,string $path): bool {
    if(!str_starts_with($path,'/admin'))return false;
    $admin=lc_admin();
    if($method==='GET'&&$path==='/admin'){
        $pending=(int)(lc_one("SELECT COUNT(*) AS n FROM users WHERE role='clipador' AND status='pending'")['n']??0);
        $total=(int)(lc_one("SELECT COUNT(*) AS n FROM users WHERE role='clipador'")['n']??0);
        $campaigns=(int)(lc_one("SELECT COUNT(*) AS n FROM campaigns WHERE status='active'")['n']??0);
        $clips=(int)(lc_one("SELECT COUNT(*) AS n FROM clips WHERE status='pending'")['n']??0);
        $withdrawals=(int)(lc_one("SELECT COUNT(*) AS n FROM withdrawals WHERE status IN ('requested','processing')")['n']??0);
        $recent=lc_all("SELECT c.title,c.status,c.submitted_at,u.full_name,p.title AS campaign_title
                        FROM clips c JOIN users u ON u.id=c.user_id JOIN campaigns p ON p.id=c.campaign_id
                        ORDER BY c.id DESC LIMIT 5");
        // Views publicas observadas: a ultima medicao por clipe aprovado, nao a soma dos dias.
        $viewsAll=lc_one("SELECT COALESCE(SUM(COALESCE((SELECT d.views FROM clip_daily_views d WHERE d.clip_id=c.id ORDER BY d.snapshot_date DESC LIMIT 1),0)),0) AS views,
          COUNT(*) AS clips,COUNT(DISTINCT c.user_id) AS players FROM clips c WHERE c.status='approved'")??[];
        $lastObservation=lc_one("SELECT MAX(observed_at) AS date FROM clip_daily_views")['date']??null;
        $rankUsers=lc_all("SELECT u.full_name,u.nickname,COUNT(c.id) AS clips,COALESCE(SUM(COALESCE((SELECT d.views FROM clip_daily_views d WHERE d.clip_id=c.id ORDER BY d.snapshot_date DESC LIMIT 1),0)),0) AS views
          FROM clips c JOIN users u ON u.id=c.user_id WHERE c.status='approved' AND u.role='clipador' GROUP BY u.id,u.full_name,u.nickname ORDER BY views DESC,clips DESC,u.id ASC LIMIT 8");
        $rankCampaigns=lc_all("SELECT p.slug,p.title,COUNT(c.id) AS clips,COALESCE(SUM(COALESCE((SELECT d.views FROM clip_daily_views d WHERE d.clip_id=c.id ORDER BY d.snapshot_date DESC LIMIT 1),0)),0) AS views
          FROM campaigns p LEFT JOIN clips c ON c.campaign_id=p.id AND c.status='approved' GROUP BY p.id,p.slug,p.title ORDER BY views DESC,clips DESC,p.id ASC LIMIT 8");
        $demo=lc_demo_enabled();
        if($demo){
            $fakePlayers=lc_demo_ranking(30);
            $rankUsers=lc_demo_merge_ranking($rankUsers,8);
            $fakeCampaigns=lc_demo_campaigns();
            foreach($fakeCampaigns as $i=>$fake){
                $rankCampaigns[]=['slug'=>$fake['slug'],'title'=>$fake['title'],
                    'clips'=>72+$i*4,'views'=>max(155000,3700000-$i*191000),'is_demo'=>true];
            }
            usort($rankCampaigns,static fn(array $a,array $b)=>(int)$b['views']<=>(int)$a['views']);
            $rankCampaigns=array_slice($rankCampaigns,0,8);
            $viewsAll['views']=(int)($viewsAll['views']??0)+array_sum(array_column($fakePlayers,'views'));
            $viewsAll['clips']=(int)($viewsAll['clips']??0)+array_sum(array_column($fakePlayers,'clips'));
            $viewsAll['players']=(int)($viewsAll['players']??0)+count($fakePlayers);
            $campaigns+=count($fakeCampaigns);
            $examples=[];
            for($i=0;$i<6;$i++){
                $examples[]=['title'=>'Clipe ilustrativo #'.($i+1),'status'=>'approved',
                    'submitted_at'=>date('Y-m-d H:i:s',strtotime('-'.($i+1).' hours')),
                    'full_name'=>$fakePlayers[$i]['full_name'],
                    'campaign_title'=>$fakeCampaigns[$i]['title'],'is_demo'=>true];
            }
            $recent=array_slice(array_merge($examples,$recent),0,10);
        }
        lc_page('Administração',function()use($admin,$pending,$total,$campaigns,$clips,$withdrawals,$recent,$viewsAll,$lastObservation,$rankUsers,$rankCampaigns,$demo){?>
<div class="container ws-dashboard">
  <section class="ws-hero" aria-labelledby="admin-overview-title">
    <div><span class="eyebrow">Painel administrativo · League Cut · placar de resultados</span><h1 id="admin-overview-title">O jogo está acontecendo.</h1>
      <p><?=$demo?'Indicadores demonstrativos com registros fictícios identificados. Pagamentos e operações reais permanecem separados.':'Visualizações reais observadas, melhores clipadores e campanhas em destaque. Métricas coletadas pela Apify.'?></p><?php if($demo):?><a class="tag" href="/admin/demo">MODO DEMONSTRAÇÃO · DADOS SIMULADOS</a><?php endif;?>
    </div>
    <div class="ws-hero-actions"><a href="/admin/clipadores?status=pending" class="btn">Ver aprovações <?=lc_icon('arrow-right')?></a><a href="/admin/campanhas" class="btn btn-ghost">Gerenciar campanhas</a></div>
  </section>
  <div class="ws-kpi-grid" aria-label="Indicadores administrativos">
    <?php foreach([
     [$demo?'Views ilustrativas':'Views observadas',(int)($viewsAll['views']??0),'chart-column',$demo?'Inclui dados simulados':'Última leitura por clipe aprovado'],
      [$demo?'Clipes ilustrativos':'Clipes aprovados',(int)($viewsAll['clips']??0),'clapperboard',$demo?'Inclui clipes fictícios':'Publicações no ranking'],
      ['Campanhas ativas',$campaigns,'layers',$demo?'Inclui campanhas fictícias':'Oportunidades disponíveis'],
      ['Clipadores no ranking',(int)($viewsAll['players']??0),'users-round',$demo?'Inclui perfis fictícios':'Com clipes aprovados']
    ] as $index=>[$name,$value,$icon,$help]): ?>
      <div class="ws-kpi"><div class="ws-kpi-top"><span><?=lc_e($name)?></span><span class="ws-kpi-icon"><?=lc_icon($icon)?></span></div>
       <div class="ws-kpi-value" <?=$index===2&&!$demo?'data-live="active_campaigns"':''?>><?=number_format((int)$value,0,',','.')?></div><p class="ws-kpi-foot"><?=lc_e($help)?></p></div>
    <?php endforeach; ?>
  </div>

  <div class="ws-dashboard-views-heading"><div><span class="eyebrow">Gamificação · placar geral</span><h2>Ranking de desempenho</h2>
  <p class="hint"><?=$demo?'Ranking ilustrativo: valores de demonstração não foram coletados de redes sociais e não representam pagamentos.':'Views públicas observadas pela Apify em clipes aprovados. Não representam pagamento nem prova de titularidade.'?></p></div>
  <span class="tag"><?=$demo?'DEMO · DADOS FICTÍCIOS':'Última leitura: '.($lastObservation?lc_e(date('d/m/Y H:i',strtotime((string)$lastObservation)).' UTC'):'Sem coletas')?></span></div>
  <div class="ws-rank-dashboard">
    <section class="ws-rank-panel"><h2><?=lc_icon('trophy')?> Top clipadores</h2>
    <?php if(!$rankUsers):?><p class="ws-rank-empty">Aguardando clipes aprovados.</p><?php else:foreach($rankUsers as $i=>$p):?>
    <div class="ws-rank-row" data-arena-position="<?=$i+1?>"><span class="ws-rank-number"><?=$i+1?>º</span><span><strong><?=lc_private($p['full_name'])?> <?=lc_rank_trophy($i+1)?></strong><small>@<?=lc_e($p['nickname']??'sem-nick')?></small>
    <small><?=(int)$p['clips']?> <?=!empty($p['is_demo'])?'clipes simulados':'clipes'?> · <?=number_format((int)$p['clips']*100+intdiv((int)$p['views'],100),0,',','.')?> XP ilustrativo</small></span>
    <span class="ws-rank-count"><?=number_format((int)$p['views'],0,',','.')?><small>views</small></span></div>
    <?php endforeach;endif;?></section>
    <section class="ws-rank-panel"><h2><?=lc_icon('chart-column')?> Top campanhas</h2>
    <?php if(!$rankCampaigns):?><p class="ws-rank-empty">Nenhuma campanha cadastrada.</p><?php else:foreach($rankCampaigns as $i=>$p):?>
    <div class="ws-rank-row" data-arena-position="<?=$i+1?>"><span class="ws-rank-number"><?=$i+1?>º</span><span><a href="<?=!empty($p['is_demo'])?'/demo/campanhas/':'/campanhas/'?><?=lc_e($p['slug'])?>"><strong><?=lc_e($p['title'])?> <?=lc_rank_trophy($i+1)?></strong></a><small><?=(int)$p['clips']?> <?=!empty($p['is_demo'])?'clipes fictícios':'clipes aprovados'?></small></span>
    <span class="ws-rank-count"><?=number_format((int)$p['views'],0,',','.')?><small>views</small></span></div>
    <?php endforeach;endif;?></section>
  </div>
  <div class="ws-dual">
    <section class="panel" aria-labelledby="queue-title">
      <div class="ws-section-title"><h2 id="queue-title">Precisa da sua atenção</h2><span class="tag tag-pending">Fila de trabalho</span></div>
      <div class="ws-queue">
        <a class="ws-queue-link" href="/admin/clipadores?status=pending"><span><?=lc_icon('users-round')?></span><div><strong>Aprovar clipadores</strong><small>Perfis novos aguardando sua decisão</small></div><span class="ws-queue-count" data-live="pending_users"><?=$pending?></span></a>
        <a class="ws-queue-link" href="/admin/clipes?status=pending"><span><?=lc_icon('clipboard-check')?></span><div><strong>Conferir clipes</strong><small>Revisar publicações antes dos créditos</small></div><span class="ws-queue-count" data-live="pending_clips"><?=$clips?></span></a>
        <a class="ws-queue-link" href="/admin/financeiro"><span><?=lc_icon('wallet')?></span><div><strong>Solicitações de saque</strong><small>Pagamentos sujeitos a conferência manual</small></div><span class="ws-queue-count" data-live="pending_withdrawals"><?=$withdrawals?></span></a>
      </div>
    </section>
    <section class="panel" aria-labelledby="recent-title">
      <div class="ws-section-title"><h2 id="recent-title">Últimas entregas</h2><a href="/admin/clipes">Ver todos <?=lc_icon('arrow-right')?></a></div>
      <?php if(!$recent):?><div class="ws-empty-note">Os vídeos enviados pelos clipadores aparecerão aqui assim que começarem a participar.</div>
      <?php else: ?><div class="ws-recent"><?php foreach($recent as $item):?>
       <div class="ws-recent-item"><div class="ws-recent-leading"><span class="ws-recent-icon"><?=lc_icon('video')?></span>
       <div class="ws-recent-main"><strong><?=lc_e($item['title'])?><?=!empty($item['is_demo'])?' · DEMO':''?></strong><small><?=lc_e($item['full_name'])?> · <?=date('d/m/Y',strtotime($item['submitted_at']))?></small></div></div>
       <span class="tag tag-<?=lc_e($item['status'])?>"><?=lc_e(lc_status_label($item['status']))?></span></div>
      <?php endforeach;?></div><?php endif;?>
    </section>
  </div>
  <div class="ws-section-title"><h2>Ferramentas de gestão</h2><span class="tag">LeagueCut</span></div>
  <div class="ws-action-cards">
    <a class="ws-action-card" href="/admin/campanhas"><?=lc_icon('clapperboard')?><strong>Campanhas</strong><small>Crie campanhas e ajuste metas e recompensas.</small><span class="ws-action-arr">Gerenciar <?=lc_icon('arrow-right')?></span></a>
    <a class="ws-action-card" href="/admin/home"><?=lc_icon('layers')?><strong>Banner da Home</strong><small>Altere mídia, título e botões da landing page.</small><span class="ws-action-arr">Personalizar <?=lc_icon('arrow-right')?></span></a>
    <a class="ws-action-card" href="/admin/bonus-mensal"><?=lc_icon('trophy')?><strong>Bônus mensal</strong><small>Feche períodos concluídos e confira o ranking.</small><span class="ws-action-arr">Acessar <?=lc_icon('arrow-right')?></span></a>
    <a class="ws-action-card" href="/admin/configuracoes"><?=lc_icon('palette')?><strong>Identidade visual</strong><small>Ajuste nome, logo e cor de destaque.</small><span class="ws-action-arr">Configurar <?=lc_icon('arrow-right')?></span></a>
  </div>
  <p class="ws-trace"><?=lc_icon('clock-3')?> Conexão: <strong data-realtime-connection>Conectando</strong> · Indicadores sincronizados automaticamente.</p>
</div>
<?php },'admin');return true;
    }
    if($method==='GET'&&$path==='/admin/administradores'){
        $admins=lc_all("SELECT id,full_name,email,status,created_at FROM users WHERE role='admin' ORDER BY id ASC");
        lc_page('Administradores',function()use($admins){?>
<div class="container"><?php lc_heading('Acesso privilegiado','Administradores','Contas administrativas armazenadas no MySQL, com senhas protegidas por hash.'); ?>
<div class="panel"><div class="panel-head"><h2>Administradores cadastrados</h2><span class="tag"><?=count($admins)?> contas</span></div>
<div class="alert">Por segurança, novas contas administrativas são criadas no terminal da VPS com senha digitada sem aparecer na tela. Não compartilhe senhas no ChatGPT ou GitHub.</div>
<div class="table-wrap"><table><thead><tr><th>Usuário</th><th>E-mail</th><th>Status</th><th>Criado em</th></tr></thead><tbody>
<?php foreach($admins as $a):?><tr><td><?=lc_private($a['full_name'])?></td><td><?=lc_private($a['email'])?></td><td><span class="tag tag-<?=lc_e($a['status'])?>"><?=lc_e($a['status'])?></span></td><td><?=date('d/m/Y',strtotime($a['created_at']))?></td></tr><?php endforeach;?>
</tbody></table></div></div><a class="btn btn-ghost" href="/admin">Voltar ao painel</a></div>
<?php },'admin');return true;
    }
    if($method==='GET'&&$path==='/admin/clipadores'){
        $filter=(string)($_GET['status']??'all');
        if(!in_array($filter,['all','pending','approved','rejected','suspended'],true))$filter='all';
        $where="role='clipador'".($filter==='all'?'':' AND status=?');
        $users=lc_all("SELECT id,full_name,email,instagram,whatsapp,portfolio_url,status,consent_whatsapp,created_at FROM users WHERE $where ORDER BY FIELD(status,'pending','approved','rejected','suspended'),created_at DESC LIMIT 200",$filter==='all'?[]:[$filter]);
        if(lc_demo_enabled()&&in_array($filter,['all','approved'],true)){
            foreach(lc_demo_ranking(30) as $i=>$person){
                $users[]=['id'=>0,'full_name'=>$person['full_name'].' · DEMO',
                    'email'=>'exemplo'.($i+1).'@example.invalid',
                    'instagram'=>$person['nickname'],'whatsapp'=>'','portfolio_url'=>null,
                    'status'=>'approved','consent_whatsapp'=>0,'created_at'=>date('Y-m-d H:i:s')];
            }
        }
        lc_page('Clipadores',function()use($users,$filter){?>
<div class="container"><?php lc_heading('Aprovações','Clipadores','Aprovação manual com registro e notificações em fila.'); ?>
<div class="ws-filter-tabs" aria-label="Filtrar clipadores por situação">
<?php foreach(['all'=>'Todos','pending'=>'Pendentes','approved'=>'Aprovados','rejected'=>'Rejeitados','suspended'=>'Suspensos'] as $v=>$label): ?>
<a href="/admin/clipadores?status=<?=$v?>" class="<?=$filter===$v?'selected':''?>" <?=$filter===$v?'aria-current="page"':''?>><?=$label?></a><?php endforeach;?></div>
<div class="panel"><div class="ws-module-head"><h2>Cadastros</h2><span class="tag"><?=count($users)?> exibidos</span></div>
<?php if(!$users):lc_empty('Nenhum cadastro','Os próximos clipadores vão aparecer aqui.');else:?>
<div class="table-wrap"><table><thead><tr><th>Clipador</th><th>Instagram</th><th>WhatsApp</th><th>Status</th><th>Ação</th></tr></thead><tbody>
<?php foreach($users as $u):?><tr>
<td><?=lc_private($u['full_name'])?><small><?=lc_private($u['email'])?></small><?php if($u['portfolio_url']):?><a href="<?=lc_e($u['portfolio_url'])?>" target="_blank" rel="noopener noreferrer">Portfólio ↗</a><?php endif;?></td>
<td>@<?=lc_e($u['instagram'])?></td><td><?=lc_e($u['whatsapp'])?></td><td><span class="tag tag-<?=lc_e($u['status'])?>"><?=lc_e(lc_status_label($u['status']))?></span></td>
<td><?php if($u['status']==='pending'):?><form method="post" action="/admin/clipadores/<?=(int)$u['id']?>/aprovar"><?=lc_csrf()?><button class="btn btn-sm" type="submit">Aprovar <?=lc_icon('check')?></button></form><?php else:?>—<?php endif;?></td>
</tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
<?php },'admin');return true;
    }
    if($method==='POST'&&preg_match('~^/admin/clipadores/([0-9]+)/aprovar$~',$path,$m)){
        lc_db()->beginTransaction();
        try{
            $u=lc_one("SELECT * FROM users WHERE id=? AND role='clipador' FOR UPDATE",[(int)$m[1]]);
            if(!$u || $u['status']!=='pending'){lc_db()->rollBack();lc_flash('Cadastro já processado.','error');lc_redirect('/admin/clipadores');}
            lc_query("UPDATE users SET status='approved',approved_at=NOW(),approved_by=? WHERE id=?",[$admin['id'],$u['id']]);
            lc_realtime_emit('user',(int)$u['id'],'creator.approved','user',(int)$u['id']);
            lc_realtime_emit('admin',null,'creator.reviewed','user',(int)$u['id']);
            lc_automation_emit('user.approved','user.approved:'.$u['id'],(int)$u['id'],[
                'nome'=>$u['full_name'],'role'=>'clipador','status'=>'approved','link'=>'/dashboard']);
            // Keep the legacy notification if no administrator has configured a flow.
            if(!lc_automation_has_trigger('user.approved')){
                lc_outbox((int)$u['id'],'email','creator_approved',['name'=>$u['full_name'],'email'=>$u['email']]);
                if((int)$u['consent_whatsapp']===1)lc_outbox((int)$u['id'],'whatsapp','creator_approved',['name'=>$u['full_name'],'phone'=>$u['whatsapp']]);
            }
            lc_db()->commit();
        }catch(Throwable $e){if(lc_db()->inTransaction())lc_db()->rollBack();throw $e;}
        lc_flash('Clipador aprovado. Notificações enfileiradas; o envio depende da configuração dos canais.');lc_redirect('/admin/clipadores');
    }
    if($method==='GET'&&$path==='/admin/campanhas'){
        $campaigns=lc_demo_merge_campaigns(lc_all('SELECT * FROM campaigns ORDER BY FIELD(status,"active","draft","paused","ended"),created_at DESC,id DESC LIMIT 120'));
        lc_page('Campanhas',function()use($campaigns){?>
<div class="container lc-admin-catalog">
  <div class="lc-admin-catalog-header">
    <div><span class="eyebrow">Central de campanhas</span><h1>O catálogo LeagueCut.</h1>
      <p>Explore campanhas como títulos de uma coleção. Abra cada uma para acompanhar o ranking dos clipadores.</p></div>
    <a class="btn" href="#criar-campanha"><?=lc_icon('plus')?> Nova campanha</a>
  </div>
  <div class="lc-admin-catalog-count"><span><?=lc_icon('layers')?> Catálogo gerenciado</span>
    <strong><?=count($campaigns)?> <?=count($campaigns)===1?'campanha':'campanhas'?></strong><?php if(lc_demo_enabled()):?><span class="tag">INCLUI EXEMPLOS FICTÍCIOS</span><?php endif;?></div>
  <div class="lc-admin-catalog-library">
    <h2>Campanhas criadas</h2>
    <?php lc_campaign_catalog($campaigns,true); ?>
  </div>
  <div id="criar-campanha" class="lc-admin-create-block">
    <div class="lc-create-heading"><h2>Adicionar campanha</h2><p>Novas campanhas aparecem no catálogo e têm um ranking próprio.</p></div>
    <?php lc_campaign_form(null); ?>
  </div>
</div>
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
        $filter=(string)($_GET['status']??'all');
        if(!in_array($filter,['all','pending','approved','rejected'],true))$filter='all';
        $clips=lc_all('SELECT c.*,u.full_name,p.title AS campaign_title,s.network, (SELECT o.views FROM clip_daily_views o WHERE o.clip_id=c.id ORDER BY o.snapshot_date DESC LIMIT 1) AS observed_views, (SELECT o.snapshot_date FROM clip_daily_views o WHERE o.clip_id=c.id ORDER BY o.snapshot_date DESC LIMIT 1) AS snapshot_date FROM clips c JOIN users u ON u.id=c.user_id JOIN campaigns p ON p.id=c.campaign_id JOIN social_accounts s ON s.id=c.social_account_id'.($filter==='all'?'':' WHERE c.status=?').' ORDER BY FIELD(c.status,"pending","approved","rejected"),c.id DESC LIMIT 150',$filter==='all'?[]:[$filter]);
        lc_page('Clipes e visualizações',function()use($clips,$filter){?>
<div class="container"><?php lc_heading('Curadoria','Conferir clipes','Aprove, reprove ou exclua publicações. As visualizações públicas são atualizadas diariamente.'); ?>
<div class="ws-filter-tabs" aria-label="Filtrar clipes por status">
<?php foreach(['all'=>'Todos','pending'=>'Pendentes','approved'=>'Aprovados','rejected'=>'Rejeitados'] as $v=>$label):?><a href="/admin/clipes?status=<?=$v?>" class="<?=$filter===$v?'selected':''?>" <?=$filter===$v?'aria-current="page"':''?>><?=$label?></a><?php endforeach;?></div>
<div class="alert">Visualizações públicas: coleta diária às 2h para clipes Instagram aprovados. Ações de aprovação não lançam bônus de views automaticamente. Clipes com créditos financeiros exigem estorno auditável antes de reprovação ou exclusão.</div>
<div class="panel"><div class="ws-module-head"><h2>Publicações para revisar</h2><span class="tag"><?=count($clips)?> exibidos</span></div>
<?php if(!$clips):lc_empty('Tudo em dia','Nenhum vídeo recebido até agora.');else:?>
<div class="table-wrap"><table><thead><tr><th>Vídeo</th><th>Criador / campanha</th><th>Atual</th><th>Views públicas (automáticas)</th><th>Ações</th></tr></thead><tbody>
<?php foreach($clips as $c):?><tr>
<td><a href="<?=lc_e($c['published_url'])?>" target="_blank" rel="noopener noreferrer"><?=lc_e($c['title'])?> ↗</a><small><?=lc_e(ucfirst($c['network']))?> <?=date('d/m/Y',strtotime($c['submitted_at']))?> · <?= $c['drive_file_id']?'Drive':'sem arquivo'?></small>

<?php if($c['drive_file_id']):?>
<button type="button" class="btn btn-ghost btn-sm clip-preview-trigger" data-preview-url="/clipes/<?=(int)$c['id']?>/video" data-preview-name="<?=lc_e($c['title'])?>"><?=lc_icon('play')?> Assistir aqui</button>
<?php if($driveUrl=lc_drive_url((string)$c['drive_file_id'])):?><a class="drive-file-link" href="<?=lc_e($driveUrl)?>" target="_blank" rel="noopener noreferrer"><?=lc_icon('folder-open')?> Abrir no Drive</a><?php endif;?>
<?php endif;?></td>
<td><?=lc_e($c['full_name'])?><small><?=lc_e($c['campaign_title'])?></small></td>
<td><span class="tag tag-<?=lc_e($c['status'])?>"><?=lc_e(lc_status_label($c['status']))?></span></td>
<td class="views-readout">
<?php if($c['observed_views']!==null):?>
<strong><?=number_format((int)$c['observed_views'],0,',','.')?></strong>
<small>Apify · leitura <?=date('d/m/Y',strtotime((string)$c['snapshot_date']))?></small>
<?php elseif($c['network']!=='instagram'):?>
<span>Sem integração</span><small><?=lc_e(ucfirst($c['network']))?>: aguardando coletor</small>
<?php elseif($c['status']!=='approved' || !$c['drive_file_id']):?>
<span>Não elegível</span><small>Aprovação e vídeo original obrigatórios</small>
<?php else:?>
<span>Aguardando coleta</span><small>Próxima tentativa: 02h de Fortaleza, sujeita a orçamento</small>
<?php endif;?>
</td>
<td><div class="clip-admin-actions">
<form method="post" action="/admin/clipes/<?=(int)$c['id']?>/revisar" class="clip-admin-action-form"><?=lc_csrf()?>
<input type="hidden" name="decision" value="<?=$c['status']==='approved'?'rejected':'approved'?>">
<button class="btn btn-sm <?=$c['status']==='approved'?'btn-ghost':''?>" type="submit"><?=$c['status']==='approved'?'Reprovar':'Aprovar'?></button>
</form>
<form method="post" action="/admin/clipes/<?=(int)$c['id']?>/excluir" class="clip-admin-action-form" onsubmit="return confirm('Excluir permanentemente este clipe e seu arquivo original no Google Drive? Esta ação não poderá ser desfeita.');"><?=lc_csrf()?>
<button class="btn btn-sm btn-delete-clip" type="submit" aria-label="Excluir clipe <?=lc_e($c['title'])?>">Excluir</button>
</form>
</div></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
<?php lc_clip_preview_modal(); },'admin');return true;
    }
    if($method==='POST'&&preg_match('~^/admin/clipes/([0-9]+)/revisar$~D',$path,$m)){
        try {
            $decision=lc_post('decision',20);
            $message=lc_admin_change_clip((int)$m[1],$decision,(int)$admin['id']);
            lc_flash($message);
        }catch(Throwable $e){
            lc_flash($e->getMessage(),'error');
        }
        lc_redirect('/admin/clipes');
    }
    if($method==='POST'&&preg_match('~^/admin/clipes/([0-9]+)/excluir$~D',$path,$m)){
        try {
            $message=lc_admin_delete_clip((int)$m[1],(int)$admin['id']);
            lc_flash($message);
        }catch(Throwable $e){
            lc_flash($e->getMessage(),'error');
        }
        lc_redirect('/admin/clipes');
    }
    if($method==='GET'&&$path==='/admin/financeiro'){
        $withdrawals=lc_all('SELECT w.*,u.full_name,u.email,u.pix_key_enc FROM withdrawals w JOIN users u ON u.id=w.user_id ORDER BY FIELD(w.status,"requested","processing","paid","rejected"),w.id DESC LIMIT 120');
        lc_page('Saques',function()use($withdrawals){?><div class="container"><?php lc_heading('Controle financeiro','Solicitações de saque','Registre pagamentos externos somente depois de confirmar o Pix.'); ?>
<div class="alert">Pagamentos seguem manuais nesta versão. Marque como pago somente após confirmar a transferência e informar o identificador real do Pix. Não compartilhe chaves Pix em logs públicos.</div><div class="panel">
<?php if(!$withdrawals):lc_empty('Sem saques pendentes','Os pedidos dos clipadores vão aparecer aqui.');else:?>
<div class="table-wrap"><table><thead><tr><th>Clipador</th><th>Valor</th><th>Chave Pix</th><th>Status</th><th>Processar</th></tr></thead><tbody>
<?php foreach($withdrawals as $w):?><tr><td><?=lc_private($w['full_name'])?><small><?=lc_private($w['email'])?></small></td><td><?=lc_private(lc_money($w['amount_cents']))?></td>
<td><?=lc_private(lc_decrypt($w['pix_key_enc']))?></td><td><span class="tag tag-<?=lc_e($w['status'])?>"><?=lc_e(lc_status_label($w['status']))?></span></td>
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
        lc_db()->beginTransaction();
        try {
            $row=lc_one("SELECT id,user_id,status FROM withdrawals WHERE id=? FOR UPDATE",[(int)$m[1]]);
            if(!$row||!in_array($row['status'],['requested','processing'],true)){
                lc_db()->rollBack();lc_flash('Solicitação já processada.','error');lc_redirect('/admin/financeiro');
            }
            lc_query("UPDATE withdrawals SET status=?,pix_provider_reference=?,processed_at=NOW()
                  WHERE id=?",[$decision,$decision==='paid'?$reference:null,(int)$m[1]]);
            lc_realtime_emit('user',(int)$row['user_id'],'withdrawal.updated','withdrawal',(int)$row['id']);
            lc_realtime_emit('admin',null,'withdrawal.updated','withdrawal',(int)$row['id']);
            lc_automation_emit('withdrawal.'.$decision,'withdrawal.'.$decision.':'.$row['id'],(int)$row['user_id'],['status'=>$decision,'link'=>'/financeiro']);
            lc_db()->commit();
        }catch(Throwable $e){if(lc_db()->inTransaction())lc_db()->rollBack();throw $e;}
        lc_flash('Solicitação atualizada.');lc_redirect('/admin/financeiro');
    }
    if($method==='GET'&&$path==='/admin/bonus-mensal'){
        $campaigns=lc_all('SELECT id,title,monthly_leader_bonus_cents FROM campaigns WHERE monthly_leader_bonus_cents>0 ORDER BY id DESC');
        $allCampaigns=lc_all('SELECT id,title FROM campaigns ORDER BY id DESC LIMIT 300');
        $programs=lc_all('SELECT b.*,c.title AS campaign_title FROM monthly_bonus_programs b
                 JOIN campaigns c ON c.id=b.campaign_id ORDER BY b.id DESC LIMIT 100');
        $admin=lc_admin();
        lc_page('Bônus mensal',function()use($campaigns,$allCampaigns,$programs,$admin){?><div class="container container-md"><?php lc_heading('Fechamento','Bônus mensal','Premie a maior soma de views verificadas no mês para cada campanha.'); ?>
<div class="panel"><h2>Fechar mês</h2><p>Este processo só aceita meses finalizados. Em empate, vence o menor ID de usuário (critério provisório). A operação é idempotente.</p>
<form method="post" action="/admin/bonus-mensal"><?=lc_csrf()?><div class="form-inline">
<label class="field"><span>Campanha</span><select name="campaign_id"><?php foreach($campaigns as $c):?><option value="<?=(int)$c['id']?>"><?=lc_e($c['title'])?> · <?=lc_money($c['monthly_leader_bonus_cents'])?></option><?php endforeach;?></select></label>
<label class="field"><span>Mês encerrado</span><input type="month" name="month" max="<?=date('Y-m',strtotime('first day of previous month'))?>" required></label>
<button class="btn" type="submit">Apurar vencedor →</button></div></form></div>
<div class="panel">
<div class="panel-head"><div><span class="eyebrow">Bônus independentes</span><h2>Cadastrar novo bônus mensal</h2></div><span class="tag"><?=count($programs)?> programas</span></div>
<p class="hint">Cadastre quantos bônus quiser para a mesma campanha. Cada um tem regra e pagamento próprios. O fechamento é manual e só considera meses concluídos.</p>
<form action="/admin/bonus-mensal/criar" method="post"><?=lc_csrf()?>
<div class="form-grid">
<label class="field"><span>Nome do bônus</span><input name="bonus_name" maxlength="120" minlength="3" placeholder="Ex.: Rei das views" required></label>
<label class="field"><span>Campanha</span><select name="campaign_id" required><?php foreach($allCampaigns as $c):?><option value="<?=(int)$c['id']?>"><?=lc_e($c['title'])?></option><?php endforeach;?></select></label>
<label class="field"><span>Critério de classificação</span><select name="metric"><option value="verified_views">Maior soma de views verificadas</option><option value="approved_clips">Mais clipes aprovados</option></select></label>
<label class="field"><span>Prêmio (R$)</span><input name="bonus_amount" type="text" inputmode="decimal" data-lc-mask="money" placeholder="Ex.: 100,00" required></label>
<label class="field"><span>Confirme a senha do administrador</span><input name="admin_password" type="password" autocomplete="current-password" required></label>
<?php if(lc_mfa_enabled((int)$admin['id'])):?><label class="field"><span>Código Google Authenticator</span><input name="mfa_code" autocomplete="one-time-code" required></label><?php endif;?>
</div><div class="form-actions"><button class="btn" type="submit">Cadastrar bônus →</button></div></form>
</div>
<div class="panel"><div class="panel-head"><h2>Programas mensais cadastrados</h2><span class="tag"><?=count($programs)?> registrados</span></div>
<?php if(!$programs):lc_empty('Nenhum bônus extra ainda','Cadastre acima o primeiro programa de bonificação mensal.');else:?>
<div class="lc-monthly-program-list">
<?php foreach($programs as $b):?>
<section class="lc-monthly-program">
<div><span class="eyebrow">#<?=(int)$b['id']?> · <?=lc_e($b['campaign_title'])?></span>
<h3><?=lc_e($b['name'])?></h3>
<p><?=lc_e($b['metric']==='approved_clips'?'Maior quantidade de clipes aprovados':'Maior soma de views verificadas')?> · prêmio <?=lc_private(lc_money($b['amount_cents']))?></p>
<span class="tag <?=$b['enabled']?'tag-approved':'tag-pending'?>"><?=$b['enabled']?'Ativo':'Pausado'?></span></div>
<div class="lc-monthly-program-actions">
<?php if((int)$b['enabled']===1):?><form method="post" action="/admin/bonus-mensal/<?=(int)$b['id']?>/apurar"><?=lc_csrf()?>
<label class="field"><span>Mês encerrado</span><input name="month" type="month" max="<?=date('Y-m',strtotime('first day of previous month'))?>" required></label>
<label class="field"><span>Senha do ADM</span><input name="admin_password" type="password" autocomplete="current-password" required></label>
<?php if(lc_mfa_enabled((int)$admin['id'])):?><label class="field"><span>2FA</span><input name="mfa_code" required autocomplete="one-time-code"></label><?php endif;?>
<button class="btn btn-sm" type="submit">Apurar bônus</button></form><?php endif;?>
<form method="post" action="/admin/bonus-mensal/<?=(int)$b['id']?>/estado"><?=lc_csrf()?>
<?php if(!(int)$b['enabled']):?><input type="hidden" name="enabled" value="1"><?php endif;?>
<label class="field"><span>Senha do ADM</span><input name="admin_password" type="password" autocomplete="current-password" required></label>
<?php if(lc_mfa_enabled((int)$admin['id'])):?><label class="field"><span>2FA</span><input name="mfa_code" required autocomplete="one-time-code"></label><?php endif;?>
<button class="btn btn-ghost btn-sm" type="submit"><?=$b['enabled']?'Pausar':'Reativar'?></button></form></div>
</section><?php endforeach;?></div>
<?php endif;?></div>
</div>
<?php },'admin');return true;
    }
    if($method==='POST'&&$path==='/admin/bonus-mensal'){
        lc_db()->beginTransaction();
        try{$winner=lc_credit_monthly_leader(lc_int('campaign_id',0,2147483647),lc_post('month',7));lc_db()->commit();}
        catch(Throwable $e){if(lc_db()->inTransaction())lc_db()->rollBack();lc_flash($e->getMessage(),'error');lc_redirect('/admin/bonus-mensal');}
        lc_flash($winner?'Apuração realizada. Repetições não geram pagamento duplo.':'Sem vídeos aprovados com views nesse período.');lc_redirect('/admin/bonus-mensal');
    }
    if($method==='GET'&&$path==='/admin/configuracoes'){
        $s=lc_settings();$currentLogo=(string)($s['logo_url']??'');
        lc_page('Personalização',function()use($s,$currentLogo){?>
<div class="container container-md lc-visual-admin lc-brand-admin">
<?php lc_heading('Identidade visual','Personalização','Veja a marca ativa, simule as alterações e salve somente quando estiver satisfeito.'); ?>
<div class="lc-editor-tabbar lc-editor-static-tabs" aria-label="Áreas de personalização">
  <span class="lc-editor-tab is-current" aria-current="page"><?=lc_icon('palette')?> Marca</span>
  <a class="lc-editor-tab lc-editor-tab-link" href="/admin/seo#icones"><?=lc_icon('layers')?> Ícones e favicon</a>
  <a class="lc-editor-tab lc-editor-tab-link" href="/admin/seo#compartilhamento"><?=lc_icon('share-2')?> Compartilhamento</a>
  <a class="lc-editor-tab lc-editor-tab-link" href="/admin/seo#metadados"><?=lc_icon('globe')?> SEO e metadados</a>
</div>
<div class="panel lc-editor-panel">
<form method="post" action="/admin/configuracoes" enctype="multipart/form-data" data-brand-editor><?=lc_csrf()?>
  <div class="lc-brand-preview" data-brand-preview style="--brand-preview-accent:<?=lc_e((string)($s['primary_color']??'#e50914'))?>">
    <div class="lc-brand-preview-title">Prévia da marca no cabeçalho</div>
    <div class="lc-brand-live-header">
      <img src="<?=lc_e(lc_brand_logo_src($currentLogo)?$currentLogo:'/assets/leaguecut-icon.svg')?>" alt="Marca ativa ou escolhida" data-brand-image>
      <strong data-brand-text><?=lc_e((string)($s['brand_name']??'LeagueCut'))?></strong>
    </div><div class="lc-brand-live-accent"></div>
    <p class="lc-editor-preview-caption">Visualização local: nada será publicado até clicar em Salvar.</p>
  </div>
  <div class="form-grid">
    <?php lc_field('Nome da plataforma','brand_name','text',(string)($s['brand_name']??'LeagueCut'),true,'LeagueCut','maxlength="80"'); ?>
    <?php lc_field('Cor principal','primary_color','color',(string)($s['primary_color']??'#e50914'),true); ?>
    <label class="field full"><span>Nova logo</span>
      <input name="logo_upload" type="file" accept="image/*,.svg,image/svg+xml" data-preview-upload>
      <small>PNG, JPG, WebP, GIF, AVIF ou SVG (até 5 MB). SVG é higienizado no servidor antes de salvar.</small>
    </label>
    <div class="full"><div class="lc-editor-preview-card"><small>Logo ativa agora</small>
      <img src="<?=lc_e(lc_brand_logo_src($currentLogo)?$currentLogo:'/assets/leaguecut-icon.svg')?>" alt="Logo ativa" style="max-height:88px;object-fit:contain">
      <p class="lc-editor-preview-caption"><?=lc_e($currentLogo!==''?'Arquivo da marca configurado':'Usando a marca padrão LeagueCut')?></p></div></div>
    <div class="full"><?php lc_field('URL HTTPS da logo (alternativa ao upload)','logo_url','text',$currentLogo,false,'https://.../logo.svg','maxlength="800"'); ?>
      <p class="hint">Se não selecionar arquivo, uma URL HTTPS válida substituirá a logo atual.</p>
    </div>
  </div>
  <div class="lc-editor-save"><small>Prévia instantânea sem alterar o site.</small><button class="btn" type="submit">Salvar identidade visual <?=lc_icon('check')?></button></div>
</form></div></div>
<?php },'admin');return true;
    }
    if($method==='POST'&&$path==='/admin/configuracoes'){
        $name=lc_post('brand_name',80);$color=lc_post('primary_color',7);$logo=lc_post('logo_url',800);
        if(mb_strlen($name)<2||!preg_match('/^#[a-fA-F0-9]{6}$/',$color)||($logo!==''&&!lc_brand_logo_src($logo))){
            lc_flash('Verifique o nome, a cor e o endereço da logo.','error');lc_redirect('/admin/configuracoes');
        }
        $upload=$_FILES['logo_upload']??null;
        if($upload && ($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            try{$logo=lc_brand_store_logo($upload);}
            catch(Throwable $e){lc_flash($e->getMessage(),'error');lc_redirect('/admin/configuracoes');}
        }
        foreach(['brand_name'=>$name,'primary_color'=>$color,'logo_url'=>$logo] as $key=>$value){
            lc_query('INSERT INTO platform_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,$value]);
        }
        lc_flash('Marca atualizada em toda a plataforma.');lc_redirect('/admin/configuracoes');
    }
    return false;
}
