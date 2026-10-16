<?php
declare(strict_types=1);

function lc_account_validate_input(array $source): array {
    $name=trim((string)($source['full_name']??''));
    $email=mb_strtolower(trim((string)($source['email']??'')));
    $phone=preg_replace('/\D/','',(string)($source['whatsapp']??''))??'';
    $newPass=(string)($source['new_password']??'');
    $pix=trim((string)($source['new_pix_key']??''));
    $nickname=trim((string)($source['nickname']??''));
    if($nickname!=='')$nickname=lc_nickname_validate($nickname);
    if(mb_strlen($name)<3||mb_strlen($name)>160||!filter_var($email,FILTER_VALIDATE_EMAIL)||
       strlen($email)>190||!in_array(strlen($phone),[10,11,12,13],true)||
       ($newPass!==''&&(strlen($newPass)<12||strlen($newPass)>128))||
       mb_strlen($pix)>150)
        throw new InvalidArgumentException('Confira os dados. Senhas novas exigem ao menos 12 caracteres.');
    return ['nickname'=>$nickname,'full_name'=>$name,'email'=>$email,'whatsapp'=>$phone,
       'new_password'=>$newPass,'new_pix_key'=>$pix];
}
/** Caller owns a DB transaction; used both after direct TOTP and confirmed remote code. */
function lc_account_apply_update(array $u,array $data): bool {
    $data=lc_account_validate_input($data);
    $uid=(int)$u['id'];
    $existing=lc_one('SELECT id,full_name,nickname,email,whatsapp FROM users WHERE id=? FOR UPDATE',[$uid]);
    if(!$existing)throw new InvalidArgumentException('Conta não encontrada.');
    if($data['new_pix_key']!==''){
        $pending=(int)(lc_one("SELECT COUNT(*) AS n FROM withdrawals
             WHERE user_id=? AND status IN ('requested','processing')",[$uid])['n']??0);
        if($pending>0)throw new InvalidArgumentException('Não altere o Pix enquanto há saques em análise.');
    }
    $changed=[];
    foreach(['full_name','email','whatsapp'] as $field){
        if((string)$existing[$field]!==$data[$field])$changed[]=$field;
    }
    if($data['nickname']!=='' && $existing['nickname']!==$data['nickname'])$changed[]='nickname';
    if($data['new_pix_key']!=='')$changed[]='pix_key_enc';
    if($data['new_password']!=='')$changed[]='password_hash';
    if(!$changed)return false;
    lc_query('UPDATE users SET full_name=?,email=?,whatsapp=?,
       nickname=COALESCE(?,nickname),pix_key_enc=COALESCE(?,pix_key_enc),password_hash=COALESCE(?,password_hash)
       WHERE id=?',[
       $data['full_name'],$data['email'],$data['whatsapp'],
       $data['nickname']!==''?$data['nickname']:null,
       $data['new_pix_key']!==''?lc_encrypt($data['new_pix_key']):null,
       $data['new_password']!==''?password_hash($data['new_password'],PASSWORD_DEFAULT):null,
       $uid
    ]);
    lc_mfa_rotate_sessions($uid);
    foreach($changed as $field)lc_mfa_event('account.change.'.$field,$uid);
    return true;
}
function lc_account_self_routes(string $method,string $path): bool {
    if($path!=='/conta')return false;
    $u=lc_login_required(false);
    $uid=(int)$u['id'];
    $full=lc_one('SELECT email,whatsapp,consent_whatsapp FROM users WHERE id=?',[$uid])?:[];
    $channels=lc_mfa_enabled($uid)?[]:lc_otp_available_channels($full);
    if($method==='POST'){
        try{
            $input=lc_account_validate_input($_POST);
            if($channels){
                if(!lc_otp_require_password($u,(string)($_POST['current_password']??'')))
                    throw new InvalidArgumentException('Sua senha atual não foi confirmada.');
                $channel=(string)($_POST['verification_channel']??'');
                lc_otp_begin($u,'account_update',$input,$channel);
                lc_flash('Se o canal estiver disponível, o código chegará em instantes.');
                lc_redirect('/seguranca/codigo');
            }
            if(!lc_mfa_stepup_or_password($u,lc_post('mfa_code',60),(string)($_POST['current_password']??'')))
                throw new InvalidArgumentException('Confirmação de segurança inválida ou bloqueada temporariamente.');
            $db=lc_db();$db->beginTransaction();
            try{$changed=lc_account_apply_update($u,$input);$db->commit();}
            catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            $_SESSION['mfa_version']=(int)(lc_mfa_state($uid)['session_version']??0);
            session_regenerate_id(true);
            lc_flash($changed?'Dados atualizados e demais sessões invalidadas.':'Nenhuma alteração necessária.');
        }catch(InvalidArgumentException|RuntimeException $e){
            lc_flash($e->getMessage(),'error');
        }catch(Throwable $e){
            lc_flash('Não foi possível atualizar os dados. Confira as informações.','error');
        }
        lc_redirect('/conta');
    }
    if($method==='GET'){
        $enabled=lc_mfa_enabled($uid);
        lc_page('Meus dados e segurança',function()use($u,$enabled,$channels){?>
<div class="container container-md">
<?php lc_heading('Minha conta','Dados e acesso','Edite suas informações com uma confirmação adicional de segurança.'); ?>
<div class="panel">
<form action="/conta" method="post" autocomplete="off"><?=lc_csrf()?>
<div class="form-grid">
<label class="field"><span>Nome completo</span><input name="full_name" value="<?=lc_e($u['full_name'])?>" minlength="3" maxlength="160" required></label>
<label class="field"><span>Seu nickname público</span><input name="nickname" value="<?=lc_e($u['nickname']??'')?>" minlength="3" maxlength="24" pattern="[a-zA-Z][a-zA-Z0-9_]{2,23}" required></label>
<label class="field"><span>E-mail de acesso</span><input type="email" name="email" value="<?=lc_e($u['email'])?>" required maxlength="190"></label>
<label class="field"><span>WhatsApp</span><input name="whatsapp" data-lc-mask="phone" value="<?=lc_e($u['whatsapp'])?>" required maxlength="19"></label>
<label class="field"><span>Nova senha (opcional)</span><input type="password" name="new_password" autocomplete="new-password" minlength="12" maxlength="128" placeholder="Mantenha em branco para não alterar"></label>
<label class="field full"><span>Nova chave Pix (opcional)</span><input name="new_pix_key" maxlength="150" autocomplete="off" placeholder="Informe somente para substituir a atual"></label>
<?php if($enabled):?>
<label class="field full"><span>Código do Google Authenticator ou recuperação</span>
<input name="mfa_code" autocomplete="one-time-code" required maxlength="60"></label>
<?php else:?>
<label class="field full"><span>Confirme sua senha atual</span>
<input type="password" name="current_password" autocomplete="current-password" required></label>
<?php if($channels):?>
<label class="field full"><span>Enviar código de confirmação para</span>
<select name="verification_channel" required>
<?php foreach($channels as $ch):?><option value="<?=lc_e($ch)?>"><?=$ch==='email'?'Meu e-mail cadastrado':'Meu WhatsApp cadastrado'?></option><?php endforeach;?>
</select></label>
<p class="hint full">O código será enviado ao contato cadastrado antes da alteração. Os dados só mudam depois da confirmação.</p>
<?php else:?>
<p class="hint full">Nenhum provedor de código está ativo. Temporariamente, a confirmação usa sua senha atual. Recomendamos <a href="/seguranca/2fa">ativar o Google Authenticator</a>.</p>
<?php endif;?>
<?php endif;?>
</div><div class="form-actions"><button class="btn" type="submit"><?=$enabled||!$channels?'Salvar dados com segurança →':'Solicitar código →'?></button>
<a class="btn btn-ghost" href="/seguranca/2fa">Configurar Google Authenticator</a></div>
</form></div></div>
<?php },$u['role']==='admin'?'admin':($u['role']==='criador'?'creator':'dashboard'));
        return true;
    }
    return false;
}
