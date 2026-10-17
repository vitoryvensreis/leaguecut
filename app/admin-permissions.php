<?php
declare(strict_types=1);
/**
 * Master account is anchored to an immutable user ID during the migration.
 * Neither changing an email nor creating a second admin grants master access.
 */
const LC_MASTER_EMAIL='vitoryvensreisdeoliveira@gmail.com';

function lc_master_user_id(): int {
    $row=lc_one("SELECT setting_value FROM platform_settings WHERE setting_key='master_admin_user_id'");
    return max(0,(int)($row['setting_value']??0));
}
function lc_is_master(array $user): bool {
    return ($user['role']??'')==='admin'
       && ($user['status']??'')==='approved'
       && (int)($user['id']??0)>0
       && (int)$user['id']===lc_master_user_id()
       && mb_strtolower(trim((string)($user['email']??'')))===LC_MASTER_EMAIL;
}
function lc_admin_areas(): array {
    return [
      'overview'=>'Visão geral','clippers'=>'Clipadores','clips'=>'Conferir clipes',
      'support'=>'Chamados e relatórios','campaigns'=>'Campanhas',
      'finance'=>'Saques, bônus e comissões','creator'=>'Pacotes e assinaturas',
      'automations'=>'Automações e WhatsApp','branding'=>'Home, identidade e SEO',
      'integrations'=>'Integrações','notifications'=>'Notificações',
      'people'=>'Contas de usuários','privacy'=>'Privacidade e LGPD',
      'audit'=>'Auditoria','demo'=>'Modo demonstração'
    ];
}
function lc_admin_area_for_path(string $path): ?string {
    $prefix=[
      '/admin/administradores'=>'master','/admin/funcoes'=>'master',
      '/admin/atividades'=>'audit','/admin/auditoria'=>'audit',
      '/admin/privacidade'=>'privacy','/admin/contas'=>'people',
      '/admin/clipadores'=>'clippers','/admin/clipes'=>'clips',
      '/admin/chamados'=>'support','/admin/campanhas'=>'campaigns',
      '/admin/saques'=>'finance','/admin/financeiro'=>'finance',
      '/admin/bonus-mensal'=>'finance','/admin/indicacoes'=>'finance',
      '/admin/pacotes'=>'creator','/admin/assinaturas'=>'creator',
      '/admin/automacoes'=>'automations','/admin/whatsapp'=>'automations',
      '/admin/home'=>'branding','/admin/configuracoes'=>'branding',
      '/admin/seo'=>'branding','/admin/integracoes'=>'integrations',
      '/admin/notificacoes'=>'notifications','/admin/demo'=>'demo'
    ];
    if($path==='/admin')return 'overview';
    foreach($prefix as $p=>$area)if($path===$p||str_starts_with($path,$p.'/'))return $area;
    return null; // fail closed for custom roles
}
function lc_admin_role_assignment(int $uid): ?array {
    return lc_one('SELECT r.id,r.name FROM admin_user_roles a JOIN admin_roles r ON r.id=a.role_id WHERE a.user_id=?',[$uid]);
}
function lc_admin_allowed(array $u,string $area): bool {
    if(($u['role']??'')!=='admin'||($u['status']??'')!=='approved')return false;
    if(lc_is_master($u))return true;
    if($area==='master')return false;
    if(!array_key_exists($area,lc_admin_areas()))return false;
    $assignment=lc_admin_role_assignment((int)$u['id']);
    if(!$assignment)return true; // legacy administrators retain existing scope
    return (bool)lc_one('SELECT 1 AS found FROM admin_role_permissions WHERE role_id=? AND area_key=?',[(int)$assignment['id'],$area]);
}
function lc_admin_can_url(array $u,string $url): bool {
    $path=parse_url($url,PHP_URL_PATH)?:$url;
    $area=lc_admin_area_for_path($path);
    return $area!==null&&lc_admin_allowed($u,$area);
}
function lc_admin_require_master(array $u): void {
    if(!lc_is_master($u)){http_response_code(403);exit('Ação exclusiva do administrador master.');}
}
function lc_admin_permissions_for(int $roleId): array {
    return array_column(lc_all('SELECT area_key FROM admin_role_permissions WHERE role_id=?',[$roleId]),'area_key');
}
function lc_admin_roles_routes(string $method,string $path): bool {
    if($path!=='/admin/funcoes'&&!preg_match('~^/admin/funcoes/(?:criar|atribuir|[0-9]+/(?:salvar|excluir))$~D',$path))return false;
    $actor=lc_admin();
    lc_admin_require_master($actor);
    if($method==='POST'){
        if(!lc_account_admin_password($actor)){
            lc_flash('Senha do master ou código 2FA incorreto.','error');
            lc_redirect('/admin/funcoes');
        }
        try{
            $db=lc_db();$db->beginTransaction();
            if($path==='/admin/funcoes/criar' || preg_match('~^/admin/funcoes/([0-9]+)/salvar$~D',$path,$m)){
                $id=isset($m[1])?(int)$m[1]:0;
                $name=trim(lc_post('name',80));$description=lc_post('description',255);
                if(mb_strlen($name)<3||mb_strlen($name)>80)throw new InvalidArgumentException('Nome da função deve ter entre 3 e 80 caracteres.');
                $selected=(array)($_POST['permissions']??[]);
                if(array_filter($selected,fn($a)=>!is_string($a)||!array_key_exists($a,lc_admin_areas())))
                    throw new InvalidArgumentException('Permissão inválida.');
                $allowed=array_values(array_unique($selected));
                if(!$allowed)throw new InvalidArgumentException('Selecione ao menos uma aba.');
                if($id>0){
                    if(!lc_one('SELECT id FROM admin_roles WHERE id=? FOR UPDATE',[$id]))throw new InvalidArgumentException('Função inexistente.');
                    lc_query('UPDATE admin_roles SET name=?,description=? WHERE id=?',[$name,$description,$id]);
                    lc_query('DELETE FROM admin_role_permissions WHERE role_id=?',[$id]);
                }else{
                    lc_query('INSERT INTO admin_roles(name,description,created_by) VALUES(?,?,?)',[$name,$description,(int)$actor['id']]);
                    $id=(int)$db->lastInsertId();
                }
                foreach($allowed as $key)lc_query('INSERT INTO admin_role_permissions(role_id,area_key) VALUES(?,?)',[$id,$key]);
                lc_account_admin_audit((int)$actor['id'],'role.saved',null,['fields'=>['role_name','permissions']]);
            }elseif($path==='/admin/funcoes/atribuir'){
                $uid=lc_int('user_id',0,2147483647);
                $roleId=lc_int('admin_role_id',0,2147483647);
                $target=lc_one('SELECT id,email,role,status FROM users WHERE id=? FOR UPDATE',[$uid]);
                if(!$target||$target['role']!=='admin'||$target['status']!=='approved'||lc_is_master($target))
                    throw new InvalidArgumentException('Selecione outro administrador ativo, que não seja o master.');
                if($roleId){
                    if(!lc_one('SELECT id FROM admin_roles WHERE id=?',[$roleId]))throw new InvalidArgumentException('Função não encontrada.');
                    lc_query('INSERT INTO admin_user_roles(user_id,role_id,assigned_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id),assigned_by=VALUES(assigned_by)',[$uid,$roleId,(int)$actor['id']]);
                }else lc_query('DELETE FROM admin_user_roles WHERE user_id=?',[$uid]);
                lc_mfa_rotate_sessions($uid);
                lc_account_admin_audit((int)$actor['id'],'role.assigned',$uid,['fields'=>['admin_role']]);
            }elseif(preg_match('~^/admin/funcoes/([0-9]+)/excluir$~D',$path,$m)){
                $id=(int)$m[1];
                if(lc_one('SELECT user_id FROM admin_user_roles WHERE role_id=? LIMIT 1',[$id]))
                    throw new InvalidArgumentException('Desvincule os administradores antes de apagar a função.');
                lc_query('DELETE FROM admin_roles WHERE id=?',[$id]);
                lc_account_admin_audit((int)$actor['id'],'role.deleted',null,['fields'=>['role']]);
            }else throw new InvalidArgumentException('Operação inválida.');
            $db->commit();
            lc_flash('Funções e permissões atualizadas com sucesso.');
        }catch(Throwable $e){
            if(lc_db()->inTransaction())lc_db()->rollBack();
            lc_flash($e instanceof InvalidArgumentException?$e->getMessage():'Falha ao alterar funções e permissões.','error');
        }
        lc_redirect('/admin/funcoes');
    }
    if($method==='GET'&&$path==='/admin/funcoes'){
        $roles=lc_all('SELECT id,name,description FROM admin_roles ORDER BY name');
        $admins=lc_all("SELECT u.id,u.full_name,u.email,r.id AS custom_role_id,r.name AS custom_role_name FROM users u
             LEFT JOIN admin_user_roles a ON a.user_id=u.id LEFT JOIN admin_roles r ON r.id=a.role_id
             WHERE u.role='admin' AND u.status='approved' AND u.id<>? ORDER BY u.full_name",[lc_master_user_id()]);
        $areas=lc_admin_areas();
        lc_page('Funções e permissões',function()use($roles,$admins,$areas,$actor){?>
<div class="container container-md lc-account-admin">
<?php lc_heading('Administração master','Funções personalizadas','Crie funções, marque as abas permitidas e atribua a administradores.'); ?>
<p class="hint">O acesso é validado também no servidor; ocultar uma aba não é a única proteção. Sem função personalizada, o administrador mantém o perfil padrão.</p>
<div class="panel"><h2>Nova função</h2>
<form method="post" action="/admin/funcoes/criar"><?=lc_csrf()?>
<div class="form-grid"><label class="field"><span>Nome da função</span><input name="name" required maxlength="80" placeholder="Ex.: Suporte"></label>
<label class="field"><span>Descrição</span><input name="description" maxlength="255"></label></div>
<div class="lc-permission-grid"><?php foreach($areas as $key=>$title):?><label><input type="checkbox" name="permissions[]" value="<?=lc_e($key)?>"> <?=lc_e($title)?></label><?php endforeach;?></div>
<?php lc_admin_roles_password_fields($actor); ?><button class="btn" type="submit">Criar função</button></form></div>
<?php foreach($roles as $role):$assigned=lc_admin_permissions_for((int)$role['id']);?>
<div class="panel"><h2><?=lc_e($role['name'])?></h2>
<form method="post" action="/admin/funcoes/<?=(int)$role['id']?>/salvar"><?=lc_csrf()?>
<div class="form-grid"><label class="field"><span>Nome</span><input name="name" maxlength="80" required value="<?=lc_e($role['name'])?>"></label>
<label class="field"><span>Descrição</span><input name="description" maxlength="255" value="<?=lc_e($role['description'])?>"></label></div>
<div class="lc-permission-grid"><?php foreach($areas as $key=>$title):?><label><input type="checkbox" name="permissions[]" value="<?=lc_e($key)?>" <?=in_array($key,$assigned,true)?'checked':''?>> <?=lc_e($title)?></label><?php endforeach;?></div>
<?php lc_admin_roles_password_fields($actor); ?><button class="btn btn-sm" type="submit">Salvar permissões</button></form>
<form method="post" action="/admin/funcoes/<?=(int)$role['id']?>/excluir" onsubmit="return confirm('Excluir esta função? Administradores vinculados impedem exclusão.');"><?=lc_csrf()?>
<?php lc_admin_roles_password_fields($actor); ?><button class="btn btn-ghost btn-sm" type="submit">Excluir função</button></form></div>
<?php endforeach; ?>
<div class="panel"><h2>Atribuir funções</h2>
<?php foreach($admins as $admin):?><form method="post" action="/admin/funcoes/atribuir" class="lc-admin-role-row"><?=lc_csrf()?>
<input type="hidden" name="user_id" value="<?=(int)$admin['id']?>">
<strong><?=lc_private($admin['full_name'])?></strong>
<select name="admin_role_id"><option value="0">Administrador padrão</option>
<?php foreach($roles as $role):?><option value="<?=(int)$role['id']?>" <?=(int)($admin['custom_role_id']??0)===(int)$role['id']?'selected':''?>><?=lc_e($role['name'])?></option><?php endforeach;?></select>
<?php lc_admin_roles_password_fields($actor); ?><button type="submit" class="btn btn-sm">Aplicar</button></form><?php endforeach; ?>
</div></div>
<?php },'admin');return true;
    }
    return false;
}
function lc_admin_roles_password_fields(array $actor):void {?>
<label class="field"><span>Senha master</span><input type="password" name="admin_password" required autocomplete="current-password"></label>
<?php if(lc_mfa_enabled((int)$actor['id'])):?><label class="field"><span>Código 2FA</span><input name="mfa_code" autocomplete="one-time-code" required maxlength="60"></label><?php endif;?>
<?php }
