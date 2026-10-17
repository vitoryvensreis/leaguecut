<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/asaas.php';
// Never log key, token, account balance, customer details or response bodies.
try{
    $check=lc_asaas_check_connection();
    $webhook=lc_asaas_check_webhook_reachability();
    echo 'ASAAS_ENVIRONMENT='.strtoupper($check['environment'])."\n";
    echo 'ASAAS_KEY_PRESENT='.($check['key_present']?'YES':'NO')."\n";
    echo 'ASAAS_WEBHOOK_TOKEN_VALID='.($check['webhook_ready']?'YES':'NO')."\n";
    echo 'ASAAS_OPERATIONS_ENABLED='.($check['operations_enabled']?'YES':'NO')."\n";
    echo 'ASAAS_API_READONLY='.$check['api_status']."\n";
    echo 'ASAAS_WEBHOOK_UNAUTHENTICATED_POST='.$webhook."\n";
    echo 'ASAAS_WRITES_PRODUCTION_BLOCKED=YES'."\n";
}catch(Throwable $e){
    // No exception message: cURL errors, environment info and internal paths
    // can inadvertently expose infrastructure context.
    echo "ASAAS_CONNECTION_DIAGNOSTIC_FAILED\n";
}
