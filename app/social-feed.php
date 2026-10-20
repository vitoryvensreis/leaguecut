<?php
declare(strict_types=1);

/**
 * Public LeagueCut feed. We ONLY show clips accepted by moderation from
 * approved clipper accounts. Embeds are native to the original providers;
 * no scraped media, downloaded video, or new API credentials are needed.
 */
function lc_feed_safe_original(string $url,string $network): string {
    if(strlen($url)>800 || !lc_valid_https($url) ||
       preg_match('/[\x00-\x20\x7f]/D',$url))return '';
    $parts=parse_url($url);
    if(!is_array($parts)||isset($parts['user'])||isset($parts['pass'])||
       isset($parts['port'])||isset($parts['fragment']))return '';
    $host=strtolower((string)($parts['host']??''));
    $allowed=$network==='instagram'?['instagram.com','www.instagram.com']:
             ($network==='tiktok'?['tiktok.com','www.tiktok.com','vm.tiktok.com','vt.tiktok.com']:[]);
    return in_array($host,$allowed,true)?$url:'';
}
function lc_feed_embed_url(string $url,string $network): string {
    $clean=lc_feed_safe_original($url,$network);
    if($clean==='')return '';
    $parts=parse_url($clean);
    $path=(string)($parts['path']??'');
    if($network==='instagram'){
        if(preg_match('~^/(reel|p|tv)/([a-zA-Z0-9_-]{5,80})/?$~D',$path,$m))
            return 'https://www.instagram.com/'.$m[1].'/'.$m[2].'/embed/';
    }elseif($network==='tiktok'&&
           preg_match('~^/@[a-zA-Z0-9._]{2,45}/video/([0-9]{10,25})/?$~D',$path,$m)){
        return 'https://www.tiktok.com/embed/v2/'.$m[1];
    }
    return '';
}
function lc_social_feed_route(string $method,string $path): bool {
    if($method!=='GET'||$path!=='/feed')return false;

    $network=strtolower(trim((string)($_GET['rede']??'')));
    if(!in_array($network,['instagram','tiktok'],true))$network='';
    $pageInput=(string)($_GET['pagina']??'1');
    $page=ctype_digit($pageInput)?max(1,min((int)$pageInput,100)):1;
    $limit=12;$offset=($page-1)*$limit;
    $conditions=["c.status='approved'","u.status='approved'","u.role='clipador'"];
    $params=[];
    if($network!==''){$conditions[]="s.network=?";$params[]=$network;}
    $from=' FROM clips c
            INNER JOIN users u ON u.id=c.user_id
            INNER JOIN social_accounts s ON s.id=c.social_account_id
            INNER JOIN campaigns p ON p.id=c.campaign_id
            WHERE '.implode(' AND ',$conditions);
    $total=(int)(lc_one('SELECT COUNT(*) AS n'.$from,$params)['n']??0);
    $rows=lc_all('SELECT c.id,c.published_url,c.submitted_at,c.verified_views,
          s.network,s.username,u.nickname,p.title AS campaign_title,
          p.slug AS campaign_slug,p.status AS campaign_status'
          .$from.' ORDER BY c.submitted_at DESC,c.id DESC LIMIT '.$limit.' OFFSET '.$offset,$params);

    $queryPage=static function(int $n)use($network): string {
        return '/feed?'.http_build_query(array_filter(['rede'=>$network,'pagina'=>$n],static fn($v)=>$v!==''));
    };

    lc_page('Feed da comunidade',function()use($rows,$total,$page,$limit,$network,$queryPage){ ?>
<div class="lc-feed" aria-labelledby="lc-feed-title">
  <section class="lc-feed-hero">
    <div class="lc-feed-wrap">
      <span class="eyebrow">COMUNIDADE LEAGUECUT · CLIPES APROVADOS</span>
      <h1 id="lc-feed-title">O talento ganha <em>espaço.</em></h1>
      <p>Assista aos conteúdos publicados pelos clipadores nas campanhas LeagueCut. Tudo aqui passou pela conferência da plataforma.</p>
      <div class="lc-feed-hero-bottom">
        <nav class="lc-feed-filters" aria-label="Filtrar feed por rede">
          <a href="/feed" <?=$network===''?'aria-current="page"':''?>>Todos</a>
          <a href="/feed?rede=instagram" <?=$network==='instagram'?'aria-current="page"':''?>>Instagram</a>
          <a href="/feed?rede=tiktok" <?=$network==='tiktok'?'aria-current="page"':''?>>TikTok</a>
        </nav>
        <span class="lc-feed-total"><?=number_format($total,0,',','.')?> <?= $total===1?'publicação aprovada':'publicações aprovadas' ?></span>
      </div>
    </div>
  </section>
  <section class="lc-feed-wrap lc-feed-content" aria-label="Publicações dos clipadores">
  <?php if(!$rows):?>
    <div class="lc-feed-empty">
      <span class="lc-feed-empty-icon"><?=lc_icon('clapperboard')?></span>
      <h2>Os primeiros clipes estão a caminho.</h2>
      <p><?= $network!==''?'Ainda não há clipes aprovados desta rede. Veja outras publicações ou volte em breve.':'Assim que os clipadores enviarem suas publicações e a equipe aprovar, elas aparecerão aqui automaticamente.' ?></p>
      <a href="<?=$network!==''?'/feed':'/explorar'?>" class="btn btn-ghost"><?=$network!==''?'Ver todas as redes':'Explorar campanhas'?> <?=lc_icon('arrow-right')?></a>
    </div>
  <?php else:?>
    <div class="lc-feed-grid">
      <?php foreach($rows as $entry):
         $id=(int)$entry['id'];
         $platform=(string)$entry['network'];
         $url=lc_feed_safe_original((string)$entry['published_url'],$platform);
         $embed=lc_feed_embed_url((string)$entry['published_url'],$platform);
         $nickname=trim((string)($entry['nickname']??''));
         $handle=trim((string)$entry['username']);
         $display=$nickname!==''?'@'.$nickname:('Clipador #'.(int)$id);
         ?>
      <article class="lc-feed-card" aria-label="Publicação do clipador <?=lc_e($display)?>">
        <div class="lc-feed-video">
        <?php if($embed!=='' && $url!==''):?>
          <div class="lc-feed-placeholder" data-feed-player data-embed="<?=lc_e($embed)?>" data-network="<?=lc_e($platform)?>">
            <div class="lc-feed-preview-art" aria-hidden="true">
              <span class="lc-feed-preview-brand">LEAGUE<span>CUT</span></span>
              <span class="lc-feed-preview-number">#<?=str_pad((string)$id,4,'0',STR_PAD_LEFT)?></span>
            </div>
            <button class="lc-feed-play" type="button" data-feed-play aria-label="Reproduzir publicação no <?=lc_e(ucfirst($platform))?>">
              <span class="lc-feed-play-icon"><?=lc_icon('play')?></span>
              <strong>Assistir publicação</strong>
              <small>Carregar vídeo do <?=lc_e(ucfirst($platform))?></small>
            </button>
          </div>
        <?php else:?>
          <div class="lc-feed-placeholder lc-feed-preview-only">
            <span class="lc-feed-preview-brand">LEAGUE<span>CUT</span></span>
            <span class="lc-feed-play-icon"><?=lc_icon('clapperboard')?></span>
            <p>Veja a publicação na rede social</p>
          </div>
        <?php endif;?>
        <span class="lc-feed-network"><?=lc_e(ucfirst($platform))?></span>
        </div>
        <div class="lc-feed-info">
          <div class="lc-feed-meta"><span><?=lc_icon('users-round')?> <?=lc_e($display)?></span><time datetime="<?=lc_e(substr((string)$entry['submitted_at'],0,10))?>"><?=date('d/m/Y',strtotime((string)$entry['submitted_at']))?></time></div>
          <h2><?=lc_e((string)$entry['campaign_title'])?></h2>
          <p>Clipador <?=lc_e('@'.$handle)?> · Clipe aprovado</p>
          <div class="lc-feed-card-actions">
            <?php if($url!==''):?><a href="<?=lc_e($url)?>" target="_blank" rel="noopener noreferrer" class="lc-feed-original">Abrir no <?=lc_e(ucfirst($platform))?> <?=lc_icon('external-link')?></a><?php endif;?>
            <?php if($entry['campaign_status']==='active'):?><a href="/campanhas/<?=lc_e((string)$entry['campaign_slug'])?>" class="lc-feed-campaign-link">Campanha <?=lc_icon('arrow-right')?></a><?php endif;?>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <nav class="lc-feed-pages" aria-label="Navegação das publicações">
      <?php if($page>1):?><a href="<?=lc_e($queryPage($page-1))?>">← Anteriores</a><?php endif;?>
      <span>Página <?=$page?> de <?=max(1,(int)ceil($total/$limit))?></span>
      <?php if($page*$limit<$total):?><a href="<?=lc_e($queryPage($page+1))?>">Próximas <?=lc_icon('arrow-right')?></a><?php endif;?>
    </nav>
  <?php endif;?>
  <p class="lc-feed-disclaimer">Os vídeos são reproduzidos diretamente das plataformas de origem, após você clicar em assistir. Contas privadas, conteúdo removido ou incorporação desativada podem impedir a reprodução. Nesse caso, abra a publicação na rede social.</p>
  </section>
</div>
<?php },'feed');
    return true;
}
