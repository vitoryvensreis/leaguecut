<?php
declare(strict_types=1);

function lc_icon(string $name, string $class = ''): string {
    static $allowed = ["play","compass","layout-dashboard","library","wallet","share-2","shield-check","log-out","users-round","user-round-cog","clapperboard","chart-column","hand-coins","trophy","palette","chevron-right","arrow-right","external-link","lock-keyhole","check","circle-check","sparkles","folder-open","clock-3","plus","upload-cloud","settings-2","bell","activity","clipboard-check","layers","circle-dollar-sign","menu","video","globe","shield"];
    if (!in_array($name, $allowed, true)) $name = 'circle-check';
    $class = trim(preg_replace('/[^a-zA-Z0-9_ -]/', '', $class) ?? '');
    return '<svg class="lc-icon ' . lc_e($class) . '" aria-hidden="true" focusable="false"><use href="/assets/icons.svg#' . lc_e($name) . '"></use></svg>';
}

/** Portuguese, accessible status labels without changing persisted enum values. */
/** Escape sensitive display content and enable the page-wide visual privacy toggle. */
function lc_private(mixed $value): string {
    return '<span class="lc-private" data-lc-private><span class="lc-private-value">'.
       lc_e($value).'</span></span>';
}

function lc_status_label(string $status): string {
    return [
        'pending'=>'Pendente','approved'=>'Aprovado','rejected'=>'Rejeitado',
        'suspended'=>'Suspenso','requested'=>'Solicitado','processing'=>'Em análise',
        'paid'=>'Pago','draft'=>'Rascunho','active'=>'Ativa','paused'=>'Pausada',
        'ended'=>'Encerrada','sent'=>'Enviado','failed'=>'Falhou'
    ][$status]??ucfirst($status);
}

function lc_workspace_links(array $u): array {
    if($u['role']==='admin'){
        return [
          ['Visão geral','/admin','layout-dashboard'],
          ['Clipadores','/admin/clipadores','users-round'],
          ['Conferir clipes','/admin/clipes','clipboard-check'],
          ['Campanhas','/admin/campanhas','clapperboard'],
          ['Saques','/admin/financeiro','wallet'],
          ['Bônus mensal','/admin/bonus-mensal','trophy'],
          ['Pacotes para criadores','/admin/pacotes','layers'],
          ['Assinaturas','/admin/assinaturas','circle-dollar-sign'],
          ['Automações','/admin/automacoes','activity'],
          ['Conexão WhatsApp','/admin/whatsapp','share-2'],
          ['Banner da Home','/admin/home','layers'],
          ['Personalização','/admin/configuracoes','palette'],
          ['Integrações','/admin/integracoes','settings-2'],
          ['Administradores','/admin/administradores','shield-check'],
          ['Gerenciar contas','/admin/contas','user-round-cog'],
          ['Indicações e comissões','/admin/indicacoes','hand-coins'],
          ['Auditoria de contas','/admin/atividades','activity'],
          ['Acessos e operações','/admin/auditoria','list-checks'],
          ['Meus dados','/conta','user-round-cog'],
          ['Indicações','/indicacoes','users-round'],
          ['Segurança e 2FA','/seguranca/2fa','shield-check']
        ];
    }
    if($u['role']==='criador'){
        return [
          ['Minha área','/criador','layout-dashboard'],
          ['Pacotes mensais','/planos','layers'],
          ['Indicações','/indicacoes','users-round'],
          ['Segurança e 2FA','/seguranca/2fa','shield-check'],
        ];
    }
    return [
      ['Visão geral','/dashboard','layout-dashboard'],
      ['Explorar campanhas','/explorar','compass'],
      ['Ranking geral','/ranking','trophy'],
      ['Minha biblioteca','/biblioteca','library'],
      ['Minhas redes','/redes','share-2'],
      ['Financeiro','/financeiro','wallet'],
      ['Indicações','/indicacoes','users-round'],
          ['Segurança e 2FA','/seguranca/2fa','shield-check']
    ];
}
function lc_workspace_nav(array $u, string $current): void {
    $links=lc_workspace_links($u);
    $groups=$u['role']==='admin' ? ['Operação'=>array_slice($links,0,8),'Configuração'=>array_slice($links,8)] : ['Meu espaço'=>$links];
    foreach($groups as $label=>$items): ?>
      <div class="ws-nav-group">
        <p class="ws-nav-label"><?=lc_e($label)?></p>
        <?php foreach($items as [$name,$url,$icon]):
            $selected=$current===$url||($url!=='/admin'&&$url!=='/dashboard'&&$url!=='/explorar'&&str_starts_with($current,$url.'/')); ?>
          <a href="<?=lc_e($url)?>" class="ws-nav-link <?=$selected?'is-current':''?>" <?=$selected?'aria-current="page"':''?>>
            <?=lc_icon($icon)?><span><?=lc_e($name)?></span>
            <?php if($selected): ?><span class="ws-current-dot" aria-hidden="true"></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach;
}

