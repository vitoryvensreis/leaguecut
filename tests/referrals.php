<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/referrals.php';
function referral_assert(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('B3_REFERRAL_FAILED: '.$why);
}
referral_assert(lc_referral_amount('fixed',1234,10000)===1234,'fixed cents');
referral_assert(lc_referral_amount('percent',1250,10000)===1250,'12.50 percent');
referral_assert(lc_referral_amount('percent',5001,10000)===0,'percentage cap');
referral_assert(lc_referral_amount('fixed',50001,10000)===0,'fixed cap');
referral_assert(lc_referral_amount('percent',1250,0)===0,'zero revenue never yields reward');
$fixture=lc_one("SELECT id,whatsapp FROM users WHERE email='creator@leaguecut.test' AND role='clipador' AND status='approved'");
$admin=lc_one("SELECT id FROM users WHERE email='admin@leaguecut.test' AND role='admin'");
$clip=lc_one("SELECT l.clip_id,l.amount_cents,c.status
  FROM ledger_entries l JOIN clips c ON c.id=l.clip_id WHERE l.user_id=? AND
  l.kind='clip' AND l.amount_cents>0 AND c.status='approved' ORDER BY l.id LIMIT 1",[$fixture['id']??0]);
referral_assert((bool)$fixture&&(bool)$admin&&(bool)$clip,'CI fixtures ready');
$db=lc_db();$db->beginTransaction();
try{
    $handle='ref_'.bin2hex(random_bytes(4));$email=$handle.'@test.leaguecut.local';
    lc_query("INSERT INTO users(full_name,email,password_hash,whatsapp,instagram,pix_key_enc,role,status)
        VALUES(?,?,?,?,?,'','clipador','approved')",
        ['CI Referral',$email,'hash','85991112233','referral_ci']);
    $parent=(int)$db->lastInsertId();
    lc_query('UPDATE users SET nickname=? WHERE id=?',[$handle,$parent]);
    $code=lc_referral_code($parent);
    referral_assert((bool)preg_match('/^[A-F0-9]{16}$/D',$code),'random public referral code');
    referral_assert(!lc_referral_attach($parent,'clipador',$code),'self referral prohibited');
    referral_assert(lc_referral_attach((int)$fixture['id'],'clipador',$code),'eligible attribution created');
    referral_assert(lc_referral_attach((int)$fixture['id'],'clipador',$code),'attribution idempotent');
    $attribution=lc_one('SELECT * FROM referrals WHERE referred_user_id=?',[$fixture['id']]);
    referral_assert((int)$attribution['referrer_user_id']===$parent,'referrer immutably linked');
    lc_query("UPDATE referral_rules SET enabled=1,mode='percent',value_int=1000 WHERE referred_role='clipador'");
    lc_referral_offer((int)$fixture['id'],'clip_credit',(int)$clip['clip_id'],(int)$clip['amount_cents']);
    lc_referral_offer((int)$fixture['id'],'clip_credit',(int)$clip['clip_id'],(int)$clip['amount_cents']);
    $c=lc_one('SELECT * FROM referral_commissions WHERE referred_user_id=?',[$fixture['id']]);
    referral_assert($c && $c['status']==='pending','pending commission only');
    referral_assert((int)$c['amount_cents']===lc_referral_amount('percent',1000,(int)$clip['amount_cents']),'correct reward');
    referral_assert(!lc_one("SELECT id FROM ledger_entries WHERE user_id=? AND dedupe_key=?",[$parent,'referral:'.$c['id']]),
        'no automatic balance credit before review');
    lc_referral_review((int)$c['id'],(int)$admin['id'],'approved');
    $credit=lc_one("SELECT * FROM ledger_entries WHERE user_id=? AND dedupe_key=?",[$parent,'referral:'.$c['id']]);
    referral_assert($credit && (int)$credit['amount_cents']===(int)$c['amount_cents'],'approved credit issued once');
    try{lc_referral_review((int)$c['id'],(int)$admin['id'],'approved');throw new RuntimeException('Double approval accepted');}
    catch(InvalidArgumentException $e){referral_assert(true,'duplicate review forbidden');}
    $count=(int)(lc_one("SELECT COUNT(*) AS n FROM ledger_entries WHERE user_id=? AND dedupe_key=?",[$parent,'referral:'.$c['id']])['n']??0);
    referral_assert($count===1,'commission credited exactly once');
    // A paid reward is never generated for fake/unapproved revenue.
    lc_referral_offer((int)$fixture['id'],'creator_cycle',99999,10000);
    referral_assert((int)(lc_one("SELECT COUNT(*) AS n FROM referral_commissions WHERE referred_user_id=?",[$fixture['id']])['n']??0)===1,
       'no wrong-type commission');
    echo "B3_REFERRALS_OK\n";
}finally{if($db->inTransaction())$db->rollBack();}
