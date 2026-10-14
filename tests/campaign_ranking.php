<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/campaign-experience.php';
$c=lc_one("SELECT id FROM campaigns WHERE title=? ORDER BY id DESC LIMIT 1",['Campanha Teste Real']);
if(!$c)throw new RuntimeException('Missing campaign CI fixture.');
$campaignId=(int)$c['id'];
$rank=lc_campaign_rankings($campaignId);
if(count($rank)!==1||$rank[0]['approved_clips']!==5 ||
   $rank[0]['public_views']!==0 || $rank[0]['xp']!==500 ||
   $rank[0]['level']!==2||$rank[0]['rank']!==1)
    throw new RuntimeException('Leaderboard score does not match approved-only and zero observed views.');
if(!str_starts_with(lc_campaign_public_name('Criador Teste',false,100),'Clipador #'))
    throw new RuntimeException('Guest leaderboard must anonymize names.');
if(lc_campaign_public_name('Criador Teste',true,100)!=='Criador T.')
    throw new RuntimeException('Logged-in leaderboard nickname is incorrect.');
$clip=lc_one("SELECT id FROM clips WHERE campaign_id=? AND status='approved' ORDER BY id LIMIT 1",[$campaignId]);
if(!$clip)throw new RuntimeException('No approved clip fixture.');
$viewsDate=(new DateTimeImmutable('now',new DateTimeZone('America/Fortaleza')))->format('Y-m-d');
$db=lc_db();$db->beginTransaction();
try{
    lc_query("INSERT INTO clip_daily_views(clip_id,snapshot_date,views,source,observed_at)
       VALUES(?,?,?,'apify',UTC_TIMESTAMP())",
       [(int)$clip['id'],$viewsDate,1733]);
    $ranked=lc_campaign_rankings($campaignId);
    if($ranked[0]['public_views']!==1733 || $ranked[0]['xp']!==517 ||
       $ranked[0]['progress']!==3 || $ranked[0]['next_xp']!==483)
       throw new RuntimeException('Daily views not contributing correctly to XP.');
    $ledger=lc_one("SELECT COUNT(*) AS n FROM ledger_entries WHERE campaign_id=?",[$campaignId]);
    $before=(int)($ledger['n']??0);
    lc_campaign_rankings($campaignId);
    $after=(int)(lc_one("SELECT COUNT(*) AS n FROM ledger_entries WHERE campaign_id=?",[$campaignId])['n']??0);
    if($before!==$after)throw new RuntimeException('Leaderboard must not issue financial credits.');
}finally{$db->rollBack();}
echo "CAMPAIGN_RANKING_GAMIFIED_OK\n";
