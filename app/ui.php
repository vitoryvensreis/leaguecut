<?php
declare(strict_types=1);

function lc_icon(string $name, string $class = ''): string {
    static $allowed = ["play","compass","layout-dashboard","library","wallet","share-2","shield-check","log-out","users-round","user-round-cog","clapperboard","chart-column","hand-coins","trophy","palette","chevron-right","arrow-right","external-link","lock-keyhole","check","circle-check","sparkles","folder-open","clock-3","plus","upload-cloud","settings-2","bell","activity","clipboard-check","layers","circle-dollar-sign","menu","video","globe","shield"];
    if (!in_array($name, $allowed, true)) $name = 'circle-check';
    $class = trim(preg_replace('/[^a-zA-Z0-9_ -]/', '', $class) ?? '');
    return '<svg class="lc-icon ' . lc_e($class) . '" aria-hidden="true" focusable="false"><use href="/assets/icons.svg#' . lc_e($name) . '"></use></svg>';
}

function lc_page(string $title, callable $body, string $active = ''): void {
    $u = lc_user();
    $s = lc_settings();
    $brand = $s['brand_name'] ?? 'LeagueCut';
    $primary = $s['primary_color'] ?? '#e50914';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) $primary = '#e50914';
    $logo = (string)($s['logo_url'] ?? '');
    $flash = lc_take_flash();
?><!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#101114"><meta name="description" content="LeagueCut — campanhas e recompensas para criadores de conteúdo">
<title><?=lc_e($title)?> · <?=lc_e($brand)?></title>
<link rel="stylesheet" href="/assets/app.css?v=2">
<link rel="stylesheet" href="/assets/v2.css?v=1">
<link rel="stylesheet" href="/assets/landing.css?v=1">
<style>:root{--accent:<?=$primary?>}</style>
</head>
<body>
<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="/" aria-label="<?=lc_e($brand)?>, página inicial">
      <?php if (lc_valid_https($logo)): ?><img class="brand-image" src="<?=lc_e($logo)?>" alt="<?=lc_e($brand)?>"><?php else: ?><span class="brand-glyph"><?=lc_icon("play")?></span><span><?=lc_e($brand)?></span><?php endif; ?>
    </a>
    <nav class="main-nav" aria-label="Navegação principal">
      <a class="<?=$active==='home'?'selected':''?>" href="/"><?=lc_icon("play")?> Início</a>
      <a class="<?=$active==='explore'?'selected':''?>" href="/explorar"><?=lc_icon("compass")?> Campanhas</a>
      <?php if ($u && $u['status']==='approved'): ?>
      <a class="<?=$active==='dashboard'?'selected':''?>" href="/dashboard"><?=lc_icon("layout-dashboard")?> Minha área</a>
      <a class="<?=$active==='library'?'selected':''?>" href="/biblioteca"><?=lc_icon("library")?> Biblioteca</a>
      <a class="<?=$active==='finance'?'selected':''?>" href="/financeiro"><?=lc_icon("wallet")?> Financeiro</a>
      <a class="<?=$active==='social'?'selected':''?>" href="/redes"><?=lc_icon("share-2")?> Redes</a>
      <?php endif; ?>
      <?php if ($u && $u['role']==='admin'): ?><a class="<?=$active==='admin'?'selected':''?>" href="/admin"><?=lc_icon("shield-check")?> Administração</a><?php endif; ?>
    </nav>
    <div class="header-right">
      <?php if ($u): ?>
      <span class="user-short"><?=lc_e(explode(' ', $u['full_name'])[0])?></span>
      <span class="avatar" title="<?=lc_e($u['full_name'])?>"><?=lc_e(mb_strtoupper(mb_substr($u['full_name'],0,1)))?></span>
      <form method="post" action="/sair" class="inline-form"><?=lc_csrf()?><button class="text-button" type="submit"><?=lc_icon("log-out")?> <span>Sair</span></button></form>
      <?php else: ?>
      <a class="header-login" href="/entrar">Entrar</a><a class="btn btn-sm" href="/cadastrar">Ser clipador <?=lc_icon("arrow-right")?></a>
      <?php endif; ?>
    </div>
  </div>
</header>
<main>
  <?php if ($flash): ?><div class="toast <?=$flash['type']==='error'?'toast-error':''?>" role="status"><?=lc_e($flash['message'])?></div><?php endif; ?>
  <?php $body(); ?>
</main>
<footer class="site-footer"><span><?=lc_e($brand)?> © <?=date('Y')?></span><span>Crie conteúdo. Faça parte. Receba pelo resultado.</span></footer>
</body></html><?php
}
function lc_tile(array $c): void {
    $cover = (string)($c['cover_url'] ?? '');
?><a class="campaign-card" href="/campanhas/<?=lc_e($c['slug'])?>">
  <div class="campaign-cover">
    <?php if(lc_valid_https($cover)):?><img src="<?=lc_e($cover)?>" alt="" loading="lazy"><?php else:?><div class="default-art"><span><?=lc_e(mb_strtoupper(mb_substr($c['title'],0,1)))?></span><i><?=lc_icon("play")?></i></div><?php endif;?>
    <div class="cover-overlay"></div><span class="cover-play"><?=lc_icon("play")?></span>
    <span class="cover-tag"><?=lc_e($c['status']==='active'?'EM ALTA':'CAMPANHA')?></span>
  </div>
  <div class="campaign-info"><strong><?=lc_e($c['title'])?></strong>
  <span><?=lc_money((int)$c['payment_per_video_cents'])?> / vídeo</span>
  <div class="card-meta"><span><?=lc_e($c['category'])?></span><span>Ver campanha <?=lc_icon("arrow-right")?></span></div></div>
</a><?php
}
function lc_field(string $label, string $name, string $type = 'text', string $value = '', bool $required = false, string $placeholder = '', string $extra = ''): void {
?><label class="field"><span><?=lc_e($label)?></span><input name="<?=lc_e($name)?>" type="<?=lc_e($type)?>" value="<?=lc_e($value)?>" placeholder="<?=lc_e($placeholder)?>" <?=$required?'required':''?> <?=$extra?>></label><?php
}
function lc_empty(string $title, string $details): void {
?><div class="empty"><span class="empty-icon"><?=lc_icon("sparkles")?></span><h3><?=lc_e($title)?></h3><p><?=lc_e($details)?></p></div><?php
}
function lc_heading(string $eyebrow, string $title, string $description=''): void {
?><div class="page-heading"><span class="eyebrow"><?=lc_e($eyebrow)?></span><h1><?=lc_e($title)?></h1><?php if($description):?><p><?=lc_e($description)?></p><?php endif;?></div><?php
}
