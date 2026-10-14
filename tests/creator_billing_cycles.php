<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/creator-billing.php';

foreach(['2026-01-31'=>'2026-02-28','2028-01-31'=>'2028-02-29',
         '2026-04-30'=>'2026-05-30','2026-10-14'=>'2026-11-14'] as $start=>$expected) {
    if(lc_creator_cycle_end($start)!==$expected)
        throw new RuntimeException('Month-end calculation failed for '.$start);
}
$buyer=lc_one("SELECT id FROM users WHERE email='buyer@leaguecut.test' AND role='criador'");
$admin=lc_one("SELECT id FROM users WHERE email='admin@leaguecut.test' AND role='admin'");
$package=lc_one("SELECT * FROM creator_packages WHERE status='active' AND fulfillment_mode='download' LIMIT 1");
if(!$buyer || !$admin || !$package)throw new RuntimeException('Billing fixtures not present.');
$existing=lc_one("SELECT * FROM creator_subscriptions WHERE creator_id=? AND package_id=? ORDER BY id DESC LIMIT 1",
    [$buyer['id'],$package['id']]);
if(!$existing || $existing['status']!=='cancelled')throw new RuntimeException('Expected cancelled paid subscription.');
$balance=lc_creator_effective_balance((int)$existing['id'],(int)$buyer['id']);
if(!$balance || (int)$balance['remaining']!==(int)$package['monthly_clips'])
    throw new RuntimeException('Cancelled but prepaid period must remain accessible.');

$db=lc_db();
$db->beginTransaction();
try{
    lc_query("UPDATE creator_subscription_cycles SET payment_status='pending' WHERE id=?",[$balance['id']]);
    if(lc_creator_effective_balance((int)$existing['id'],(int)$buyer['id']))
        throw new RuntimeException('Unpaid subscription improperly unlocked quota.');
}finally{if($db->inTransaction())$db->rollBack();}

// Exercise initial confirmation and renewal only against the disposable CI DB.
// An active subscription alone must never create a paid cycle.
lc_query("INSERT INTO creator_subscriptions
  (creator_id,package_id,status,price_cents_snapshot,monthly_clips_snapshot,fulfillment_mode_snapshot)
  VALUES(?,?,'pending',?,?,?)",
  [$buyer['id'],$package['id'],$package['monthly_price_cents'],$package['monthly_clips'],$package['fulfillment_mode']]);
$id=(int)$db->lastInsertId();
if(lc_creator_effective_balance($id,(int)$buyer['id'])!==null)
    throw new RuntimeException('Pending request was granted credits.');
lc_creator_confirm_payment($id,(int)$admin['id']);
$cycle=lc_one('SELECT * FROM creator_subscription_cycles WHERE subscription_id=?',[$id]);
if(!$cycle || $cycle['payment_status']!=='confirmed' || (int)$cycle['clips_used']!==0)
    throw new RuntimeException('Initial payment did not create a valid confirmed cycle.');
try {
    lc_creator_confirm_payment($id,(int)$admin['id']);
    throw new RuntimeException('Duplicate payment confirmation was accepted.');
}catch(RuntimeException $e){
    if(str_contains($e->getMessage(),'Duplicate payment'))throw $e;
}
$pastStart=(new DateTimeImmutable('today'))->modify('-2 months')->format('Y-m-d');
$pastEnd=(new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
lc_query('UPDATE creator_subscription_cycles SET cycle_start=?,cycle_end=? WHERE id=?',
    [$pastStart,$pastEnd,$cycle['id']]);
if(lc_creator_effective_balance($id,(int)$buyer['id'])!==null)
    throw new RuntimeException('Expired cycle remained redeemable.');
lc_creator_confirm_payment($id,(int)$admin['id'],true);
$count=(int)lc_one('SELECT COUNT(*) AS n FROM creator_subscription_cycles WHERE subscription_id=?',[$id])['n'];
if($count!==2 || !lc_creator_effective_balance($id,(int)$buyer['id']))
    throw new RuntimeException('Manual renewal did not activate exactly one new cycle.');
echo "CREATOR_BILLING_CYCLES_SAFE_OK\n";
