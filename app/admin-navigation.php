<?php
declare(strict_types=1);

/** Navigation is presentation-only. All original URLs and server-side permission checks remain authoritative. */
function lc_admin_navigation_groups(): array {
    return [
      ['key'=>'campaigns','title'=>'Campanhas e clipes','icon'=>'clapperboard','links'=>['/admin/campanhas','/admin/clipes']],
      ['key'=>'accounts','title'=>'Opções de contas','icon'=>'users-round','links'=>['/admin/clipadores','/admin/administradores','/admin/contas','/admin/funcoes','/indicacoes','/admin/indicacoes']],
      ['key'=>'support','title'=>'Suporte','icon'=>'circle-check','links'=>['/admin/chamados','/admin/chamados/relatorios']],
      ['key'=>'finance','title'=>'Financeiro','icon'=>'wallet','links'=>['/admin/financeiro','/admin/asaas','/admin/bonus-mensal']],
      ['key'=>'creators','title'=>'Criadores e assinaturas','icon'=>'layers','links'=>['/admin/pacotes','/admin/assinaturas']],
      ['key'=>'comms','title'=>'Comunicação e automações','icon'=>'share-2','links'=>['/admin/automacoes','/admin/whatsapp','/admin/notificacoes','/notificacoes']],
      ['key'=>'brand','title'=>'Personalização e SEO','icon'=>'palette','links'=>['/admin/home','/admin/banners-clipador','/admin/configuracoes','/admin/seo']],
      ['key'=>'system','title'=>'Sistema e auditoria','icon'=>'settings-2','links'=>['/admin/integracoes','/admin/atividades','/admin/auditoria','/admin/privacidade','/admin/demo']]
    ];
}
function lc_admin_navigation_is_active(string $current,string $url): bool {
    if($current===$url)return true;
    // A section's base URL only matches its own descendants, never siblings such as /chamados/relatorios.
    return $url!=='/admin' && str_starts_with($current,$url.'/');
}
function lc_admin_navigation_visible(array $u,array $links): array {
    $available=[];
    foreach(lc_workspace_links($u) as $link) $available[$link[1]]=$link;
    $out=[];
    foreach($links as $path) if(isset($available[$path]))$out[]=$available[$path];
    return $out;
}
function lc_admin_navigation_group_for_path(array $u,string $path): ?array {
    foreach(lc_admin_navigation_groups() as $group){
        $visible=lc_admin_navigation_visible($u,$group['links']);
        // Prefer the most specific item: /admin/chamados/relatorios before /admin/chamados.
        usort($visible,static fn(array $a,array $b):int=>strlen($b[1])<=>strlen($a[1]));
        foreach($visible as $link)if(lc_admin_navigation_is_active($path,$link[1])){
            $group['visible']=$visible;
            return $group;
        }
    }
    return null;
}
function lc_admin_sidebar_navigation(array $u,string $current): void {
    $overview=lc_admin_navigation_visible($u,['/admin']);
    $currentGroup=lc_admin_navigation_group_for_path($u,$current);
    ?>
    <div class="ws-nav-group lc-admin-nav" data-admin-nav data-user="<?=(int)$u['id']?>">
      <p class="ws-nav-label">Painel administrativo</p>
      <?php foreach($overview as [$name,$url,$icon]):?>
        <a href="<?=lc_e($url)?>" class="ws-nav-link <?=lc_admin_navigation_is_active($current,$url)?'is-current':''?>" <?=lc_admin_navigation_is_active($current,$url)?'aria-current="page"':''?>>
          <?=lc_icon($icon)?> <span><?=lc_e($name)?></span>
        </a>
      <?php endforeach;?>
      <?php foreach(lc_admin_navigation_groups() as $group):
        $visible=lc_admin_navigation_visible($u,$group['links']);
        if(!$visible)continue;
        $active=$currentGroup!==null && $currentGroup['key']===$group['key'];
      ?>
        <details class="lc-admin-nav-group <?=$active?'is-active':''?>" data-admin-group="<?=lc_e($group['key'])?>" <?=$active?'open':''?>>
          <summary class="lc-admin-nav-summary"><?=lc_icon($group['icon'])?><span><?=lc_e($group['title'])?></span><?=lc_icon('chevron-right','lc-admin-chevron')?></summary>
          <div class="lc-admin-nav-submenu">
            <?php foreach($visible as [$name,$url,$icon]):$selected=lc_admin_navigation_is_active($current,$url);?>
              <a href="<?=lc_e($url)?>" class="lc-admin-nav-child <?=$selected?'is-current':''?>" <?=$selected?'aria-current="page"':''?>>
                <?=lc_icon($icon)?> <span><?=lc_e($name)?></span>
                <?php if($selected):?><span class="ws-current-dot" aria-hidden="true"></span><?php endif;?>
              </a>
            <?php endforeach;?>
          </div>
        </details>
      <?php endforeach;?>
      <?php $mine=lc_admin_navigation_visible($u,['/conta','/seguranca/2fa']);if($mine):?>
        <details class="lc-admin-nav-group lc-admin-personal" data-admin-group="my-account" <?=in_array($current,['/conta','/seguranca/2fa'],true)?'open':''?>>
          <summary class="lc-admin-nav-summary"><?=lc_icon('user-round-cog')?><span>Minha conta</span><?=lc_icon('chevron-right','lc-admin-chevron')?></summary>
          <div class="lc-admin-nav-submenu">
            <?php foreach($mine as [$name,$url,$icon]):?>
              <a href="<?=lc_e($url)?>" class="lc-admin-nav-child <?=$current===$url?'is-current':''?>" <?=$current===$url?'aria-current="page"':''?>><?=lc_icon($icon)?> <span><?=lc_e($name)?></span></a>
            <?php endforeach;?>
          </div>
        </details>
      <?php endif;?>
    </div>
    <?php
}
function lc_admin_context_tabs(array $u,string $path): void {
    $group=lc_admin_navigation_group_for_path($u,$path);
    if(!$group || count($group['visible'])<2)return;
    ?>
    <nav class="lc-admin-internal-tabs" aria-label="<?=lc_e($group['title'])?>">
      <strong><?=lc_icon($group['icon'])?> <?=lc_e($group['title'])?></strong>
      <div class="lc-admin-tabs-scroll">
        <?php foreach($group['visible'] as [$name,$url,$icon]):$selected=lc_admin_navigation_is_active($path,$url);?>
          <a href="<?=lc_e($url)?>" class="lc-admin-tab <?=$selected?'is-current':''?>" <?=$selected?'aria-current="page"':''?>>
            <?=lc_icon($icon)?> <?=lc_e($name)?>
          </a>
        <?php endforeach;?>
      </div>
    </nav>
    <?php
}
