<?php
declare(strict_types=1);
require_once __DIR__ . '/input-validation.php';
require_once __DIR__ . '/legal-entity.php';
require_once __DIR__ . '/clipper-ui.php';
require_once __DIR__ . '/admin-navigation.php';

function lc_icon(string $name, string $class = ''): string {
    static $allowed = ["play","compass","layout-dashboard","library","wallet","share-2","shield-check","log-out","users-round","user-round-cog","clapperboard","chart-column","hand-coins","trophy","palette","chevron-right","arrow-right","external-link","lock-keyhole","check","circle-check","sparkles","folder-open","clock-3","plus","upload-cloud","settings-2","bell","activity","clipboard-check","layers","circle-dollar-sign","menu","video","globe","shield","eye","eye-off"];
    if (!in_array($name, $allowed, true)) $name = 'circle-check';
    $class = trim(preg_replace('/[^a-zA-Z0-9_ -]/', '', $class) ?? '');
    return '<svg class="lc-icon ' . lc_e($class) . '" aria-hidden="true" focusable="false"><use href="/assets/icons.svg#' . lc_e($name) . '"></use></svg>';
}


/**
 * One small, accessible trophy for podium positions only.
 * No changes to ranking, rewards, profile information or calculation.
 */
function lc_rank_trophy(int $position): string {
    $medals=[
        1=>['gold','ouro'],
        2=>['silver','prata'],
        3=>['bronze','bronze']
    ];
    if(!isset($medals[$position]))return '';
    [$tone,$label]=$medals[$position];
    $title=$position.'º lugar · Troféu de '.$label;
    return '<span class="lc-rank-trophy lc-rank-trophy--'.$tone.'" data-rank-medal="'.$position.'" '.
        'role="img" aria-label="'.lc_e($title).'" title="'.lc_e($title).'">'.
        lc_icon('trophy','lc-rank-trophy-icon').'</span>';
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
        $links=[
          ['Visão geral','/admin','layout-dashboard'],
          ['Clipadores','/admin/clipadores','users-round'],
          ['Conferir clipes','/admin/clipes','clipboard-check'],
          ['Chamados','/admin/chamados','clipboard-check'],
          ['Relatórios suporte','/admin/chamados/relatorios','chart-column'],
          ['Campanhas','/admin/campanhas','clapperboard'],
          ['Caixa da plataforma','/admin/financeiro/caixa','circle-dollar-sign'],
          ['Saques','/admin/financeiro','wallet'],
          ['Pagamentos Asaas','/admin/asaas','wallet'],
          ['Bônus mensal','/admin/bonus-mensal','trophy'],
          ['Pacotes para criadores','/admin/pacotes','layers'],
          ['Assinaturas','/admin/assinaturas','circle-dollar-sign'],
          ['Automações','/admin/automacoes','activity'],
          ['Conexão WhatsApp','/admin/whatsapp','share-2'],
          ['Banner da Home','/admin/home','layers'],
          ['Banners do clipador','/admin/banners-clipador','layers'],
          ['Personalização','/admin/configuracoes','palette'],
          ['SEO e compartilhamento','/admin/seo','globe'],
          ['Integrações','/admin/integracoes','settings-2'],
          ['Administradores','/admin/administradores','shield-check'],
          ['Gerenciar contas','/admin/contas','user-round-cog'],
          ['Funções e permissões','/admin/funcoes','shield-check'],
           ['Modo demonstração','/admin/demo','sparkles'],
          ['Indicações e comissões','/admin/indicacoes','hand-coins'],
          ['Auditoria de contas','/admin/atividades','activity'],
          ['Acessos e operações','/admin/auditoria','clipboard-check'],
          ['Solicitações LGPD','/admin/privacidade','shield-check'],
          ['Meus dados','/conta','user-round-cog'],
          ['Indicações','/indicacoes','users-round'],
          ['Enviar notificações','/admin/notificacoes','bell'],
          ['Notificações','/notificacoes','bell'],
          ['Segurança e 2FA','/seguranca/2fa','shield-check']
        ];
        return array_values(array_filter($links,static function(array $link)use($u):bool{
            return !str_starts_with($link[1],'/admin') || !function_exists('lc_admin_can_url') || lc_admin_can_url($u,$link[1]);
        }));
    }
    if($u['role']==='criador'){
        return [
          ['Minha área','/criador','layout-dashboard'],
          ['Pacotes mensais','/planos','layers'],
          ['Suporte','/suporte','circle-check'],
          ['Indicações','/indicacoes','users-round'],
          ['Notificações','/notificacoes','bell'],
          ['Segurança e 2FA','/seguranca/2fa','shield-check'],
          ['Meus direitos LGPD','/privacidade/solicitacoes','shield-check'],
          ['Dispositivos conectados','/seguranca/dispositivos','shield-check'],
        ];
    }
    return [
      ['Visão geral','/dashboard','layout-dashboard'],
      ['Explorar campanhas','/explorar','compass'],
      ['Feed','/feed','clapperboard'],
      ['Ranking geral','/ranking','trophy'],
      ['Minha biblioteca','/biblioteca','library'],
      ['Minhas redes','/redes','share-2'],
      ['Financeiro','/financeiro','wallet'],
      ['Suporte','/suporte','circle-check'],
      ['Indicações','/indicacoes','users-round'],
          ['Notificações','/notificacoes','bell'],
          ['Segurança e 2FA','/seguranca/2fa','shield-check']
    ];
}
function lc_workspace_nav(array $u, string $current): void {
    if($u['role']==='admin'){lc_admin_sidebar_navigation($u,$current);return;}
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
    <?php endforeach; ?>
<?php
}

