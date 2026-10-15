<?php
declare(strict_types=1);
/**
 * Backfill only missing verification states, for historical clips.
 * CLI; dry-run default; preserves original clip and all financial data.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/hybrid-verification.php';
$apply=in_array('--apply',$argv,true);
if($apply && lc_env('HYBRID_BACKFILL_ENABLED','0')!=='1'){
    fwrite(STDERR,"Backfill disabled; set HYBRID_BACKFILL_ENABLED=1 only in staging.\n");exit(2);
}
$max=max(1,min(500,(int)lc_env('HYBRID_BACKFILL_BATCH','100')));
$rows=lc_all("SELECT c.id,a.network,c.published_url
 FROM clips c
 JOIN social_accounts a ON a.id=c.social_account_id
 LEFT JOIN clip_verification_state s ON s.clip_id=c.id
 WHERE s.clip_id IS NULL
 ORDER BY c.id ASC LIMIT ".$max);
$recognized=0;$manual=0;$inserted=0;
foreach($rows as $row){
    $network=(string)$row['network'];
    $link=LcHybridVerification::identify((string)$row['published_url']);
    $valid=$link!==null && $link['network']===$network;
    if($valid)$recognized++;else $manual++;
    if(!$apply)continue;
    $statement=lc_query("INSERT IGNORE INTO clip_verification_state
       (clip_id,network,external_id,canonical_url,status)
       VALUES(?,?,?,?,?)",[(int)$row['id'],$network,
       $valid?$link['external_id']:null,$valid?$link['canonical_url']:null,
       $valid?'pending':'manual_review']);
    $inserted+=$statement->rowCount();
}
echo json_encode(['mode'=>$apply?'apply':'dry-run','examined'=>count($rows),
  'recognized'=>$recognized,'manual_review'=>$manual,'inserted'=>$inserted],
  JSON_THROW_ON_ERROR)."\n";