function lc_page(string $title, callable $body, string $active = ''): void {
    $u = lc_user();
    $s = lc_settings();
    $brand = $s['brand_name'] ?? 'LeagueCut';
    $primary = $s['primary_color'] ?? '#e50914';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) $primary = '#e50914';
    $logo = (string)($s['logo_url'] ?? '');
    $flash = lc_take_flash();
    $workspace = $u && ($u['role']==='admin'||$u['status']==='approved') && in_array($active,['admin','dashboard','library','social','finance','creator'],true);
    $path = parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/';
    $areaTitle=$u && $u['role']==='admin'?'Administração':($u&&$u['role']==='criador'?'Área do criador':'Área do clipador');
    // Load only assets used on this page. Keep the base design system everywhere.
    $isHome=$active==='home';
    $isExplore=$active==='explore';
    $isHomeEditor=$path==='/admin/home';
    $needsLanding=$isHome||$isExplore;
    $needsBanner=$isHome||$isHomeEditor;
    $needsCampaignExperience=$isExplore||$path==='/admin/campanhas'||str_starts_with($path,'/campanhas/');
?><!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#101114"><meta name="description" content="LeagueCut — campanhas e recompensas para criadores de conteúdo">
<title><?=lc_e($title)?> · <?=lc_e($brand)?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css?v=10">
<link rel="stylesheet" href="/assets/v2.css?v=10">
<?php if($needsLanding): ?><link rel="stylesheet" href="/assets/landing.css?v=11"><?php endif; ?>
<?php if($needsBanner): ?><link rel="stylesheet" href="/assets/banner.css?v=11"><?php endif; ?>
<?php if($needsCampaignExperience): ?><link rel="stylesheet" href="/assets/campaign-experience.css?v=1"><?php endif; ?>
<?php if($workspace): ?>
<link rel="stylesheet" href="/assets/workspace.css?v=10">
<link rel="stylesheet" href="/assets/workspace-motion.css?v=1">
<link rel="stylesheet" href="/assets/realtime.css?v=10">
<?php endif; ?>
<link rel="stylesheet" href="/assets/polish.css?v=10">
<link rel="stylesheet" href="/assets/creator-packages.css?v=2">
<link rel="stylesheet" href="/assets/ux-upgrade.css?v=2">
<link rel="stylesheet" href="/assets/motion-glass.css?v=1">
<link rel="stylesheet" href="/assets/brand-scale-neon.css?v=1">
<link rel="stylesheet" href="/assets/arena.css?v=1">
<?php if($path==='/admin/bonus-mensal'): ?><link rel="stylesheet" href="/assets/monthly-bonuses.css?v=1"><?php endif; ?>
<?php if($u): ?><link rel="stylesheet" href="/assets/privacy-toggle.css?v=1"><?php endif; ?>
<?php if(in_array($path,['/indicacoes','/admin/indicacoes'],true)): ?><link rel="stylesheet" href="/assets/referrals.css?v=1"><?php endif; ?>
<?php if($path==='/seguranca/2fa'): ?><link rel="stylesheet" href="/assets/mfa-qr.css?v=1"><?php endif; ?>
<?php if(str_starts_with($path,"/admin/automacoes")||$path==="/admin/whatsapp"): ?><link rel="stylesheet" href="/assets/flow-editor.css?v=2">
<?php if(str_starts_with($path,"/admin/automacoes/")||$path==="/admin/automacoes/novo"): ?><link rel="stylesheet" href="/assets/flow-canvas-v2.css?v=1"><?php endif; ?><?php endif; ?>
<?php if(preg_match('~^/campanhas/|^/biblioteca$|^/admin/clipes$~',$path)): ?><link rel="stylesheet" href="/assets/clips.css?v=12"><?php endif; ?>
<?php if(in_array($path,['/admin/integracoes','/admin/clipes'],true)): ?><link rel="stylesheet" href="/assets/integrations.css?v=10"><?php endif; ?>
<style>:root{--accent:<?=$primary?>}</style>
<?php if($u): ?><script>document.documentElement.classList.add('lc-privacy-ready');</script><?php endif; ?>
<?php if($workspace): ?><meta name="lc-csrf" content="<?=lc_e(lc_token())?>"><?php endif;?>
</head>
<body class="<?=$workspace?'workspace-page':'public-page'?>">
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<header class="site-header <?=$workspace?'workspace-header':''?>">
  <div class="header-inner">
    <a class="brand" href="/" aria-label="<?=lc_e($brand)?>, página inicial">
      <?php if (lc_brand_logo_src($logo)): ?><img class="brand-image" src="<?=lc_e($logo)?>" alt="<?=lc_e($brand)?>" width="175" height="44" decoding="async"><?php else: ?><span class="brand-glyph"><?=lc_icon("play")?></span><span><?=lc_e($brand)?></span><?php endif; ?>
    </a>
    <?php if(!$workspace): ?><nav class="main-nav" aria-label="Navegação principal">
      <a class="<?=$active==='home'?'selected':''?>" <?=$active==='home'?'aria-current="page"':''?> href="/"><?=lc_icon("play")?> Início</a>
      <a class="<?=$active==='explore'?'selected':''?>" <?=$active==='explore'?'aria-current="page"':''?> href="/explorar"><?=lc_icon("compass")?> Campanhas</a>
      <a class="<?=$active==='ranking'?'selected':''?>" href="/ranking"><?=lc_icon('trophy')?> Ranking</a>
      <?php if($u && $u['role']==='criador'): ?><a class="<?=$active==='creator'?'selected':''?>" href="/criador"><?=lc_icon('layout-dashboard')?> Minha área</a><?php endif; ?>
      <?php if ($u && $u['status']==='approved' && $u['role']!=='criador'): ?>
      <a class="<?=$active==='dashboard'?'selected':''?>" <?=$active==='dashboard'?'aria-current="page"':''?> href="/dashboard"><?=lc_icon("layout-dashboard")?> Minha área</a>
      <a class="<?=$active==='library'?'selected':''?>" <?=$active==='library'?'aria-current="page"':''?> href="/biblioteca"><?=lc_icon("library")?> Biblioteca</a>
      <a class="<?=$active==='finance'?'selected':''?>" <?=$active==='finance'?'aria-current="page"':''?> href="/financeiro"><?=lc_icon("wallet")?> Financeiro</a>
      <a class="<?=$active==='social'?'selected':''?>" <?=$active==='social'?'aria-current="page"':''?> href="/redes"><?=lc_icon("share-2")?> Redes</a>
      <?php endif; ?>
      <?php if ($u && $u['role']==='admin'): ?><a class="<?=$active==='admin'?'selected':''?>" <?=$active==='admin'?'aria-current="page"':''?> href="/admin"><?=lc_icon("shield-check")?> Administração</a><?php endif; ?>
    </nav><?php else: ?><div class="workspace-breadcrumb"><?=lc_icon("layers")?> <?=lc_e($areaTitle)?> <span class="workspace-breadcrumb-slash">/</span> <?=lc_e($title)?></div><?php endif; ?>
    <div class="header-right">
      <?php if($workspace): ?>
      <details class="rt-notifications" id="rt-notifications">
        <summary aria-label="Atualizações" title="Atualizações"><?=lc_icon('bell')?><span class="rt-unread" data-realtime-unread hidden>0</span></summary>
        <div class="rt-notification-panel"><div class="rt-panel-head"><strong>Atualizações</strong><span class="rt-connection-state" data-realtime-connection>Conectando</span></div>
        <div class="rt-notification-list" data-realtime-feed><p>Nenhuma atualização recente.</p></div></div>
      </details>
      <a class="ws-site-link" href="/"><?=lc_icon("external-link")?> <span>Ver site</span></a>
      <?php endif; ?>
      <?php if ($u): ?>
      <span class="user-short"><?=lc_e(explode(' ', $u['full_name'])[0])?></span>
      <span class="avatar" title="<?=lc_e($u['full_name'])?>"><?=lc_e(mb_strtoupper(mb_substr($u['full_name'],0,1)))?></span>
      <form method="post" action="/sair" class="inline-form"><?=lc_csrf()?><button class="text-button" type="submit" aria-label="Sair da conta"><?=lc_icon("log-out")?> <span>Sair</span></button></form>
      <?php else: ?>
      <a class="header-login" href="/entrar">Entrar</a><a class="btn btn-sm" href="/cadastrar">Ser clipador <?=lc_icon("arrow-right")?></a>
      <?php endif; ?>
    </div>
  </div>
