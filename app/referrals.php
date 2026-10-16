<?php
declare(strict_types=1);

/** Public code is a random identifier, never user ID, email, or session token. */
function lc_referral_code(int $uid): string {
    $existing=lc_one('SELECT code FROM referral_codes WHERE user_id=?',[$uid]);
    if($existing)return (string)$existing['code'];
    $code=strtoupper(bin2hex(random_bytes(8)));
    try{
        lc_query('INSERT INTO referral_codes(user_id,code) VALUES(?,?)',[$uid,$code]);
        return $code;
    }catch(PDOException $e){
        $existing=lc_one('SELECT code FROM referral_codes WHERE user_id=?',[$uid]);
        if($existing)return (string)$existing['code'];
        throw $e;
    }
}
function lc_referral_capture(string $code): void {
    $code=strtoupper(trim($code));
    if(preg_match('/^[A-F0-9]{16}$/D',$code))
        $_SESSION['referral_code']=$code;
}
function lc_referral_attach(int $uid,string $role,string $code): bool {
    if(!in_array($role,['clipador','criador'],true)||!preg_match('/^[A-F0-9]{16}$/D',$code))return false;
    $parent=lc_one("SELECT u.id,u.whatsapp,u.status,u.role
      FROM referral_codes rc JOIN users u ON u.id=rc.user_id
      WHERE rc.code=?",[$code]);
    $child=lc_one('SELECT id,whatsapp FROM users WHERE id=?',[$uid]);
    if(!$parent||!$child||$parent['status']!=='approved'||
       !in_array($parent['role'],['clipador','criador'],true)||
       (int)$parent['id']===$uid)return false;
    // Basic duplicate-identity safeguard. Manual review still required to pay.
    $phone=static fn(string $v)=>preg_replace('/\D/','',$v)??'';
    if($phone($parent['whatsapp'])!==''&&$phone($parent['whatsapp'])===$phone($child['whatsapp']))return false;
    lc_query('INSERT IGNORE INTO referrals(referrer_user_id,referred_user_id,referred_role) VALUES(?,?,?)',
        [$parent['id'],$uid,$role]);
    return lc_query('SELECT 1 FROM referrals WHERE referred_user_id=? AND referrer_user_id=?',
         [$uid,$parent['id']])->fetchColumn()!==false;
}
function lc_referral_amount(string $mode,int $value,int $base): int {
    if($base<=0||$base>100000000000)return 0;
    if($mode==='fixed')return ($value>=0&&$value<=50000)?$value:0;
    if($mode==='percent')return ($value>=0&&$value<=5000)?intdiv($base*$value+5000,10000):0;
    return 0;
}
/**
 * Called in the SAME transaction that creates the first settled clip credit or
 * confirms an actually received creator monthly cycle. Creates pending review,
 * NEVER a withdrawal-ready credit before admin approval.
 */
function lc_referral_offer(int $referredId,string $kind,int $sourceId,int $baseCents): void {
    if(!in_array($kind,['clip_credit','creator_cycle'],true)||$sourceId<1||$baseCents<=0)return;
    $link=lc_one("SELECT r.*,u.status AS parent_status
        FROM referrals r JOIN users u ON u.id=r.referrer_user_id
        WHERE r.referred_user_id=? FOR UPDATE",[$referredId]);
    if(!$link||$link['parent_status']!=='approved')return;
    if(($kind==='clip_credit'&&$link['referred_role']!=='clipador')||
       ($kind==='creator_cycle'&&$link['referred_role']!=='criador'))return;
    $rule=lc_one('SELECT enabled,mode,value_int FROM referral_rules WHERE referred_role=?',[$link['referred_role']]);
    if(!$rule||(int)$rule['enabled']!==1)return;
    $amount=lc_referral_amount((string)$rule['mode'],(int)$rule['value_int'],$baseCents);
    if($amount<1)return;
    if($rule['mode']==='fixed'){
        $one=lc_one('SELECT id FROM referral_commissions WHERE referral_id=? LIMIT 1',[$link['id']]);
        if($one)return; // Fixed = one-time upon first eligible financial event.
    }
    $source=($kind==='clip_credit'?'clip:':'cycle:').$sourceId;
    lc_query("INSERT IGNORE INTO referral_commissions
      (referral_id,referrer_user_id,referred_user_id,source_type,source_key,base_amount_cents,
       amount_cents,mode,rate_value)
      VALUES(?,?,?,?,?,?,?,?,?)",[
       $link['id'],$link['referrer_user_id'],$referredId,$kind,$source,$baseCents,
       $amount,$rule['mode'],$rule['value_int']
    ]);
}
function lc_referral_source_valid(array $c): bool {
    $source=(string)$c['source_key'];
    if($c['source_type']==='clip_credit'&&preg_match('/^clip:(\d+)$/D',$source,$m)){
        return (bool)lc_one("SELECT l.id FROM ledger_entries l JOIN clips c ON c.id=l.clip_id
          WHERE l.user_id=? AND l.dedupe_key=? AND l.kind='clip' AND l.amount_cents=?
            AND c.status='approved' LIMIT 1",
            [$c['referred_user_id'],$source,$c['base_amount_cents']]);
    }
    if($c['source_type']==='creator_cycle'&&preg_match('/^cycle:(\d+)$/D',$source,$m)){
        return (bool)lc_one("SELECT cy.id FROM creator_subscription_cycles cy
           JOIN creator_subscriptions s ON s.id=cy.subscription_id
           WHERE cy.id=? AND cy.payment_status='confirmed'
             AND s.creator_id=? AND s.price_cents_snapshot=? LIMIT 1",
             [(int)$m[1],$c['referred_user_id'],$c['base_amount_cents']]);
    }
    return false;
}
/** Caller owns a transaction; no mutation if source reversed or commission already reviewed. */
function lc_referral_review(int $commissionId,int $adminId,string $decision): void {
    if(!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException('Decisão inválida.');
    $c=lc_one('SELECT * FROM referral_commissions WHERE id=? FOR UPDATE',[$commissionId]);
    if(!$c||$c['status']!=='pending')throw new InvalidArgumentException('Comissão já processada ou indisponível.');
    $parent=lc_one("SELECT id FROM users WHERE id=? AND status='approved' FOR UPDATE",[$c['referrer_user_id']]);
    if(!$parent)throw new InvalidArgumentException('Indicador não está ativo.');
    if($decision==='approved'){
        if(!lc_referral_source_valid($c))throw new InvalidArgumentException('Evento financeiro não está mais elegível para comissão.');
        lc_query("INSERT INTO ledger_entries
          (user_id,kind,amount_cents,dedupe_key,description)
          VALUES(?,'adjustment',?,?,'Comissão de indicação aprovada')",
          [$c['referrer_user_id'],$c['amount_cents'],'referral:'.$commissionId]);
    }
    lc_query('UPDATE referral_commissions SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?',
       [$decision,$adminId,$commissionId]);
}
