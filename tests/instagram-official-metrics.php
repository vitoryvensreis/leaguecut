<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/hybrid-verification.php';
require dirname(__DIR__).'/app/instagram-official-metrics.php';
function yes(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$list=json_encode(['data'=>[['id'=>'179123456789','permalink'=>'https://www.instagram.com/reel/AbCd123/','username'=>'mycreator']]],JSON_THROW_ON_ERROR);
yes(LcInstagramMetrics::matchMedia($list,'AbCd123','mycreator')==='179123456789','exact media');
yes(LcInstagramMetrics::matchMedia($list,'AbCd124','mycreator')===null,'wrong shortcode');
yes(LcInstagramMetrics::matchMedia($list,'AbCd123','other')===null,'wrong account');
yes(LcInstagramMetrics::parseViews('{"data":[{"name":"views","values":[{"value":1567}]}]}')===['source'=>'instagram_graph','views'=>1567],'views');
yes(LcInstagramMetrics::parseViews('{"data":[{"name":"reach","values":[{"value":1567}]}]}')===null,'no views');
yes(LcInstagramMetrics::parseViews('{"data":[{"name":"views","values":[{"value":-1}]}]}')===null,'negative');
echo "Instagram official metrics parser OK\n";
