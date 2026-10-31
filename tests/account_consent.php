<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/mfa.php';
require dirname(__DIR__).'/app/account-self.php';

function check_consent(bool $ok,string $reason): void {
    if(!$ok)throw new RuntimeException('WhatsApp consent test failed: '.$reason);
}
$source=file_get_contents(dirname(__DIR__).'/app/account-self.php');
check_consent(is_string($source),'source readable');
check_consent(str_contains($source,"'/conta/consentimento-whatsapp'"),'user-facing preference route');
check_consent(str_contains($source,'lc_mfa_stepup_or_password($u,'),'owner verification before change');
check_consent(str_contains($source,'lc_account_whatsapp_consent_save($uid,$optIn)'),'authenticated user id used');
check_consent(str_contains($source,"consent.whatsapp.revoked"),'revocation audit');
try{
    lc_account_whatsapp_consent_save(0,true);
    throw new RuntimeException('Zero account ID accepted');
}catch(InvalidArgumentException $e){}

$existing=lc_one("SELECT id,consent_whatsapp FROM users WHERE whatsapp IS NOT NULL AND whatsapp!='' ORDER BY id LIMIT 1");
if($existing){
    $uid=(int)$existing['id'];$previous=(int)$existing['consent_whatsapp'];
    $db=lc_db();$db->beginTransaction();
    try{
        check_consent(lc_account_whatsapp_consent_save($uid,false)===(bool)$previous,'revoke preference');
        check_consent((int)lc_one('SELECT consent_whatsapp FROM users WHERE id=?',[$uid])['consent_whatsapp']===0,'revoked in database');
        check_consent(lc_account_whatsapp_consent_save($uid,false)===false,'no duplicate revocation');
        check_consent(lc_account_whatsapp_consent_save($uid,true)===true,'opt in preference');
        check_consent((int)lc_one('SELECT consent_whatsapp FROM users WHERE id=?',[$uid])['consent_whatsapp']===1,'opt in persisted');
        check_consent(lc_account_whatsapp_consent_save($uid,true)===false,'no duplicate opt-in');
        $db->rollBack();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
    check_consent((int)lc_one('SELECT consent_whatsapp FROM users WHERE id=?',[$uid])['consent_whatsapp']===$previous,'transaction rollback');
}
echo "WHATSAPP_CONSENT_OK\n";
