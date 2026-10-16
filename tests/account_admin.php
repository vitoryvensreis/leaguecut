<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/account-admin.php';
function test_admin(bool $condition,string $description): void {
 if(!$condition)throw new RuntimeException('ADMIN_TEST_FAILED: '.$description);
}
$valid=[
 'full_name'=>'Administrador de Teste','email'=>'admin-ci@example.com',
 'whatsapp'=>'(85) 99999-1234','instagram'=>'test.admin','role'=>'admin',
 'status'=>'approved','new_password'=>'a-secure-passphrase-2026','new_pix_key'=>''
];
$checked=lc_account_admin_validate($valid,true);
test_admin($checked['email']==='admin-ci@example.com' && $checked['role']==='admin','valid admin data');
test_admin($checked['whatsapp']==='85999991234','server strips phone mask');
foreach([
 ['status'=>'pending'],
 ['role'=>'superadmin'],
 ['new_password'=>'12345'],
 ['email'=>'not an email']
] as $override){
 try{
   lc_account_admin_validate(array_replace($valid,$override),true);
   throw new RuntimeException('Invalid privileged input accepted');
 }catch(InvalidArgumentException $e){test_admin(true,'unsafe creation rejected');}
}
$pdo=lc_db();
$pdo->beginTransaction();
try{
    $seed=lc_one('SELECT id FROM users WHERE role="admin" ORDER BY id LIMIT 1') ?:
          lc_one('SELECT id FROM users ORDER BY id LIMIT 1');
    test_admin((int)($seed['id']??0)>0,'user seed');
    $actor=(int)$seed['id'];
    lc_account_admin_audit($actor,'ci.audit.test',$actor,['fields'=>['email','password_hash']]);
    $row=lc_one('SELECT details_json,ip_hash FROM account_admin_audit WHERE action=? AND actor_id=? ORDER BY id DESC LIMIT 1',
      ['ci.audit.test',$actor]);
    test_admin(is_array($row),'audit persisted');
    $payload=json_decode($row['details_json'],true);
    test_admin($payload['fields']===['email','password_hash'],'audit stores field names');
    test_admin(strlen($row['ip_hash'])===64,'IP salted hash');
    $pdo->rollBack();
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 throw $e;
}
echo "ADMIN_ACCOUNTS_OK\n";
