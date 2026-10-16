<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/pwa.php';
function pa(bool $pass,string $error):void {if(!$pass)throw new RuntimeException('B6D1_PWA_FAIL: '.$error);}
foreach([32,48,180,192,512] as $size){
    $img=lc_pwa_icon_png($size);
    pa(substr($img,0,8)==="\x89PNG\r\n\x1a\n",'PNG signature');
    $dimensions=unpack('Nwidth/Nheight',substr($img,16,8));
    pa($dimensions['width']===$size&&$dimensions['height']===$size,'real image dimensions');
    pa(strlen($img)>100,'image pixels encoded');
}
$maskable=lc_pwa_icon_png(192,true);
pa(substr($maskable,0,8)==="\x89PNG\r\n\x1a\n",'maskable PNG signature');
pa(hash('sha256',$maskable)!==hash('sha256',lc_pwa_icon_png(192,false)),'maskable art has distinct safe zone');
$ico=lc_brand_favicon_ico();
pa(substr($ico,0,4)==="\x00\x00\x01\x00",'real ICO format');
$card=lc_brand_social_png();
$cardDims=unpack('Nwidth/Nheight',substr($card,16,8));
pa(substr($card,0,8)==="\x89PNG\r\n\x1a\n",'social card PNG');
pa($cardDims['width']===1200&&$cardDims['height']===630,'social card 1200 by 630');
ob_start();
pa(lc_pwa_routes('GET','/manifest.webmanifest'),'manifest route');
$manifest=json_decode((string)ob_get_clean(),true,12,JSON_THROW_ON_ERROR);
pa(count($manifest['icons'])===4,'two full icons and two maskable versions');
pa(str_contains(json_encode($manifest['icons']),'maskable'),'maskable PWA declaration');
$sw=lc_pwa_sw_source();
pa(str_contains($sw,"req.mode==='navigate'"),'SW must bypass navigation');
pa(str_contains($sw,"req.headers.has('Authorization')"),'SW must bypass auth');
pa(str_contains($sw,'const staticAsset=') && str_contains($sw,'assets'),'SW static whitelist');
pa(!str_contains($sw,"caches.match('/admin')"),'admin never cached');
pa(!str_contains($sw,'clients.openWindow("/financeiro")'),'private navigation not present');
echo "B6D1_PWA_SECURITY_OK\n";