function lc_page(string $title, callable $body, string $active = ''): void {
    $u = lc_user();
    $s = lc_settings();
    $brand = $s['brand_name'] ?? 'LeagueCut';
    $primary = $s['primary_color'] ?? '#e50914';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) $primary = '#e50914';
    $logo = (string)($s['logo_url'] ?? '');
    $flash = lc_take_flash();
    $avatarSrc=$u&&function_exists('lc_avatar_src')?lc_avatar_src((int)$u['id']):'';
    $workspace = $u && ($u['role']==='admin'||$u['status']==='approved') && (in_array($active,['admin','dashboard','library','social','finance','creator'],true)||$u['role']==='clipador');
    $path = parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/';
    $seo=lc_seo_meta($title,$s,$path,(int)(http_response_code()?:200));
    $activeAppIcon=lc_pwa_active_icon($s);
    $appIconRev=lc_pwa_icon_revision($activeAppIcon);
    $areaTitle=$u && $u['role']==='admin'?'Administração':($u&&$u['role']==='criador'?'Área do criador':'Área do clipador');
    // Load only assets used on this page. Keep the base design system everywhere.
    $isHome=$active==='home';
    $isExplore=$active==='explore';
    $isHomeEditor=$path==='/admin/home';
    $needsLanding=$isHome||$isExplore;
    $needsBanner=$isHome||$isHomeEditor;
    $needsCampaignExperience=$isExplore||$path==='/admin/campanhas'||str_starts_with($path,'/campanhas/');
