<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/mfa.php';
require dirname(__DIR__).'/app/remember-device.php';
function remember_assert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('B6_REMEMBER_FAILED: '.$why);}
session_start();
$user=lc_one("SELECT id FROM users WHERE email='creator@leaguecut.test'");
remember_assert((bool)$user,'CI user available');
$uid=(int)$user['id'];
$state=lc_mfa_state($uid);
$version=(int)($state['session_version']??0);
$enabled=$state && $state['enabled_at']!==null;
$selector=bin2hex(random_bytes(12));$validator=bin2hex(random_bytes(32));
lc_query("INSERT INTO account_remember_tokens (user_id,selector,token_hash,mfa_version,mfa_verified,expires_at)
 VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 5 DAY))",[$uid,$selector,lc_remember_key($selector,$validator),$version,$enabled?1:0]);
$_SESSION=[];$_COOKIE[LC_REMEMBER_COOKIE]=$selector.'.'.$validator;
remember_assert(lc_remember_restore(),'valid cookie restores authentication');
remember_assert((int)($_SESSION['uid']??0)===$uid,'user restored');
remember_assert((int)($_SESSION['mfa_version']??-1)===$version,'MFA version restored');
$new=lc_one('SELECT selector,token_hash FROM account_remember_tokens WHERE user_id=? ORDER BY id DESC LIMIT 1',[$uid]);
remember_assert($new && $new['selector']!==$selector,'validator and selector rotate on use');
$_SESSION=[];$_COOKIE[LC_REMEMBER_COOKIE]=$selector.'.'.$validator;
remember_assert(!lc_remember_restore(),'replay of used cookie denied');
$expSelector=bin2hex(random_bytes(12));$expValidator=bin2hex(random_bytes(32));
lc_query("INSERT INTO account_remember_tokens (user_id,selector,token_hash,mfa_version,mfa_verified,expires_at)
 VALUES(?,?,?,?,?,DATE_SUB(NOW(),INTERVAL 1 DAY))",[$uid,$expSelector,lc_remember_key($expSelector,$expValidator),$version,$enabled?1:0]);
$_COOKIE[LC_REMEMBER_COOKIE]=$expSelector.'.'.$expValidator;
remember_assert(!lc_remember_restore(),'expired cookie denied');
$_COOKIE[LC_REMEMBER_COOKIE]=$new['selector'].'.'.str_repeat('0',64);
remember_assert(!lc_remember_restore(),'unknown validator denied');
$_COOKIE[LC_REMEMBER_COOKIE]=$new['selector'].'.'.str_repeat('1',64);
lc_remember_revoke_current();
remember_assert(!lc_one('SELECT id FROM account_remember_tokens WHERE selector=?',[$new['selector']]),'logout revokes current device');
lc_query('DELETE FROM account_remember_tokens WHERE user_id=?',[$uid]);
echo "B6_REMEMBER_OK\n";
