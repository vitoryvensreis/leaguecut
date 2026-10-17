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

$asaasEventId='evt_05b708f961d739ea7eba7e4db318f621&368604920';
$asaasPayload=json_encode([
    'id'=>$asaasEventId,'event'=>'PAYMENT_CREATED','dateCreated'=>'2026-10-17 10:16:39',
    'account'=>['id'=>'ci_asaas_account','ownerId'=>null],
    'payment'=>['object'=>'payment','id'=>'pay_7zutocfjc62p8jl','customer'=>'cus_000000000','value'=>5.0]
],JSON_THROW_ON_ERROR);
test_asaas(lc_asaas_webhook_receive($asaasPayload,str_repeat('t',48))===200,
    'documented real Asaas event ID with & must be accepted');
test_asaas(lc_asaas_webhook_receive($asaasPayload,str_repeat('t',48))===200,
    'Asaas retries with & event ID must be idempotent');
$asaasSaved=lc_one('SELECT event_type,object_id FROM asaas_webhook_events WHERE event_id=?',[$asaasEventId]);
test_asaas($asaasSaved && $asaasSaved['event_type']==='PAYMENT_CREATED'
    && $asaasSaved['object_id']==='pay_7zutocfjc62p8jl',
    'Asaas event with & saved with correct event type and payment ID');
$asaasCount=(int)(lc_one('SELECT COUNT(*) AS n FROM asaas_webhook_events WHERE event_id=?',[$asaasEventId])['n']??0);
test_asaas($asaasCount===1,'Asaas & event not duplicated');
$rejectedPayload=json_encode(['id'=>'evt_bad<script>&123','event'=>'PAYMENT_CREATED',
    'payment'=>['id'=>'pay_example']],JSON_THROW_ON_ERROR);
test_asaas(lc_asaas_webhook_receive($rejectedPayload,str_repeat('t',48))===400,
    'unsafe event IDs remain rejected');
test_asaas(lc_asaas_webhook_receive($asaasPayload,'bad-token')===401,
    'authentication is always required even for real IDs');
test_asaas(!lc_one("SELECT id FROM ledger_entries WHERE dedupe_key='asaas:".$asaasEventId."'"),
    'Asaas event ID with & cannot cause a ledger credit');
lc_query('DELETE FROM asaas_webhook_events WHERE event_id=?',[$asaasEventId]);

test_asaas(lc_asaas_webhook_receive('{broken',str_repeat('t',48))===400,'bad JSON rejected');
test_asaas(lc_asaas_webhook_receive(str_repeat('x',65537),str_repeat('t',48))===413,'oversized event rejected');
test_asaas(!lc_one("SELECT id FROM ledger_entries WHERE dedupe_key='asaas:".$eventId."'"),'webhook does not fabricate money');
lc_query('DELETE FROM asaas_webhook_events WHERE event_id=?',[$eventId]);
putenv('ASAAS_ENV=production');
test_asaas(lc_asaas_base_url()==='https://api.asaas.com/v3','correct production URL');
try{lc_asaas_live_block();throw new RuntimeException('Production billing permitted');}
catch(RuntimeException $e){test_asaas(str_contains($e->getMessage(),'produção bloqueada'),'production writes blocked');}
echo "ASAAS_SANDBOX_FOUNDATION_OK\n";
