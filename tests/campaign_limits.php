<?php
declare(strict_types=1);
/** Campaign capacity rules: clipper slots and weekly approved cap. Runs inside a rolled-back transaction. */
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/campaign-limits.php';
function limits_assert(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('CAMPAIGN_LIMITS_FAILED: '.$message);
}
// Pure helpers first (no DB).
limits_assert(lc_campaign_limit_value(null)===null && lc_campaign_limit_value('')===null && lc_campaign_limit_value('0')===null,'empty/zero means unlimited');
limits_assert(lc_campaign_limit_value('12')===12,'numeric limit');
[$b,$e]=lc_campaign_week_bounds('2026-11-06 15:00:00');
limits_assert($b==='2026-11-02 00:00:00' && $e==='2026-11-09 00:00:00','monday-to-monday week');
[$b]=lc_campaign_week_bounds('2026-11-08 23:59:59');
limits_assert($b==='2026-11-02 00:00:00','sunday still belongs to the same week');
$_POST=['max_clippers'=>'50','max_approved_per_week'=>'7','max_approved_per_week_unlimited'=>'1'];
limits_assert(lc_campaign_limit_from_post('max_clippers',LC_CAMPAIGN_MAX_CLIPPERS)===50,'form limit parsed');
limits_assert(lc_campaign_limit_from_post('max_approved_per_week',LC_CAMPAIGN_MAX_WEEKLY)===null,'unlimited checkbox wins');
$_POST=['max_approved_per_week'=>'5000'];
try{lc_campaign_limit_from_post('max_approved_per_week',LC_CAMPAIGN_MAX_WEEKLY);limits_assert(false,'out of range accepted');}
catch(InvalidArgumentException){}
$_POST=[];

$db=lc_db();$db->beginTransaction();
try{
    $suffix=bin2hex(random_bytes(4));
    $users=[];
    foreach(['a','b','c'] as $k){
        lc_query("INSERT INTO users(full_name,email,password_hash,whatsapp,instagram,pix_key_enc,role,status)
          VALUES(?,?,?,?,?,?,'clipador','approved')",['Limite '.$k,"limits-$k-$suffix@leaguecut.test",'x','85999999999','lim'.$k,'enc']);
        $uid=(int)$db->lastInsertId();
        lc_query("INSERT INTO social_accounts(user_id,network,username,profile_url) VALUES(?,'instagram',?,?)",[$uid,'lim'.$k.$suffix,'https://www.instagram.com/lim'.$k]);
        $users[$k]=['id'=>$uid,'account'=>(int)$db->lastInsertId()];
    }
    lc_query("INSERT INTO campaigns(title,slug,description,status,max_clippers,max_approved_per_week) VALUES(?,?,?,'active',2,2)",
       ['Limites '.$suffix,'limites-'.$suffix,'Campanha para teste de limites']);
    $cid=(int)$db->lastInsertId();
    $campaign=lc_one('SELECT * FROM campaigns WHERE id=?',[$cid]);
    $now=date('Y-m-d H:i:s');
    $clip=function(string $k,string $status='pending')use($users,$cid,$now){
        lc_query("INSERT INTO clips(user_id,campaign_id,social_account_id,title,published_url,status,submitted_at,drive_file_id)
          VALUES(?,?,?,?,?,?,?,?)",[$users[$k]['id'],$cid,$users[$k]['account'],'t','https://www.instagram.com/reel/'.bin2hex(random_bytes(5)),$status,$now,'test-drive-x']);
        return (int)lc_db()->lastInsertId();
    };
    limits_assert(lc_campaign_submission_block($campaign,$users['a']['id'])===null,'first clipper allowed');
    $clip('a','rejected'); // rejected clips still occupy a slot
    $clip('b');
    limits_assert(lc_campaign_participant_count($cid)===2,'two participants (rejected included)');
    $blocked=lc_campaign_submission_block($campaign,$users['c']['id']);
    limits_assert($blocked!==null && str_contains($blocked,'esgotadas'),'third clipper blocked when full');
    limits_assert(lc_campaign_submission_block($campaign,$users['b']['id'])===null,'existing participant keeps sending');
    // Weekly approved cap for clipper B = 2.
    $first=$clip('b');$second=$clip('b');$third=$clip('b');
    foreach([$first,$second] as $id){
        $row=lc_one('SELECT * FROM clips WHERE id=?',[$id]);
        lc_campaign_assert_can_approve($row);
        lc_query("UPDATE clips SET status='approved' WHERE id=?",[$id]);
    }
    $row=lc_one('SELECT * FROM clips WHERE id=?',[$third]);
    try{lc_campaign_assert_can_approve($row);limits_assert(false,'third approval in the week accepted');}
    catch(RuntimeException $e){limits_assert(str_contains($e->getMessage(),'Limite semanal'),'weekly cap message');}
    $weeklyBlock=lc_campaign_submission_block($campaign,$users['b']['id']);
    limits_assert($weeklyBlock!==null && str_contains($weeklyBlock,'limite de 2'),'new sends blocked after weekly cap');
    // Re-approving an already counted clip does not count itself twice.
    $approvedRow=lc_one('SELECT * FROM clips WHERE id=?',[$first]);
    lc_campaign_assert_can_approve($approvedRow);
    // Unlimited campaign.
    lc_query('UPDATE campaigns SET max_clippers=NULL,max_approved_per_week=NULL WHERE id=?',[$cid]);
    $campaign=lc_one('SELECT * FROM campaigns WHERE id=?',[$cid]);
    limits_assert(lc_campaign_submission_block($campaign,$users['c']['id'])===null,'unlimited campaign accepts new clipper');
    lc_campaign_assert_can_approve($row);
    $summary=lc_campaign_limits_summary($campaign,$users['b']['id']);
    limits_assert($summary['max_clippers']===null && $summary['full']===false && $summary['participants']===2,'summary unlimited');
    echo "CAMPAIGN_LIMITS_OK\n";
}finally{if($db->inTransaction())$db->rollBack();}
