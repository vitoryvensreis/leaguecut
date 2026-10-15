<?php
declare(strict_types=1);
// Manually invoked CLI; disabled by default; NO cron, no payment modifications.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/hybrid-persistence.php';
require dirname(__DIR__).'/app/tiktok-official-metrics.php';
if(lc_env('HYBRID_TIKTOK_SYNC_ENABLED','0')!=='1'){
    fwrite(STDERR,"TikTok metrics synchronization disabled.\n");exit(2);
}
$max=max(1,min(20,(int)lc_env('HYBRID_TIKTOK_SYNC_BATCH','5')));
$rows=lc_all("SELECT s.clip_id,s.external_id,o.access_token_enc
  FROM clip_verification_state s
  JOIN clips c ON c.id=s.clip_id AND c.status='approved'
  JOIN social_accounts a ON a.id=c.social_account_id AND a.network='tiktok'
  JOIN social_oauth_connections o ON o.social_account_id=a.id AND o.provider='tiktok'
  WHERE s.network='tiktok' AND s.external_id REGEXP '^[0-9]{8,30}$'
    AND o.revoked_at IS NULL AND o.expires_at>UTC_TIMESTAMP()
    AND (s.last_observed_at IS NULL OR s.last_observed_at<UTC_TIMESTAMP()-INTERVAL 24 HOUR)
  ORDER BY s.last_observed_at ASC,s.clip_id ASC LIMIT ".$max);
$success=0;$errors=0;
foreach($rows as $row){
    try{
        $token=lc_decrypt((string)$row['access_token_enc']);
        $obs=LcTikTokMetrics::fetch((string)$row['external_id'],$token);
        lc_hybrid_observation((int)$row['clip_id'],$obs,true);
        $success++;
    }catch(Throwable $e){
        error_log('LeagueCut TikTok observation failed for clip #'.(int)$row['clip_id']);
        $errors++;
    }
}
echo json_encode(['total'=>count($rows),'observed'=>$success,'failed'=>$errors],JSON_THROW_ON_ERROR)."\n";
exit($errors>0?1:0);