</header>
<?php if($workspace): ?>
<div class="workspace-shell">
  <aside class="workspace-sidebar" aria-label="Navegação da <?=lc_e($areaTitle)?>">
    <div class="ws-sidebar-top"><div class="ws-area-icon"><?=lc_icon($u['role']==='admin'?'shield-check':($u['role']==='criador'?'video':'clapperboard'))?></div><div><strong><?=lc_e($areaTitle)?></strong><span>Central LeagueCut</span></div></div>
    <nav aria-label="Menu de <?=lc_e($areaTitle)?>"><?php lc_workspace_nav($u,$path); ?></nav>
    <div class="ws-sidebar-bottom"><div class="ws-sidebar-avatar"><?=lc_e(mb_strtoupper(mb_substr($u['full_name'],0,1)))?></div><div><strong><?=lc_e($u['full_name'])?></strong><span><?=lc_e($u['role']==='admin'?'Administrador':($u['role']==='criador'?'Criador':'Clipador'))?></span></div></div>
  </aside>
  <div class="ws-mobile-menu"><details><summary><?=lc_icon("menu")?> Menu do painel <?=lc_icon("chevron-right")?></summary><nav aria-label="Menu mobile do painel"><?php lc_workspace_nav($u,$path); ?></nav></details></div>
  <div class="workspace-body">
