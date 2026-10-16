<?php
declare(strict_types=1);

/** Account administration: no unauthenticated privilege changes or password disclosure. */
function lc_account_admin_audit(int $actorId,string $action,?int $subject=null,array $details=[]): void {
    $safe=array_intersect_key($details,array_flip(['fields','role_from','role_to','status_from','status_to','account_id']));
    $ip=hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'),lc_env('APP_KEY','leaguecut'));
    lc_query('INSERT INTO account_admin_audit(actor_id,subject_user_id,action,details_json,ip_hash)
              VALUES(?,?,?,?,?)',[$actorId,$subject,$action,json_encode($safe,JSON_THROW_ON_ERROR),$ip]);
}
function lc_account_admin_password(array $actor): bool {
    $provided=(string)($_POST['admin_password']??'');
    if($provided===''||strlen($provided)>200)return false;
    $row=lc_one('SELECT password_hash FROM users WHERE id=?',[(int)$actor['id']]);
    if(!$row||!password_verify($provided,$row['password_hash']))return false;
    // Enabled accounts require a fresh one-use authenticator value for changes.
    if(function_exists('lc_mfa_enabled')&&lc_mfa_enabled((int)$actor['id']))
        return lc_mfa_consume((int)$actor['id'],lc_post('mfa_code',60));
    return true;
}
function lc_account_admin_validate(array $payload,bool $isCreate=false): array {
    $name=trim((string)($payload['full_name']??''));
    $email=mb_strtolower(trim((string)($payload['email']??'')));
    $whatsapp=preg_replace('/\D/','',(string)($payload['whatsapp']??''))??'';
    $instagram=ltrim(trim((string)($payload['instagram']??'')),'@');
    $role=(string)($payload['role']??'');
    $status=(string)($payload['status']??'');
    $password=(string)($payload['new_password']??'');
    $pix=trim((string)($payload['new_pix_key']??''));
    if(mb_strlen($name)<3||mb_strlen($name)>160||
       !filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||
       ($whatsapp!==''&&(strlen($whatsapp)<10||strlen($whatsapp)>13))||
       ($instagram!==''&&!preg_match('/^[a-zA-Z0-9._]{2,80}$/D',$instagram))||
       !in_array($role,['admin','clipador','criador'],true)||
       !in_array($status,['pending','approved','rejected','suspended'],true)||
       ($role==='admin'&&$status!=='approved')||
       ($password!==''&&(strlen($password)<12||strlen($password)>128))||
       ($isCreate&&$password==='')||mb_strlen($pix)>150)
        throw new InvalidArgumentException('Confira nome, e-mail, WhatsApp, perfil, status e senha (mínimo de 12 caracteres).');
    return compact('name','email','whatsapp','instagram','role','status','password','pix');
}
function lc_account_admin_routes(string $method,string $path): bool {
    if(!in_array($path,['/admin/administradores','/admin/administradores/criar','/admin/contas','/admin/atividades'],true)&&
       !preg_match('~^/admin/contas/([0-9]+)/editar$~D',$path))return false;
    $actor=lc_admin();
    if($method==='POST'&&$path==='/admin/administradores/criar'){
        if(!lc_account_admin_password($actor)){
            lc_flash('Informe sua senha administrativa atual para criar outra conta.','error');
            lc_redirect('/admin/administradores');
        }
        try{
            $input=$_POST;
            $input['role']='admin';$input['status']='approved';
            $fields=lc_account_admin_validate($input,true);
            if(lc_one('SELECT id FROM users WHERE email=?',[$fields['email']]))
                throw new InvalidArgumentException('Este e-mail já está cadastrado.');
            lc_db()->beginTransaction();
            lc_query('INSERT INTO users(full_name,email,password_hash,whatsapp,instagram,portfolio_url,pix_key_enc,role,status,approved_at,approved_by,consent_whatsapp)
                      VALUES(?,?,?,?,?,NULL,?,"admin","approved",NOW(),?,0)',
                [$fields['name'],$fields['email'],password_hash($fields['password'],PASSWORD_DEFAULT),
                 $fields['whatsapp'],$fields['instagram'],lc_encrypt(''),(int)$actor['id']]);
            $id=(int)lc_db()->lastInsertId();
            lc_nickname_complete($id);
            lc_account_admin_audit((int)$actor['id'],'admin.created',$id,['fields'=>['full_name','email','role','status']]);
            lc_db()->commit();
            lc_flash('Conta administrativa criada. A senha não é exibida nem enviada por mensagem.');
        }catch(Throwable $e){
            if(lc_db()->inTransaction())lc_db()->rollBack();
            lc_flash($e instanceof InvalidArgumentException?$e->getMessage():'Não foi possível criar a conta; confira e-mail e dados.','error');
        }
        lc_redirect('/admin/administradores');
    }
    if($method==='GET'&&$path==='/admin/administradores'){
        $admins=lc_all("SELECT id,full_name,email,whatsapp,status,created_at FROM users WHERE role='admin' ORDER BY id ASC");
        lc_page('Administradores',function()use($admins){?>
<div class="container lc-account-admin">
<?php lc_heading('Acesso privilegiado','Administradores','Gerencie acessos administrativos sem usar o terminal da VPS.'); ?>
<div class="panel"><div class="panel-head"><h2>Administradores cadastrados</h2><span class="tag"><?=count($admins)?> contas</span></div>
<p class="hint">Cadastros e alterações exigem confirmação da sua senha administrativa. Nunca compartilhe senhas por mensagens.</p>
<div class="table-wrap"><table><thead><tr><th>Usuário</th><th>E-mail</th><th>WhatsApp</th><th>Status</th><th>Ação</th></tr></thead><tbody>
<?php foreach($admins as $a):?><tr><td><?=lc_e($a['full_name'])?></td><td><?=lc_e($a['email'])?></td><td><?=lc_e($a['whatsapp'])?></td><td><span class="tag tag-<?=lc_e($a['status'])?>"><?=lc_e($a['status'])?></span></td><td><a class="btn btn-ghost btn-sm" href="/admin/contas/<?=(int)$a['id']?>/editar">Editar</a></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="panel lc-account-form-panel"><h2>Adicionar administrador</h2>
<form method="post" action="/admin/administradores/criar" autocomplete="off"><?=lc_csrf()?>
<div class="form-grid">
<label class="field"><span>Nome completo</span><input name="full_name" autocomplete="off" maxlength="160" minlength="3" required></label>
<label class="field"><span>E-mail único</span><input type="email" name="email" autocomplete="off" maxlength="190" required></label>
<label class="field"><span>WhatsApp (opcional)</span><input name="whatsapp" data-lc-mask="phone" inputmode="tel" placeholder="(85) 99999-9999" maxlength="18"></label>
<label class="field"><span>Senha inicial (mínimo 12 caracteres)</span><input type="password" name="new_password" autocomplete="new-password" minlength="12" maxlength="128" required></label>
<label class="field full"><span>Confirme sua senha de administrador</span><input type="password" name="admin_password" autocomplete="current-password" required></label>
<?php if(lc_mfa_enabled((int)lc_admin()['id'])):?>
<label class="field full"><span>Código Google Authenticator</span><input name="mfa_code" maxlength="60" required autocomplete="one-time-code"></label>
<?php endif;?>
</div><div class="form-actions"><button class="btn" type="submit">Criar administrador →</button></div>
</form></div><a class="btn btn-ghost" href="/admin/contas">Gerenciar todas as contas</a>
</div>
<?php },'admin');return true;
    }
    if($method==='GET'&&$path==='/admin/contas'){
        $users=lc_all("SELECT id,full_name,email,whatsapp,role,status,created_at FROM users ORDER BY id DESC LIMIT 300");
        lc_page('Gerenciar contas',function()use($users){?>
<div class="container lc-account-admin"><?php lc_heading('Contas e permissões','Gerenciar usuários','Clipadores, criadores e administradores em um único lugar.'); ?>
<div class="panel"><div class="table-wrap"><table><thead><tr><th>Nome</th><th>E-mail</th><th>Tipo</th><th>Status</th><th>Ação</th></tr></thead><tbody>
<?php foreach($users as $row):?><tr><td><?=lc_e($row['full_name'])?></td><td><?=lc_e($row['email'])?></td><td><?=lc_e($row['role'])?></td><td><?=lc_e($row['status'])?></td><td><a class="btn btn-ghost btn-sm" href="/admin/contas/<?=(int)$row['id']?>/editar">Editar</a></td></tr><?php endforeach; ?></tbody></table></div>
<p class="hint">Exibindo as 300 contas mais recentes. Alterações de e-mail, função, telefone e senha são auditadas.</p></div></div>
<?php },'admin');return true;
    }
    if(preg_match('~^/admin/contas/([0-9]+)/editar$~D',$path,$match)){
        $id=(int)$match[1];
        if($id<1){http_response_code(404);return true;}
        if($method==='POST'){
            if(!lc_account_admin_password($actor)){
                lc_flash('Sua senha de administrador está incorreta. Nenhum dado foi alterado.','error');
                lc_redirect('/admin/contas/'.$id.'/editar');
            }
            try{
                $f=lc_account_admin_validate($_POST);
                $db=lc_db();$db->beginTransaction();
                $existing=lc_one('SELECT id,role,status,full_name,email,whatsapp,instagram FROM users WHERE id=? FOR UPDATE',[$id]);
                if(!$existing)throw new InvalidArgumentException('Usuário não encontrado.');
                if($id===(int)$actor['id'] && ($f['role']!=='admin'||$f['status']!=='approved'))
                    throw new InvalidArgumentException('Você não pode remover o próprio acesso administrativo.');
                if($existing['role']==='admin' && ($f['role']!=='admin'||$f['status']!=='approved')){
                    $active=lc_one("SELECT COUNT(*) AS n FROM users WHERE role='admin' AND status='approved'");
                    if((int)($active['n']??0)<=1)
                        throw new InvalidArgumentException('É obrigatório manter ao menos um administrador ativo.');
                }
                $changed=[];
                foreach(['name'=>'full_name','email'=>'email','whatsapp'=>'whatsapp','instagram'=>'instagram','role'=>'role','status'=>'status'] as $src=>$target)
                    if((string)$f[$src]!== (string)$existing[$target])$changed[]=$target;
                $password=$f['password']!==''?password_hash($f['password'],PASSWORD_DEFAULT):null;
                if($password!==null)$changed[]='password_hash';
                if($f['pix']!=='')$changed[]='pix_key_enc';
                if($changed){
                    lc_query('UPDATE users SET full_name=?,email=?,whatsapp=?,instagram=?,role=?,status=?,
                       password_hash=COALESCE(?,password_hash), pix_key_enc=COALESCE(?,pix_key_enc),
                       approved_at=IF(?="approved",COALESCE(approved_at,NOW()),NULL),
                       approved_by=IF(?="approved",?,approved_by) WHERE id=?',
                      [$f['name'],$f['email'],$f['whatsapp'],$f['instagram'],$f['role'],$f['status'],
                       $password,$f['pix']!==''?lc_encrypt($f['pix']):null,$f['status'],$f['status'],
                       (int)$actor['id'],$id]);
                    // Force other browser sessions of the edited account to sign in again.
                    $newVersion=lc_mfa_rotate_sessions($id);
                    lc_account_admin_audit((int)$actor['id'],'account.updated',$id,
                        ['fields'=>$changed,'role_from'=>$existing['role'],'role_to'=>$f['role'],
                         'status_from'=>$existing['status'],'status_to'=>$f['status']]);
                }
                $db->commit();
                if($changed && $id===(int)$actor['id']){
                    $_SESSION['mfa_version']=$newVersion;
                    session_regenerate_id(true);
                }
                lc_flash($changed?'Conta atualizada e alteração registrada.':'Nenhuma alteração necessária.');
            }catch(Throwable $e){
                if(lc_db()->inTransaction())lc_db()->rollBack();
                lc_flash($e instanceof InvalidArgumentException?$e->getMessage():'Não foi possível atualizar a conta; verifique e-mail e dados.','error');
            }
            lc_redirect('/admin/contas/'.$id.'/editar');
        }
        if($method==='GET'){
            $user=lc_one('SELECT id,full_name,email,whatsapp,instagram,role,status FROM users WHERE id=?',[$id]);
            if(!$user){http_response_code(404);lc_page('Não encontrado',fn()=>print('Conta não encontrada.'));return true;}
            lc_page('Editar usuário',function()use($user){?>
<div class="container container-md lc-account-admin"><?php lc_heading('Permissões e dados','Editar conta #'.(int)$user['id'],'Alterações sensíveis exigem sua senha atual e ficam registradas.'); ?>
<div class="panel"><form method="post" action="/admin/contas/<?=(int)$user['id']?>/editar" autocomplete="off"><?=lc_csrf()?>
<div class="form-grid">
<label class="field"><span>Nome completo</span><input name="full_name" maxlength="160" minlength="3" required value="<?=lc_e($user['full_name'])?>"></label>
<label class="field"><span>E-mail</span><input name="email" type="email" maxlength="190" required value="<?=lc_e($user['email'])?>"></label>
<label class="field"><span>WhatsApp</span><input name="whatsapp" data-lc-mask="phone" maxlength="18" inputmode="tel" value="<?=lc_e($user['whatsapp'])?>"></label>
<label class="field"><span>Instagram</span><input name="instagram" maxlength="80" value="<?=lc_e($user['instagram'])?>"></label>
<label class="field"><span>Tipo de conta</span><select name="role">
<?php foreach(['admin'=>'Administrador','clipador'=>'Clipador','criador'=>'Criador'] as $role=>$label):?><option value="<?=$role?>" <?=$user['role']===$role?'selected':''?>><?=$label?></option><?php endforeach;?></select></label>
<label class="field"><span>Status</span><select name="status">
<?php foreach(['approved'=>'Aprovada','pending'=>'Pendente','rejected'=>'Rejeitada','suspended'=>'Suspensa'] as $status=>$label):?><option value="<?=$status?>" <?=$user['status']===$status?'selected':''?>><?=$label?></option><?php endforeach;?></select></label>
<label class="field"><span>Nova senha (deixe vazio para manter)</span><input name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="128"></label>
<label class="field"><span>Substituir chave Pix (opcional)</span><input name="new_pix_key" autocomplete="off" maxlength="150" placeholder="Nunca exibimos a chave Pix atual"></label>
<label class="field full"><span>Confirme sua senha administrativa</span><input name="admin_password" type="password" autocomplete="current-password" required></label>
<?php if(lc_mfa_enabled((int)lc_admin()['id'])):?>
<label class="field full"><span>Código Google Authenticator</span><input name="mfa_code" maxlength="60" required autocomplete="one-time-code"></label>
<?php endif;?>
</div><div class="form-actions"><button class="btn" type="submit">Salvar alterações →</button><a href="/admin/contas">Voltar</a></div>
</form></div></div><?php },'admin');return true;
        }
    }
    if($method==='GET'&&$path==='/admin/atividades'){
        $logs=lc_all("SELECT l.id,l.action,l.actor_id,l.subject_user_id,l.details_json,l.created_at,
             u.full_name AS actor_name FROM account_admin_audit l
             LEFT JOIN users u ON u.id=l.actor_id ORDER BY l.id DESC LIMIT 250");
        lc_page('Auditoria de contas',function()use($logs){?>
<div class="container lc-account-admin"><?php lc_heading('Trilha de auditoria','Ações administrativas','Registro inicial de criação e edição de contas.'); ?>
<div class="panel"><p class="hint">Este primeiro bloco registra mutações de contas; o registro global de navegação, financeiro e operações virá no bloco de auditoria.</p>
<div class="table-wrap"><table><thead><tr><th>Quando</th><th>Administrador</th><th>Ação</th><th>Conta afetada</th><th>Campos alterados</th></tr></thead><tbody>
<?php foreach($logs as $log):$detail=json_decode((string)($log['details_json']??'{}'),true)?:[];?>
<tr><td><?=lc_e($log['created_at'])?></td><td><?=lc_e($log['actor_name']??'#'.$log['actor_id'])?></td><td><?=lc_e($log['action'])?></td><td>#<?=(int)$log['subject_user_id']?></td><td><?=lc_e(implode(', ',is_array($detail['fields']??null)?$detail['fields']:[]))?></td></tr>
<?php endforeach;?></tbody></table></div></div></div>
<?php },'admin');return true;
    }
    return false;
}
