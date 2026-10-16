<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/mfa.php';
require dirname(__DIR__).'/app/otp-challenge.php';
require dirname(__DIR__).'/app/password-recovery.php';
function reset_assert(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('B6_RESET_FAILED: '.$why);
}
$u=lc_one("SELECT id,password_hash FROM users WHERE email='creator@leaguecut.test'");
reset_assert((bool)$u,'CI fixture available');
$uid=(int)$u['id'];
$old=$u['password_hash'];
$before=(int)(lc_one('SELECT COUNT(*) AS n FROM password_reset_challenges')['n']??0);
lc_reset_begin('totally-unknown-user@example.invalid','email');
$after=(int)(lc_one('SELECT COUNT(*) AS n FROM password_reset_challenges')['n']??0);
reset_assert($before===$after,'unknown user cannot trigger code');
$code='87419420';
lc_query("INSERT INTO password_reset_challenges
  (user_id,channel,session_hash,code_hash,status,expires_at)
  VALUES(?,'email',?,?,'ready',DATE_ADD(NOW(), INTERVAL 10 MINUTE))",
  [$uid,lc_otp_session_binding($uid),str_repeat('0',64)]);
$id=(int)lc_db()->lastInsertId();
lc_query('UPDATE password_reset_challenges SET code_hash=? WHERE id=?',[lc_reset_hash($id,$code),$id]);
$_SESSION['lc_reset_id']=$id;
reset_assert(lc_reset_finish('11111111','Test-Another-Strong-Password-2026')===false,'incorrect code rejected');
$attempts=(int)lc_one('SELECT attempts FROM password_reset_challenges WHERE id=?',[$id])['attempts'];
reset_assert($attempts===1,'wrong guess increments attempts');
reset_assert(lc_reset_finish($code,'short')===false,'weak new password rejected');
reset_assert(lc_reset_finish($code,'Changed-LeagueCut-Password-2026')===true,'correct one-time code resets password');
$new=lc_one('SELECT password_hash FROM users WHERE id=?',[$uid]);
reset_assert(password_verify('Changed-LeagueCut-Password-2026',$new['password_hash']),'new password stored hashed');
reset_assert($new['password_hash']!==$old,'original hash replaced');
reset_assert(!password_verify('This-Is-A-Test-Password-123',$new['password_hash']),'old password invalidated');
reset_assert(lc_one('SELECT status FROM password_reset_challenges WHERE id=?',[$id])['status']==='used','code marked used');
$_SESSION['lc_reset_id']=$id;
reset_assert(lc_reset_finish($code,'Changed-Again-Strong-Password-2026')===false,'replay rejected');
lc_query('UPDATE users SET password_hash=? WHERE id=?',[$old,$uid]);
echo "B6_RESET_OK\n";