<?php endif; ?>
<main id="conteudo" tabindex="-1">
  <?php if ($flash): ?><div class="toast <?=$flash['type']==='error'?'toast-error':''?>" role="<?=$flash['type']==='error'?'alert':'status'?>"><?=lc_e($flash['message'])?></div><?php endif; ?>
  <?php $body(); ?>
</main>
<?php if($workspace): ?></div></div><?php endif; ?>
<footer class="site-footer"><span><?=lc_e($brand)?> © <?=date('Y')?></span><nav aria-label="Informações legais" style="display:flex;gap:14px;flex-wrap:wrap"><a href="/planos">Planos para criadores</a><a href="/politica-de-privacidade">Privacidade</a><a href="/termos-de-uso">Termos de Uso</a><a href="/exclusao-de-dados">Exclusão de Dados</a></nav></footer>
<?php if(!$u):lc_auth_dialogs($path,$flash);endif;?>
<script src="/assets/ux-upgrade.js?v=1" defer></script>
<script src="/assets/motion-glass.js?v=1" defer></script>
<?php if(str_starts_with($path,"/admin/automacoes")||$path==="/admin/whatsapp"): ?><?php if(str_starts_with($path,"/admin/automacoes/")||$path==="/admin/automacoes/novo"): ?><script src="/assets/flow-canvas-v2.js?v=1" defer></script><?php endif; ?>
<script src="/assets/flow-editor.js?v=2" defer></script><?php endif; ?>
<?php if($workspace): ?><div class="rt-live-announcer" role="status" aria-live="polite" data-realtime-toast></div>
<script defer src="/assets/realtime.js?v=1" data-rt-enabled="<?=lc_env('REALTIME_WS_ENABLED','0')==='1'?'1':'0'?>"></script><?php endif;?>
<script src="/assets/input-masks.js?v=1" defer></script>
<?php if($u): ?><script src="/assets/privacy-toggle.js?v=1" defer></script><?php endif; ?>
</body></html><?php
}
function lc_tile(array $c): void {
    $cover = (string)($c['cover_url'] ?? '');
?><a class="campaign-card" href="/campanhas/<?=lc_e($c['slug'])?>">
  <div class="campaign-cover">
    <?php if(lc_valid_https($cover)):?><img src="<?=lc_e($cover)?>" alt="" loading="lazy" decoding="async"><?php else:?><div class="default-art"><span><?=lc_e(mb_strtoupper(mb_substr($c['title'],0,1)))?></span><i><?=lc_icon("play")?></i></div><?php endif;?>
    <div class="cover-overlay"></div><span class="cover-play"><?=lc_icon("play")?></span>
    <span class="cover-tag"><?=lc_e($c['status']==='active'?'EM ALTA':'CAMPANHA')?></span>
  </div>
  <div class="campaign-info"><strong><?=lc_e($c['title'])?></strong>
  <span><?=lc_money((int)$c['payment_per_video_cents'])?> / vídeo</span>
  <div class="card-meta"><span><?=lc_e($c['category'])?></span><span>Ver campanha <?=lc_icon("arrow-right")?></span></div></div>
</a><?php
}
function lc_clip_preview_modal(): void { ?>
<dialog id="lc-clip-preview" class="clip-preview-dialog" aria-labelledby="lc-preview-title">
 <div class="clip-preview-title"><strong id="lc-preview-title">Prévia do vídeo</strong><button type="button" id="lc-preview-close" class="btn btn-ghost btn-sm" aria-label="Fechar player">Fechar</button></div>
 <video id="lc-preview-player" playsinline controls preload="none" controlslist="nodownload" aria-label="Vídeo original armazenado no Google Drive"></video>
 <p class="hint">Vídeo privado: disponível apenas para o responsável ou um administrador. A reprodução depende do Google Drive.</p>
</dialog>
<script defer src="/assets/clip-preview.js?v=8"></script>
<?php }
function lc_field(string $label, string $name, string $type = 'text', string $value = '', bool $required = false, string $placeholder = '', string $extra = ''): void {
?><label class="field"><span><?=lc_e($label)?></span><input name="<?=lc_e($name)?>" type="<?=lc_e($type)?>" value="<?=lc_e($value)?>" placeholder="<?=lc_e($placeholder)?>" <?=$required?'required':''?> <?=$extra?>></label><?php
}
function lc_empty(string $title, string $details): void {
?><div class="empty"><span class="empty-icon"><?=lc_icon("sparkles")?></span><h3><?=lc_e($title)?></h3><p><?=lc_e($details)?></p></div><?php
}
function lc_heading(string $eyebrow, string $title, string $description=''): void {
?><div class="page-heading"><span class="eyebrow"><?=lc_e($eyebrow)?></span><h1><?=lc_e($title)?></h1><?php if($description):?><p><?=lc_e($description)?></p><?php endif;?></div><?php
}

