<?php
declare(strict_types=1);
// Manually invoked, opt-in CLI. No scheduled job or payout effect.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/hybrid-persistence.php';
require dirname(__DIR__).'/app/instagram-official-metrics.php';
if(lc_env('HYBRID_INSTAGRAM_SYNC_ENABLED','0')!=='1'){
    fwrite(STDERR,"Instagram synchronization disabled.\n");exit(2);
}
$max=max(1,min(10,(int)lc_env('HYBRID_INSTAGRAM_SYNC_BATCH','3')));
$rows=lc_all("SELECT s.clip_id,s.external_id,a.username,o.access_token_enc
 FROM clip_verification_state s
 JOIN clips c ON c.id=s.clip_id AND c.status='approved'
 JOIN social_accounts a ON a.id=c.social_account_id AND a.network='instagram'
 JOIN social_oauth_connections o ON o.social_account_id=a.id AND o.provider='instagram'
 WHERE s.network='instagram' AND s.external_id IS NOT NULL
   AND o.revoked_at IS NULL AND o.expires_at>UTC_TIMESTAMP()
   AND (s.last_observed_at IS NULL OR s.last_observed_at<UTC_TIMESTAMP()-INTERVAL 24 HOUR)
 ORDER BY s.last_observed_at ASC,s.clip_id ASC LIMIT ".$max);
$ok=0;$fail=0;
foreach($rows as $row){
 try{
  $token=lc_decrypt((string)$row['access_token_enc']);
  $observation=LcInstagramMetrics::fetch((string)$row['external_id'],(string)$row['username'],$token);
  lc_hybrid_observation((int)$row['clip_id'],$observation,true);
  $ok++;
 }catch(Throwable $e){
  error_log('LeagueCut Instagram observation failed for clip #'.(int)$row['clip_id']);
  $fail++;
 }
}
echo json_encode(['total'=>count($rows),'observed'=>$ok,'failed'=>$fail],JSON_THROW_ON_ERROR)."\n";
exit($fail?1:0);
