<?php
declare(strict_types=1);

/** RFC 6238: 6 digits, HMAC-SHA1, 30-second time step. No secrets or codes in logs. */
function lc_mfa_base32_encode(string $bytes): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits=0;$buffer=0;$out='';
    foreach(unpack('C*',$bytes) as $byte){
        $buffer=($buffer<<8)|$byte;$bits+=8;
        while($bits>=5){$bits-=5;$out.=$alphabet[($buffer>>$bits)&31];}
        $buffer&=(1<<$bits)-1;
    }
    if($bits>0)$out.=$alphabet[($buffer<<(5-$bits))&31];
    return $out;
}
function lc_mfa_base32_decode(string $secret): string {
    $secret=strtoupper(str_replace(' ','',$secret));
    if(!preg_match('/^[A-Z2-7]{16,64}$/D',$secret))return '';
    $map=array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
    $bits=0;$buffer=0;$out='';
    foreach(str_split($secret) as $char){
        $buffer=($buffer<<5)|$map[$char];$bits+=5;
        if($bits>=8){$bits-=8;$out.=chr(($buffer>>$bits)&255);$buffer&=(1<<$bits)-1;}
    }
    return $out;
}
function lc_mfa_otp(string $secret,int $step): string {
    $key=lc_mfa_base32_decode($secret);
    if($key===''||$step<0)throw new InvalidArgumentException('Segredo MFA inválido.');
    $message=pack('N2',($step>>32)&0xffffffff,$step&0xffffffff);
    $digest=hash_hmac('sha1',$message,$key,true);
    $offset=ord($digest[19])&15;
    $n=(unpack('N',substr($digest,$offset,4))[1]&0x7fffffff)%1000000;
    return str_pad((string)$n,6,'0',STR_PAD_LEFT);
}
function lc_mfa_find_step(string $secret,string $code,int $now, int $last=-1): ?int {
    if(!preg_match('/^\d{6}$/D',$code))return null;
    $current=intdiv($now,30);
    for($step=$current-1;$step<=$current+1;$step++){
        if($step<0||$step<=$last)continue;
        if(hash_equals(lc_mfa_otp($secret,$step),$code))return $step;
    }
    return null;
}
function lc_mfa_state(int $uid): ?array {
    return lc_one('SELECT user_id,secret_enc,recovery_json,last_used_step,session_version,enabled_at FROM account_mfa WHERE user_id=?',[$uid]);
}
function lc_mfa_enabled(int $uid): bool {
    return (lc_mfa_state($uid)['enabled_at']??null)!==null;
}
function lc_mfa_ip_hash(): string {
    return hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'),lc_env('APP_KEY',''));
}
function lc_mfa_event(string $action,?int $uid): void {
    lc_query('INSERT INTO account_security_events(user_id,action,ip_hash) VALUES(?,?,?)',
        [$uid,$action,lc_mfa_ip_hash()]);
}
function lc_mfa_recovery_key(string $code): string {
    $text=strtoupper(str_replace(['-',' '],'',$code));
    if(!preg_match('/^[A-F0-9]{20}$/D',$text))return '';
    return hash_hmac('sha256',$text,lc_env('APP_KEY',''));
}
function lc_mfa_make_recovery(): array {
    $plain=[];$hashed=[];
    for($i=0;$i<10;$i++){
        $value=strtoupper(bin2hex(random_bytes(10)));
        $plain[]=implode('-',str_split($value,5));
        $hashed[]=lc_mfa_recovery_key($value);
    }
    return [$plain,$hashed];
}
/** Consume a TOTP time step or one backup code in a row-locked transaction. */
function lc_mfa_consume(int $uid,string $input): bool {
    $input=trim($input);
    $db=lc_db();
    if($db->inTransaction())throw new LogicException('Validação MFA antes da transação financeira.');
    $db->beginTransaction();
    try{
        $state=lc_one('SELECT * FROM account_mfa WHERE user_id=? FOR UPDATE',[$uid]);
        if(!$state||$state['enabled_at']===null){$db->rollBack();return false;}
        $failed=(int)(lc_one('SELECT COUNT(*) AS n FROM account_mfa_attempts WHERE user_id=? AND created_at > DATE_SUB(NOW(),INTERVAL 15 MINUTE)',[$uid])['n']??0);
        if($failed>=6){$db->rollBack();return false;}
        $step=null;$recovery=false;
        if(preg_match('/^\d{6}$/D',$input)){
            $secret=lc_decrypt($state['secret_enc']);
            if($secret!=='')$step=lc_mfa_find_step($secret,$input,time(),(int)$state['last_used_step']);
        }else{
            $needle=lc_mfa_recovery_key($input);
            $hashes=json_decode((string)$state['recovery_json'],true)?:[];
            if($needle!==''){
                foreach($hashes as $i=>$hash){
                    if(is_string($hash)&&hash_equals($hash,$needle)){
                        unset($hashes[$i]);$recovery=true;break;
                    }
                }
            }
        }
        if($step===null&&!$recovery){
            lc_query('INSERT INTO account_mfa_attempts(user_id,ip_hash) VALUES(?,?)',[$uid,lc_mfa_ip_hash()]);
            lc_mfa_event('mfa.failed',$uid);
            $db->commit();return false;
        }
        if($recovery){
            lc_query('UPDATE account_mfa SET recovery_json=? WHERE user_id=?',
                [json_encode(array_values($hashes),JSON_THROW_ON_ERROR),$uid]);
        }else{
            lc_query('UPDATE account_mfa SET last_used_step=? WHERE user_id=?',[$step,$uid]);
        }
        lc_query('DELETE FROM account_mfa_attempts WHERE user_id=?',[$uid]);
        lc_mfa_event($recovery?'mfa.recovery_used':'mfa.verified',$uid);
        $db->commit();return true;
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function lc_mfa_login_pending(int $uid): void {
    session_regenerate_id(true);
    unset($_SESSION['uid'],$_SESSION['mfa_verified'],$_SESSION['mfa_version']);
    $_SESSION['mfa_pending_uid']=$uid;
    $_SESSION['mfa_pending_at']=time();
}
function lc_mfa_finish_login(int $uid): never {
    $user=lc_one('SELECT id,role FROM users WHERE id=?',[$uid]);
    if(!$user)lc_redirect('/entrar');
    session_regenerate_id(true);
    $_SESSION['uid']=$uid;
    $_SESSION['mfa_verified']=true;
    $_SESSION['mfa_version']=(int)(lc_mfa_state($uid)['session_version']??0);
    unset($_SESSION['mfa_pending_uid'],$_SESSION['mfa_pending_at']);
    lc_mfa_event('login.success',$uid);
    lc_redirect($user['role']==='criador'?'/criador':($user['role']==='admin'?'/admin':'/dashboard'));
}
/** A step-up challenge required for high-value operations. */
function lc_mfa_stepup_or_password(array $u,string $code,string $password): bool {
    if(lc_mfa_enabled((int)$u['id']))return lc_mfa_consume((int)$u['id'],$code);
    if($password===''||strlen($password)>200)return false;
    $record=lc_one('SELECT password_hash FROM users WHERE id=?',[$u['id']]);
    return $record!==null&&password_verify($password,$record['password_hash']);
}
function lc_mfa_session_is_valid(int $uid): bool {
    $state=lc_mfa_state($uid);
    $version=(int)($state['session_version']??0);
    if((int)($_SESSION['mfa_version']??0)!==$version)return false;
    if($state&&$state['enabled_at']!==null){
        return ($_SESSION['mfa_verified']??false)===true;
    }
    return true;
}
