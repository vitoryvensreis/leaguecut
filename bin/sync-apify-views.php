<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/apify.php';
try{
    if(lc_env('DB_NAME')!=='leaguecut')throw new RuntimeException('Banco incorreto.');
    if(lc_env('APIFY_SYNC_ENABLED','1')!=='1'){
        echo "APIFY_SCHEDULE_PAUSED: aguardando confirmacao de visualizacoes\n";
        exit(0);
    }
    lc_apify_sync();
}catch(Throwable $e){
    error_log('LeagueCut metrics: '.$e->getMessage());
    fwrite(STDERR,"APIFY_SYNC_ERROR\n");exit(1);
}