?><!doctype html>
<html lang="pt-BR" data-lc-user-id="<?=$u?(int)$u['id']:0?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?=lc_e($primary)?>">
<link rel="manifest" href="/pwa/manifest?v=<?=lc_e($appIconRev)?>">
<meta name="application-name" content="LeagueCut">
<meta name="apple-mobile-web-app-title" content="LeagueCut">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<?php if($activeAppIcon): ?>
<link rel="icon" <?=$activeAppIcon['type']?'type="'.lc_e($activeAppIcon['type']).'"':''?> href="<?=lc_e($activeAppIcon['src'])?>">
<link rel="shortcut icon" href="<?=lc_e($activeAppIcon['src'])?>">
<link rel="apple-touch-icon" href="<?=lc_e($activeAppIcon['src'])?>">
<?php else: ?>
<link rel="icon" type="image/svg+xml" href="/assets/leaguecut-icon.svg?v=3">
<link rel="icon" type="image/png" sizes="32x32" href="/pwa/icon/32?v=4">
<link rel="icon" type="image/png" sizes="48x48" href="/pwa/icon/48?v=4">
<link rel="shortcut icon" href="/favicon.ico?v=3" type="image/x-icon">
<link rel="apple-touch-icon" sizes="180x180" href="/pwa/icon/180?v=4">
<?php endif; ?>
<title><?=lc_e($seo['title'])?></title>
<?php lc_seo_head($seo); ?>
<!-- System font fallback avoids automatic third-party font requests before optional consent. -->
<link rel="stylesheet" href="/assets/app.css?v=10">
<link rel="stylesheet" href="/assets/v2.css?v=10">
<?php if($needsLanding): ?><link rel="stylesheet" href="/assets/landing.css?v=11"><?php endif; ?>
<?php if($needsBanner): ?><link rel="stylesheet" href="/assets/banner.css?v=11"><?php endif; ?>
<?php if($needsCampaignExperience): ?><link rel="stylesheet" href="/assets/campaign-experience.css?v=1"><?php endif; ?>
<?php if($path==='/feed'): ?><link rel="stylesheet" href="/assets/social-feed.css?v=4"><?php endif; ?>
<?php if($path==='/admin/campanhas'||str_starts_with($path,'/admin/campanhas/')): ?><link rel="stylesheet" href="/assets/campaign-cover-admin.css?v=1"><?php endif; ?>
<?php if($workspace): ?>
<link rel="stylesheet" href="/assets/workspace.css?v=10">
<link rel="stylesheet" href="/assets/workspace-motion.css?v=1">
<link rel="stylesheet" href="/assets/realtime.css?v=10">
<?php endif; ?>
<link rel="stylesheet" href="/assets/polish.css?v=10">
<link rel="stylesheet" href="/assets/creator-packages.css?v=2">
<link rel="stylesheet" href="/assets/ux-upgrade.css?v=2">
<link rel="stylesheet" href="/assets/signup-dialog-polish.css?v=2">
<link rel="stylesheet" href="/assets/auth-mfa-modal.css?v=2">
<link rel="stylesheet" href="/assets/motion-glass.css?v=1">
<link rel="stylesheet" href="/assets/brand-scale-neon.css?v=1">
<link rel="stylesheet" href="/assets/arena.css?v=1">
<?php if($path==='/admin/seo'): ?><link rel="stylesheet" href="/assets/seo-admin.css?v=1"><?php endif; ?>
<link rel="stylesheet" href="/assets/b4b-arena-polish.css?v=1">
<link rel="stylesheet" href="/assets/rank-trophies.css?v=1">
<?php if($path==='/admin/bonus-mensal'): ?><link rel="stylesheet" href="/assets/monthly-bonuses.css?v=1"><?php endif; ?>
<?php if($u): ?><link rel="stylesheet" href="/assets/privacy-toggle.css?v=2"><?php endif; ?>
<?php if(in_array($path,['/indicacoes','/admin/indicacoes'],true)): ?><link rel="stylesheet" href="/assets/referrals.css?v=1"><?php endif; ?>
<?php if($path==='/seguranca/2fa'): ?><link rel="stylesheet" href="/assets/mfa-qr.css?v=1"><?php endif; ?>
<?php if(str_starts_with($path,"/admin/automacoes")||$path==="/admin/whatsapp"): ?><link rel="stylesheet" href="/assets/flow-editor.css?v=2">
<?php if(str_starts_with($path,"/admin/automacoes/")||$path==="/admin/automacoes/novo"): ?><link rel="stylesheet" href="/assets/flow-canvas-v2.css?v=1"><?php endif; ?><?php endif; ?>
<?php if(preg_match('~^/campanhas/|^/biblioteca$|^/admin/clipes$~',$path)): ?><link rel="stylesheet" href="/assets/clips.css?v=12"><?php endif; ?>
<?php if(in_array($path,['/admin/integracoes','/admin/clipes'],true)): ?><link rel="stylesheet" href="/assets/integrations.css?v=10"><?php endif; ?>
<link rel="stylesheet" href="/assets/b6-layout.css?v=3">
<link rel="stylesheet" href="/assets/clipper-ux-basics.css?v=1">
<link rel="stylesheet" href="/assets/clipper-ux.css?v=1">
<link rel="stylesheet" href="/assets/interface-refine.css?v=1">
<?php if($workspace): ?><link rel="stylesheet" href="/assets/admin-navigation.css?v=2"><?php endif; ?>
<link rel="stylesheet" href="/assets/pwa-install.css?v=4">
<?php if($path==='/admin/funcoes'): ?><link rel="stylesheet" href="/assets/admin-roles.css?v=1"><?php endif; ?>
<link rel="stylesheet" href="/assets/privacy-consent.css?v=1">
<?php if($path==="/suporte" || str_starts_with($path,"/suporte/") || str_starts_with($path,"/admin/chamados") || $path==="/admin/notificacoes"): ?><link rel="stylesheet" href="/assets/support.css?v=1"><?php endif; ?>
<link rel="stylesheet" href="/assets/paint-mask-surfaces.css?v=1">
<link rel="stylesheet" href="/assets/stable-scroll-layout.css?v=1">
<link rel="stylesheet" href="/assets/form-system.css?v=1">
<style>:root{--accent:<?=$primary?>}</style>
<?php if($u): ?><script>document.documentElement.classList.add('lc-privacy-ready');</script><?php endif; ?>
<?php if($workspace): ?><meta name="lc-csrf" content="<?=lc_e(lc_token())?>"><?php endif;?>
</head>
<body class="<?=$workspace?'workspace-page':'public-page'?> <?=$u&&$u['role']==='clipador'&&$u['status']!=='approved'?'lc-awaiting-only':''?> <?=$u&&$u['role']==='clipador'&&$path==='/dashboard'?'lc-clipper-dashboard-page':''?>">
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<header class="site-header <?=$workspace?'workspace-header':''?>">
  <div class="header-inner">
    <a class="brand" href="<?=!$u?'/':($u['role']==='admin'?'/admin':($u['role']==='criador'?'/criador':($u['status']==='approved'?'/dashboard':'/aguardando')))?>" aria-label="<?=lc_e($brand)?>, minha área">
      <?php if (lc_brand_logo_src($logo)): ?><img class="brand-image" src="<?=lc_e($logo)?>" alt="<?=lc_e($brand)?>" width="175" height="44" decoding="async"><?php else: ?><span class="brand-glyph"><?=lc_icon("play")?></span><span><?=lc_e($brand)?></span><?php endif; ?>
    </a>
    <?php if(!$workspace): ?><nav class="main-nav" aria-label="Navegação principal">
      <a class="<?=$active==='home'?'selected':''?>" <?=$active==='home'?'aria-current="page"':''?> href="/"><?=lc_icon("play")?> Início</a>
      <a class="<?=$active==='explore'?'selected':''?>" <?=$active==='explore'?'aria-current="page"':''?> href="/explorar"><?=lc_icon("compass")?> Campanhas</a>
      <a class="<?=$active==='feed'?'selected':''?>" <?=$active==='feed'?'aria-current="page"':''?> href="/feed"><?=lc_icon("clapperboard")?> Feed</a>
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
      <?php if($u && $workspace): ?><button type="button" id="lc-privacy-toggle" class="lc-privacy-header-btn" aria-label="Mostrar dados" aria-pressed="false" title="Mostrar dados"><?=lc_icon("eye")?></button><?php endif; ?>
      <?php if($workspace): ?>
      <details class="rt-notifications" id="rt-notifications">
        <summary aria-label="Atualizações" title="Atualizações"><?=lc_icon('bell')?><span class="rt-unread" data-realtime-unread hidden>0</span></summary>
        <div class="rt-notification-panel"><div class="rt-panel-head"><strong>Atualizações</strong><span class="rt-connection-state" data-realtime-connection>Conectando</span></div>
        <div class="rt-notification-list" data-realtime-feed><p>Nenhuma atualização recente.</p></div></div>
      </details>
      <?php if($u['role']==='clipador'): ?>
       <?php $position=null;foreach(lc_public_ranking(50) as $rankIndex=>$person){if((string)($person['nickname']??'')===(string)($u['nickname']??'')){$position=$rankIndex+1;break;}} ?>
       <a class="ws-site-link ws-rank-link" href="/ranking"><?=lc_icon('trophy')?> <span><?=$position!==null?$position.'º no ranking': 'Sem posição no ranking'?></span></a>
       <?php else: ?><a class="ws-site-link" href="/"><?=lc_icon("external-link")?> <span>Ver site</span></a><?php endif; ?>
      <?php endif; ?>
      <?php if ($u): ?>
      <span class="user-short"><?=lc_e(explode(' ', $u['full_name'])[0])?></span>
      <a class="avatar" href="/conta" title="Alterar foto do perfil"><?php if($avatarSrc):?><img src="<?=lc_e($avatarSrc)?>" alt="" class="lc-user-avatar-photo"><?php else:?><?=lc_e(mb_strtoupper(mb_substr($u['full_name'],0,1)))?><?php endif;?></a>
      <form method="post" action="/sair" class="inline-form"><?=lc_csrf()?><button class="text-button" type="submit" aria-label="Sair da conta"><?=lc_icon("log-out")?> <span>Sair</span></button></form>
      <?php else: ?>
      <a class="header-login" href="/entrar">Entrar</a><a class="btn btn-sm" href="/cadastrar">Ser clipador <?=lc_icon("arrow-right")?></a>
      <?php endif; ?>
    </div>
  </div>
