<?php
declare(strict_types=1);

function lc_clipper_dashboard_hero(array $campaigns,array $banners): void {
    $items=[];
    foreach($campaigns as $c){
        if(count($items)>=4)break;
        $items[]=[
          'type'=>'campaign','eyebrow'=>'CAMPANHA ATIVA','title'=>$c['title'],
          'description'=>mb_substr(trim((string)$c['description']),0,145),
          'action'=>'Ver campanha','url'=>'/campanhas/'.$c['slug'],
          'image'=>lc_campaign_cover_valid((string)($c['cover_url']??''))?(string)$c['cover_url']:''
        ];
    }
    foreach($banners as $b){
        if(count($items)>=4)break;
        $items[]=[
          'type'=>'banner','eyebrow'=>'LEAGUECUT INFORMA','title'=>$b['title'],
          'description'=>$b['description'],'action'=>$b['button_text'],
          'url'=>$b['button_url'],'image'=>(string)($b['image_url']??'')
        ];
    }
    if(!$items){
        $items[]=['type'=>'empty','eyebrow'=>'EM BREVE','title'=>'Novas campanhas estão chegando',
        'description'=>'Cadastre suas redes e fique pronto para as próximas oportunidades.',
        'action'=>'Cadastrar minhas redes','url'=>'/redes','image'=>''];
    }
?>
<section class="lc-clipper-spotlight" aria-labelledby="lc-spotlight-title">
 <div class="lc-spotlight-heading"><div><span class="eyebrow">PRA VOCÊ</span><h1 id="lc-spotlight-title">Campanhas em destaque</h1></div>
  <a href="/explorar">Explorar todas <?=lc_icon('arrow-right')?></a></div>
 <div class="lc-spotlight-track" id="lc-spotlight-track" tabindex="0" aria-label="Campanhas e informações em destaque">
 <?php foreach($items as $i=>$item): ?>
 <article class="lc-spotlight-card lc-spotlight-<?=lc_e($item['type'])?>" tabindex="0">
  <?php if($item['image']!==''):?><img class="lc-spotlight-bg" src="<?=lc_e($item['image'])?>" alt="" loading="<?=$i===0?'eager':'lazy'?>"><?php endif;?>
  <div class="lc-spotlight-shade" aria-hidden="true"></div>
  <div class="lc-spotlight-content">
   <span class="lc-spotlight-kicker"><?=lc_e($item['eyebrow'])?></span>
   <div class="lc-spotlight-bottom"><h2><?=lc_e($item['title'])?></h2>
   <p><?=lc_e($item['description'])?></p>
   <a class="btn lc-spotlight-cta" href="<?=lc_e($item['url'])?>"><?=lc_e($item['action'])?> <?=lc_icon('arrow-right')?></a></div>
  </div>
 </article>
 <?php endforeach;?>
 </div>
 <?php if(count($items)>1):?><div class="lc-spotlight-mobile-controls">
  <button type="button" data-spotlight-prev aria-label="Destaque anterior"><?=lc_icon('arrow-right')?></button>
  <span id="lc-spotlight-index">1 de <?=count($items)?></span>
  <button type="button" data-spotlight-next aria-label="Próximo destaque"><?=lc_icon('arrow-right')?></button>
 </div><?php endif;?>
</section>
<?php
}
