<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/asaas.php';
function test_asaas(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('ASAAS_FOUNDATION_FAIL: '.$message);
}
putenv('ASAAS_WEBHOOK_TOKEN='.str_repeat('t',48));
putenv('ASAAS_ENABLED=0');
putenv('ASAAS_ENV=sandbox');
test_asaas(lc_asaas_env()==='sandbox','sandbox environment default');
test_asaas(lc_asaas_base_url()==='https://api-sandbox.asaas.com/v3','correct test API host');
test_asaas(!lc_asaas_enabled(),'disabled until key + operator activation');
try{lc_asaas_request('POST','/transfers',[]);throw new RuntimeException('Transfer API permitted');}
catch(RuntimeException $e){test_asaas(true,'transfers cannot be sent');}
$eventId='evt_ci_'.bin2hex(random_bytes(6));
$payload=json_encode(['id'=>$eventId,'event'=>'PAYMENT_RECEIVED',
    'payment'=>['id'=>'pay_demo_abcd','value'=>999999999,'status'=>'RECEIVED']],JSON_THROW_ON_ERROR);
test_asaas(lc_asaas_webhook_receive($payload,'bad')===401,'unauthorized token denied');
test_asaas(!lc_one('SELECT event_id FROM asaas_webhook_events WHERE event_id=?',[$eventId]),'not persisted without token');
test_asaas(lc_asaas_webhook_receive($payload,str_repeat('t',48))===200,'valid event accepted');
test_asaas(lc_asaas_webhook_receive($payload,str_repeat('t',48))===200,'duplicate event safely accepted');
$count=(int)(lc_one('SELECT COUNT(*) AS n FROM asaas_webhook_events WHERE event_id=?',[$eventId])['n']??0);
test_asaas($count===1,'event deduplicated by provider ID');
test_asaas(lc_asaas_webhook_receive('{broken',str_repeat('t',48))===400,'bad JSON rejected');
test_asaas(lc_asaas_webhook_receive(str_repeat('x',65537),str_repeat('t',48))===413,'oversized event rejected');
test_asaas(!lc_one("SELECT id FROM ledger_entries WHERE dedupe_key='asaas:".$eventId."'"),'webhook does not fabricate money');
lc_query('DELETE FROM asaas_webhook_events WHERE event_id=?',[$eventId]);
putenv('ASAAS_ENV=production');
test_asaas(lc_asaas_base_url()==='https://api.asaas.com/v3','correct production URL');
try{lc_asaas_live_block();throw new RuntimeException('Production billing permitted');}
catch(RuntimeException $e){test_asaas(str_contains($e->getMessage(),'produção bloqueada'),'production writes blocked');}
echo "ASAAS_SANDBOX_FOUNDATION_OK\n";
