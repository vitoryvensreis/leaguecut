<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/push-storage.php';
require dirname(__DIR__).'/app/push-sender.php';
function assertPush(bool $yes,string $note):void {
    if(!$yes)throw new RuntimeException('B6D2B2_PUSH_FAILED: '.$note);
}
putenv('PUSH_REGISTRATION_ENABLED=0');
putenv('PUSH_SENDING_ENABLED=0');
assertPush(!lc_push_send_ready(),'sender must be off by default');
assertPush(lc_push_send_batch()['accepted']===0,'no database delivery when disabled');
assertPush(!lc_push_vapid_configured(),'empty VAPID should be rejected');
$keys=\Minishlink\WebPush\VAPID::createVapidKeys();
assertPush(is_array($keys)&&!empty($keys['publicKey'])&&!empty($keys['privateKey']),'generate VAPID keys for isolated test');
putenv('PUSH_VAPID_PUBLIC='.$keys['publicKey']);
putenv('PUSH_VAPID_PRIVATE='.$keys['privateKey']);
putenv('PUSH_VAPID_SUBJECT=mailto:ci@leaguecut.test');
assertPush(lc_push_vapid_configured(),'valid generated keys pass readiness');
putenv('PUSH_REGISTRATION_ENABLED=1');
assertPush(!lc_push_send_ready(),'cannot send with sender gate disabled');
putenv('PUSH_SENDING_ENABLED=1');
assertPush(lc_push_send_ready(),'explicit double opt-in enables test sender');
$payload=lc_push_payload(17,'user','clipador');
assertPush(str_contains($payload,'"/dashboard"')&&!str_contains($payload,'email')&&!str_contains($payload,'cpf'),'push payload generic and safe');
$uid=(int)(lc_one("SELECT id FROM users WHERE email='admin@leaguecut.test'")['id']??0);
assertPush($uid>0,'CI admin fixture');
$endpoint='https://fcm.googleapis.com/fcm/send/ci_test_subscription_'.bin2hex(random_bytes(12));
$sub=['endpoint'=>$endpoint,'keys'=>['p256dh'=>str_repeat('A',88),'auth'=>str_repeat('B',22)]];
lc_push_store_subscription($uid,$sub);
lc_realtime_emit('admin',null,'creator.registered','test',null);
$events=[];
$mock=function(array $s,string $message)use(&$events):array{
    $events[]=['endpoint'=>$s['endpoint'],'payload'=>json_decode($message,true)];
    return ['success'=>true,'expired'=>false];
};
$first=lc_push_send_batch($mock);
assertPush($first['accepted']>=1,'provider mock accepted at least one queued push');
assertPush(count($events)===$first['accepted'],'mock transport used for all accepted events');
$second=lc_push_send_batch($mock);
assertPush($second['accepted']===0,'do not resend already accepted events');
foreach($events as $entry)assertPush(($entry['payload']['title']??'')==='LeagueCut' && !str_contains(json_encode($entry),$keys['privateKey']),'generic alert and no VAPID disclosure');
lc_push_remove_subscription($uid,$endpoint);
putenv('PUSH_SENDING_ENABLED=0');
assertPush(!lc_push_send_ready(),'sending gate can disable immediately');
echo "B6D2B2_VAPID_QUEUE_OK\n";
