<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/mfa.php';
require dirname(__DIR__).'/app/automation-engine.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/account-self.php';
require dirname(__DIR__).'/app/otp-challenge.php';
require dirname(__DIR__).'/vendor/autoload.php';
function otp_assert(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('OTP_TEST_FAILED: '.$message);
}
$stamp=bin2hex(random_bytes(5));
lc_query("INSERT INTO users(full_name,email,password_hash,whatsapp,instagram,pix_key_enc,role,status,consent_whatsapp)
 VALUES(?,?,?,?,?,?,'clipador','approved',1)",[
    'Teste Seguro','otp-ci-'.$stamp.'@example.com',password_hash('ci-test-password-12345',PASSWORD_DEFAULT),
    '85999991111','otp_'.$stamp,lc_encrypt('test-pix')]);
$uid=(int)lc_db()->lastInsertId();
$user=lc_one('SELECT id,full_name,email,whatsapp,role,status,consent_whatsapp FROM users WHERE id=?',[$uid]);
otp_assert(is_array($user),'test user');
try{
    otp_assert(lc_otp_hash(12,'000001')!==lc_otp_hash(13,'000001'),'hash bound to challenge');
    otp_assert(lc_otp_hash(12,'000001')!==lc_otp_hash(12,'000002'),'hash bound to code');
    otp_assert(!lc_otp_channel_available($user,'unknown'),'reject untrusted channel');
    otp_assert(lc_otp_mask('email-test@exemplo.com','email')==='em***@exemplo.com','mask contact');
    $payload=['full_name'=>'Nome Alterado','email'=>$user['email'],'whatsapp'=>$user['whatsapp'],'new_password'=>'','new_pix_key'=>''];
    $enc=lc_encrypt(json_encode($payload,JSON_THROW_ON_ERROR));
    $otp='349011';
    lc_query("INSERT INTO account_otp_challenges
      (user_id,purpose,channel,session_hash,code_hash,payload_enc,status,expires_at)
      VALUES(?,'account_update','email',?, ?,?,'ready',DATE_ADD(NOW(),INTERVAL 5 MINUTE))",
      [$uid,lc_otp_session_binding($uid),str_repeat('0',64),$enc]);
    $id=(int)lc_db()->lastInsertId();
    lc_query('UPDATE account_otp_challenges SET code_hash=? WHERE id=?',[lc_otp_hash($id,$otp),$id]);
    $_SESSION=['uid'=>$uid,'mfa_version'=>0,'lc_otp_pending'=>$id];
    try{lc_otp_verify_and_apply($user,'111111');throw new RuntimeException('wrong OTP accepted');}
    catch(InvalidArgumentException $e){otp_assert(true,'wrong OTP rejected');}
    $state=lc_one('SELECT attempts,status FROM account_otp_challenges WHERE id=?',[$id]);
    otp_assert((int)$state['attempts']===1 && $state['status']==='ready','attempt tracked, no mutation');
    otp_assert(lc_one('SELECT full_name FROM users WHERE id=?',[$uid])['full_name']==='Teste Seguro','wrong code unchanged');
    otp_assert(lc_otp_verify_and_apply($user,$otp)==='/conta','correct code performs action');
    otp_assert(lc_one('SELECT full_name FROM users WHERE id=?',[$uid])['full_name']==='Nome Alterado','account payload applied atomically');
    $state=lc_one('SELECT status,payload_enc FROM account_otp_challenges WHERE id=?',[$id]);
    otp_assert($state['status']==='used'&&$state['payload_enc']==='','operation consumed and payload removed');
    $_SESSION['lc_otp_pending']=$id;
    try{lc_otp_verify_and_apply($user,$otp);throw new RuntimeException('replayed OTP accepted');}
    catch(InvalidArgumentException $e){otp_assert(true,'replay rejected');}
    // Expired code cannot authorize anything.
    lc_query("INSERT INTO account_otp_challenges
      (user_id,purpose,channel,session_hash,code_hash,payload_enc,status,expires_at)
      VALUES(?,'account_update','email',?, ?,?,'ready',DATE_SUB(NOW(),INTERVAL 1 MINUTE))",
      [$uid,lc_otp_session_binding($uid),str_repeat('a',64),$enc]);
    $_SESSION['lc_otp_pending']=(int)lc_db()->lastInsertId();
    try{lc_otp_verify_and_apply($user,'123456');throw new RuntimeException('expired OTP accepted');}
    catch(InvalidArgumentException $e){otp_assert(true,'expired code rejected');}
    // Unaccepted send state must never be valid.
    lc_query("INSERT INTO account_otp_challenges
      (user_id,purpose,channel,session_hash,code_hash,payload_enc,status,expires_at)
      VALUES(?,'account_update','email',?, ?,?,'sending',DATE_ADD(NOW(),INTERVAL 5 MINUTE))",
      [$uid,lc_otp_session_binding($uid),str_repeat('b',64),$enc]);
    $_SESSION['lc_otp_pending']=(int)lc_db()->lastInsertId();
    try{lc_otp_verify_and_apply($user,'123456');throw new RuntimeException('unaccepted provider code accepted');}
    catch(InvalidArgumentException $e){otp_assert(true,'sending code rejected');}
    unset($_SESSION['lc_otp_pending']);
    echo "OTP_SECURITY_OK\n";
}finally{
    $_SESSION=[];
    lc_query('DELETE FROM users WHERE id=?',[$uid]);
}
