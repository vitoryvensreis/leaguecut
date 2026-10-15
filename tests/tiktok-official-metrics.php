<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/tiktok-official-metrics.php';
function expect(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$id='7234567890123456789';
$payload=json_encode(['error'=>['code'=>'ok'],'data'=>['videos'=>[
 ['id'=>'1111111111111111111','view_count'=>500],['id'=>$id,'view_count'=>1234]
]]],JSON_THROW_ON_ERROR);
expect(LcTikTokMetrics::parse($payload,$id)===['source'=>'tiktok_display','views'=>1234],'exact ID');
expect(LcTikTokMetrics::parse($payload,'7234567890123456788')===null,'unowned video');
expect(LcTikTokMetrics::parse('{"error":{"code":"access_token_invalid"},"data":{"videos":[]}}',$id)===null,'provider error');
expect(LcTikTokMetrics::parse('{"error":{"code":"ok"},"data":{"videos":[{"id":"'.$id.'","view_count":-1}]}}',$id)===null,'negative');
expect(LcTikTokMetrics::parse('{}',$id)===null,'missing data');
echo "TikTok official metrics parsing OK\n";
