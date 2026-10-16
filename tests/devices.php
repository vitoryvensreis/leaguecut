<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/mfa.php';
require dirname(__DIR__).'/app/remember-device.php';
require dirname(__DIR__).'/app/devices.php';
function mustDevice(bool $yes,string $why):void {if(!$yes)throw new RuntimeException('B7A_DEVICE_FAILED: '.$why);}
$u=lc_one("SELECT id FROM users WHERE email='creator@leaguecut.test'");
mustDevice((bool)$u,'CI fixture available');
$uid=(int)$u['id'];
$selector=bin2hex(random_bytes(12));
$other=bin2hex(random_bytes(12));
foreach([$selector,$other] as $s)lc_query("INSERT INTO account_remember_tokens(user_id,selector,token_hash,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))",[$uid,$s,str_repeat('a',64)]);
try {
  $_COOKIE[LC_REMEMBER_COOKIE]=$selector.'.'.str_repeat('b',64);
  mustDevice(count(lc_devices_for_user($uid))===2,'two devices');
  mustDevice(lc_devices_revoke_others($uid)===1,'other token removed');
  mustDevice(count(lc_devices_for_user($uid))===1,'current remains');
  $row=lc_devices_for_user($uid)[0];
  mustDevice(lc_devices_revoke($uid,(int)$row['id']),'current token revoked');
  mustDevice(count(lc_devices_for_user($uid))===0,'no own tokens remain');
  echo "B7A_DEVICES_OK\n";
} finally {
  lc_query("DELETE FROM account_remember_tokens WHERE user_id=? AND selector IN (?,?)",[$uid,$selector,$other]);
}
