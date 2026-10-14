<?php
declare(strict_types=1);

function lc_public_routes(string $method,string $path): bool {
    if ($method==='GET' && $path==='/') {
        $campaigns = lc_all("SELECT * FROM campaigns WHERE status='active' ORDER BY created_at DESC LIMIT 60");
        $featured = $campaigns[0] ?? null;
        lc_page('Explore campanhas',function()use($campaigns,$featured){ ?>
<section class="hero"><div class="hero-deco">▶</div><div class="hero-inner">
<span class="eyebrow">LeagueCut Originals • Comunidade criativa</span>
<h1>Seus vídeos.<br>Novas oportunidades.</h1>
<p>Escolha campanhas, crie conteúdos para as redes e receba pelas entregas e metas verificadas. Tudo em um só lugar.</p>
<div class="btn-row"><a class="btn" href="<?= $featured? '/campanhas/'.lc_e($featured['slug']) : '/cadastrar' ?>"><?= $featured?'Explorar destaque':'Quero participar' ?> →</a><a class="btn btn-ghost" href="/cadastrar">Seja um clipador</a></div>
</div></section>
<div class="container">
<section class="section"><div class="section-head"><h2>Em alta agora</h2><span class="tag"><?=count($campaigns)?> campanhas disponíveis</span></div>
<?php if(!$campaigns): lc_empty('Em breve, novas campanhas','Estamos preparando oportunidades. Crie sua conta e acompanhe os lançamentos.');
else: ?><div class="catalog catalog-horizontal"><?php foreach($campaigns as $campaign)lc_tile($campaign); ?></div><?php endif;?></section>
<section class="section large-surface"><span class="eyebrow">Como funciona</span><h2>Um espaço para quem cria.</h2><div class="rule-grid">
<div><strong>01 · Cadastre-se</strong><p>Preencha seus dados e aguarde a aprovação da equipe.</p></div>
<div><strong>02 · Escolha</strong><p>Encontre uma campanha e conheça as regras e recompensas.</p></div>
<div><strong>03 · Publique</strong><p>Envie seu vídeo e o link da publicação para conferência.</p></div>
<div><strong>04 · Receba</strong><p>Acompanhe metas, créditos e solicite saques disponíveis.</p></div>
</div></section>
</div>
<?php },'home'); return true;
    }
    if ($method==='GET' && $path==='/cadastrar') {
        if (lc_user()) lc_redirect('/dashboard');
        lc_page('Crie sua conta',function(){ ?>
<div class="auth-layout"><div class="auth-box"><span class="eyebrow">Comece por aqui</span><h1>Junte-se aos criadores.</h1><p>Preencha o cadastro para entrar na fila de aprovação.</p>
<div class="panel"><form action="/cadastrar" method="post"><?=lc_csrf()?><div class="form-grid">
<?php lc_field('Nome completo','full_name','text','',true,'Seu nome','autocomplete="name"'); ?>
<?php lc_field('E-mail','email','email','',true,'voce@exemplo.com','autocomplete="email"'); ?>
<?php lc_field('Senha (mínimo 10 caracteres)','password','password','',true,'Crie uma senha forte','minlength="10" autocomplete="new-password"'); ?>
<?php lc_field('Instagram (seu @)','instagram','text','',true,'@seuperfil'); ?>
<?php lc_field('WhatsApp com DDD','whatsapp','tel','',true,'85999999999'); ?>
<?php lc_field('Portfólio (link HTTPS, opcional)','portfolio_url','url','',false,'https://...'); ?>
<?php lc_field('Chave Pix','pix_key','text','',true,'CPF, e-mail, telefone ou chave aleatória'); ?>
<label class="field full"><span><input type="checkbox" name="consent_whatsapp" value="1" style="width:auto"> Aceito receber avisos operacionais sobre meu cadastro por WhatsApp.</span></label>
</div><div class="form-actions"><button class="btn" type="submit">Enviar cadastro →</button><a href="/entrar">Já tenho conta</a></div>
<p class="form-note">Seu cadastro fica pendente até aprovação manual. A chave Pix é armazenada criptografada.</p></form></div></div></div>
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
        lc_flash('Cadastro enviado! Aguarde a aprovação da equipe.');lc_redirect('/aguardando');
    }
    if ($method==='GET' && $path==='/entrar') {
        if(lc_user())lc_redirect('/dashboard');
        lc_page('Entrar',function(){ ?>
<div class="auth-layout"><div class="auth-box"><span class="eyebrow">Bem-vindo de volta</span><h1>Sua próxima campanha começa aqui.</h1><p>Entre para acompanhar suas entregas e recompensas.</p>
<div class="panel"><form method="post" action="/entrar"><?=lc_csrf()?><div class="form-grid">
<div class="full"><?php lc_field('E-mail','email','email','',true,'voce@exemplo.com','autocomplete="email"'); ?></div>
<div class="full"><?php lc_field('Senha','password','password','',true,'Sua senha','autocomplete="current-password"'); ?></div>
</div><div class="form-actions"><button class="btn" type="submit">Entrar →</button><a href="/cadastrar">Criar conta</a></div></form></div></div></div>
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
        lc_redirect('/dashboard');
    }
    if ($method==='POST' && $path==='/sair') {
        $_SESSION=[];session_regenerate_id(true);lc_redirect('/');
    }
    if ($method==='GET' && $path==='/aguardando') {
        $u=lc_login_required(false);
        if($u['role']==='admin'||$u['status']==='approved') lc_redirect('/dashboard');
        lc_page('Status do cadastro',function()use($u){?>
<div class="container container-sm"><span class="eyebrow">Análise de cadastro</span>
<div class="large-surface"><h1><?= $u['status']==='pending'?'Estamos analisando seu perfil.':($u['status']==='rejected'?'Cadastro não aprovado.':'Conta suspensa.') ?></h1>
<p><?= $u['status']==='pending'?'Seu cadastro foi recebido. Nossa equipe verificará os dados; quando aprovado, você terá acesso ao painel.':'Entre em contato com a administração para obter informações.' ?></p>
<span class="tag tag-<?=lc_e($u['status'])?>"><?=lc_e(strtoupper($u['status']))?></span><div class="form-actions"><a class="btn btn-ghost" href="/">Ver campanhas disponíveis</a></div></div></div>
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
<div class="container"><div class="page-heading"><span class="eyebrow"><?=lc_e($c['category'])?> · <?=lc_e(strtoupper($c['status']))?></span><h1><?=lc_e($c['title'])?></h1><p><?=nl2br(lc_e($c['description']))?></p></div>
<div class="rule-grid">
<div class="metric"><small>Por vídeo aprovado*</small><strong><?=lc_money($c['payment_per_video_cents'])?></strong></div>
<div class="metric"><small>Meta de vídeos/semana</small><strong><?=lc_e($c['min_videos_week'])?></strong></div>
<div class="metric"><small>Bônus por <?=number_format($c['milestone_views'],0,',','.')?> views</small><strong><?=lc_money($c['milestone_bonus_cents'])?></strong></div>
<div class="metric"><small>Líder de views no mês</small><strong><?=lc_money($c['monthly_leader_bonus_cents'])?></strong></div></div>
<div class="panel"><h2>Regras da campanha</h2><p>Os vídeos precisam ser publicados na rede cadastrada, enviados com o link e aprovados pelo administrador. *O valor por vídeo só é liberado quando você atinge a quantidade mínima de vídeos aprovados na semana. Visualizações exigem conferência; o bônus mensal depende do fechamento do mês.</p></div>
<?php if(!$u): ?><a class="btn" href="/cadastrar">Quero participar →</a>
<?php elseif($u['role']==='clipador'&&$u['status']!=='approved'): ?><div class="alert">Seu perfil aguarda aprovação antes de enviar clipes.</div>
<?php elseif($u['role']==='clipador'):?>
<div class="panel"><h2>Enviar publicação</h2>
<?php if(!$networks):?><div class="alert">Cadastre primeiro seu perfil do Instagram ou TikTok em <a href="/redes">Minhas redes →</a>.</div><?php else:?>
<form method="post" action="/clipes" enctype="multipart/form-data"><?=lc_csrf()?><input type="hidden" name="campaign_id" value="<?=(int)$c['id']?>">
<div class="form-grid"><?php lc_field('Título do clipe','title','text','',true,'Ex.: A fala mais forte do episódio'); ?>
<label class="field"><span>Rede usada</span><select name="social_account_id" required><?php foreach($networks as $n):?><option value="<?=(int)$n['id']?>"><?=lc_e(ucfirst($n['network']).' · @'.$n['username'])?></option><?php endforeach;?></select></label>
<div class="full"><?php lc_field('Link do vídeo publicado','published_url','url','',true,'https://www.instagram.com/reel/...'); ?></div>
<label class="field full"><span>Enviar vídeo original para Drive (opcional no início)</span><input type="file" name="video" accept=".mp4,.mov,.webm,video/mp4,video/quicktime,video/webm"><small><?=lc_drive_ready()?'Armazenamento privado do Drive habilitado. Limite por arquivo: 1 GB, sujeito a limites da hospedagem.':'Integração Drive ainda não configurada: envie o link sem anexar arquivo.'?></small></label>
</div><div class="form-actions"><button class="btn" type="submit">Enviar para conferência →</button></div></form>
<?php endif;?></div>
<?php endif;?></div>
<?php },'home');return true;
    }
    return false;
}
