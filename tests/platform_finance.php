<?php
declare(strict_types=1);
/**
 * Platform cash + WhatsApp-authorized withdrawals. No real WhatsApp or Asaas
 * traffic: WPP is disabled in CI and ASAAS_PLATFORM_PAYOUTS_ENABLED=0.
 * Creates its own fixtures and removes them at the end (the master e-mail is unique).
 */
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/admin-permissions.php';
require dirname(__DIR__).'/app/account-admin.php';
require dirname(__DIR__).'/app/mfa.php';
require dirname(__DIR__).'/app/asaas.php';
require dirname(__DIR__).'/app/platform-finance.php';
function pf_assert(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('PLATFORM_FINANCE_FAILED: '.$message);
}
putenv('ASAAS_ENABLED=0');putenv('ASAAS_PLATFORM_PAYOUTS_ENABLED=0');putenv('WPP_AUTH_TOKEN=');
putenv('FINANCE_APPROVER_WHATSAPP=5585921627669');

// Pure checks.
pf_assert(lc_pf_approver_phone()==='5585921627669','approver phone from env');
pf_assert(lc_pf_map_provider_status('DONE')==='paid' && lc_pf_map_provider_status('FAILED')==='failed'
    && lc_pf_map_provider_status('CANCELLED')==='failed' && lc_pf_map_provider_status('BANK_PROCESSING')==='processing','status map');
pf_assert(lc_pf_safe_receipt('https://www.asaas.com/comprovantes/1')!==null && lc_pf_safe_receipt('https://evil.example/asaas.com')===null,'receipt host');
try{lc_asaas_platform_transfer(['value'=>10.0]);pf_assert(false,'transfer allowed while disabled');}
catch(RuntimeException){}
try{lc_asaas_request('POST','/transfers',[]);pf_assert(false,'generic transfer path opened');}
catch(RuntimeException){}
$_SESSION['lc_after_login']='/admin/financeiro/autorizar/12/'.str_repeat('A',43);
pf_assert(lc_after_login_destination('admin','/admin')==='/admin/financeiro/autorizar/12/'.str_repeat('A',43),'after-login link for admin');
$_SESSION['lc_after_login']='https://evil.example';
pf_assert(lc_after_login_destination('admin','/admin')==='/admin','external redirect rejected');
$_SESSION['lc_after_login']='/admin/financeiro/autorizar/12/'.str_repeat('A',43);
pf_assert(lc_after_login_destination('clipador','/dashboard')==='/dashboard','clipper never redirected to the link');