/** Shared, progressively enhanced auth dialogs. Direct URLs remain functional without JS. */
function lc_auth_dialogs(string $path,?array $flash): void {
    foreach(['entrar'=>'login','cadastrar'=>'signup'] as $route=>$kind):
        $direct=$path==='/'.$route;
        $isSignup=$kind==='signup';
?>
<dialog id="lc-auth-<?=$kind?>" class="lc-auth-dialog" aria-labelledby="lc-auth-title-<?=$kind?>" <?=$direct?'open':''?>>
  <div class="lc-auth-dialog-inner">
    <button class="lc-auth-close" type="button" aria-label="Fechar janela" data-auth-close>×</button>
    <span class="eyebrow"><?=$isSignup?'Junte-se ao League Cut':'Bem-vindo de volta'?></span>
    <h2 id="lc-auth-title-<?=$kind?>"><?=$isSignup?'Junte-se aos criadores.':'Entre na sua arena.'?></h2>
    <p class="lc-auth-desc"><?=$isSignup?'Crie sua conta para participar de campanhas. Seu cadastro será analisado pela equipe.':'Acompanhe seus clipes, campanhas e recompensas em um só lugar.'?></p>
    <?php if($direct && $flash):?><p class="lc-auth-message <?=$flash['type']==='error'?'is-error':''?>" role="alert"><?=lc_e($flash['message'])?></p><?php endif;?>
    <form method="post" action="/<?=$route?>" class="lc-auth-form"><?=lc_csrf()?>
      <div class="form-grid">
      <?php if($isSignup):?>
        <?php lc_field('Nome completo','full_name','text','',true,'Seu nome','autocomplete="name"'); ?>
        <?php lc_field('Nickname público (opcional)','nickname','text','',false,'seunick','minlength="3" maxlength="24" pattern="[a-zA-Z][a-zA-Z0-9_]{2,23}" autocomplete="nickname"'); ?>
        <?php lc_field('E-mail','email','email','',true,'voce@exemplo.com','autocomplete="email"'); ?>
        <?php lc_field('Senha (mínimo 10 caracteres)','password','password','',true,'Crie uma senha forte','minlength="10" autocomplete="new-password"'); ?>
        <?php lc_field('Instagram (seu @)','instagram','text','',true,'@seuperfil'); ?>
        <?php lc_field('WhatsApp com DDD','whatsapp','tel','',true,'(85) 99999-9999','autocomplete="tel" inputmode="tel"'); ?>
        <?php lc_field('Portfólio (HTTPS, opcional)','portfolio_url','url','',false,'https://...'); ?>
        <div class="full"><?php lc_field('Chave Pix','pix_key','text','',true,'CPF, e-mail, telefone ou chave aleatória'); ?></div>
        <label class="field full lc-auth-consent"><span><input type="checkbox" name="consent_whatsapp" value="1"> Aceito receber avisos operacionais sobre meu cadastro por WhatsApp.</span></label>
      <?php else:?>
        <div class="full"><?php lc_field('E-mail ou nickname','email','text','',true,'seunick ou voce@exemplo.com','autocomplete="username"'); ?></div>
        <div class="full"><?php lc_field('Senha','password','password','',true,'Sua senha','autocomplete="current-password"'); ?></div>
      <?php endif;?>
      </div>
      <div class="form-actions">
        <button class="btn" type="submit"><?=$isSignup?'Enviar cadastro':'Entrar'?> <?=lc_icon('arrow-right')?></button>
        <a href="<?=$isSignup?'/entrar':'/cadastrar'?>" data-auth-link><?=$isSignup?'Já tenho conta':'Criar conta'?></a>
      </div>
      <?php if($isSignup):?><p class="form-note">Cadastro sujeito à aprovação. A chave Pix é armazenada criptografada.</p><?php endif;?>
    </form>
  </div>
</dialog>
<?php endforeach;
}
