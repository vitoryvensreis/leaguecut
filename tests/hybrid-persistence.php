<?php
declare(strict_types=1);
$writes = [];
function lc_query(string $sql,array $args=[]): object {
    global $writes;
    $writes[]=['sql'=>$sql,'args'=>$args];
    return new stdClass();
}
require dirname(__DIR__) . '/app/hybrid-persistence.php';
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);}

lc_hybrid_submission(51,'instagram','https://www.instagram.com/reel/AbC1234/?utm_source=test');
check(count($writes)===1,'one state write');
check($writes[0]['args'][0]===51,'correct clip');
check($writes[0]['args'][2]==='AbC1234','external id extracted');
check($writes[0]['args'][4]==='pending','pending until observation');
$writes=[];
lc_hybrid_submission(52,'tiktok','https://vm.tiktok.com/ZMfoobar/');
check($writes[0]['args'][4]==='manual_review','redirect link remains accepted but untrusted');
check($writes[0]['args'][2]===null,'unverified redirect has no external ID');
try{lc_hybrid_submission(53,'tiktok','https://www.instagram.com/reel/AbC1234/');throw new RuntimeException('Expected rejection');}
catch(InvalidArgumentException $e){}
try{lc_hybrid_submission(54,'unknown','https://www.instagram.com/reel/AbC1234/');throw new RuntimeException('Expected rejection');}
catch(InvalidArgumentException $e){}
check(count($writes)===1,'rejected submissions do not write');
$policy=LcHybridVerification::assess(['source'=>'youtube_data','views'=>100],false);
check($policy['observed_views']===100&&$policy['verified_views']===null,'observation not a payout');
echo "Hybrid persistence submission tests OK\n";
