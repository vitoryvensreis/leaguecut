<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';

$role=lc_one("SELECT COLUMN_TYPE AS role_type FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'");
if(!$role || !str_contains($role['role_type'],"'criador'"))
    throw new RuntimeException('User enum missing creator role.');
foreach(['creator_packages','creator_subscriptions','creator_subscription_cycles','creator_deliveries'] as $table){
    $row=lc_one('SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
    if(!$row)throw new RuntimeException('Missing creator table '.$table);
}
$buyer=lc_one('SELECT id,role,status FROM users WHERE email=?',['buyer@leaguecut.test']);
$admin=lc_one('SELECT id,role FROM users WHERE email=?',['admin@leaguecut.test']);
$clipper=lc_one('SELECT id,role FROM users WHERE email=?',['creator@leaguecut.test']);
if(!$buyer || $buyer['role']!=='criador' || $buyer['status']!=='approved' ||
   !$admin || $admin['role']!=='admin' || !$clipper || $clipper['role']!=='clipador')
    throw new RuntimeException('Existing account roles were unexpectedly modified.');
$package=lc_one("SELECT * FROM creator_packages WHERE fulfillment_mode='download' AND status='active' ORDER BY id LIMIT 1");
if(!$package)throw new RuntimeException('Missing package fixture');
$start='2026-10-14';$end='2026-11-14';
$db=lc_db();
$db->beginTransaction();
try{
    lc_query("INSERT INTO creator_subscriptions(creator_id,package_id,status,price_cents_snapshot,monthly_clips_snapshot,fulfillment_mode_snapshot)
        VALUES(?,?,'pending',?,?,?)",[
        $buyer['id'],$package['id'],$package['monthly_price_cents'],$package['monthly_clips'],$package['fulfillment_mode']]);
    $subId=(int)$db->lastInsertId();
    lc_query("INSERT INTO creator_subscription_cycles(subscription_id,cycle_start,cycle_end,clips_included,payment_status)
              VALUES(?,?,?,?,'pending')",[$subId,$start,$end,$package['monthly_clips']]);
    $cycleId=(int)$db->lastInsertId();
    lc_query("INSERT INTO creator_deliveries(subscription_id,cycle_id,creator_id,title,status)
              VALUES(?,?,?,'Clip preparado','queued')",[$subId,$cycleId,$buyer['id']]);
    $delivery=lc_one('SELECT * FROM creator_deliveries WHERE creator_id=? AND cycle_id=?',[$buyer['id'],$cycleId]);
    $cycle=lc_one('SELECT * FROM creator_subscription_cycles WHERE id=?',[$cycleId]);
    if(!$delivery || $delivery['status']!=='queued' ||
       !$cycle || $cycle['payment_status']!=='pending' || (int)$cycle['clips_used']!==0)
        throw new RuntimeException('Premature quota activation or data corruption.');
}finally {
    if($db->inTransaction())$db->rollBack();
}
$sub=lc_one("SELECT s.id,s.status,s.cancelled_at,cy.payment_status,cy.clips_included,cy.clips_used
 FROM creator_subscriptions s JOIN creator_subscription_cycles cy ON cy.subscription_id=s.id
 WHERE s.creator_id=? ORDER BY s.id DESC LIMIT 1",[$buyer['id']]);
if(!$sub || $sub['status']!=='cancelled' || !$sub['cancelled_at'] ||
   $sub['payment_status']!=='confirmed' || (int)$sub['clips_included']!==12 ||
   (int)$sub['clips_used']!==0)
    throw new RuntimeException('Creator billing lifecycle fixture was modified unexpectedly.');
echo "CREATOR_SCHEMA_PAID_CYCLE_AND_CANCELLATION_OK\n";
