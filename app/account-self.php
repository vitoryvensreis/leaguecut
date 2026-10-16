<?php
declare(strict_types=1);

function lc_account_self_routes(string $method,string $path): bool {
    if($path!=='/conta')return false;
    $u=lc_login_required(false);$uid=(int)$u['id'];
    if($method==='POST'){
        $name=lc_post('full_name',160);
        $email=mb_strtolower(lc_post('email',190));
        $phone=preg_replace('/\D/','',lc_post('whatsapp',24))??'';
        $newPass=(string)($_POST['new_password']??'');
        $pix=lc_post('new_pix_key',150);
        if(mb_strlen($name)<3||!filter_var($email,FILTER_VALIDATE_EMAIL)||
           !in_array(strlen($phone),[10,11,12,13],true)||
           ($newPass!==''&&(strlen($newPass)<12||strlen($newPass)>128))){
            lc_flash('Confira os dados. Senhas novas exigem ao menos 12 caracteres.','error');
            lc_redirect('/conta');
        }
        if(!lc_mfa_stepup_or_password($u,lc_post('mfa_code',60),(string)($_POST['current_password']??''))){
            lc_flash('Confirmação de segurança inválida ou bloqueada temporariamente.','error');
            lc_redirect('/conta');
        }
        $db=lc_db();$db->beginTransaction();
        try{
            $existing=lc_one('SELECT id,full_name,email,whatsapp FROM users WHERE id=? FOR UPDATE',[$uid]);
            if(!$existing)throw new RuntimeException('Conta não encontrada.');
            if($pix!==''){
                // Do not silently change destination while payouts are awaiting review.
                $pending=(int)(lc_one("SELECT COUNT(*) AS n FROM withdrawals
                     WHERE user_id=? AND status IN ('requested','processing')",[$uid])['n']??0);
                if($pending>0)throw new InvalidArgumentException('Não altere o Pix enquanto há saques em análise.');
            }
            $changed=[];
            foreach(['full_name'=>$name,'email'=>$email,'whatsapp'=>$phone] as $key=>$value)
                if($value!==$existing[$key])$changed[]=$key;
            if($pix!=='')$changed[]='pix_key_enc';
            if($newPass!=='')$changed[]='password_hash';
            if($changed){
                lc_query('UPDATE users SET full_name=?,email=?,whatsapp=?,
                    pix_key_enc=COALESCE(?,pix_key_enc),password_hash=COALESCE(?,password_hash) WHERE id=?',
                    [$name,$email,$phone,$pix!==''?lc_encrypt($pix):null,
                     $newPass!==''?password_hash($newPass,PASSWORD_DEFAULT):null,$uid]);
                $next=lc_mfa_rotate_sessions($uid);
                foreach($changed as $field)
                    lc_mfa_event('account.change.'.$field,$uid);
            }else $next=(int)(lc_mfa_state($uid)['session_version']??0);
            $db->commit();
            $_SESSION['mfa_version']=$next;
            session_regenerate_id(true);
            lc_flash($changed?'Dados alterados e demais sessões invalidadas.':'Nenhuma alteração necessária.');
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            lc_flash($e instanceof InvalidArgumentException?$e->getMessage():
                'Não foi possível alterar a conta. Verifique se o e-mail já está cadastrado.','error');
        }
        lc_redirect('/conta');
    }
    if($method==='GET'){
        $enabled=lc_mfa_enabled($uid);
        lc_page('Meus dados e segurança',function()use($u,$enabled){?>
<div class="container container-md">
<?php lc_heading('Minha conta','Dados e acesso','Edite suas informações com uma confirmação de segurança adicional.'); ?>
<div class="panel">
<form action="/conta" method="post" autocomplete="off"><?=lc_csrf()?>
<div class="form-grid">
<label class="field"><span>Nome completo</span><input name="full_name" value="<?=lc_e($u['full_name'])?>" minlength="3" maxlength="160" required></label>
<label class="field"><span>E-mail de acesso</span><input type="email" name="email" value="<?=lc_e($u['email'])?>" required maxlength="190></label>
<label class="field"><span>WhatsApp</span><input name="whatsapp" data-lc-mask="phone" value="<?=lc_e($u['whatsapp'])?>" required maxlength="19"></label>
<label class="field"><span>Nova senha (opcional)</span><input type="password" name="new_password" autocomplete="new-password" minlength="12" maxlength="128" placeholder="Mantenha em branco para não alterar"></label>
<label class="field full"><span>Nova chave Pix (opcional)</span><input name="new_pix_key" maxlength="150" autocomplete="off" placeholder="Informe somente para substituir a atual"></label>
<?php if($enabled):?>
<label class="field full"><span>Código do Google Authenticator ou de recuperação</span>
<input name="mfa_code" autocomplete="one-time-code" required maxlength="60"></label>
<?php else:?>
<label class="field full"><span>Confirme sua senha atual</span><input type="password" name="current_password" autocomplete="current-password" required></label>
<p class="hint full">Com o 2FA desativado, a confirmação usa sua senha atual. Confirmações por e-mail e WhatsApp estão previstas para a próxima etapa de segurança.</p>
<?php endif;?>
</div><div class="form-actions"><button class="btn" type="submit">Salvar dados com segurança →</button>
<a class="btn btn-ghost" href="/seguranca/2fa">Configurar Google Authenticator</a></div>
</form></div></div>
<?php },$u['role']==='admin'?'admin':($u['role']==='criador'?'creator':'dashboard'));
        return true;
    }
    return false;
}
