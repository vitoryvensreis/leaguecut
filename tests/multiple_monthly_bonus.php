<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/monthly-bonus.php';
function bonus_assert(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('MULTI_BONUS_FAILED: '.$message);
}
$campaign=lc_one("SELECT id FROM campaigns WHERE title=? ORDER BY id DESC LIMIT 1",['Campanha Teste Real']);
$clipper=lc_one("SELECT id FROM users WHERE email='creator@leaguecut.test' AND role='clipador' AND status='approved'");
$admin=lc_one("SELECT id FROM users WHERE email='admin@leaguecut.test' AND role='admin'");
bonus_assert((bool)$campaign && (bool)$clipper && (bool)$admin,'smoke fixtures required');
$month=(new DateTimeImmutable('first day of previous month',new DateTimeZone('America/Fortaleza')))->format('Y-m');
$db=lc_db();$db->beginTransaction();
try{
    lc_query("UPDATE clips SET submitted_at=CONCAT(?,'-15 12:00:00'),
      verified_views=1000 WHERE campaign_id=? AND user_id=? AND status='approved'",
      [$month,$campaign['id'],$clipper['id']]);
    $clips=lc_one("SELECT COUNT(*) AS n FROM clips WHERE campaign_id=? AND user_id=? AND status='approved'",
      [$campaign['id'],$clipper['id']]);
    bonus_assert((int)$clips['n']===5,'five approved clips');
    foreach([['Mais views','verified_views',1250],['Mais cortes','approved_clips',1750]] as [$name,$metric,$cents]){
        lc_query('INSERT INTO monthly_bonus_programs(campaign_id,name,metric,amount_cents,created_by)
            VALUES(?,?,?,?,?)',[$campaign['id'],$name,$metric,$cents,$admin['id']]);
        $ids[]=(int)$db->lastInsertId();
    }
    $a=lc_monthly_bonus_award($ids[0],$month);
    $b=lc_monthly_bonus_award($ids[1],$month);
    bonus_assert($a['status']==='created' && $b['status']==='created','distinct bonuses both awarded');
    bonus_assert((int)$a['amount_cents']===1250 && (int)$b['amount_cents']===1750,'correct program amounts');
    bonus_assert((int)$a['user_id']===(int)$clipper['id'] && (int)$b['user_id']===(int)$clipper['id'],'same eligible winner');
    $again=lc_monthly_bonus_award($ids[0],$month);
    bonus_assert($again['status']==='already','bonus and month idempotent');
    $paid=(int)(lc_one("SELECT COUNT(*) AS n FROM ledger_entries
       WHERE dedupe_key IN (?,?)",["monthly_bonus:{$ids[0]}:$month","monthly_bonus:{$ids[1]}:$month"])['n']??0);
    bonus_assert($paid===2,'one ledger credit per unique program/month');
    try{lc_monthly_bonus_award($ids[0],date('Y-m'));throw new RuntimeException('Current month paid');}
    catch(InvalidArgumentException $e){bonus_assert(true,'reject open month');}
    lc_query('UPDATE monthly_bonus_programs SET enabled=0 WHERE id=?',[$ids[1]]);
    $otherMonth=(new DateTimeImmutable($month.'-01'))->modify('-1 month')->format('Y-m');
    try{lc_monthly_bonus_award($ids[1],$otherMonth);throw new RuntimeException('Paused bonus paid');}
    catch(InvalidArgumentException $e){bonus_assert(true,'reject paused program');}
    echo "MULTI_MONTHLY_BONUS_OK\n";
}finally{if($db->inTransaction())$db->rollBack();}
