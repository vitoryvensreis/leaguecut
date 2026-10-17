<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/asaas.php';
require dirname(__DIR__).'/app/asaas-pix-tests.php';
function pix_test(bool $pass,string $description):void {
    if(!$pass)throw new RuntimeException('ASAAS_PIX_TEST_FAILED: '.$description);
}
putenv('ASAAS_ENV=production');
putenv('ASAAS_PRODUCTION_API_KEY=$aact_prod_fakekey');
putenv('ASAAS_LIVE_TEST_ENABLED=0');
putenv('ASAAS_ENABLED=0');
pix_test(!lc_asaas_test_live_enabled(),'real Pix test must remain disabled by default');
pix_test(!lc_asaas_enabled(),'normal production billing stays off');
foreach(['1'=>'100','5,00'=>'500','9.5'=>'950','50,00'=>'5000'] as $entry=>$expected)
    pix_test(lc_asaas_test_amount((string)$entry)===(int)$expected,'parse cents '.$entry);
foreach(['0','0.50','50,01','999.00','abc','5,999','-2,00'] as $entry){
    try{lc_asaas_test_amount($entry);throw new RuntimeException('Accepted invalid '.$entry);}
    catch(InvalidArgumentException){}
}
foreach(['CPF'=>['123.456.789-09','12345678909'],
          'CNPJ'=>['12.345.678/0001-99','12345678000199'],
          'PHONE'=>['(85) 99999-9999','85999999999'],
          'EMAIL'=>['test@example.invalid','test@example.invalid'],
          'EVP'=>['12345678-1234-1234-1234-123456789012','12345678-1234-1234-1234-123456789012']] as $type=>$keys){
    pix_test(lc_asaas_test_pix_key($keys[0],$type)===$keys[1],$type.' key normalized');
    pix_test(lc_asaas_test_mask_key($keys[1],$type)!==$keys[1],$type.' key masked');
}
foreach([['1234','CPF'],['abc','PHONE'],['test@','EMAIL'],['whatever','FAKE']] as [$key,$type]){
    try{lc_asaas_test_pix_key($key,$type);throw new RuntimeException('Accepted invalid '.$type);}
    catch(InvalidArgumentException){}
}
try{
    lc_asaas_test_api('POST','/transfers',['value'=>1.00,'pixAddressKey'=>'123','pixAddressKeyType'=>'CPF']);
    throw new RuntimeException('Production transfer POST unexpectedly allowed');
}catch(RuntimeException $e){
    pix_test(str_contains($e->getMessage(),'desativados'),'real payment gate blocks POST before network');
}
try{
    lc_asaas_test_api('POST','/payments',['customer'=>'cus_fake']);
    throw new RuntimeException('Production charge POST unexpectedly allowed');
}catch(RuntimeException $e){
    pix_test(str_contains($e->getMessage(),'desativados'),'real invoice gate blocks POST before network');
}
try{
    lc_asaas_test_api('POST','/finance/balance',[]);
    throw new RuntimeException('Out-of-scope POST unexpectedly allowed');
}catch(RuntimeException $e){}
$source=(string)file_get_contents(dirname(__DIR__).'/app/asaas-pix-tests.php');
pix_test(str_contains($source,"lc_account_admin_password("),'every operation confirms master credentials');
pix_test(str_contains($source,"'ENVIAR PIX'"),'explicit outbound confirmation');
pix_test(str_contains($source,"FOR UPDATE"),'row-level one-shot transfer locking');
pix_test(str_contains($source,'recipient_key_encrypted'),'Pix key encrypted');
pix_test(str_contains($source,"'sending'"),'persist attempted operation before external request');
pix_test(!str_contains($source,'lc_creator_confirm_payment('),'test charge never unlocks subscriptions');
pix_test(!str_contains($source,'UPDATE withdrawals'),'test outgoing not confused with clipper withdrawals');
echo "ASAAS_PIX_TEST_SAFETY_OK\n";
