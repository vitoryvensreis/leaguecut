<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/account-admin.php';
require dirname(__DIR__).'/app/account-delete.php';
function authz_test(bool $condition,string $reason):void {
    if(!$condition)throw new RuntimeException('MASTER_PERMISSIONS_FAIL: '.$reason);
}
function authz_make_user(string $email,string $role='admin'):int {
    $nick='authz_'.bin2hex(random_bytes(5));
    lc_query("INSERT INTO users(full_name,nickname,email,password_hash,whatsapp,instagram,pix_key_enc,role,status) VALUES(?,?,?,?,?,?,?,?,?)",
        ['CI User '.$nick,$nick,$email,password_hash('ci-not-a-real-password',PASSWORD_DEFAULT),'85999999999','','',$role,'approved']);
    return (int)lc_db()->lastInsertId();
}
$existingSetting=lc_one("SELECT setting_value FROM platform_settings WHERE setting_key='master_admin_user_id'");
$masterId=0;$otherId=0;$subjectId=0;$roleId=0;
try{
    $masterId=authz_make_user(LC_MASTER_EMAIL);
    $otherId=authz_make_user('ci-admin-'.bin2hex(random_bytes(5)).'@example.invalid');
    $subjectId=authz_make_user('ci-finance-'.bin2hex(random_bytes(5)).'@example.invalid','clipador');
    lc_query("INSERT INTO platform_settings(setting_key,setting_value) VALUES('master_admin_user_id',?)
           ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",[(string)$masterId]);
    $master=lc_one('SELECT id,email,role,status FROM users WHERE id=?',[$masterId]);
    $other=lc_one('SELECT id,email,role,status FROM users WHERE id=?',[$otherId]);
    authz_test(lc_is_master($master),'master requires recorded ID plus exact email');
    authz_test(!lc_is_master($other),'other admin is not master');
    authz_test(!lc_is_master(array_replace($other,['email'=>LC_MASTER_EMAIL])),'email alone never elevates other account');
    authz_test(lc_admin_can_url($master,'/admin/funcoes'),'master can manage roles');
    authz_test(!lc_admin_can_url($other,'/admin/funcoes'),'other admin cannot create roles');
    authz_test(lc_admin_can_url($other,'/admin/campanhas'),'unassigned legacy admin retains ordinary tabs');
    lc_query('INSERT INTO admin_roles(name,description,created_by) VALUES(?,?,?)',['CI Limited '.bin2hex(random_bytes(3)),'only campaigns',$masterId]);
    $roleId=(int)lc_db()->lastInsertId();
    lc_query("INSERT INTO admin_role_permissions(role_id,area_key) VALUES(?,'campaigns')",[$roleId]);
    lc_query('INSERT INTO admin_user_roles(user_id,role_id,assigned_by) VALUES(?,?,?)',[$otherId,$roleId,$masterId]);
    authz_test(lc_admin_can_url($other,'/admin/campanhas'),'allowed custom tab accessible');
    authz_test(lc_admin_can_url($other,'/admin/campanhas/1/editar'),'nested GET and POST protected by same group');
    authz_test(!lc_admin_can_url($other,'/admin/financeiro'),'disabled custom tab not accessible');
    authz_test(!lc_admin_can_url($other,'/admin/contas'),'account editing denied without grant');
    authz_test(!lc_admin_can_url($other,'/admin/secret-future-page'),'unknown admin tabs fail closed');
    authz_test(lc_admin_first_url($other)==='/admin/campanhas','limited admin lands in permitted tab');
    $campaign=lc_one('SELECT id FROM campaigns ORDER BY id LIMIT 1');
    authz_test((int)($campaign['id']??0)>0,'campaign fixture exists');
    $dedupe='master-delete-'.bin2hex(random_bytes(9));
    lc_query("INSERT INTO ledger_entries(user_id,campaign_id,kind,amount_cents,dedupe_key,description)
              VALUES (?,?,'adjustment',45600,?,'CI financial removal')",
              [$subjectId,(int)$campaign['id'],$dedupe]);
    lc_query("INSERT INTO withdrawals(user_id,amount_cents,status) VALUES(?,?,'paid')",[$subjectId,12000]);
    $email=(string)lc_one('SELECT email FROM users WHERE id=?',[$subjectId])['email'];
    authz_test(lc_account_delete_blocker($subjectId)!==null,'finance requires explicit override');
    try{
        lc_account_delete_execute($otherId,$subjectId,$email,true);
        throw new RuntimeException('non-master erased financial records');
    }catch(InvalidArgumentException $e){
        authz_test(str_contains($e->getMessage(),'master'),'non-master financial purge rejected');
    }
    authz_test((bool)lc_one('SELECT id FROM users WHERE id=?',[$subjectId]),'blocked subject still exists');
    lc_account_delete_execute($masterId,$subjectId,$email,true);
    authz_test(!lc_one('SELECT id FROM users WHERE id=?',[$subjectId]),'master hard-deleted financial subject');
    authz_test(!lc_one('SELECT id FROM ledger_entries WHERE dedupe_key=?',[$dedupe]),'financial ledger erased');
    authz_test(!lc_one('SELECT id FROM withdrawals WHERE user_id=?',[$subjectId]),'withdrawal history erased');
    echo "MASTER_ROLES_AND_FINANCIAL_PURGE_OK\n";
}finally{
    if($existingSetting){
        lc_query("UPDATE platform_settings SET setting_value=? WHERE setting_key='master_admin_user_id'",[$existingSetting['setting_value']]);
    }else{
        lc_query("DELETE FROM platform_settings WHERE setting_key='master_admin_user_id'");
    }
    if($otherId)lc_query('DELETE FROM admin_user_roles WHERE user_id=?',[$otherId]);
    if($roleId)lc_query('DELETE FROM admin_roles WHERE id=?',[$roleId]);
    if($otherId)lc_query('DELETE FROM users WHERE id=?',[$otherId]);
    if($masterId)lc_query('DELETE FROM users WHERE id=?',[$masterId]);
}
