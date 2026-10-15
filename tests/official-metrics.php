<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/official-metrics.php';
function assertion(bool $ok,string $message):void {if (!$ok)throw new RuntimeException($message);}
$id='AbCdEfGhI12';
$body=json_encode(['items'=>[['id'=>$id,'statistics'=>['viewCount'=>'1024']]]],JSON_THROW_ON_ERROR);
assertion(LcOfficialMetrics::youtubePayload($body,$id)===['source'=>'youtube_data','views'=>1024],'official count');
assertion(LcOfficialMetrics::youtubePayload($body,'QwErTyUiOp1')===null,'must match video id');
assertion(LcOfficialMetrics::youtubePayload('{"items":[{"id":"AbCdEfGhI12","statistics":{"viewCount":"-1"}}]}',$id)===null,'negative');
assertion(LcOfficialMetrics::youtubePayload('{"items":[{"id":"AbCdEfGhI12","statistics":{"viewCount":"2000000001"}}]}',$id)===null,'oversized');
assertion(LcOfficialMetrics::youtubePayload('not json',$id)===null,'invalid JSON');
echo "Official metrics provider tests OK\n";