$created=['users'=>[],'packages'=>[],'subs'=>[]];
$existingSetting=lc_one("SELECT setting_value FROM platform_settings WHERE setting_key='master_admin_user_id'");
$make=function(string $email,string $role)use(&$created): int {
    lc_query("INSERT INTO users(full_name,nickname,email,password_hash,whatsapp,instagram,pix_key_enc,role,status) VALUES(?,?,?,?,?,?,?,?,'approved')",
        ['PF '.$role,'pf_'.bin2hex(random_bytes(5)),$email,password_hash('ci-pass-123456',PASSWORD_DEFAULT),'85999999999','pf','',$role]);
    $id=(int)lc_db()->lastInsertId();$created['users'][]=$id;return $id;
};
try{
    $masterId=$make(LC_MASTER_EMAIL,'admin');
    $adminId=$make('pf-admin-'.bin2hex(random_bytes(4)).'@example.invalid','admin');
    $creatorId=$make('pf-creator-'.bin2hex(random_bytes(4)).'@example.invalid','criador');
    lc_query("INSERT INTO platform_settings(setting_key,setting_value) VALUES('master_admin_user_id',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",[(string)$masterId]);
    $master=lc_one('SELECT id,email,role,status FROM users WHERE id=?',[$masterId]);
    $admin=lc_one('SELECT id,email,role,status FROM users WHERE id=?',[$adminId]);
    pf_assert(lc_is_master($master) && !lc_is_master($admin),'fixtures master/admin');

    $before=lc_pf_summary();
    // R$ 5.000,00 of confirmed creator income.
    lc_query("INSERT INTO creator_packages(name,slug,description,monthly_price_cents,monthly_clips,fulfillment_mode) VALUES(?,?,?,?,?,?)",['PF pacote','pf-pacote-'.bin2hex(random_bytes(4)),'ci',500000,4,'download']);
    $created['packages'][]=$packageId=(int)lc_db()->lastInsertId();
    lc_query("INSERT INTO creator_subscriptions(creator_id,package_id,status,price_cents_snapshot,monthly_clips_snapshot,fulfillment_mode_snapshot) VALUES(?,?,'active',500000,4,'download')",[$creatorId,$packageId]);
    $created['subs'][]=$subId=(int)lc_db()->lastInsertId();
    lc_query("INSERT INTO creator_subscription_cycles(subscription_id,cycle_start,cycle_end,clips_included,payment_status) VALUES(?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 1 MONTH),4,'confirmed')",[$subId]);
    $after=lc_pf_summary();
    pf_assert($after['income']-$before['income']===500000 && $after['cash']-$before['cash']===500000,'confirmed cycle counted as income and cash');
    pf_assert($after['available']===max(0,$after['cash']-$after['committed']-$after['platform_reserved']),'available formula');
    $available=$after['available'];
    pf_assert($available>=50000,'available covers the test amount');

    // Validation.
    try{lc_pf_request($admin,50,'CPF','529.982.247-25','');pf_assert(false,'below minimum accepted');}catch(InvalidArgumentException){}
    try{lc_pf_request($admin,$available+1,'CPF','529.982.247-25','');pf_assert(false,'above available accepted');}catch(InvalidArgumentException){}
    try{lc_pf_request($admin,1000,'CPF','111.111.111-11','');pf_assert(false,'invalid CPF accepted');}catch(InvalidArgumentException){}

    // Request reserves the amount.
    $id=lc_pf_request($admin,20000,'CPF','529.982.247-25','teste ci');
    $row=lc_one('SELECT * FROM platform_withdrawals WHERE id=?',[$id]);
    pf_assert($row['status']==='awaiting_authorization' && (int)$row['amount_cents']===20000,'pending request');
    pf_assert(lc_decrypt($row['pix_key_enc'])==='52998224725' && !str_contains($row['pix_key_hint'],'52998224725'),'key encrypted + masked hint');
    pf_assert(preg_match('/^lcpw-'.$id.'-[a-f0-9]{16}$/',$row['provider_reference'])===1,'external reference format');
    pf_assert(lc_pf_summary()['available']===$available-20000,'amount reserved');

    // WhatsApp offline: request kept, failure logged, no false "sent".
    try{lc_pf_send_link($id,$adminId);pf_assert(false,'send reported success without WhatsApp');}
    catch(RuntimeException $e){pf_assert(str_contains($e->getMessage(),'não foi enviado'),'clear WhatsApp failure message');}
    pf_assert((bool)lc_one("SELECT id FROM platform_finance_events WHERE withdrawal_id=? AND event='link_send_failed'",[$id]),'send failure logged');
    pf_assert(!lc_one("SELECT id FROM platform_finance_events WHERE withdrawal_id=? AND event='link_sent'",[$id]),'no fake sent event');

    // Fresh token (bypass cooldown for the test).
    lc_query('UPDATE platform_withdrawals SET link_last_sent_at=NULL WHERE id=?',[$id]);
    [$token]=lc_pf_issue_token($id,$adminId);
    pf_assert(lc_pf_link_row($id,$token)!==null,'valid link');
    pf_assert(lc_pf_link_row($id,str_repeat('A',43))===null,'forged token rejected');
    try{lc_pf_decide($id,$token,$admin,'authorize');pf_assert(false,'non-master authorized');}
    catch(RuntimeException $e){pf_assert(str_contains($e->getMessage(),'master'),'non-master blocked');}
    pf_assert((bool)lc_one("SELECT id FROM platform_finance_events WHERE withdrawal_id=? AND event='authorization_denied'",[$id]),'denial logged');
    pf_assert(lc_one('SELECT status FROM platform_withdrawals WHERE id=?',[$id])['status']==='awaiting_authorization','still pending after denial');

    // Expired link.
    lc_query('UPDATE platform_withdrawals SET link_expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?',[$id]);
    try{lc_pf_decide($id,$token,$master,'authorize');pf_assert(false,'expired link used');}catch(RuntimeException){}

    // Master authorizes; dispatch is blocked (payouts disabled) and stays authorized + reserved.
    lc_query('UPDATE platform_withdrawals SET link_last_sent_at=NULL WHERE id=?',[$id]);
    [$token]=lc_pf_issue_token($id,$adminId);
    $message=lc_pf_decide($id,$token,$master,'authorize');
    pf_assert(str_contains($message,'não enviado'),'dispatch blocked message');
    $row=lc_one('SELECT * FROM platform_withdrawals WHERE id=?',[$id]);
    pf_assert($row['status']==='authorized' && (int)$row['authorized_by']===$masterId && $row['link_token_hash']===null,'authorized by master, token burned');
    try{lc_pf_decide($id,$token,$master,'authorize');pf_assert(false,'token reused');}catch(RuntimeException){}
    pf_assert(lc_pf_summary()['available']===$available-20000,'authorized amount stays reserved');
    pf_assert((bool)lc_one("SELECT id FROM platform_finance_events WHERE withdrawal_id=? AND event='dispatch_blocked'",[$id]),'blocked dispatch logged');

    // Master cancels -> reserve released.
    lc_pf_cancel($id,$master);
    pf_assert(lc_pf_summary()['available']===$available,'cancel releases reserve');

    // Refusal path.
    $id2=lc_pf_request($admin,10000,'EMAIL','Financeiro@Example.com','');
    pf_assert(lc_decrypt(lc_one('SELECT pix_key_enc FROM platform_withdrawals WHERE id=?',[$id2])['pix_key_enc'])==='financeiro@example.com','email normalized');
    [$token2]=lc_pf_issue_token($id2,$adminId);
    lc_pf_decide($id2,$token2,$master,'refuse');
    pf_assert(lc_one('SELECT status FROM platform_withdrawals WHERE id=?',[$id2])['status']==='refused','refused');
    pf_assert(lc_pf_summary()['available']===$available,'refusal releases reserve');

    // 24h expiry.
    $id3=lc_pf_request($admin,10000,'PHONE','(85) 99216-2766','');
    lc_query('UPDATE platform_withdrawals SET created_at=DATE_SUB(NOW(),INTERVAL 25 HOUR) WHERE id=?',[$id3]);
    lc_pf_expire_stale();
    pf_assert(lc_one('SELECT status FROM platform_withdrawals WHERE id=?',[$id3])['status']==='expired','stale request expired');

    // Log is complete and ordered.
    $events=array_column(lc_all('SELECT event FROM platform_finance_events WHERE withdrawal_id=? ORDER BY id',[$id]),'event');
    foreach(['requested','link_issued','link_send_failed','authorization_denied','authorized','dispatch_blocked','cancelled'] as $expected)
        pf_assert(in_array($expected,$events,true),'log contains '.$expected);
    $leak=lc_one("SELECT id FROM platform_finance_events WHERE detail LIKE '%52998224725%' OR detail LIKE ?",['%'.$token.'%']);
    pf_assert(!$leak,'log never contains full key or token');

    $statement=lc_pf_statement('in',null,null,1);
    pf_assert($statement['total']>=1 && (int)$statement['rows'][0]['amount']>0,'statement lists income');
    echo "PLATFORM_FINANCE_OK\n";
}finally{
    $ids=$created['users'];
    if($ids){
        $in=implode(',',array_map('intval',$ids));
        lc_query("DELETE e FROM platform_finance_events e JOIN platform_withdrawals p ON p.id=e.withdrawal_id WHERE p.requested_by IN ($in)");
        lc_query("DELETE FROM platform_finance_events WHERE actor_user_id IN ($in)");
        lc_query("DELETE FROM platform_withdrawals WHERE requested_by IN ($in)");
    }
    foreach($created['subs'] as $sid){lc_query('DELETE FROM creator_subscription_cycles WHERE subscription_id=?',[$sid]);lc_query('DELETE FROM creator_subscriptions WHERE id=?',[$sid]);}
    foreach($created['packages'] as $pid)lc_query('DELETE FROM creator_packages WHERE id=?',[$pid]);
    if($existingSetting)lc_query("UPDATE platform_settings SET setting_value=? WHERE setting_key='master_admin_user_id'",[$existingSetting['setting_value']]);
    else lc_query("DELETE FROM platform_settings WHERE setting_key='master_admin_user_id'");
    foreach($ids as $uid){
        lc_query('DELETE FROM account_security_events WHERE user_id=?',[$uid]);
        lc_query('DELETE FROM users WHERE id=?',[$uid]);
    }
}