</header>
<?php if($workspace): ?>
<div class="workspace-shell">
  <?php if($u['role']==='clipador'): ?>
  <nav class="lc-clipper-mobile-dock" aria-label="Ações rápidas do clipador">
    <a class="lc-dock-link <?=$path==='/conta'?'is-active':''?>" href="/conta" <?=$path==='/conta'?'aria-current="page"':''?>><?=lc_icon('user-round-cog')?><span>Minha conta</span></a>
    <button class="lc-dock-create" type="button" data-clip-open aria-label="Publicar clipe" title="Publicar clipe"><?=lc_icon('plus')?></button>
    <button class="lc-dock-link lc-dock-menu" type="button" data-sidebar-toggle aria-controls="lc-workspace-sidebar" aria-expanded="false"><?=lc_icon('menu')?><span>Menu</span></button>
  </nav>
  <?php else: ?>
  <button type="button" class="ws-sidebar-mobile-toggle" data-sidebar-toggle aria-controls="lc-workspace-sidebar" aria-expanded="false"><?=lc_icon('menu')?> Menu</button>
  <?php endif; ?>
  <button type="button" class="ws-sidebar-backdrop" data-sidebar-close aria-label="Fechar menu" hidden></button>
  <aside id="lc-workspace-sidebar" class="workspace-sidebar" aria-label="Navegação da <?=lc_e($areaTitle)?>">
    <div class="ws-sidebar-top"><div class="ws-area-icon"><?=lc_icon($u['role']==='admin'?'shield-check':($u['role']==='criador'?'video':'clapperboard'))?></div><div><strong><?=lc_e($areaTitle)?></strong><span>Central LeagueCut</span></div></div>
    <?php if($u['role']==='clipador'): ?><button type="button" class="ws-publish-clip" data-clip-open><?=lc_icon('upload-cloud')?> Publicar clipe <?=lc_icon('plus')?></button><?php endif; ?>
    <nav aria-label="Menu de <?=lc_e($areaTitle)?>"><?php lc_workspace_nav($u,$path); ?></nav>
    <div class="ws-sidebar-bottom"><a class="ws-sidebar-avatar" href="/conta" title="Alterar foto de perfil"><?php if($avatarSrc):?><img src="<?=lc_e($avatarSrc)?>" alt="" class="lc-user-avatar-photo"><?php else:?><?=lc_e(mb_strtoupper(mb_substr($u['full_name'],0,1)))?><?php endif;?></a><div><strong><?=lc_e($u['full_name'])?></strong><span><?=lc_e($u['role']==='admin'?'Administrador':($u['role']==='criador'?'Criador':'Clipador'))?></span></div></div>
  </aside>

  <div class="workspace-body">
<?php endif; ?>
<main id="conteudo" tabindex="-1">
  <?php if ($flash): ?><div class="toast <?=$flash['type']==='error'?'toast-error':''?>" role="<?=$flash['type']==='error'?'alert':'status'?>"><?=lc_e($flash['message'])?></div><?php endif; ?>
  <?php if($workspace && $u['role']==='admin')lc_admin_context_tabs($u,$path); ?>
  <?php $body(); ?>
</main>
<?php if($workspace): ?></div></div><?php endif; ?>
<footer class="site-footer"><div class="lc-footer-company">
<span><?=lc_e($brand)?> © <?=date('Y')?></span>
<small>Um produto da <?=lc_e(lc_legal_controller()['business_name'])?> · CNPJ <?=lc_e(lc_legal_controller()['cnpj'])?></small>
</div>
<button type="button" class="btn btn-ghost btn-sm" data-pwa-install hidden>Instalar LeagueCut</button>
<dialog class="lc-pwa-help" id="lc-pwa-install-help" aria-labelledby="lc-pwa-help-title">
  <div class="lc-pwa-help-head"><h2 id="lc-pwa-help-title">Instalar LeagueCut</h2><button type="button" class="btn btn-ghost btn-sm" data-pwa-close aria-label="Fechar instruções de instalação">Fechar</button></div>
  <p data-pwa-instructions>Abra o menu do navegador e escolha a opção para instalar este site como aplicativo.</p>
  <p class="hint" data-pwa-status aria-live="polite"></p>
