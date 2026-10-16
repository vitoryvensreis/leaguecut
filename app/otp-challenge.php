<?php
declare(strict_types=1);

/**
 * Transactional step-up for accounts without TOTP. A code does not authorize
 * anything unless a configured provider actually accepts the send.
 * Acknowledged by provider != delivered/read (no false delivery claims).
 */
function lc_otp_channel_available(array $user,string $channel): bool {
    if($channel==='email'){
        return lc_env('MAIL_HOST')!==''&&lc_env('MAIL_USERNAME')!==''&&
          lc_env('MAIL_PASSWORD')!==''&&filter_var(lc_env('MAIL_FROM'),FILTER_VALIDATE_EMAIL)!==false &&
          filter_var((string)$user['email'],FILTER_VALIDATE_EMAIL)!==false;
    }
    if($channel==='whatsapp'){
        if(empty($user['consent_whatsapp'])||empty($user['whatsapp']))return false;
        return function_exists('lc_wpp_ready')&&lc_wpp_ready();
    }
    return false;
}
function lc_otp_available_channels(array $user): array {
    $available=[];
    foreach(['email','whatsapp'] as $channel)
        if(lc_otp_channel_available($user,$channel))$available[]=$channel;
    return $available;
}
function lc_otp_session_binding(int $userId): string {
    if(PHP_SAPI==='cli' && session_id()==='')return hash_hmac('sha256','ci-session:'.$userId,lc_env('APP_KEY'));
    return hash_hmac('sha256',session_id().':'.$userId,lc_env('APP_KEY'));
}
function lc_otp_hash(int $id,string $code): string {
    return hash_hmac('sha256','leaguecut-otp:'.$id.':'.$code,lc_env('APP_KEY'));
}
function lc_otp_mask(string $text,string $channel): string {
    if($channel==='email'){
        $parts=explode('@',$text,2);
        return mb_substr($parts[0],0,2).'***@'.($parts[1]??'');
    }
    $d=preg_replace('/\D/','',$text)??'';
    return 'WhatsApp final '.substr($d,-4);
}
function lc_otp_email_send(array $user,string $code,string $purpose): void {
    if(!class_exists(\PHPMailer\PHPMailer\PHPMailer::class))
        throw new RuntimeException('PHPMailer não instalado.');
    $mailer=new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host=lc_env('MAIL_HOST');
    $mailer->Port=(int)lc_env('MAIL_PORT','587');
    $mailer->SMTPAuth=true;
    $mailer->Username=lc_env('MAIL_USERNAME');
    $mailer->Password=lc_env('MAIL_PASSWORD');
    $mailer->SMTPSecure=$mailer->Port===465
        ?\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        :\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Timeout=15;
    $mailer->CharSet='UTF-8';
    $mailer->setFrom(lc_env('MAIL_FROM'),lc_env('MAIL_FROM_NAME','LeagueCut'));
    $mailer->addAddress((string)$user['email']);
    $mailer->Subject='LeagueCut — confirme sua operação';
    $label=$purpose==='withdrawal'?'solicitação de saque':'alteração de dados';
    $mailer->Body="Código para confirmar sua {$label} na LeagueCut: {$code}\n".
        "Válido por 5 minutos, para uso único. Não compartilhe este código.\n".
        "Se não solicitou, ignore esta mensagem e proteja sua conta.";
    $mailer->send();
}
/** Send only to the pre-existing contact saved on the account. */
function lc_otp_send(array $user,string $channel,string $code,string $purpose): void {
    if(!lc_otp_channel_available($user,$channel))
        throw new RuntimeException('Canal de verificação indisponível.');
    if($channel==='email'){lc_otp_email_send($user,$code,$purpose);return;}
    $kind=$purpose==='withdrawal'?'saque':'alteração de dados';
    lc_wpp_send((string)$user['whatsapp'],
       "LeagueCut: código de confirmação para {$kind}: {$code}. ".
       "Válido por 5 minutos e uso único. Nunca compartilhe.");
}
/** Validate original password before requesting a code (defense-in-depth). */
function lc_otp_require_password(array $user,string $password): bool {
    if($password===''||strlen($password)>200)return false;
    return lc_mfa_stepup_or_password($user,'',$password);
}
/**
 * Create and send a one-time challenge. No unverified challenge can be used.
 * User metadata never comes from the form: it is re-read from MySQL.
 */
