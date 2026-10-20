<?php
declare(strict_types=1);

function lc_mfa_routes(string $method,string $path): bool {
    if(!in_array($path,['/seguranca/2fa','/seguranca/2fa/iniciar','/seguranca/2fa/ativar','/seguranca/2fa/desativar','/seguranca/verificar'],true))return false;
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');

    if($path==='/seguranca/verificar'){
        if($signed=lc_user())lc_redirect($signed['role']==='admin'?'/admin':($signed['role']==='criador'?'/criador':'/dashboard'));
        $pending=(int)($_SESSION['mfa_pending_uid']??0);
        $started=(int)($_SESSION['mfa_pending_at']??0);
        if($pending<1||$started<time()-300||$started>time()){
            unset($_SESSION['mfa_pending_uid'],$_SESSION['mfa_pending_at']);
            if(lc_auth_async_request())lc_auth_json(false,'login','Sessão expirada. Entre novamente para confirmar o segundo fator.','',419);
            lc_flash('Por segurança, entre novamente para confirmar o segundo fator.','error');
            lc_redirect('/entrar');
        }
        if($method==='POST'){
            $code=lc_post('mfa_code',60);
            if(lc_mfa_consume($pending,$code))lc_mfa_finish_login($pending,lc_auth_async_request());
            if(lc_auth_async_request())lc_auth_json(false,'mfa','Código inválido, expirado ou limite de tentativas atingido.','',401);
            lc_flash('Código inválido, expirado ou limite de tentativas atingido.','error');
            lc_redirect('/seguranca/verificar');
        }
        if($method==='GET'){
            lc_page('Verificação em duas etapas',function(){?>
<div class="container container-sm lc-security"><div class="panel">
<span class="eyebrow">SEGURANÇA DA CONTA</span><h1>Confirme seu acesso</h1>
<p>Digite o código de 6 dígitos do Google Authenticator ou utilize um dos seus códigos de recuperação.</p>
<form method="post" action="/seguranca/verificar"><?=lc_csrf()?>
<label class="field"><span>Código de verificação</span><input name="mfa_code" inputmode="text" autocomplete="one-time-code" maxlength="60" required autofocus placeholder="000000 ou código de recuperação"></label>
<div class="form-actions"><button class="btn" type="submit">Verificar e entrar →</button><a href="/entrar">Voltar</a></div></form>
<p class="hint">O código muda a cada 30 segundos. Depois de 6 tentativas incorretas, aguarde 15 minutos.</p>
</div></div>
<?php });return true;
        }
        return false;
    }
    $u=lc_login_required(false);
    $uid=(int)$u['id'];
    if($method==='POST'&&$path==='/seguranca/2fa/iniciar'){
        $record=lc_one('SELECT password_hash FROM users WHERE id=?',[$uid]);
        if(!$record||!password_verify((string)($_POST['current_password']??''),$record['password_hash'])){
            lc_flash('Senha atual incorreta.','error');lc_redirect('/seguranca/2fa');
        }
        if(lc_mfa_enabled($uid)){
            lc_flash('A autenticação já está ativada.','error');lc_redirect('/seguranca/2fa');
        }
        $_SESSION['mfa_setup_secret_enc']=lc_encrypt(lc_mfa_base32_encode(random_bytes(20)));
        $_SESSION['mfa_setup_expires']=time()+600;
        lc_redirect('/seguranca/2fa');
    }
    if($method==='POST'&&$path==='/seguranca/2fa/ativar'){
        $secret=isset($_SESSION['mfa_setup_secret_enc'])?
            lc_decrypt((string)$_SESSION['mfa_setup_secret_enc']):'';
        $expiry=(int)($_SESSION['mfa_setup_expires']??0);
        if($secret===''||$expiry<time()||lc_mfa_enabled($uid)){
            unset($_SESSION['mfa_setup_secret_enc'],$_SESSION['mfa_setup_expires']);
            lc_flash('Configuração expirada. Inicie novamente.','error');lc_redirect('/seguranca/2fa');
        }
        $step=lc_mfa_find_step($secret,lc_post('mfa_code',6),time());
        if($step===null){
            lc_flash('Código não confere. Verifique a hora do celular.','error');lc_redirect('/seguranca/2fa');
        }
        [$plain,$hashes]=lc_mfa_make_recovery();
        $db=lc_db();$db->beginTransaction();
        try{
            $existing=lc_one('SELECT user_id,enabled_at FROM account_mfa WHERE user_id=? FOR UPDATE',[$uid]);
            if($existing&&$existing['enabled_at']!==null)throw new RuntimeException('MFA já ativo.');
            lc_query('INSERT INTO account_mfa(user_id,secret_enc,recovery_json,last_used_step,session_version,enabled_at)
                 VALUES(?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE secret_enc=VALUES(secret_enc),
                 recovery_json=VALUES(recovery_json),last_used_step=VALUES(last_used_step),
                 session_version=session_version+1,enabled_at=NOW()',
                [$uid,lc_encrypt($secret),json_encode($hashes,JSON_THROW_ON_ERROR),$step]);
            lc_mfa_event('mfa.enabled',$uid);
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        $_SESSION['mfa_verified']=true;
        $_SESSION['mfa_version']=(int)(lc_mfa_state($uid)['session_version']??0);
        $_SESSION['mfa_recovery_once']=$plain;
        unset($_SESSION['mfa_setup_secret_enc'],$_SESSION['mfa_setup_expires']);
        session_regenerate_id(true);
        lc_flash('Google Authenticator ativado! Salve seus códigos de recuperação antes de sair.');
        lc_redirect('/seguranca/2fa');
    }
    if($method==='POST'&&$path==='/seguranca/2fa/desativar'){
        $record=lc_one('SELECT password_hash FROM users WHERE id=?',[$uid]);
        if(!$record||!password_verify((string)($_POST['current_password']??''),$record['password_hash'])||
           !lc_mfa_consume($uid,lc_post('mfa_code',60))){
            lc_flash('Senha ou segundo fator incorreto. Nenhuma alteração feita.','error');
            lc_redirect('/seguranca/2fa');
        }
        $db=lc_db();$db->beginTransaction();
        try{
            lc_query("UPDATE account_mfa SET secret_enc='',recovery_json='[]',last_used_step=-1,
                       session_version=session_version+1,enabled_at=NULL WHERE user_id=?",[$uid]);
            lc_mfa_event('mfa.disabled',$uid);
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        $_SESSION['mfa_version']=(int)(lc_mfa_state($uid)['session_version']??0);
        $_SESSION['mfa_verified']=false;
        session_regenerate_id(true);
        lc_flash('Autenticação em duas etapas desativada. As demais sessões foram invalidadas.');
        lc_redirect('/seguranca/2fa');
    }
    if($method==='GET'&&$path==='/seguranca/2fa'){
        $enabled=lc_mfa_enabled($uid);
        $setupSecret=isset($_SESSION['mfa_setup_secret_enc'])&&
           (int)($_SESSION['mfa_setup_expires']??0)>=time()
          ?lc_decrypt((string)$_SESSION['mfa_setup_secret_enc']):'';
        if($enabled)$setupSecret='';
        $setupQr=$setupSecret!==''?lc_mfa_setup_qr($setupSecret,(string)$u['email']):'';
        $recovery=$_SESSION['mfa_recovery_once']??[];
        unset($_SESSION['mfa_recovery_once']);
        $events=lc_all('SELECT action,created_at FROM account_security_events WHERE user_id=? ORDER BY id DESC LIMIT 12',[$uid]);
        lc_page('Autenticação em duas etapas',function()use($u,$enabled,$setupSecret,$setupQr,$recovery,$events){?>
<div class="container container-md lc-security">
<?php lc_heading('Proteção da conta','Autenticação em duas etapas','Compatível com Google Authenticator e outros aplicativos TOTP.'); ?>
<?php if($recovery):?>
<div class="panel lc-security-recovery"><h2>Salve seus códigos de recuperação</h2>
<p>Esses códigos são exibidos uma única vez. Cada um só funciona uma vez. Não compartilhe nem envie por WhatsApp.</p>
<div class="lc-security-codes">
<?php foreach($recovery as $code):?><code><?=lc_e($code)?></code><?php endforeach;?></div>
<p class="hint">Guarde-os fora deste navegador. Depois de sair desta tela, não será possível visualizá-los novamente.</p>
</div>
<?php endif;?>
<div class="panel">
<h2><?=$enabled?'2FA ativado':'Ativar Google Authenticator'?></h2>
<?php if($enabled):?>
<p>Seu login agora exige senha e código temporário. Você pode usar um código de recuperação quando perder o aparelho.</p>
<form action="/seguranca/2fa/desativar" method="post"><?=lc_csrf()?>
<label class="field"><span>Sua senha atual</span><input type="password" name="current_password" autocomplete="current-password" required></label>
<label class="field"><span>Código do aplicativo ou recuperação</span><input name="mfa_code" autocomplete="one-time-code" required></label>
<div class="form-actions"><button type="submit" class="btn btn-ghost">Desativar 2FA</button></div></form>
<?php elseif($setupSecret!==''):?>
<p>Abra o Google Authenticator, toque em <strong>Adicionar → Ler QR Code</strong> e escaneie a imagem. Se preferir, use a chave manual abaixo.</p>
<div class="lc-mfa-qr-frame"><img src="<?=lc_e($setupQr)?>" width="240" height="240" alt="QR Code privado para ativação do Google Authenticator" loading="eager" referrerpolicy="no-referrer"></div>
<p class="hint">Nome da conta: <?=lc_e($u['email'])?> | Emissor: LeagueCut</p>
<label class="field"><span>Chave secreta (copie no seu aplicativo; não compartilhe)</span>
<input readonly value="<?=lc_e($setupSecret)?>" spellcheck="false" autocomplete="off"></label>
<form action="/seguranca/2fa/ativar" method="post"><?=lc_csrf()?>
<label class="field"><span>Digite o código de 6 dígitos gerado pelo aplicativo</span>
<input name="mfa_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label>
<div class="form-actions"><button class="btn" type="submit">Confirmar e ativar →</button></div></form>
<p class="hint">A configuração expira em 10 minutos. Nenhum segredo é enviado a terceiros para gerar QR Code.</p>
<?php else:?>
<p>Além da senha, seu próximo login exigirá um código temporário do aplicativo de autenticação.</p>
<form action="/seguranca/2fa/iniciar" method="post"><?=lc_csrf()?>
<label class="field"><span>Confirme sua senha atual</span><input type="password" name="current_password" autocomplete="current-password" required></label>
<div class="form-actions"><button class="btn" type="submit">Configurar autenticador →</button></div>
</form>
<?php endif;?></div>
<div class="panel"><h2>Atividades recentes de segurança</h2>
<?php if(!$events):?><p>Nenhuma atividade registrada nesta fase.</p><?php endif;?>
<div class="table-wrap"><table><thead><tr><th>Ação</th><th>Data</th></tr></thead><tbody>
<?php foreach($events as $e):?><tr><td><?=lc_e($e['action'])?></td><td><?=lc_e($e['created_at'])?></td></tr><?php endforeach;?></tbody></table></div></div>
</div>
<?php },$u['role']==='admin'?'admin':($u['role']==='criador'?'creator':'dashboard'));
        return true;
    }
    return false;
}
