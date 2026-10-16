<?php
declare(strict_types=1);

/**
 * Visual score is illustrative XP, not a payment balance or official monthly prize.
 * Only approved clips count, using the last real daily public-view observation.
 */
function lc_campaign_rankings(int $campaignId,int $max=30): array {
    $max=max(1,min(100,$max));
    $rows=lc_all("SELECT c.user_id,u.nickname,
      COUNT(*) AS approved_clips,
      COALESCE(SUM(COALESCE((
        SELECT d.views FROM clip_daily_views d
        WHERE d.clip_id=c.id ORDER BY d.snapshot_date DESC LIMIT 1
      ),0)),0) AS public_views,
      MAX((SELECT d.snapshot_date FROM clip_daily_views d WHERE d.clip_id=c.id
        ORDER BY d.snapshot_date DESC LIMIT 1)) AS latest_snapshot
      FROM clips c JOIN users u ON u.id=c.user_id
      WHERE c.campaign_id=? AND c.status='approved'
        AND u.role='clipador' AND u.status='approved' AND u.nickname IS NOT NULL
      GROUP BY c.user_id,u.nickname
      ORDER BY public_views DESC, approved_clips DESC,c.user_id ASC
      LIMIT ".$max,[$campaignId]);
    foreach($rows as $idx=>&$row) {
        $row['rank']=$idx+1;
        $row['approved_clips']=(int)$row['approved_clips'];
        $row['public_views']=(int)$row['public_views'];
        // 100 XP for each approved clip, one extra XP per 100 observed views.
        $row['xp']=$row['approved_clips']*100+intdiv($row['public_views'],100);
        $row['level']=min(100,1+intdiv($row['xp'],500));
        $row['progress']=$row['level']>=100?100:(int)round(($row['xp']%500)*100/500);
        $row['next_xp']=$row['level']>=100?0:500-($row['xp']%500);
    }
    unset($row);
    return $rows;
}
function lc_campaign_public_name(string $name,bool $signedIn,int $id): string {
    if(!$signedIn)return 'Clipador #'.str_pad((string)$id,3,'0',STR_PAD_LEFT);
    $parts=preg_split('/\s+/u',trim($name))?:[];
    $first=$parts[0]??'Criador';
    $last=count($parts)>1?' '.mb_strtoupper(mb_substr(end($parts),0,1)).'.':'';
    return $first.$last;
}
function lc_campaign_leaderboard(int $campaignId,?array $viewer): void {
    $ranking=lc_campaign_rankings($campaignId,30);
    $signedIn=$viewer!==null;
    $own=$viewer && $viewer['role']==='clipador'?(int)$viewer['id']:0;
    ?>
<section class="lc-leaderboard" aria-labelledby="lc-leaderboard-title">
  <div class="lc-rank-heading">
    <div><span class="eyebrow"><?=lc_icon('trophy')?> Arena de criadores</span>
      <h2 id="lc-leaderboard-title">Ranking da campanha</h2>
      <p>Dispute posições, suba de nível e acompanhe o desempenho dos vídeos aprovados.</p>
    </div>
    <span class="lc-leaderboard-live"><?=lc_icon('chart-column')?> Atualização diária</span>
  </div>
  <div class="lc-rank-help">O placar usa <strong>views públicas observadas</strong> em cada clipe aprovado. XP é uma pontuação fictícia de gamificação: 100 por clipe aprovado + 1 a cada 100 views. Não representa ganhos, views auditadas nem define automaticamente o bônus de líder mensal.</div>
  <?php if(!$ranking):?>
    <div class="lc-rank-empty"><?=lc_icon('trophy')?><strong>A arena está esperando seu primeiro destaque.</strong><p>Quando os clipes forem aprovados, seus criadores aparecerão no ranking.</p></div>
  <?php else:?>
  <div class="lc-podium" aria-label="Pódio de criadores">
    <?php foreach([1,0,2] as $positionIndex):if(!isset($ranking[$positionIndex]))continue;
      $p=$ranking[$positionIndex];$medal=$p['rank']===1?'Ouro':($p['rank']===2?'Prata':'Bronze');?>
      <article class="lc-podium-player lc-podium-player-<?=$p['rank']?> <?=$own===(int)$p['user_id']?'is-me':''?>" data-arena-position="<?=$p['rank']?>">
        <span class="lc-podium-place">#<?=$p['rank']?> <?=lc_rank_trophy((int)$p['rank'])?> · <?=lc_e($medal)?></span>
        <div class="lc-podium-avatar" aria-hidden="true"><?=lc_e(mb_strtoupper(mb_substr($p['nickname'],0,1)))?></div>
        <strong><?=lc_e('@'.(string)$p['nickname'])?></strong>
        <span class="lc-podium-level">Nível <?=$p['level']?></span>
        <span class="lc-podium-views"><?=number_format($p['public_views'],0,',','.')?> <small>views públicas</small></span>
        <span class="lc-podium-xp"><?=number_format($p['xp'],0,',','.')?> XP</span>
        <div class="lc-podium-bar" aria-label="<?=$p['progress']?>% para o próximo nível"><span style="width:<?=$p['progress']?>%"></span></div>
      </article>
    <?php endforeach;?>
  </div>
  <div class="lc-rank-list" role="list" aria-label="Classificação completa">
    <?php foreach($ranking as $p):?>
    <div class="lc-rank-row <?=$own===(int)$p['user_id']?'is-me':''?>" role="listitem">
      <span class="lc-rank-number">#<?=$p['rank']?></span>
      <span class="lc-rank-row-user">
        <span class="lc-rank-row-avatar"><?=lc_e(mb_strtoupper(mb_substr($p['nickname'],0,1)))?></span>
        <span><strong><?=lc_e('@'.(string)$p['nickname'])?> <?=lc_rank_trophy((int)$p['rank'])?><?=$own===(int)$p['user_id']?' · Você':''?></strong><small>Nível <?=$p['level']?> · <?=$p['approved_clips']?> <?=$p['approved_clips']===1?'clipe aprovado':'clipes aprovados'?></small></span>
      </span>
      <span class="lc-rank-row-view"><?=number_format($p['public_views'],0,',','.')?><small>views</small></span>
      <span class="lc-rank-row-xp"><?=number_format($p['xp'],0,',','.')?> XP<small><?=$p['next_xp']>0?'Faltam '.$p['next_xp'].' XP':'Nível máximo'?></small></span>
      <span class="lc-rank-progress" aria-label="<?=$p['progress']?>% do nível"><span style="width:<?=$p['progress']?>%"></span></span>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>
</section>
<?php
}
/** Netflix-style editorial catalog, responsive shelves without page overflow. */
function lc_campaign_catalog(array $campaigns,bool $admin=false): void {
    if(!$campaigns){
        lc_empty('Novas campanhas em breve',$admin?'Crie sua primeira campanha no formulário abaixo.':'Novas oportunidades aparecerão por aqui.');
        return;
    }
    $hero=null;
    foreach($campaigns as $item){if($item['status']==='active'){$hero=$item;break;}}
    $hero??=$campaigns[0];
    $groups=[];
    foreach($campaigns as $item){
        $category=trim((string)$item['category'])?:'Destaques';
        $groups[$category][]=$item;
    }
    ?>
<section class="lc-catalog-showcase" aria-label="Campanha em destaque">
  <?php if(lc_valid_https((string)$hero['cover_url'])):?>
  <img class="lc-catalog-hero-img" src="<?=lc_e($hero['cover_url'])?>" alt="" loading="eager" decoding="async">
  <?php endif;?>
  <div class="lc-catalog-hero-content">
    <span class="lc-catalog-topline"><?=lc_icon('sparkles')?> <?=!empty($hero['is_demo'])?'DEMONSTRAÇÃO':'LeagueCut em destaque'?></span>
    <h2><?=lc_e($hero['title'])?></h2>
    <p><?=lc_e(mb_substr((string)($hero['description']??''),0,220))?></p>
    <div class="lc-catalog-hero-meta">
      <span><?=lc_e($hero['category'])?></span>
      <span><?=lc_money((int)$hero['payment_per_video_cents'])?> / vídeo</span>
      <span><?=!empty($hero['is_demo'])?'Fictícia':($hero['status']==='active'?'Ativa':lc_e(lc_status_label($hero['status'])))?></span>
    </div>
    <div class="lc-catalog-hero-buttons">
      <a class="btn" href="<?=!empty($hero['is_demo'])?'/demo/campanhas/':'/campanhas/'?><?=lc_e($hero['slug'])?>"><?=lc_icon('play')?> Explorar e ver ranking</a>
      <?php if($admin&&empty($hero['is_demo'])):?><a class="btn btn-ghost" href="/admin/campanhas/<?=(int)$hero['id']?>/editar"><?=lc_icon('settings-2')?> Editar</a><?php endif;?>
    </div>
  </div>
</section>
<div class="lc-catalog-shelves">
<?php foreach($groups as $category=>$items):?>
<section class="lc-catalog-shelf" aria-label="Campanhas de <?=lc_e($category)?>">
  <div class="lc-catalog-shelf-heading"><div><span><?=lc_icon('clapperboard')?> Coleção</span><h2><?=lc_e($category)?></h2></div>
   <span><?=$admin?count($items).' no catálogo':count($items).' disponíveis'?></span></div>
  <div class="lc-catalog-grid">
    <?php foreach($items as $campaign):?>
    <article class="lc-catalog-item">
      <?php lc_tile($campaign); ?>
      <?php if($admin&&empty($campaign['is_demo'])):?><a href="/admin/campanhas/<?=(int)$campaign['id']?>/editar" class="lc-catalog-manage"><?=lc_icon('settings-2')?> Editar campanha</a><?php endif;?>
    </article>
    <?php endforeach;?>
  </div>
</section>
<?php endforeach;?>
</div>
<?php
}
