<?php
declare(strict_types=1);
/** Disposable GitHub Actions fixture for popup login integration only. */
if(PHP_SAPI!=='cli' || (getenv('GITHUB_ACTIONS')?:'')!=='true' ||
   (getenv('APP_URL')?:'')!=='http://127.0.0.1:8080' ||
   (getenv('DB_NAME')?:'')!=='leaguecut'){
    fwrite(STDERR,"CI fixture disabled: refusing database writes outside isolated Actions test.\n");exit(1);
}
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/mfa.php';
$email='auth-popup-ci@leaguecut.test';
$command=$argv[1]??'';
if($command==='delete'){
    lc_query('DELETE FROM users WHERE email=?',[$email]);
    echo "AUTH_POPUP_FIXTURE_DELETED\n";
    exit;
}
if($command!=='create')exit("Usage: create|delete\n");
if(lc_one('SELECT id FROM users WHERE email=?',[$email]))throw new RuntimeException('Fixture already exists');
$secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
lc_query("INSERT INTO users(full_name,email,password_hash,whatsapp,instagram,pix_key_enc,role,status,consent_whatsapp)
 VALUES(?,?,?,?,?,?,'clipador','approved',0)",[
 'Popup CI User',$email,password_hash('auth-ci-password-only',PASSWORD_DEFAULT),
 '85999991188','popup-ci-test',lc_encrypt('ci-pix-value')]);
$id=(int)lc_db()->lastInsertId();
lc_query("INSERT INTO account_mfa(user_id,secret_enc,recovery_json,last_used_step,session_version,enabled_at)
 VALUES(?,?,?,-1,1,NOW())",[$id,lc_encrypt($secret),'[]']);
echo "AUTH_POPUP_FIXTURE_READY\n";
