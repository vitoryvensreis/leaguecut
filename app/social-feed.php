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
             ($network==='tiktok'?['tiktok.com','www.tiktok.com','vm.tiktok.com','vt.tiktok.com']:($network==='youtube'?['youtube.com','www.youtube.com','m.youtube.com','youtu.be','www.youtu.be']:[]));
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
        return 'https://www.tiktok.com/player/v1/'.$m[1].'?description=0&music_info=0';
    }elseif($network==='youtube'){
        $host=strtolower((string)($parts['host']??''));
        $id='';
        if(in_array($host,['youtu.be','www.youtu.be'],true) && preg_match('~^/([A-Za-z0-9_-]{11})/?$~D',$path,$m))$id=$m[1];
        elseif(preg_match('~^/(?:shorts|embed|live)/([A-Za-z0-9_-]{11})/?$~D',$path,$m))$id=$m[1];
        elseif($path==='/watch'){
            parse_str((string)($parts['query']??''),$query);
            $candidate=(string)($query['v']??'');
            if(preg_match('/^[A-Za-z0-9_-]{11}$/D',$candidate))$id=$candidate;
        }
        if($id!=='')return 'https://www.youtube-nocookie.com/embed/'.$id;
    }
    return '';
}
function lc_social_feed_route(string $method,string $path): bool {
    if($method!=='GET'||$path!=='/feed')return false;

    $network=''; // A timeline reúne todas as redes, sem filtros.
    $pageInput=(string)($_GET['pagina']??'1');
    $page=ctype_digit($pageInput)?max(1,min((int)$pageInput,100)):1;
    $limit=10;$offset=($page-1)*$limit;
    $conditions=["c.status='approved'","u.status='approved'","u.role='clipador'"];
    $params=[];
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

    $queryPage=static fn(int $n): string => '/feed?pagina='.$n;

    $leaders=lc_public_ranking(15);
    lc_page('Feed da comunidade',function()use($rows,$total,$page,$limit,$network,$queryPage,$leaders){ ?>
<div class="lc-feed" aria-label="Feed de vídeos da comunidade">
  <section class="lc-feed-top lc-feed-wrap" aria-label="Comunidade e destaques">
    <div class="lc-feed-stories-heading"><strong><?=lc_icon('trophy')?> Destaques do ranking</strong><a href="/ranking">Ver ranking completo <?=lc_icon('arrow-right')?></a></div>
    <div class="lc-feed-stories" role="list" aria-label="Melhores clipadores do ranking geral">
      <?php foreach($leaders as $i=>$leader): $nick=(string)($leader['nickname']??''); if($nick==='')continue; ?>
        <a href="/ranking" role="listitem" class="lc-feed-story" title="<?=lc_e(($i+1).'º · @'.$nick)?>">
          <span class="lc-feed-story-ring"><span class="lc-feed-story-avatar"><?=lc_e(strtoupper(substr($nick,0,1)))?></span></span>
          <span class="lc-feed-story-nick">@<?=lc_e($nick)?></span>
          <small><?=$i+1?>º lugar</small>
        </a>
      <?php endforeach; ?>
      <?php if(!$leaders):?><p class="lc-feed-story-empty">Os destaques aparecem aqui quando houver clipes aprovados.</p><?php endif; ?>
    </div>
  </section>
  <section class="lc-feed-wrap lc-feed-content" aria-label="Publicações dos clipadores">
  <?php if(!$rows):?>
    <div class="lc-feed-empty">
      <span class="lc-feed-empty-icon"><?=lc_icon('clapperboard')?></span>
      <h2>Os primeiros clipes estão a caminho.</h2>
      <p>Assim que os clipadores enviarem suas publicações e a equipe aprovar, elas aparecerão aqui automaticamente.</p>
      <a href="/explorar" class="btn btn-ghost">Explorar campanhas <?=lc_icon('arrow-right')?></a>
    </div>
  <?php else:?>
    <div class="lc-feed-grid" id="lc-feed-timeline" aria-live="polite">
      <?php foreach($rows as $entry):
         $id=(int)$entry['id'];
         $platform=(string)$entry['network'];
         $url=lc_feed_safe_original((string)$entry['published_url'],$platform);
         $embed=lc_feed_embed_url((string)$entry['published_url'],$platform);
         $nickname=trim((string)($entry['nickname']??''));
         $handle=trim((string)$entry['username']);
         $display=$nickname!==''?'@'.$nickname:('Clipador #'.(int)$id);
         ?>
      <article class="lc-feed-card lc-feed-card-<?=lc_e($platform)?>" aria-label="Vídeo de <?=lc_e($display)?>">
        <?php if($embed!=='' && $platform==='instagram'): ?>
          <div class="lc-feed-embed lc-feed-embed-instagram">
            <blockquote class="instagram-media"
              data-instgrm-permalink="<?=lc_e((string)preg_replace('~/embed/$~','/',$embed))?>"
              data-instgrm-version="14">
              <a href="<?=lc_e($url)?>" target="_blank" rel="noopener noreferrer">Ver vídeo no Instagram</a>
            </blockquote>
          </div>
        <?php elseif($embed!==''): ?>
          <?php $isShort=$platform==='youtube' && preg_match('~^/shorts/~',(string)(parse_url($url,PHP_URL_PATH)??'')); ?>
          <div class="lc-feed-embed lc-feed-embed-<?=lc_e($platform)?> <?=$platform==='youtube'&&$isShort?'lc-feed-embed-short':''?>">
            <iframe class="lc-feed-iframe"
              title="Vídeo de <?=lc_e($display)?> no <?=lc_e(ucfirst($platform))?>"
              src="<?=lc_e($embed)?>" loading="lazy"
              referrerpolicy="strict-origin-when-cross-origin"
              allow="encrypted-media; fullscreen; picture-in-picture"
              allowfullscreen></iframe>
          </div>
        <?php else: ?>
          <div class="lc-feed-unavailable">
            <p>Não foi possível incorporar este vídeo.</p>
            <?php if($url!==''):?><a href="<?=lc_e($url)?>" target="_blank" rel="noopener noreferrer">Abrir publicação original <?=lc_icon('external-link')?></a><?php endif;?>
          </div>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
    <nav class="lc-feed-pages" aria-label="Navegação das publicações" data-feed-pagination>
      <?php if($page>1):?><a href="<?=lc_e($queryPage($page-1))?>">← Anteriores</a><?php endif;?>
      <span class="lc-feed-page-indicator">Página <?=$page?> de <?=max(1,(int)ceil($total/$limit))?></span>
      <?php if($page*$limit<$total):?><a href="<?=lc_e($queryPage($page+1))?>">Próximas <?=lc_icon('arrow-right')?></a><?php endif;?>
    </nav>
    <div class="lc-feed-loading" data-feed-loading aria-live="polite"></div>
    <div class="lc-feed-sentinel" data-feed-sentinel aria-hidden="true"></div>
  <?php endif;?>
  <p class="lc-feed-disclaimer">Vídeos incorporados das plataformas de origem. Publicações privadas, removidas ou com incorporação desativada podem não funcionar.</p>
  </section>
</div>
<?php },'feed');
    return true;
}
