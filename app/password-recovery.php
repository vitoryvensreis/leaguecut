<?php
declare(strict_types=1);

/**
 * Account recovery must never reveal if an email/nickname belongs to a user.
 * Codes are session-bound, hashed at rest, one-use, limited to 5 guesses.
 * Sending requires a configured and available mail/WhatsApp provider.
 */
function lc_reset_hash(int $id,string $code): string {
    return hash_hmac('sha256','leaguecut-reset:'.$id.':'.$code,lc_env('APP_KEY'));
}
function lc_reset_begin(string $identifier,string $channel): void {
    if(!in_array($channel,['email','whatsapp'],true)||strlen($identifier)<3||strlen($identifier)>190)return;
    $user=lc_one("SELECT id,email,whatsapp,consent_whatsapp,status FROM users
      WHERE email=? OR nickname=? LIMIT 1",[mb_strtolower($identifier),mb_strtolower($identifier)]);
    if(!$user||!lc_otp_channel_available($user,$channel))return; // Uniform public message.
    $uid=(int)$user['id'];
    $db=lc_db();$id=0;$code=str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT);
    $db->beginTransaction();
    try{
        lc_one('SELECT id FROM users WHERE id=? FOR UPDATE',[$uid]);
        $recent=(int)(lc_one("SELECT COUNT(*) AS n FROM password_reset_challenges WHERE user_id=?
            AND created_at>=DATE_SUB(NOW(),INTERVAL 20 MINUTE)",[$uid])['n']??0);
        $last=lc_one("SELECT (created_at>DATE_SUB(NOW(),INTERVAL 60 SECOND)) AS too_recent
           FROM password_reset_challenges WHERE user_id=? ORDER BY id DESC LIMIT 1",[$uid]);
        if($recent>=4||($last&&(int)$last['too_recent']===1)){
            $db->commit();return;
        }
        lc_query("UPDATE password_reset_challenges SET status='expired'
           WHERE user_id=? AND status IN ('sending','ready')",[$uid]);
        lc_query("INSERT INTO password_reset_challenges
          (user_id,channel,session_hash,code_hash,status,expires_at)
          VALUES(?,?,?,?,'sending',DATE_ADD(NOW(),INTERVAL 10 MINUTE))",
          [$uid,$channel,lc_otp_session_binding($uid),str_repeat('0',64)]);
        $id=(int)$db->lastInsertId();
        lc_query("UPDATE password_reset_challenges SET code_hash=? WHERE id=?",
           [lc_reset_hash($id,$code),$id]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('LEAGUECUT_RESET_INIT_FAILED');return;}
    try{
        lc_otp_send($user,$channel,$code,'password_reset');
        lc_query("UPDATE password_reset_challenges SET status='ready'
          WHERE id=? AND status='sending'",[$id]);
        $_SESSION['lc_reset_id']=$id;
    }catch(Throwable $e){
        lc_query("UPDATE password_reset_challenges SET status='failed' WHERE id=?",[$id]);
        error_log('LEAGUECUT_RESET_DELIVERY_FAILED');
    }
}
function lc_reset_finish(string $code,string $password): bool {
    $id=(int)($_SESSION['lc_reset_id']??0);
    if($id<1||!preg_match('/^[0-9]{8}$/D',$code)||
       strlen($password)<12||strlen($password)>128)return false;
    $db=lc_db();$db->beginTransaction();
    try{
        $row=lc_one("SELECT *,
             (expires_at>NOW()) AS fresh FROM password_reset_challenges WHERE id=? FOR UPDATE",[$id]);
        if(!$row||$row['status']!=='ready'||(int)$row['fresh']!==1||
           (int)$row['attempts']>=5||
           !hash_equals((string)$row['session_hash'],lc_otp_session_binding((int)$row['user_id']))){
             $db->rollBack();return false;
        }
        if(!hash_equals((string)$row['code_hash'],lc_reset_hash($id,$code))){
            lc_query("UPDATE password_reset_challenges
               SET attempts=attempts+1,
                   status=IF(attempts+1>=5,'failed',status) WHERE id=?",[$id]);
            $db->commit();return false;
        }
        $uid=(int)$row['user_id'];
        lc_query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$uid]);
        lc_mfa_rotate_sessions($uid);
        lc_query("UPDATE password_reset_challenges SET status='used',used_at=NOW() WHERE id=?",[$id]);
        lc_query("UPDATE password_reset_challenges SET status='expired'
             WHERE user_id=? AND id<>? AND status IN ('ready','sending')",[$uid,$id]);
        $db->commit();
        unset($_SESSION['lc_reset_id']);
        // Revoke current browser and any remembered session (if configured later).
        if(function_exists('lc_remember_revoke_current'))lc_remember_revoke_current();
        $_SESSION=[];session_regenerate_id(true);
        return true;
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        error_log('LEAGUECUT_RESET_APPLY_FAILED');return false;
    }
}
function lc_password_reset_routes(string $method,string $path): bool {
    if(!in_array($path,['/recuperar-senha','/recuperar-senha/confirmar'],true))return false;
    if($method==='POST'&&$path==='/recuperar-senha'){
        // Throttle repeated requests from same browser, including nonexistent accounts.
        $last=(int)($_SESSION['lc_reset_request_at']??0);
        if($last<=time()-45){
            $_SESSION['lc_reset_request_at']=time();
            lc_reset_begin(trim((string)($_POST['identifier']??'')),(string)($_POST['channel']??'email'));
        }
        lc_flash('Se a conta e o canal estiverem disponíveis, um código será enviado ao contato cadastrado. Verifique seu e-mail ou WhatsApp.');
        lc_redirect('/recuperar-senha/confirmar');
    }
    if($method==='POST'&&$path==='/recuperar-senha/confirmar'){
        $password=(string)($_POST['new_password']??'');
        $check=(string)($_POST['confirm_password']??'');
        if(!hash_equals($password,$check) || !lc_reset_finish((string)($_POST['code']??''),$password)){
            lc_flash('Código inválido/expirado ou senha fora dos requisitos (mínimo 12 caracteres).','error');
            lc_redirect('/recuperar-senha/confirmar');
        }
        lc_flash('Senha redefinida. Entre com sua nova senha.');lc_redirect('/entrar');
    }
    if($method!=='GET')return false;
    $confirm=$path==='/recuperar-senha/confirmar';
    lc_page($confirm?'Confirmar recuperação':'Recuperar senha',function()use($confirm){?>
<div class="container container-sm" style="max-width:620px">
<?php lc_heading('Segurança LeagueCut',$confirm?'Defina uma nova senha':'Esqueceu sua senha?',
   $confirm?'Digite o código enviado ao contato cadastrado e escolha uma nova senha.':
   'Receba um código pelo e-mail ou WhatsApp associado à sua conta.'); ?>
<div class="panel"><form method="post" action="<?=$confirm?'/recuperar-senha/confirmar':'/recuperar-senha'?>"><?=lc_csrf()?>
<?php if(!$confirm):?>
<label class="field"><span>E-mail ou nickname cadastrado</span><input name="identifier" type="text" maxlength="190" autocomplete="username" required></label>
<label class="field"><span>Enviar pelo contato cadastrado</span>
<select name="channel"><option value="email">E-mail</option><option value="whatsapp">WhatsApp (quando autorizado e conectado)</option></select></label>
<p class="hint">Por segurança, não informamos se um e-mail ou nickname está cadastrado. Somente contatos já registrados na conta recebem o código.</p>
<?php else:?>
<label class="field"><span>Código de 8 dígitos</span><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" pattern="[0-9]{8}" required></label>
<label class="field"><span>Nova senha (mínimo 12 caracteres)</span><input name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required></label>
<label class="field"><span>Confirme a nova senha</span><input name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required></label>
<?php endif;?><div class="form-actions">
<button class="btn" type="submit"><?=$confirm?'Redefinir senha':'Solicitar código'?></button>
<a href="<?=$confirm?'/recuperar-senha':'/entrar'?>"><?=$confirm?'Pedir novo código':'Voltar ao login'?></a>
</div></form></div></div>
<?php });return true;
}