function lc_otp_begin(array $user,string $purpose,array $payload,string $channel): void {
    if(!in_array($purpose,['account_update','withdrawal'],true))
        throw new InvalidArgumentException('Operação não suportada.');
    if(lc_mfa_enabled((int)$user['id']))
        throw new RuntimeException('Esta conta já usa Google Authenticator.');
    $full=lc_one('SELECT id,email,whatsapp,full_name,consent_whatsapp FROM users WHERE id=?',[$user['id']]);
    if(!$full||!lc_otp_channel_available($full,$channel))
        throw new InvalidArgumentException('Canal escolhido indisponível. Ative o Google Authenticator ou escolha outro canal.');
    $uid=(int)$user['id'];
    $secret=lc_env('APP_KEY');
    if($secret==='')throw new RuntimeException('Segredo da aplicação não configurado.');
    $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
    $cipher=lc_encrypt(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $db=lc_db();$db->beginTransaction();
    try{
        // User row lock serializes code issuance across multiple browser sessions.
        lc_one('SELECT id FROM users WHERE id=? FOR UPDATE',[$uid]);
        $count=(int)(lc_one('SELECT COUNT(*) AS n FROM account_otp_challenges WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 20 MINUTE)',[$uid])['n']??0);
        if($count>=4)throw new InvalidArgumentException('Limite de envios atingido. Aguarde 20 minutos.');
        $last=lc_one('SELECT created_at FROM account_otp_challenges WHERE user_id=? ORDER BY id DESC LIMIT 1',[$uid]);
        if($last&&strtotime($last['created_at'])>time()-60)
            throw new InvalidArgumentException('Aguarde 60 segundos antes de solicitar outro código.');
        lc_query("UPDATE account_otp_challenges SET status='expired' WHERE user_id=? AND status IN ('sending','ready')",[$uid]);
        // Code hash depends on its generated DB id; no plaintext OTP in database.
        lc_query("INSERT INTO account_otp_challenges
            (user_id,purpose,channel,session_hash,code_hash,payload_enc,status,expires_at)
            VALUES(?,?,?,?,?,?,'sending',DATE_ADD(NOW(),INTERVAL 5 MINUTE))",
            [$uid,$purpose,$channel,lc_otp_session_binding($uid),str_repeat('0',64),$cipher]);
        $id=(int)$db->lastInsertId();
        lc_query('UPDATE account_otp_challenges SET code_hash=? WHERE id=?',[lc_otp_hash($id,$code),$id]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    try{
        lc_otp_send($full,$channel,$code,$purpose);
        lc_query("UPDATE account_otp_challenges SET status='ready' WHERE id=? AND status='sending'",[$id]);
        $_SESSION['lc_otp_pending']=$id;
        lc_mfa_event('otp.provider_accepted.'.$purpose,$uid);
    }catch(Throwable $e){
        lc_query("UPDATE account_otp_challenges SET status='failed',payload_enc='' WHERE id=?",[$id]);
        lc_mfa_event('otp.provider_failed.'.$purpose,$uid);
        // Never propagate SMTP/WPP exceptions that could carry an OTP in remote messages.
        throw new RuntimeException('O provedor não confirmou o envio. Nenhuma alteração foi realizada.');
    }
}
/**
 * A challenge may be consumed only in the same DB transaction as the mutation.
 * On exception the challenge stays available for a new attempt (unless expired).
 */
function lc_otp_verify_and_apply(array $user,string $code): string {
    $uid=(int)$user['id'];$id=(int)($_SESSION['lc_otp_pending']??0);
    if($id<1||!preg_match('/^\d{6}$/D',$code))throw new InvalidArgumentException('Código inválido.');
    $db=lc_db();$db->beginTransaction();
    try{
        $row=lc_one('SELECT * FROM account_otp_challenges WHERE id=? AND user_id=? FOR UPDATE',[$id,$uid]);
        if(!$row||$row['status']!=='ready'||strtotime($row['expires_at'])<=time()||
           !hash_equals((string)$row['session_hash'],lc_otp_session_binding($uid))||
           lc_mfa_enabled($uid)){
            $db->rollBack();throw new InvalidArgumentException('Código expirado ou solicitação inválida.');
        }
        if((int)$row['attempts']>=5){$db->rollBack();throw new InvalidArgumentException('Tentativas esgotadas.');}
        if(!hash_equals($row['code_hash'],lc_otp_hash($id,$code))){
            lc_query("UPDATE account_otp_challenges SET attempts=attempts+1,
               status=IF(attempts+1>=5,'failed',status) WHERE id=?",[$id]);
            lc_mfa_event('otp.invalid',$uid);
            $db->commit();throw new InvalidArgumentException('Código incorreto ou tentativas esgotadas.');
        }
        $payload=json_decode(lc_decrypt($row['payload_enc']),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($payload))throw new RuntimeException('Operação inválida.');
        if($row['purpose']==='account_update'){
            lc_account_apply_update($user,$payload);
            $redirect='/conta';
        }elseif($row['purpose']==='withdrawal'){
            lc_withdrawal_apply($user,(int)($payload['amount_cents']??0));
            $redirect='/financeiro';
        }else throw new RuntimeException('Operação não reconhecida.');
        lc_query("UPDATE account_otp_challenges SET status='used',payload_enc='',used_at=NOW()
          WHERE id=? AND status='ready'",[$id]);
        lc_mfa_event('otp.confirmed.'.$row['purpose'],$uid);
        $db->commit();
        unset($_SESSION['lc_otp_pending']);
        // Revoke other sessions if the account details changed.
        if($row['purpose']==='account_update'){
            $_SESSION['mfa_version']=(int)(lc_mfa_state($uid)['session_version']??0);
            session_regenerate_id(true);
        }
        return $redirect;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function lc_otp_routes(string $method,string $path): bool {
    if($path!=='/seguranca/codigo')return false;
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Robots-Tag: noindex,nofollow');
    $user=lc_login_required();
    $id=(int)($_SESSION['lc_otp_pending']??0);
    $row=$id?lc_one('SELECT purpose,channel,status,expires_at FROM account_otp_challenges WHERE id=? AND user_id=?',[$id,$user['id']]):null;
    if(!$row||$row['status']!=='ready'||strtotime($row['expires_at'])<=time()){
        unset($_SESSION['lc_otp_pending']);
        lc_flash('Não há código ativo. Solicite uma nova verificação.','error');
        lc_redirect('/conta');
    }
    if($method==='POST'){
        try{
            $redirect=lc_otp_verify_and_apply($user,trim((string)($_POST['otp_code']??'')));
            lc_flash('Código confirmado. Operação realizada.');
            lc_redirect($redirect);
        }catch(InvalidArgumentException $e){
            lc_flash($e->getMessage(),'error');lc_redirect('/seguranca/codigo');
        }
    }
    if($method==='GET'){
        lc_page('Confirmar operação',function()use($row){?>
<div class="container container-sm">
<div class="panel"><span class="eyebrow">VERIFICAÇÃO DE SEGURANÇA</span>
<h1>Confirme sua operação</h1>
<p>Solicitação <?=($row['purpose']==='withdrawal'?'de saque':'de alteração de dados')?>. O código foi aceito pelo provedor de <?=($row['channel']==='email'?'e-mail':'WhatsApp')?> e pode levar alguns instantes para chegar.</p>
<p>Válido por 5 minutos. Depois disso, solicite um novo código. Cada código serve somente para esta operação.</p>
<form method="post" action="/seguranca/codigo" autocomplete="off"><?=lc_csrf()?>
<label class="field"><span>Código recebido (6 dígitos)</span><input name="otp_code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" required autofocus></label>
<div class="form-actions"><button type="submit" class="btn">Confirmar operação →</button>
<a href="<?=$row['purpose']==='withdrawal'?'/financeiro':'/conta'?>">Cancelar</a></div>
</form></div></div>
<?php },$user['role']==='admin'?'admin':($user['role']==='criador'?'creator':'dashboard'));return true;
    }
    return false;
}
