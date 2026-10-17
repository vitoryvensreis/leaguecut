<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/asaas.php';
function check_asaas_connection(bool $yes,string $message):void {
    if(!$yes)throw new RuntimeException('ASAAS_READ_ONLY_CHECK_FAIL: '.$message);
}
putenv('ASAAS_ENV=production');
putenv('ASAAS_ENABLED=0');
putenv('ASAAS_PRODUCTION_API_KEY=');
putenv('ASAAS_WEBHOOK_TOKEN=');
$missing=lc_asaas_check_connection();
check_asaas_connection($missing['environment']==='production','correct environment');
check_asaas_connection($missing['api_status']==='KEY_MISSING','without key no request');
check_asaas_connection(!$missing['operations_enabled'],'writes stay disabled');
check_asaas_connection(!$missing['webhook_ready'],'no webhook token');
putenv('ASAAS_PRODUCTION_API_KEY=invalid_sandbox_value');
putenv('ASAAS_WEBHOOK_TOKEN='.str_repeat('t',48));
$invalid=lc_asaas_check_connection();
check_asaas_connection($invalid['api_status']==='KEY_ENVIRONMENT_MISMATCH','invalid key never sent to API');
check_asaas_connection($invalid['webhook_ready'],'token presence without revealing value');
try{
    lc_asaas_live_block();
    throw new RuntimeException('Production write allowed');
}catch(RuntimeException $e){
    check_asaas_connection(str_contains($e->getMessage(),'produção bloqueada'),'production writes always blocked');
}
$cli=(string)file_get_contents(dirname(__DIR__).'/bin/asaas-connection-check.php');
check_asaas_connection(str_contains($cli,'ASAAS_API_READONLY='),'CLI prints only status');
check_asaas_connection(!str_contains($cli,'balance_amount'),'CLI never prints account balance');
echo "ASAAS_READ_ONLY_DIAGNOSTIC_OK\n";