</dialog>
<nav class="lc-footer-nav" aria-label="Informações legais">
<a href="/planos">Planos para criadores</a><a href="/politica-de-privacidade">Privacidade</a>
<a href="/termos-de-uso">Termos de Uso</a><a href="/exclusao-de-dados">Exclusão de Dados</a>
<a href="/preferencias-de-privacidade">Preferências de cookies</a><?php if($u): ?><a href="/privacidade/solicitacoes">Meus direitos LGPD</a><?php endif;?></nav>
<?php $footerSocials=lc_social_footer_links($s); if($footerSocials): ?>
<nav class="lc-footer-socials" aria-label="Redes sociais oficiais">
<?php foreach($footerSocials as $social): ?><a href="<?=lc_e($social['url'])?>" target="_blank" rel="noopener noreferrer external"><?=lc_icon('external-link')?> <?=lc_e($social['label'])?></a><?php endforeach;?></nav>
<?php endif; ?></footer>
<?php if($workspace && $u['role']==='clipador')lc_quick_clip_modal($u); ?>
<?php if(!$u):lc_auth_dialogs($path,$flash);endif;?>
<?php lc_privacy_banner(); ?>
<script src="/assets/ux-upgrade.js?v=3" defer></script>
<?php if($path==='/feed'): ?><script src="/assets/social-feed.js?v=4" defer></script><?php endif; ?>
<script src="/assets/signup-nickname.js?v=1" defer></script>
<script src="/assets/auth-mfa-modal.js?v=1" defer></script>
<script src="/assets/motion-glass.js?v=1" defer></script>
<?php if(str_starts_with($path,"/admin/automacoes")||$path==="/admin/whatsapp"): ?><?php if(str_starts_with($path,"/admin/automacoes/")||$path==="/admin/automacoes/novo"): ?><script src="/assets/flow-canvas-v2.js?v=1" defer></script><?php endif; ?>
<script src="/assets/flow-editor.js?v=5" defer></script><?php endif; ?>
<?php if($workspace): ?><div class="rt-live-announcer" role="status" aria-live="polite" data-realtime-toast></div>
<script defer src="/assets/realtime.js?v=1" data-rt-enabled="<?=lc_env('REALTIME_WS_ENABLED','0')==='1'?'1':'0'?>"></script><?php endif;?>
<script src="/assets/pwa.js?v=4" defer></script>
<?php if($path==="/notificacoes" && $u): ?><script src="/assets/background-push.js?v=1" defer></script><?php endif; ?>
<?php if($path==="/admin/notificacoes" && $u && $u['role']==='admin'): ?><script src="/assets/admin-notices.js?v=1" defer></script><?php endif; ?>
<?php if($u): ?><script src="/assets/notification-preferences.js?v=1" defer></script><?php endif; ?>
<script src="/assets/form-system.js?v=1" defer></script>
<script src="/assets/input-masks.js?v=2" defer></script>
<?php if($u && in_array($path,['/admin/seo','/admin/configuracoes','/admin/banners-clipador'],true)): ?><script src="/assets/personalization-preview.js?v=1" defer></script><?php endif; ?>
<?php if($u): ?><script src="/assets/privacy-toggle.js?v=3" defer></script><?php endif; ?>
<?php if($workspace): ?><script src="/assets/clipper-ux.js?v=1" defer></script><script src="/assets/admin-navigation.js?v=1" defer></script><?php endif; ?>
</body></html><?php
}
function lc_tile(array $c): void {
    $cover = (string)($c['cover_url'] ?? '');
?><?php $isDemo=!empty($c['is_demo']); ?><a class="campaign-card" href="<?=$isDemo?'/demo/campanhas/':'/campanhas/'?><?=lc_e($c['slug'])?>">
  <div class="campaign-cover">
    <?php if(lc_campaign_cover_valid($cover)):?><img src="<?=lc_e($cover)?>" alt="" loading="lazy" decoding="async"><?php else:?><div class="default-art"><span><?=lc_e(mb_strtoupper(mb_substr($c['title'],0,1)))?></span><i><?=lc_icon("play")?></i></div><?php endif;?>
    <div class="cover-overlay"></div><span class="cover-play"><?=lc_icon("play")?></span>
    <?php $tileMax=isset($c['max_clippers'])?(int)$c['max_clippers']:0;$tileFull=$tileMax>0&&isset($c['participants'])&&(int)$c['participants']>=$tileMax; ?>
    <span class="cover-tag <?=$tileFull?'is-full':''?>"><?=!empty($c['is_demo'])?'DEMONSTRAÇÃO':($tileFull?'VAGAS ESGOTADAS':lc_e($c['status']==='active'?'EM ALTA':'CAMPANHA'))?></span>
  </div>
  <div class="campaign-info"><strong><?=lc_e($c['title'])?></strong>
  <span><?=!empty($c['is_demo'])?'Valor ilustrativo: ':''?><?=lc_money((int)$c['payment_per_video_cents'])?> / vídeo<?php if($tileMax>0&&!$tileFull&&isset($c['participants'])):?> · <?=max(0,$tileMax-(int)$c['participants'])?> vagas<?php endif;?></span>
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
    // Whitelist currency fields. Never apply currency formatting to percent inputs.
    $isMoney=$type==='text'&&in_array($name,['amount','bonus_amount','payment_per_video','milestone_bonus','monthly_leader_bonus','monthly_price','fixed_value'],true);
    $mask=$isMoney?'data-lc-mask="money"':'';
    $inputMode=$isMoney&&!str_contains($extra,'inputmode=')?'inputmode="decimal"':'';
?><label class="field"><span><?=lc_e($label)?></span><input name="<?=lc_e($name)?>" type="<?=lc_e($type)?>" value="<?=lc_e($value)?>" placeholder="<?=lc_e($placeholder)?>" <?=$required?'required':''?> <?=$mask?> <?=$inputMode?> <?=$extra?>></label><?php
}
function lc_empty(string $title, string $details): void {
?><div class="empty"><span class="empty-icon"><?=lc_icon("sparkles")?></span><h3><?=lc_e($title)?></h3><p><?=lc_e($details)?></p></div><?php
}
function lc_heading(string $eyebrow, string $title, string $description=''): void {
?><div class="page-heading"><span class="eyebrow"><?=lc_e($eyebrow)?></span><h1><?=lc_e($title)?></h1><?php if($description):?><p><?=lc_e($description)?></p><?php endif;?></div><?php
}

