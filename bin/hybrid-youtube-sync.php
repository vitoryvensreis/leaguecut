<?php
declare(strict_types=1);
// CLI only. Intentionally opt-in; no cron/job or HTTP route is installed.
if (PHP_SAPI !== 'cli') {http_response_code(403);exit;}
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/hybrid-persistence.php';
require dirname(__DIR__) . '/app/official-metrics.php';
if (lc_env('HYBRID_YOUTUBE_SYNC_ENABLED','0')!=='1') {
    fwrite(STDERR,"YouTube sync disabled. Set HYBRID_YOUTUBE_SYNC_ENABLED=1 only in staging after migrations.\n");
    exit(2);
}
$key=lc_env('YOUTUBE_API_KEY');
if ($key==='') {fwrite(STDERR,"Missing YOUTUBE_API_KEY\n");exit(2);}
$limit=max(1,min(20,(int)lc_env('HYBRID_SYNC_BATCH_SIZE','5')));
$rows=lc_all("SELECT s.clip_id,s.external_id FROM clip_verification_state s
    JOIN clips c ON c.id=s.clip_id
    WHERE s.network='youtube' AND s.external_id IS NOT NULL
      AND c.status='approved'
      AND (s.last_observed_at IS NULL OR s.last_observed_at<UTC_TIMESTAMP()-INTERVAL 24 HOUR)
    ORDER BY s.last_observed_at ASC,s.clip_id ASC LIMIT ".$limit);
$ok=0;$failed=0;
foreach($rows as $row) {
    try{
        $observation=LcOfficialMetrics::fetchYouTube((string)$row['external_id'],$key);
        lc_hybrid_observation((int)$row['clip_id'],$observation,false);
        $ok++;
    }catch(Throwable $e) {
        // Never log keys, URLs, raw provider responses or user information.
        error_log('LeagueCut official sync failed for clip #'.(int)$row['clip_id']);
        $failed++;
    }
}
echo json_encode(['processed'=>$ok,'failed'=>$failed,'total'=>count($rows)],JSON_THROW_ON_ERROR)."\n";
exit($failed?1:0);
