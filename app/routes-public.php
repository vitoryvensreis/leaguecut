<?php
declare(strict_types=1);

function lc_public_routes(string $method,string $path): bool {

    // Public marketing landing. Campaigns are deliberately NOT queried here.
    if ($method==='GET' && $path==='/') {
        $banner=lc_home_banner();
        lc_page('Crie, publique e conquiste',function()use($banner){ ?>
<div class="lp">
<section class="lp-hero <?=lc_e($banner['text_alignment']==='center'?'lp-centered':'')?> <?=lc_e($banner['media_type']!=='default'&&$banner['media_url']?'lp-has-media':'')?>" aria-labelledby="lp-title" style="--home-overlay:<?=max(0,min(85,(int)$banner['overlay_opacity']))/100?>">
 <?php if($banner['media_type']==='image'&&$banner['media_url']):?>
  <div class="lp-hero-media" aria-hidden="true"><img src="<?=lc_e($banner['media_url'])?>" alt="" fetchpriority="high"></div>
 <?php elseif($banner['media_type']==='video'&&$banner['media_url']):?>
  <div class="lp-hero-media" aria-hidden="true"><video muted playsinline loop preload="none" src="<?=lc_e($banner['media_url'])?>" aria-hidden="true"></video></div>
 <?php endif;?>
 <div class="lp-hero-shade" aria-hidden="true"></div>
 <div class="lp-grid" aria-hidden="true"></div>
 <div class="lp-container lp-hero-layout">
  <div class="lp-hero-content">
   <span class="lp-eyebrow"><?=lc_icon('sparkles')?> <?=lc_e($banner['eyebrow'])?></span>
   <h1 id="lp-title"><?=lc_e($banner['title_top'])?><br><span><?=lc_e($banner['title_accent'])?></span><br><?=lc_e($banner['title_bottom'])?></h1>
   <p><?=lc_e($banner['subtitle'])?></p>
   <div class="lp-actions"><a class="btn lp-primary" href="<?=lc_e($banner['cta_primary_url'])?>"><?=lc_e($banner['cta_primary_text'])?> <?=lc_icon('arrow-right')?></a><a class="btn btn-ghost" href="<?=lc_e($banner['cta_secondary_url'])?>"><?=lc_e($banner['cta_secondary_text'])?> <?=lc_icon('compass')?></a></div>
   <div class="lp-trust"><span><?=lc_icon('shield-check')?> Aprovação de perfis</span><span><?=lc_icon('chart-column')?> Metas transparentes</span><span><?=lc_icon('wallet')?> Ganhos acompanhados</span></div>
  </div>
  <div class="lp-hero-art" aria-hidden="true">
   <div class="lp-halo"></div>
   <div class="lp-player">
    <div class="lp-player-top"><span><?=lc_icon('play')?> LEAGUECUT</span><span class="lp-player-status"><i></i> CREATOR STUDIO</span></div>
    <div class="lp-player-screen"><span class="lp-player-icon"><?=lc_icon('play')?></span><strong>VOCÊ CRIA.<br>O MUNDO VÊ.</strong><div class="lp-bars"><i></i><i></i><i></i><i></i></div></div>
    <div class="lp-player-timeline"><span></span><span></span><span></span><span></span><span></span></div>
    <div class="lp-player-caption"><?=lc_icon('clapperboard')?> Seu próximo projeto começa aqui</div>
   </div>
   <div class="lp-float lp-float-one"><?=lc_icon('sparkles')?> Sua criatividade em movimento</div>
   <div class="lp-float lp-float-two"><?=lc_icon('circle-check')?> Entregas verificadas</div>
  </div>
 </div>
</section>
<section class="lp-section lp-benefits" id="beneficios" aria-labelledby="benefits-title">
 <div class="lp-container">
  <div class="lp-section-intro"><span class="eyebrow">Feito para criadores</span><h2 id="benefits-title">Crie com liberdade.<br><em>Evolua com estratégia.</em></h2><p>Da escolha da campanha à solicitação de saque: o processo organizado em um só lugar.</p></div>
  <div class="lp-features">
   <article class="lp-feature"><span class="lp-feature-icon"><?=lc_icon('clapperboard')?></span><small>01 / CAMPANHAS</small><h3>Escolha seu desafio</h3><p>Briefings, regras, entregas e valores para você decidir em quais oportunidades participar.</p></article>
   <article class="lp-feature"><span class="lp-feature-icon"><?=lc_icon('chart-column')?></span><small>02 / RESULTADOS</small><h3>Acompanhe sua evolução</h3><p>Organize publicações, confira o status dos clipes e acompanhe visualizações validadas.</p></article>
   <article class="lp-feature"><span class="lp-feature-icon"><?=lc_icon('circle-dollar-sign')?></span><small>03 / RECOMPENSAS</small><h3>Entenda cada ganho</h3><p>Consulte pagamentos por vídeo e bônus, quando previstos nas regras de cada campanha.</p></article>
  </div>
 </div>
</section>
<section class="lp-section lp-process" id="como-funciona" aria-labelledby="process-title">
 <div class="lp-container lp-process-grid">
  <div class="lp-process-lead"><span class="eyebrow">Simples de começar</span><h2 id="process-title">Do primeiro login à primeira entrega.</h2><p>Um caminho direto, com aprovação manual e acompanhamento das entregas.</p><a class="lp-inline-link" href="/cadastrar">Criar minha conta <?=lc_icon('arrow-right')?></a></div>
  <div class="lp-process-steps">
   <div><span>01</span><div><h3>Cadastre-se</h3><p>Informe seus dados, portfólio e perfis para análise da equipe.</p></div><?=lc_icon('users-round')?></div>
   <div><span>02</span><div><h3>Explore as campanhas</h3><p>Leia o briefing e entenda metas e recompensas antes de começar.</p></div><?=lc_icon('compass')?></div>
   <div><span>03</span><div><h3>Publique seus vídeos</h3><p>Envie sua publicação e o link para conferência do administrador.</p></div><?=lc_icon('upload-cloud')?></div>
   <div><span>04</span><div><h3>Acompanhe seu saldo</h3><p>Veja créditos liberados e solicite saques conforme a disponibilidade.</p></div><?=lc_icon('wallet')?></div>
  </div>
 </div>
</section>
<section class="lp-section lp-showcase"><div class="lp-container"><div class="lp-showcase-inner">
 <div class="lp-showcase-icon"><?=lc_icon('sparkles')?></div>
 <div><span class="eyebrow">O próximo desafio espera por você</span><h2>Uma plataforma para quem vive de criar.</h2><p>Seu conteúdo merece organização, visibilidade e oportunidades com regras transparentes.</p></div>
 <a class="btn lp-primary" href="/explorar">Conhecer campanhas <?=lc_icon('arrow-right')?></a>
</div></div></section>
<section class="lp-section lp-faq" aria-labelledby="faq-title"><div class="lp-container lp-faq-grid">
 <div><span class="eyebrow">Perguntas frequentes</span><h2 id="faq-title">Sem mistério.</h2><p>Saiba como funciona antes de começar.</p></div>
 <div class="lp-accordion">
  <details><summary>Preciso de aprovação para enviar vídeos? <?=lc_icon('plus')?></summary><p>Sim. Após preencher o cadastro, nossa equipe analisa seu perfil manualmente antes de liberar a participação.</p></details>
  <details><summary>Como são calculadas as recompensas? <?=lc_icon('plus')?></summary><p>Cada campanha estabelece critérios próprios, como quantidade de vídeos aprovados, metas de views e bônus. Ganhos dependem da conferência e do cumprimento dessas regras.</p></details>
  <details><summary>Posso cadastrar vários perfis? <?=lc_icon('plus')?></summary><p>Sim, você pode informar perfis do Instagram e TikTok. Nesta versão, os perfis ainda não contam com verificação automática de propriedade ou métricas.</p></details>
  <details><summary>Como recebo os pagamentos? <?=lc_icon('plus')?></summary><p>Quando os créditos estiverem liberados, você poderá solicitar um saque. Os pagamentos são processados manualmente nesta fase.</p></details>
 </div>
</div></section>
<section class="lp-closer"><div class="lp-container"><div class="lp-closer-box"><span class="eyebrow">Você no próximo nível</span><h2>Tem uma boa ideia de vídeo?<br><span>Faça acontecer.</span></h2><p>Entre para o LeagueCut e descubra oportunidades para criar.</p><div class="lp-actions"><a class="btn lp-primary" href="/cadastrar">Quero participar <?=lc_icon('arrow-right')?></a><a class="btn btn-ghost" href="/entrar">Já tenho conta</a></div></div></div></section>
</div>
<script src="/assets/home-hero.js" defer></script>
<?php },'home');return true;
    }

    // Dedicated public campaign catalog with safe SQL parameters and empty states.
    if ($method==='GET' && $path==='/explorar') {
        $term=mb_substr(trim((string)($_GET['q']??'')),0,80);
        $category=mb_substr(trim((string)($_GET['categoria']??'')),0,70);
        $categories=lc_all("SELECT DISTINCT category FROM campaigns WHERE status='active' ORDER BY category LIMIT 50");
        $allowed=array_column($categories,'category');
        if($category!==''&&!in_array($category,$allowed,true))$category='';
        $where=["status='active'"];
        $args=[];
        if($term!==''){$where[]='(title LIKE ? OR description LIKE ?)';$args[]='%'.$term.'%';$args[]='%'.$term.'%';}
        if($category!==''){$where[]='category=?';$args[]=$category;}
        $campaigns=lc_all('SELECT title,slug,category,cover_url,status,payment_per_video_cents FROM campaigns WHERE '.implode(' AND ',$where).' ORDER BY created_at DESC LIMIT 80',$args);
        lc_page('Explorar campanhas',function()use($campaigns,$categories,$category,$term){ ?>
<div class="explore-page">
 <div class="explore-banner"><div class="lp-container"><span class="eyebrow">Oportunidades para criadores</span>
  <h1>Descubra sua próxima <span>campanha.</span></h1><p>Encontre briefings e conheça as regras antes de criar seu conteúdo.</p>
  <form class="explore-filters" action="/explorar" method="get" role="search">
   <label class="explore-search"><?=lc_icon('compass')?><span class="sr-only">Buscar campanhas</span><input name="q" type="search" value="<?=lc_e($term)?>" maxlength="80" placeholder="Buscar por nome ou palavra-chave"></label>
   <label class="explore-category"><span class="sr-only">Categoria</span><select name="categoria" aria-label="Categoria"><option value="">Todas as categorias</option><?php foreach($categories as $item):?><option value="<?=lc_e($item['category'])?>" <?=$category===$item['category']?'selected':''?>><?=lc_e($item['category'])?></option><?php endforeach;?></select></label>
   <button class="btn" type="submit">Filtrar <?=lc_icon('arrow-right')?></button>
  </form>
 </div></div>
 <div class="lp-container explore-content">
  <div class="section-head"><div><span class="eyebrow">Catálogo LeagueCut</span><h2><?=$term!==''||$category!==''?'Resultados encontrados':'Disponíveis agora'?></h2></div><span class="tag"><?=count($campaigns)?> <?=count($campaigns)===1?'campanha':'campanhas'?></span></div>
  <?php if($campaigns): lc_campaign_catalog($campaigns);
  else:lc_empty($term!==''||$category!==''?'Nenhuma campanha encontrada':'Novas campanhas em breve',$term!==''||$category!==''?'Tente outro termo ou remova os filtros.':'Você pode criar sua conta enquanto preparamos novas oportunidades.');?>
  <div class="explore-empty-action"><a class="btn btn-ghost" href="<?=$term!==''||$category!==''?'/explorar':'/cadastrar'?>"><?=$term!==''||$category!==''?'Limpar filtros':'Seja um clipador'?> <?=lc_icon('arrow-right')?></a></div><?php endif;?>
  <p class="explore-disclaimer"><?=lc_icon('shield-check')?> Recompensas dependem das metas e da aprovação previstas em cada campanha.</p>
 </div>
</div>
<?php },'explore');return true;
    }
    if ($method==='GET' && $path==='/cadastrar') {
        if ($current=lc_user()) lc_redirect($current['role']==='criador'?'/criador':'/dashboard');
        lc_page('Crie sua conta',function(){ ?>
<div class="lc-auth-deeplink"><span class="eyebrow">Bem-vindo ao League Cut</span><h1>Sua criatividade merece destaque.</h1><p>O formulário de cadastro está aberto em uma janela. Se você o fechou, pode <a href="/cadastrar">abrir novamente</a>.</p></div>
<?php });return true;
    }
    if ($method==='POST' && $path==='/cadastrar') {
        $name=lc_post('full_name',160);$email=mb_strtolower(lc_post('email',190));$password=(string)($_POST['password'] ?? '');
        $instagram=ltrim(lc_post('instagram',80),'@');$whatsapp=preg_replace('/\D/','',lc_post('whatsapp',24));
        $portfolio=lc_post('portfolio_url',500);$pix=lc_post('pix_key',150);
        if(mb_strlen($name)<3||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<10||strlen($password)>128||!preg_match('/^[a-zA-Z0-9._]{2,80}$/',$instagram)||strlen($whatsapp)<10||strlen($whatsapp)>13||strlen($pix)<4||($portfolio!==''&&!lc_valid_https($portfolio))){
            lc_flash('Confira seus dados: nome, e-mail, senha, Instagram, WhatsApp, portfólio e Pix.','error');lc_redirect('/cadastrar');
        }
        if(lc_one('SELECT id FROM users WHERE email=?',[$email])){lc_flash('Este e-mail já possui cadastro. Faça login.','error');lc_redirect('/entrar');}
        lc_query("INSERT INTO users(full_name,email,password_hash,instagram,whatsapp,portfolio_url,pix_key_enc,consent_whatsapp) VALUES(?,?,?,?,?,?,?,?)",
            [$name,$email,password_hash($password,PASSWORD_DEFAULT),$instagram,$whatsapp,$portfolio?:null,lc_encrypt($pix),lc_bool('consent_whatsapp')?1:0]);
        session_regenerate_id(true);$_SESSION['uid']=(int)lc_db()->lastInsertId();
        lc_realtime_emit('admin',null,'creator.registered','user',$_SESSION['uid']);
        lc_flash('Cadastro enviado! Aguarde a aprovação da equipe.');lc_redirect('/aguardando');
    }
    if ($method==='GET' && $path==='/entrar') {
        if($current=lc_user())lc_redirect($current['role']==='criador'?'/criador':'/dashboard');
        lc_page('Entrar',function(){ ?>
<div class="lc-auth-deeplink"><span class="eyebrow">Bem-vindo de volta</span><h1>Seu próximo clipe começa aqui.</h1><p>O login está aberto em uma janela. Se você o fechou, pode <a href="/entrar">abrir novamente</a>.</p></div>
<?php });return true;
    }
    if ($method==='POST' && $path==='/entrar') {
        $hash=hash('sha256',(string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $recent=(int)(lc_one("SELECT COUNT(*) AS total FROM login_attempts WHERE ip_hash=? AND created_at > DATE_SUB(NOW(),INTERVAL 15 MINUTE)",[$hash])['total']??0);
        if($recent>=10){lc_flash('Muitas tentativas. Aguarde 15 minutos.','error');lc_redirect('/entrar');}
        $email=mb_strtolower(lc_post('email',190));$user=lc_one('SELECT id,password_hash FROM users WHERE email=?',[$email]);
        if(!$user||!password_verify((string)($_POST['password']??''),$user['password_hash'])){
            lc_query('INSERT INTO login_attempts(ip_hash) VALUES(?)',[$hash]);
            lc_flash('E-mail ou senha inválidos.','error');lc_redirect('/entrar');
        }
        session_regenerate_id(true);$_SESSION['uid']=(int)$user['id'];
        $role=lc_one('SELECT role FROM users WHERE id=?',[(int)$user['id']])['role']??'clipador';
        lc_redirect($role==='criador'?'/criador':'/dashboard');
    }
    if ($method==='POST' && $path==='/sair') {
        $_SESSION=[];session_regenerate_id(true);lc_redirect('/');
    }
    if ($method==='GET' && $path==='/aguardando') {
        $u=lc_login_required(false);
        if($u['role']==='admin'||$u['status']==='approved') lc_redirect($u['role']==='criador'?'/criador':'/dashboard');
        lc_page('Status do cadastro',function()use($u){?>
<div class="container container-sm"><span class="eyebrow">Análise de cadastro</span>
<div class="large-surface"><h1><?= $u['status']==='pending'?'Estamos analisando seu perfil.':($u['status']==='rejected'?'Cadastro não aprovado.':'Conta suspensa.') ?></h1>
<p><?= $u['status']==='pending'?'Seu cadastro foi recebido. Nossa equipe verificará os dados; quando aprovado, você terá acesso ao painel.':'Entre em contato com a administração para obter informações.' ?></p>
<span class="tag tag-<?=lc_e($u['status'])?>"><?=lc_e(strtoupper($u['status']))?></span><div class="form-actions"><a class="btn btn-ghost" href="/explorar">Ver campanhas disponíveis</a></div></div></div>
<?php });return true;
    }
    if($method==='GET' && preg_match('~^/campanhas/([a-z0-9-]+)$~',$path,$m)){
        $c=lc_one("SELECT * FROM campaigns WHERE slug=? AND status='active'",[$m[1]]);
        $u=lc_user();
        if(!$c && (!$u||$u['role']!=='admin')) {http_response_code(404);lc_page('Não encontrada',fn()=>lc_empty('Campanha não encontrada','Talvez ela tenha sido encerrada.'));return true;}
        if(!$c) $c=lc_one('SELECT * FROM campaigns WHERE slug=?',[$m[1]]);
        if(!$c){http_response_code(404);lc_page('Não encontrada',fn()=>lc_empty('Campanha não encontrada','Confira o endereço.'));return true;}
        $networks=$u&&$u['role']==='clipador'&&$u['status']==='approved'?lc_all('SELECT * FROM social_accounts WHERE user_id=? ORDER BY created_at DESC',[$u['id']]):[];
        lc_page($c['title'],function()use($c,$u,$networks){?>
<div class="container lc-campaign-detail">
<div class="lc-campaign-detail-hero">
<?php if(lc_valid_https((string)$c['cover_url'])):?><img src="<?=lc_e($c['cover_url'])?>" alt="" class="lc-campaign-detail-cover" loading="eager"><?php endif;?>
<div class="lc-campaign-detail-text">
<a href="<?=$u&&$u['role']==='admin'?'/admin/campanhas':'/explorar'?>" class="lc-back-link"><?=lc_icon('arrow-left')?> Voltar ao catálogo</a>
<span class="eyebrow"><?=lc_e($c['category'])?> · <?=lc_e(strtoupper($c['status']))?></span>
<h1><?=lc_e($c['title'])?></h1>
<p><?=nl2br(lc_e($c['description']))?></p>
<?php if($u&&$u['role']==='admin'):?><a class="btn btn-ghost" href="/admin/campanhas/<?=(int)$c['id']?>/editar"><?=lc_icon('pencil')?> Editar campanha</a><?php endif;?>
<a class="btn btn-ghost" href="#lc-leaderboard-title"><?=lc_icon('trophy')?> Ver classificação</a>
</div></div>
<div class="rule-grid">
<div class="metric"><small>Por vídeo aprovado*</small><strong><?=lc_money($c['payment_per_video_cents'])?></strong></div>
<div class="metric"><small>Meta de vídeos/semana</small><strong><?=lc_e($c['min_videos_week'])?></strong></div>
<div class="metric"><small>Bônus por <?=number_format($c['milestone_views'],0,',','.')?> views</small><strong><?=lc_money($c['milestone_bonus_cents'])?></strong></div>
<div class="metric"><small>Líder de views no mês</small><strong><?=lc_money($c['monthly_leader_bonus_cents'])?></strong></div></div>
<?php lc_campaign_leaderboard((int)$c['id'],$u); ?>
<div class="panel"><h2>Regras da campanha</h2><p>Os vídeos precisam ser publicados na rede cadastrada, enviados com o link e aprovados pelo administrador. *O valor por vídeo só é liberado quando você atinge a quantidade mínima de vídeos aprovados na semana. Visualizações exigem conferência; o bônus mensal depende do fechamento do mês.</p></div>
<?php if(!$u): ?><a class="btn" href="/cadastrar">Quero participar →</a>
<?php elseif($u['role']==='clipador'&&$u['status']!=='approved'): ?><div class="alert">Seu perfil aguarda aprovação antes de enviar clipes.</div>
<?php elseif($u['role']==='clipador'):?>
<div class="panel"><h2>Enviar publicação</h2>
<?php if(!$networks):?><div class="alert">Cadastre primeiro seu perfil do Instagram ou TikTok em <a href="/redes">Minhas redes →</a>.</div><?php else:?>
<form id="lc-clip-form" method="post" action="/clipes" enctype="multipart/form-data"><?=lc_csrf()?><input type="hidden" name="campaign_id" value="<?=(int)$c['id']?>">
<div class="form-grid"><?php lc_field('Título do clipe','title','text','',true,'Ex.: A fala mais forte do episódio'); ?>
<label class="field"><span>Rede usada</span><select name="social_account_id" required><?php foreach($networks as $n):?><option value="<?=(int)$n['id']?>"><?=lc_e(ucfirst($n['network']).' · @'.$n['username'])?></option><?php endforeach;?></select></label>
<div class="full"><?php lc_field('Link do vídeo publicado','published_url','url','',true,'https://www.instagram.com/reel/...'); ?></div>
<label class="field full"><span>Vídeo original (obrigatório)</span><input id="lc-clip-file" type="file" name="video" accept=".mp4,.mov,.webm,video/mp4,video/quicktime,video/webm" required <?=lc_drive_ready()?'':'disabled'?>><small><?=lc_drive_ready()?'Obrigatório: MP4, MOV ou WebM. Arquivo deve respeitar os limites PHP/Nginx do servidor.':'Envios indisponíveis: o administrador precisa conectar o Google Drive.'?></small></label>
<div class="full clip-upload-progress" id="lc-upload-progress" hidden role="status" aria-live="polite">
  <div class="clip-upload-head"><span id="lc-upload-stage">Preparando vídeo…</span><strong id="lc-upload-percent">0%</strong></div>
  <progress id="lc-upload-meter" value="0" max="100" aria-label="Progresso do upload do vídeo">0%</progress>
  <small id="lc-upload-detail">O arquivo será transferido ao servidor e depois guardado no Google Drive.</small>
</div>
<div class="full clip-upload-error" id="lc-upload-error" hidden role="alert"></div>
</div><div class="form-actions"><button class="btn" id="lc-clip-submit" type="submit" <?=lc_drive_ready()?'':'disabled'?>><span>Enviar para conferência</span> <?=lc_icon('arrow-right')?></button></div></form>
<script defer src="/assets/clip-upload.js?v=9"></script>
<?php endif;?></div>
<?php endif;?></div>
<?php },'home');return true;
    }
    return false;
}
