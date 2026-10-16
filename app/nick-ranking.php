<?php
declare(strict_types=1);

/**
 * Nicknames are public identifiers. Restrict to non-personal, ASCII handles
 * so the normalized unique index and case-insensitive login behave the same.
 */
function lc_nickname_normalize(string $raw): string {
    return strtolower(trim($raw));
}
function lc_nickname_validate(string $raw): string {
    $nick=lc_nickname_normalize($raw);
    if(!preg_match('/^[a-z][a-z0-9_]{2,23}$/D',$nick) ||
       str_starts_with($nick,'lc_') ||
       in_array($nick,['admin','leaguecut','suporte','support','moderador','system','null','root','api','security'],true)){
        throw new InvalidArgumentException('Nick inválido. Use 3 a 24 caracteres: letras, números e _. Comece com uma letra.');
    }
    return $nick;
}
function lc_nickname_auto(int $id): string {
    return 'lc_'.$id;
}
/** Called just after INSERT on sites with a legacy form that omitted nickname. */
function lc_nickname_complete(int $id,string $submitted=''): string {
    $candidate=trim($submitted)!==''?lc_nickname_validate($submitted):lc_nickname_auto($id);
    try{
        lc_query('UPDATE users SET nickname=? WHERE id=? AND nickname IS NULL',[$candidate,$id]);
    }catch(PDOException $e){
        if((string)$e->getCode()==='23000')
            throw new InvalidArgumentException('Este nickname já está em uso. Escolha outro.');
        throw $e;
    }
    return $candidate;
}
function lc_nickname_change(int $id,string $submitted): string {
    $new=lc_nickname_validate($submitted);
    try{
        lc_query('UPDATE users SET nickname=? WHERE id=?',[$new,$id]);
    }catch(PDOException $e){
        if((string)$e->getCode()==='23000')
            throw new InvalidArgumentException('Este nickname já está em uso.');
        throw $e;
    }
    return $new;
}
/** Only last observed reading of each approved clip, never cumulative snapshots. */
function lc_public_ranking(int $limit=15): array {
    $limit=max(1,min(50,$limit));
    $real=lc_all("SELECT u.nickname AS nickname,COUNT(c.id) AS clips,
          COALESCE(SUM(COALESCE((SELECT d.views FROM clip_daily_views d
                   WHERE d.clip_id=c.id ORDER BY d.snapshot_date DESC,d.id DESC LIMIT 1),0)),0) AS views
      FROM clips c INNER JOIN users u ON u.id=c.user_id
      WHERE c.status='approved' AND u.status='approved' AND u.role='clipador'
         AND u.nickname IS NOT NULL
      GROUP BY u.id,u.nickname
      ORDER BY views DESC,clips DESC,u.id ASC LIMIT ".(int)$limit);
    return lc_demo_merge_ranking($real,$limit);
}
function lc_ranking_routes(string $method,string $path): bool {
    if($path!=='/ranking'||$method!=='GET')return false;
    $rank=lc_public_ranking(50);
    lc_page('Ranking geral de clipadores',function()use($rank){?>
<div class="container lc-rank-container">
<?php lc_heading('Arena LeagueCut','Ranking geral de clipadores',
   lc_demo_enabled()?'Classificação ilustrativa para demonstração comercial.':'Confira os destaques da comunidade e acompanhe quem está no topo.'); ?>
<div class="panel"><div class="panel-head"><h2><?=lc_icon('trophy')?> Ranking de visualizações</h2>
<span class="tag"><?=lc_demo_enabled()?'DEMONSTRAÇÃO · DADOS FICTÍCIOS':'Top '.count($rank)?></span></div>
<?php if(!$rank):?>
<p class="hint">Os primeiros destaques aparecerão por aqui em breve.</p>
<?php else:?>
<div class="lc-leaderboard" role="list">
<?php foreach($rank as $pos=>$person):?>
<div class="lc-leaderboard-row <?=$pos<3?'is-podium':''?>" data-arena-position="<?=$pos+1?>" role="listitem">
<span class="lc-leaderboard-pos"><?=$pos+1?>º</span>
<span class="lc-leaderboard-avatar" aria-hidden="true"><?=lc_e(strtoupper(substr($person['nickname'],0,1)))?></span>
<span class="lc-leaderboard-player"><strong>@<?=lc_e($person['nickname'])?></strong>
<small><?=(int)$person['clips']?> <?=!empty($person['is_demo'])?'clipes simulados':'clipes aprovados'?></small></span>
<span class="lc-leaderboard-views"><?=number_format((int)$person['views'],0,',','.')?><small><?=!empty($person['is_demo'])?'views fictícias':'visualizações'?></small></span>
</div>
<?php endforeach;?></div>
<?php endif;?>
</div></div>
<?php },'ranking');return true;
}