/**
 * Reusable form field (label, optional prefix, help text and server error).
 * Options: type, value, required, placeholder, attrs (raw, trusted), help, error, prefix, full, id.
 */
function lc_form_field(string $label, string $name, array $o = []): void {
    static $used = [];
    $id = $o['id'] ?? 'f-' . preg_replace('/[^a-z0-9_-]/i', '-', $name) . '-' . substr(md5($label . $name), 0, 5);
    // Login and signup dialogs live on the same page: ids must stay unique.
    $base = $id; $n = 2;
    while (isset($used[$id])) $id = $base . '-' . $n++;
    $used[$id] = true;
    $error = (string)($o['error'] ?? '');
    $help = (string)($o['help'] ?? '');
    $describe = trim(($help !== '' ? $id . '-help ' : '') . $id . '-msg');
    $required = !empty($o['required']);
?><div class="field lc-ff<?=!empty($o['full'])?' full':''?><?=$error!==''?' is-invalid':''?>" data-ff>
  <label for="<?=lc_e($id)?>"><?=lc_e($label)?><?php if(!$required && empty($o['hide_optional'])):?> <em class="lc-ff-optional">opcional</em><?php endif;?></label>
  <div class="lc-ff-control<?=!empty($o['prefix'])?' has-prefix':''?>">
    <?php if(!empty($o['prefix'])):?><span class="lc-ff-prefix" aria-hidden="true"><?=lc_e($o['prefix'])?></span><?php endif;?>
    <input id="<?=lc_e($id)?>" name="<?=lc_e($name)?>" type="<?=lc_e($o['type'] ?? 'text')?>" value="<?=lc_e($o['value'] ?? '')?>" placeholder="<?=lc_e($o['placeholder'] ?? '')?>" aria-describedby="<?=lc_e($describe)?>" <?=$required?'required':''?> <?=$error!==''?'aria-invalid="true"':''?> <?=$o['attrs'] ?? ''?>>
  </div>
  <?php if($help!==''):?><small class="lc-ff-help" id="<?=lc_e($id)?>-help"><?=lc_e($help)?></small><?php endif;?>
  <small class="lc-ff-msg" id="<?=lc_e($id)?>-msg" role="status" aria-live="polite"><?=lc_e($error)?></small>
</div><?php
}
/** Pix key: type selector + input whose mask follows the chosen type. */
function lc_form_pix_field(string $label, string $typeName, string $keyName, array $o = []): void {
    $selected = strtoupper((string)($o['type'] ?? ''));
    $error = (string)($o['error'] ?? '');
    $required = !empty($o['required']);
?><div class="field lc-ff lc-pix-field<?=!empty($o['full'])?' full':''?><?=$error!==''?' is-invalid':''?>" data-ff data-pix-group>
  <label for="f-<?=lc_e($keyName)?>"><?=lc_e($label)?></label>
  <div class="lc-pix-row">
    <select name="<?=lc_e($typeName)?>" data-pix-type aria-label="Tipo da chave Pix" <?=$required?'required':''?>>
      <option value="">Tipo…</option>
      <?php foreach(LC_PIX_KEY_TYPES as $value=>$text):?><option value="<?=$value?>" <?=$selected===$value?'selected':''?>><?=lc_e($text)?></option><?php endforeach;?>
    </select>
    <input id="f-<?=lc_e($keyName)?>" name="<?=lc_e($keyName)?>" type="text" maxlength="77" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="<?=lc_e($o['placeholder'] ?? 'Escolha o tipo da chave')?>" aria-describedby="f-<?=lc_e($keyName)?>-help f-<?=lc_e($keyName)?>-msg" data-pix-input <?=$required?'required':''?> <?=$error!==''?'aria-invalid="true"':''?>>
  </div>
  <small class="lc-ff-help" id="f-<?=lc_e($keyName)?>-help"><?=lc_e($o['help'] ?? 'Armazenada com criptografia. CPF e CNPJ são conferidos pelo dígito verificador.')?></small>
  <small class="lc-ff-msg" id="f-<?=lc_e($keyName)?>-msg" role="status" aria-live="polite"><?=lc_e($error)?></small>
</div><?php
}

