<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/apify.php';
// The VPS invokes this command hourly but processes views only at 02:00 Fortaleza.
// GitHub Actions may also invoke it as a delayed fallback; DB caches prevent duplicate billing.
if(in_array('--fortaleza-02',$_SERVER['argv']??[],true) &&
   (new DateTimeImmutable('now',new DateTimeZone('America/Fortaleza')))->format('H')!=='02'){
    echo "APIFY_VPS_CRON_SKIP_OUTSIDE_02H\n";
    exit(0);
}
try{
    if(lc_env('DB_NAME')!=='leaguecut')throw new RuntimeException('Banco incorreto.');
    // Daily 02:00 Fortaleza GitHub workflow is the scheduler; no legacy pause flag.
    // A missing token fails loudly instead of falsely recording a successful run.
    if(lc_env('APIFY_API_TOKEN')==='')throw new RuntimeException('Token Apify ausente.');
    lc_apify_sync();
}catch(Throwable $e){
    error_log('LeagueCut metrics: '.$e->getMessage());
    fwrite(STDERR,"APIFY_SYNC_ERROR\n");exit(1);
}
