<?php
declare(strict_types=1);

/** Trusted-device cookie never stores a password, ID, email, role, OTP or MFA secret. */
const LC_REMEMBER_COOKIE='__Host-lc_remember';
function lc_remember_is_https(): bool {
    return !empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
}
function lc_remember_key(string $selector,string $validator): string {
    return hash_hmac('sha256','remember:'.$selector.':'.$validator,lc_env('APP_KEY'));
}
function lc_remember_cookie(string $value,int $expires): void {
    if(PHP_SAPI!=='cli' && !lc_remember_is_https())return;
    setcookie(LC_REMEMBER_COOKIE,$value,[
       'expires'=>$expires,'path'=>'/','secure'=>true,
       'httponly'=>true,'samesite'=>'Lax'
    ]);
    if($value==='')unset($_COOKIE[LC_REMEMBER_COOKIE]);
}
function lc_remember_revoke_current(): void {
    $value=(string)($_COOKIE[LC_REMEMBER_COOKIE]??'');
    if(preg_match('/^([a-f0-9]{24})\.[a-f0-9]{64}$/D',$value,$match)){
        lc_query('DELETE FROM account_remember_tokens WHERE selector=?',[$match[1]]);
    }
    lc_remember_cookie('',time()-3600);
}
function lc_remember_revoke_all(int $uid): void {
    lc_query('DELETE FROM account_remember_tokens WHERE user_id=?',[$uid]);
    lc_remember_cookie('',time()-3600);
}
function lc_remember_issue(int $uid): void {
    if(PHP_SAPI!=='cli'&&!lc_remember_is_https())return;
    $state=lc_mfa_state($uid);
    $mfaEnabled=$state && $state['enabled_at']!==null;
    if($mfaEnabled && ($_SESSION['mfa_verified']??false)!==true)return;
    $version=(int)($state['session_version']??0);
    $selector=bin2hex(random_bytes(12));$validator=bin2hex(random_bytes(32));
    $expires=time()+30*86400;
    lc_remember_revoke_current();
    lc_query("INSERT INTO account_remember_tokens
      (user_id,selector,token_hash,mfa_version,mfa_verified,expires_at)
      VALUES(?,?,?,?,?,FROM_UNIXTIME(?))",[
         $uid,$selector,lc_remember_key($selector,$validator),$version,$mfaEnabled?1:0,$expires
      ]);
    // Each user can hold at most eight active devices. Oldest sessions are revoked.
    $ids=lc_all('SELECT id FROM account_remember_tokens WHERE user_id=? ORDER BY id DESC',[$uid]);
    foreach(array_slice($ids,8) as $row)lc_query('DELETE FROM account_remember_tokens WHERE id=?',[$row['id']]);
    lc_remember_cookie($selector.'.'.$validator,$expires);
}
function lc_remember_restore(): bool {
    if(PHP_SAPI!=='cli'&&!lc_remember_is_https())return false;
    if(!empty($_SESSION['uid']))return true;
    $value=(string)($_COOKIE[LC_REMEMBER_COOKIE]??'');
    if(!preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/D',$value,$match))return false;
    $selector=$match[1];$validator=$match[2];
    $db=lc_db();$db->beginTransaction();
    try{
        $row=lc_one("SELECT t.*,u.status FROM account_remember_tokens t
           JOIN users u ON u.id=t.user_id WHERE t.selector=? FOR UPDATE",[$selector]);
        if(!$row||strtotime($row['expires_at'])<=time()||
           !in_array($row['status'],['pending','approved'],true)||
           !hash_equals((string)$row['token_hash'],lc_remember_key($selector,$validator))){
           $db->rollBack();lc_remember_cookie('',time()-3600);return false;
        }
        $uid=(int)$row['user_id'];
        $state=lc_mfa_state($uid);
        $version=(int)($state['session_version']??0);
        $enabled=$state && $state['enabled_at']!==null;
        if($version!==(int)$row['mfa_version']||($enabled && (int)$row['mfa_verified']!==1)){
           $db->rollBack();lc_remember_cookie('',time()-3600);return false;
        }
        $newSelector=bin2hex(random_bytes(12));
        $newValidator=bin2hex(random_bytes(32));
        lc_query("UPDATE account_remember_tokens
            SET selector=?,token_hash=?,last_used_at=NOW() WHERE id=?",[
            $newSelector,lc_remember_key($newSelector,$newValidator),$row['id']
        ]);
        $db->commit();
        session_regenerate_id(true);
        $_SESSION['uid']=$uid;
        $_SESSION['mfa_verified']=$enabled;
        $_SESSION['mfa_version']=$version;
        unset($_SESSION['mfa_pending_uid'],$_SESSION['mfa_pending_at']);
        lc_remember_cookie($newSelector.'.'.$newValidator,strtotime($row['expires_at']));
        return true;
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        error_log('LEAGUECUT_REMEMBER_RESTORE_FAILED');
        return false;
    }
}
