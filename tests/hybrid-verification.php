<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/hybrid-verification.php';

function eq(mixed $actual,mixed $expected,string $message):void {
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . var_export($actual,true));
}
eq(LcHybridVerification::identify('https://www.instagram.com/reel/AbC1234/?utm_source=x')['external_id'],'AbC1234','Instagram');
eq(LcHybridVerification::identify('https://www.tiktok.com/@creator/video/7234567890123456789')['network'],'tiktok','TikTok');
eq(LcHybridVerification::identify('https://youtu.be/AbCdEfGhI12')['network'],'youtube','YouTube');
foreach (['http://instagram.com/reel/AbC1234/','https://evil.instagram.com/reel/AbC1234/','https://user:pass@www.instagram.com/reel/AbC1234/','https://127.0.0.1/x'] as $unsafe)
    eq(LcHybridVerification::identify($unsafe),null,'Unsafe link rejected');
eq(LcHybridVerification::assess(['source'=>'tiktok_display','views'=>100],false)['status'],'manual_review','No OAuth');
eq(LcHybridVerification::assess(['source'=>'tiktok_display','views'=>100],true)['status'],'observed','Authorized count observed');
eq(LcHybridVerification::assess(['source'=>'apify','views'=>100],true)['status'],'manual_review','Apify never official');
eq(LcHybridVerification::assess(['source'=>'youtube_data','views'=>100],false)['verified_views'],null,'No auto payout');
eq(LcHybridVerification::assess(['source'=>'youtube_data','views'=>-1],false)['status'],'manual_review','Negative rejected');
echo "Hybrid verification policy OK\n";
