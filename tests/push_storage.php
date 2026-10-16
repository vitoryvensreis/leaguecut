<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/push-storage.php';
function push_check(bool $ok,string $why): void {
    if(!$ok)throw new RuntimeException('B6D2B1_PUSH_FAIL: '.$why);
}
push_check(!lc_push_registration_enabled(),'feature remains off until server rollout');
$endpoint='https://fcm.googleapis.com/fcm/send/veryLongBrowserSubscriptionToken123456';
$key=str_repeat('A',88);
$auth=str_repeat('B',22);
$payload=['endpoint'=>$endpoint,'keys'=>['p256dh'=>$key,'auth'=>$auth]];
push_check(lc_push_endpoint_safe($endpoint),'FCM official endpoint accepted');
foreach(['http://fcm.googleapis.com/fcm/send/a',
 'https://fcm.googleapis.com.attacker.test/abc',
 'https://user:pass@fcm.googleapis.com/fcm/send/a',
 'https://localhost/push',
 'https://fcm.googleapis.com:444/fcm/send/a'] as $url){
 push_check(!lc_push_endpoint_safe($url),'unsafe endpoint blocked');
}
try{lc_push_check_subscription(['endpoint'=>'javascript:alert(1)','keys'=>$payload['keys']]);throw new RuntimeException('invalid endpoint accepted');}
catch(InvalidArgumentException $expected){}
$u=lc_one("SELECT id FROM users WHERE email='creator@leaguecut.test'");
$other=lc_one("SELECT id FROM users WHERE email='admin@leaguecut.test'");
push_check((bool)$u&&(bool)$other,'two distinct test accounts');
$id=(int)$u['id'];$another=(int)$other['id'];
lc_push_store_subscription($id,$payload);
lc_push_store_subscription($id,$payload);
$rows=lc_all('SELECT * FROM push_subscriptions WHERE user_id=?',[$id]);
push_check(count($rows)===1,'identical subscription deduplicated');
push_check(!str_contains((string)$rows[0]['endpoint_enc'],$endpoint),'endpoint never stored plaintext');
push_check(lc_decrypt((string)$rows[0]['endpoint_enc'])===$endpoint,'encrypted endpoint decrypts only server-side');
$decoded=json_decode(lc_decrypt((string)$rows[0]['keys_enc']),true);
push_check($decoded['auth']===$auth,'encrypted keys intact');
push_check((int)(lc_one('SELECT enabled FROM push_preferences WHERE user_id=?',[$id])['enabled']??0)===1,'explicit consent stored');
try{lc_push_store_subscription($another,$payload);throw new RuntimeException('cross-account takeover accepted');}
catch(InvalidArgumentException $expected){}
push_check((int)(lc_one('SELECT user_id FROM push_subscriptions WHERE endpoint_hash=?',[hash('sha256',$endpoint)])['user_id']??0)===$id,'cross-account ownership preserved');
lc_push_remove_subscription($another,$endpoint);
push_check((int)(lc_one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id=?',[$id])['n']??0)===1,'other account cannot delete subscription');
lc_push_remove_subscription($id,$endpoint);
push_check((int)(lc_one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id=?',[$id])['n']??0)===0,'owner revocation works');
push_check((int)(lc_one('SELECT enabled FROM push_preferences WHERE user_id=?',[$id])['enabled']??1)===0,'no remaining devices disables push');
echo "B6D2B1_PUSH_STORAGE_OK\n";