/** Shared, progressively enhanced auth dialogs. Direct URLs remain functional without JS. */
function lc_auth_dialogs(string $path,?array $flash): void {
    // Signup values/errors survive one redirect so nothing typed is lost (never password or Pix key).
    $old = $_SESSION['lc_signup_old'] ?? [];
    $errors = $_SESSION['lc_signup_errors'] ?? [];
    if ($path === '/cadastrar') unset($_SESSION['lc_signup_old'], $_SESSION['lc_signup_errors']);
    $v = static fn(string $k): string => (string)($old[$k] ?? '');
    $err = static fn(string $k): string => (string)($errors[$k] ?? '');
    foreach(['entrar'=>'login','cadastrar'=>'signup'] as $route=>$kind):
        $direct=$path==='/'.$route;
        $isSignup=$kind==='signup';
?>
<dialog id="lc-auth-<?=$kind?>" class="lc-auth-dialog lc-auth-v3" aria-labelledby="lc-auth-title-<?=$kind?>" <?=$direct?'open':''?>>
  <div class="lc-auth-dialog-inner">
    <button class="lc-auth-close" type="button" aria-label="Fechar janela" data-auth-close>×</button>
    <span class="eyebrow"><?=$isSignup?'Nova conta de clipador':'Bem-vindo de volta'?></span>
    <h2 id="lc-auth-title-<?=$kind?>"><?=$isSignup?'Junte-se aos criadores.':'Entre na sua arena.'?></h2>
    <p class="lc-auth-desc"><?=$isSignup?'Crie sua conta para participar de campanhas. Seu cadastro será analisado pela equipe.':'Acompanhe seus clipes, campanhas e recompensas em um só lugar.'?></p>
    <?php if($direct && $flash):?><p class="lc-auth-message <?=$flash['type']==='error'?'is-error':''?>" role="alert"><?=lc_e($flash['message'])?></p><?php endif;?>
    <?php if(!$isSignup):?><p class="lc-auth-message is-error" data-auth-error role="alert" hidden></p><?php endif;?>
    <form method="post" action="/<?=$route?>" class="lc-auth-form" <?=$isSignup?'data-validate novalidate':'data-auth-async="login"'?>><?=lc_csrf()?>
      <?php if($isSignup):?>
      <fieldset class="lc-form-section">
        <legend><span>01</span> Acesso</legend>
        <div class="form-grid">
        <?php lc_form_field('Nome completo','full_name',['required'=>true,'value'=>$v('full_name'),'placeholder'=>'Nome e sobrenome','error'=>$err('full_name'),'attrs'=>'autocomplete="name" minlength="3" maxlength="160" autocapitalize="words" data-rule="fullname"']); ?>
        <div class="field lc-ff lc-signup-nickname<?=$err('nickname')!==''?' is-invalid':''?>" data-ff>
          <label for="lc-signup-nickname">Nickname</label>
          <div class="lc-ff-control has-prefix"><span class="lc-ff-prefix" aria-hidden="true">@</span>
          <input name="nickname" type="text" id="lc-signup-nickname" required minlength="3" maxlength="24" value="<?=lc_e($v('nickname'))?>"
                 pattern="[a-zA-Z][a-zA-Z0-9_]{2,23}" autocomplete="nickname" autocapitalize="none"
                 spellcheck="false" placeholder="seunick" aria-describedby="lc-nickname-hint lc-nickname-feedback"
                 data-nickname-check <?=$err('nickname')!==''?'aria-invalid="true"':''?>></div>
          <small class="lc-ff-help lc-nickname-hint" id="lc-nickname-hint">3 a 24 caracteres: letras, números e _. Aparece no ranking.</small>
          <small class="lc-ff-msg lc-nickname-feedback" id="lc-nickname-feedback" data-nickname-feedback role="status" aria-live="polite"><?=lc_e($err('nickname'))?></small>
        </div>
        <?php lc_form_field('E-mail','email',['type'=>'email','required'=>true,'value'=>$v('email'),'placeholder'=>'voce@exemplo.com','error'=>$err('email'),'attrs'=>'autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="190" inputmode="email"']); ?>
        <?php lc_form_field('Senha','password',['type'=>'password','required'=>true,'placeholder'=>'Mínimo de 10 caracteres','error'=>$err('password'),'attrs'=>'minlength="10" maxlength="128" autocomplete="new-password" data-strength']); ?>
        </div>
      </fieldset>
      <fieldset class="lc-form-section">
        <legend><span>02</span> Contato e redes</legend>
        <div class="form-grid">
        <?php lc_form_field('Instagram','instagram',['required'=>true,'prefix'=>'@','value'=>$v('instagram'),'placeholder'=>'seuperfil','error'=>$err('instagram'),'attrs'=>'autocapitalize="none" spellcheck="false" maxlength="80" data-rule="instagram"']); ?>
        <?php lc_form_field('WhatsApp com DDD','whatsapp',['type'=>'tel','required'=>true,'prefix'=>'+55','value'=>$v('whatsapp'),'placeholder'=>'(85) 99999-9999','error'=>$err('whatsapp'),'attrs'=>'autocomplete="tel-national" inputmode="tel" maxlength="16" data-lc-mask="phone"']); ?>
        <?php lc_form_field('Portfólio','portfolio_url',['type'=>'url','full'=>true,'value'=>$v('portfolio_url'),'placeholder'=>'seusite.com ou link do Drive','help'=>'Se não tiver, deixe em branco. Completamos o https:// para você.','error'=>$err('portfolio_url'),'attrs'=>'autocapitalize="none" spellcheck="false" maxlength="500" inputmode="url" data-rule="https"']); ?>
        </div>
      </fieldset>
      <fieldset class="lc-form-section">
        <legend><span>03</span> Recebimento</legend>
        <div class="form-grid">
        <?php lc_form_pix_field('Chave Pix para receber','pix_key_type','pix_key',['required'=>true,'full'=>true,'type'=>$v('pix_key_type'),'error'=>$err('pix_key')]); ?>
        </div>
      </fieldset>
      <label class="lc-check-card full">
        <input type="checkbox" name="consent_whatsapp" value="1" <?=!empty($old['consent_whatsapp'])?'checked':''?>>
        <span class="lc-check-box" aria-hidden="true"></span>
        <span class="lc-check-text"><strong>Avisos opcionais pelo WhatsApp</strong>Quero receber mensagens operacionais sobre meu cadastro e campanhas. Posso revogar em <a href="/privacidade/solicitacoes">Meus direitos LGPD</a>.</span>
      </label>
      <p class="form-note">Ao enviar, seus dados serão usados para criar e analisar a conta, conforme os <a href="/termos-de-uso">Termos de Uso</a> e a <a href="/politica-de-privacidade">Política de Privacidade</a>. Isso não é consentimento para publicidade.</p>
      <div class="form-actions">
        <button class="btn" type="submit" data-loading-text="Enviando cadastro…">Enviar cadastro <?=lc_icon('arrow-right')?></button>
        <a href="/entrar" data-auth-link>Já tenho conta</a>
      </div>
      <p class="form-note lc-auth-foot"><?=lc_icon('shield-check')?> Cadastro sujeito à aprovação. A chave Pix é armazenada criptografada.</p>
      <?php else:?>
      <div class="form-grid">
        <?php lc_form_field('E-mail ou nickname','email',['required'=>true,'full'=>true,'hide_optional'=>true,'placeholder'=>'seunick ou voce@exemplo.com','attrs'=>'autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="190"']); ?>
        <?php lc_form_field('Senha','password',['type'=>'password','required'=>true,'full'=>true,'placeholder'=>'Sua senha','attrs'=>'autocomplete="current-password" maxlength="200"']); ?>
      </div>
      <div class="lc-login-row">
        <label class="lc-check-inline"><input type="checkbox" name="remember_me" value="1"> <span>Manter conectado por 30 dias</span></label>
        <a href="/recuperar-senha" class="lc-login-forgot">Esqueci minha senha</a>
      </div>
      <div class="form-actions">
        <button class="btn" type="submit" data-loading-text="Entrando…">Entrar <?=lc_icon('arrow-right')?></button>
        <a href="/cadastrar" data-auth-link>Criar conta</a>
      </div>
      <?php endif;?>
    </form>
  </div>
</dialog>
<?php endforeach; ?>
<dialog id="lc-auth-mfa" class="lc-auth-dialog lc-auth-mfa-dialog lc-auth-v3" aria-labelledby="lc-auth-mfa-title" aria-modal="true" <?=$path==='/seguranca/verificar'?'open':''?>>
  <div class="lc-auth-dialog-inner">
    <button class="lc-auth-close" type="button" aria-label="Fechar verificação" data-auth-close>×</button>
    <span class="eyebrow">Segurança da conta</span>
    <div class="lc-auth-mfa-symbol"><?=lc_icon('shield-check')?></div>
    <h2 id="lc-auth-mfa-title">Confirme seu acesso</h2>
    <p class="lc-auth-desc" data-otp-desc>Digite o código de 6 dígitos do seu aplicativo autenticador.</p>
    <p class="lc-auth-message is-error" data-auth-error role="alert" hidden></p>
    <form method="post" action="/seguranca/verificar" class="lc-auth-form" data-auth-async="mfa"><?=lc_csrf()?>
      <div class="lc-otp" data-otp>
        <label class="lc-otp-label" for="lc-mfa-code" data-otp-label>Código de verificação</label>
        <input id="lc-mfa-code" type="text" name="mfa_code" inputmode="numeric" autocomplete="one-time-code" maxlength="60" required placeholder="000000" data-otp-input>
        <div class="lc-otp-cells" aria-hidden="true" data-otp-cells><span></span><span></span><span></span><span></span><span></span><span></span></div>
      </div>
      <button type="button" class="lc-link-btn" data-otp-recovery aria-pressed="false">Usar um código de recuperação</button>
      <div class="form-actions">
        <button class="btn" type="submit" data-loading-text="Verificando…">Verificar e entrar <?=lc_icon('arrow-right')?></button>
        <button type="button" class="lc-auth-mfa-back" data-auth-back>Voltar ao login</button>
      </div>
      <p class="form-note">O código muda a cada 30 segundos. Após 6 tentativas incorretas, aguarde 15 minutos.</p>
    </form>
  </div>
</dialog>
<?php
}
