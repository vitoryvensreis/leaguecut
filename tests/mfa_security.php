<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/mfa.php';
function assertion(bool $result,string $message): void{
 if(!$result)throw new RuntimeException('MFA_TEST_FAILED: '.$message);
}
$testSecret=lc_mfa_base32_encode('12345678901234567890');
assertion($testSecret==='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ','base32 RFC vector');
assertion(lc_mfa_otp($testSecret,1)==='287082','RFC6238 at 59 seconds');
assertion(lc_mfa_otp($testSecret,37037036)==='081804','RFC6238 at 1111111109 seconds');
assertion(lc_mfa_find_step($testSecret,'287082',59,0)===1,'correct OTP accepted');
assertion(lc_mfa_find_step($testSecret,'287082',59,1)===null,'replayed OTP rejected');
assertion(lc_mfa_find_step($testSecret,'abc123',59)===null,'non numeric rejected');
[$plain,$hashes]=lc_mfa_make_recovery();
assertion(count($plain)===10&&count($hashes)===10,'ten recovery codes');
assertion($hashes[0]===lc_mfa_recovery_key($plain[0]),'recovery HMAC');
assertion(!str_contains(json_encode($hashes),str_replace('-','',$plain[0])),'recovery plain code not persisted');
$stamp=bin2hex(random_bytes(5));$email='mfa-ci-'.$stamp.'@example.com';
lc_query("INSERT INTO users(full_name,email,password_hash,whatsapp,instagram,pix_key_enc,role,status,consent_whatsapp)
 VALUES(?,?,?,?,?,?,'clipador','approved',0)",[
 'Security CI',$email,password_hash('not-real-pass-12345',PASSWORD_DEFAULT),
 '85999991111','ci_'.$stamp,lc_encrypt('test-pix')]);
$id=(int)lc_db()->lastInsertId();
try{
  $secret=lc_mfa_base32_encode(random_bytes(20));
  $enc=lc_encrypt($secret);
  assertion($secret!==$enc&&lc_decrypt($enc)===$secret,'secret encrypted at rest');
  lc_query("INSERT INTO account_mfa(user_id,secret_enc,recovery_json,last_used_step,session_version,enabled_at)
 VALUES(?,?,?,-1,1,NOW())",[$id,$enc,json_encode($hashes,JSON_THROW_ON_ERROR)]);
  $step=intdiv(time(),30);
  $otp=lc_mfa_otp($secret,$step);
  assertion(lc_mfa_enabled($id),'factor enabled');
  assertion(lc_mfa_consume($id,$otp),'OTP consumed');
  assertion(!lc_mfa_consume($id,$otp),'one-time replay prevented');
  assertion(lc_mfa_consume($id,$plain[0]),'recovery consumed');
  assertion(!lc_mfa_consume($id,$plain[0]),'recovery replay prevented');
  $state=lc_mfa_state($id);
  $remaining=json_decode($state['recovery_json'],true);
  assertion(count($remaining)===9,'recovery list decreased');
  $_SESSION=['uid'=>$id,'mfa_verified'=>true,'mfa_version'=>1];
  assertion(lc_mfa_session_is_valid($id),'verified browser accepted');
  $newVersion=lc_mfa_rotate_sessions($id);
  assertion($newVersion===2&&!lc_mfa_session_is_valid($id),'old browser revoked');
  $_SESSION=['uid'=>$id,'mfa_verified'=>true,'mfa_version'=>2];
  assertion(lc_mfa_session_is_valid($id),'rotated browser accepted');
  $_SESSION=[];
  lc_query("UPDATE account_mfa SET enabled_at=NULL,secret_enc='',recovery_json='[]' WHERE user_id=?",[$id]);
  assertion(!lc_mfa_enabled($id),'factor disabled');
  assertion(!lc_mfa_consume($id,$plain[1]),'disabled factor cannot consume recovery');
  lc_query('DELETE FROM users WHERE id=?',[$id]);
  echo "TOTP_SECURITY_OK\n";
}catch(Throwable $e){
 lc_query('DELETE FROM users WHERE id=?',[$id]);
 throw $e;
}
