<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/nick-ranking.php';
function rank_assert(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('B3_NICK_RANK_FAILED: '.$why);
}
rank_assert(lc_nickname_validate('Vitor_99')==='vitor_99','nick lowercase normalized');
foreach(['ab','1abc','nome com espaco','lc_999','admin','áudio'] as $invalid){
    try{lc_nickname_validate($invalid);throw new RuntimeException('Accepted invalid nick '.$invalid);}
    catch(InvalidArgumentException $e){rank_assert(true,'rejected invalid nick');}
}
$u=lc_one("SELECT id,nickname FROM users WHERE email='creator@leaguecut.test'");
rank_assert($u && $u['nickname']!==null,'migrated registered user with nickname');
rank_assert($u['nickname']==='creator_tester','registration now requires an explicitly chosen nickname');
$rank=lc_public_ranking(50);
rank_assert(count($rank)>=1,'public leaderboard includes approved clipper');
foreach($rank as $r){
    rank_assert(isset($r['nickname'],$r['views'],$r['clips']),'public fields available');
    rank_assert(!isset($r['full_name'],$r['email'],$r['whatsapp']),'never expose PII');
}
$view=lc_one("SELECT COALESCE(SUM(COALESCE((SELECT d.views FROM clip_daily_views d
 WHERE d.clip_id=c.id ORDER BY d.snapshot_date DESC,d.id DESC LIMIT 1),0)),0) AS n
 FROM clips c WHERE c.status='approved' AND c.user_id=?",[$u['id']]);
$found=array_values(array_filter($rank,fn($r)=>$r['nickname']===$u['nickname']));
rank_assert(count($found)===1 && (int)$found[0]['views']===(int)$view['n'],'sum latest observed views only');
$db=lc_db();$db->beginTransaction();
try{
    $nick='Testing_'.bin2hex(random_bytes(3));
    lc_nickname_change((int)$u['id'],$nick);
    rank_assert(lc_one('SELECT nickname FROM users WHERE id=?',[$u['id']])['nickname']===strtolower($nick),'editable nickname');
    // Inserted test row is rolled back with the entire test.
    lc_query("INSERT INTO users(full_name,email,password_hash,whatsapp,instagram,pix_key_enc,role,status)
      VALUES('Temporary CI','nick-collision@leaguecut.test','ci','85999998888','', '', 'clipador','approved')");
    $otherId=(int)$db->lastInsertId();
    lc_nickname_complete($otherId);
    try{lc_nickname_change($otherId,$nick);throw new RuntimeException('Duplicate nick allowed');}
    catch(InvalidArgumentException $e){rank_assert(true,'case-insensitive uniqueness enforced');}
}finally{$db->rollBack();}
echo "B3_NICK_RANK_OK\n";
