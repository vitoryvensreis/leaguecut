<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/account-admin.php';
require dirname(__DIR__).'/app/account-delete.php';

function account_delete_assert(bool $condition,string $reason): void {
    if(!$condition)throw new RuntimeException('ACCOUNT_DELETE_TEST_FAILED: '.$reason);
}
$admin=lc_one("SELECT id FROM users WHERE role='admin' AND status='approved' ORDER BY id ASC LIMIT 1");
$campaign=lc_one('SELECT id FROM campaigns ORDER BY id ASC LIMIT 1');
account_delete_assert((int)($admin['id']??0)>0,'admin seed');
account_delete_assert((int)($campaign['id']??0)>0,'campaign seed');
$actor=(int)$admin['id'];
$campaignId=(int)$campaign['id'];
$token=bin2hex(random_bytes(5));

function make_delete_fixture(string $token): int {
    lc_query("INSERT INTO users (full_name,nickname,email,password_hash,whatsapp,instagram,pix_key_enc,role,status)
             VALUES (?,?,?,?,?,?,?,?,?)",
             ['Demo Delete CI','ci_del_'.$token,'ci-del-'.$token.'@example.invalid',
              password_hash('test-only-password',PASSWORD_DEFAULT),'85999999999','','','clipador','approved']);
    return (int)lc_db()->lastInsertId();
}

// Test complete cascade with uncredited submissions. No Drive API is contacted.
$uid=make_delete_fixture($token);
lc_query("INSERT INTO social_accounts(user_id,network,username,profile_url)
          VALUES (?,'instagram',?,?)",[$uid,'ci_'.$token,'https://www.instagram.com/ci_'.$token.'/']);
$socialId=(int)lc_db()->lastInsertId();
lc_query("INSERT INTO clips(user_id,campaign_id,social_account_id,title,published_url,status)
          VALUES (?,?,?,?,?,'pending')",[$uid,$campaignId,$socialId,'CI clip','https://www.instagram.com/reel/DEMO_TEST_123']);
$clipId=(int)lc_db()->lastInsertId();
lc_account_delete_execute($actor,$uid,'ci-del-'.$token.'@example.invalid');
account_delete_assert(!lc_one('SELECT id FROM users WHERE id=?',[$uid]),'user hard deleted');
account_delete_assert(!lc_one('SELECT id FROM social_accounts WHERE id=?',[$socialId]),'social account cascaded');
account_delete_assert(!lc_one('SELECT id FROM clips WHERE id=?',[$clipId]),'clips cascaded');
account_delete_assert((bool)lc_one('SELECT id FROM account_admin_audit WHERE actor_id=? AND action=? ORDER BY id DESC LIMIT 1',
                                  [$actor,'account.deleted']),'deletion logged');

// Protect accounts that have ever been credited; then explicitly remove test-only
// financial record to exercise the normal clean deletion path.
$paidId=make_delete_fixture(bin2hex(random_bytes(5)));
$dedupe='delete-ci:'.bin2hex(random_bytes(10));
lc_query("INSERT INTO ledger_entries(user_id,campaign_id,kind,amount_cents,dedupe_key,description)
          VALUES (?,?,'adjustment',100,?,'CI test only')",[$paidId,$campaignId,$dedupe]);
account_delete_assert(lc_account_delete_blocker($paidId)!==null,'ledger prevents deletion');
try {
    lc_account_delete_execute($actor,$paidId,(string)lc_one('SELECT email FROM users WHERE id=?',[$paidId])['email']);
    throw new RuntimeException('Account with financial history was deleted');
} catch(InvalidArgumentException $e) {
    account_delete_assert(str_contains($e->getMessage(),'financeiros'),'financial history rejected');
}
account_delete_assert((bool)lc_one('SELECT id FROM users WHERE id=?',[$paidId]),'blocked account remains');
lc_query('DELETE FROM ledger_entries WHERE dedupe_key=?',[$dedupe]);
$email=(string)lc_one('SELECT email FROM users WHERE id=?',[$paidId])['email'];
lc_account_delete_execute($actor,$paidId,$email);
account_delete_assert(!lc_one('SELECT id FROM users WHERE id=?',[$paidId]),'cleaned fixture removed');
echo "ACCOUNT_DELETE_DATABASE_OK\n";
