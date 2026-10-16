<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/push-storage.php';
require dirname(__DIR__).'/app/push-sender.php';
require dirname(__DIR__).'/app/push-selftest.php';
function testPush(bool $v,string $why):void{if(!$v)throw new RuntimeException('B7D_PUSH_TEST_FAILED: '.$why);}
putenv('PUSH_REGISTRATION_ENABLED=0');putenv('PUSH_SENDING_ENABLED=0');
$user=lc_one("SELECT id,role,status FROM users WHERE email='creator@leaguecut.test'");
testPush((bool)$user,'fixture');
try{lc_push_selftest_enqueue($user);throw new RuntimeException('disabled gates accepted');}
catch(InvalidArgumentException $expected){}
$keys=\Minishlink\WebPush\VAPID::createVapidKeys();
putenv('PUSH_VAPID_PUBLIC='.$keys['publicKey']);
putenv('PUSH_VAPID_PRIVATE='.$keys['privateKey']);
putenv('PUSH_VAPID_SUBJECT=mailto:ci@leaguecut.test');
putenv('PUSH_REGISTRATION_ENABLED=1');putenv('PUSH_SENDING_ENABLED=1');
$uid=(int)$user['id'];
$endpoint='https://fcm.googleapis.com/fcm/send/b7d_test_'.bin2hex(random_bytes(12));
lc_push_store_subscription($uid,['endpoint'=>$endpoint,
  'keys'=>['p256dh'=>str_repeat('A',88),'auth'=>str_repeat('B',22)]]);
try{
 $id=lc_push_selftest_enqueue($user);
 testPush($id>0,'private event queued');
 $one=lc_one('SELECT scope,user_id,event_type FROM realtime_events WHERE id=?',[$id]);
 testPush($one['scope']==='user'&&(int)$one['user_id']===$uid&&$one['event_type']==='push.test','owner scoped');
 try{lc_push_selftest_enqueue($user);throw new RuntimeException('duplicate accepted');}
 catch(InvalidArgumentException $expected){}
 $reports=lc_push_send_batch(static fn($subscription,$payload)=>['success'=>true,'expired'=>false]);
 testPush($reports['accepted']>=1,'worker mock sends self test');
 echo "B7D_PUSH_SELFTEST_OK\n";
} finally{
 lc_query("DELETE FROM realtime_events WHERE user_id=? AND event_type='push.test'",[$uid]);
 lc_push_remove_subscription($uid,$endpoint);
 putenv('PUSH_REGISTRATION_ENABLED=0');putenv('PUSH_SENDING_ENABLED=0');
}
