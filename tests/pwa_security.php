<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/pwa.php';
function pa(bool $pass,string $error):void {if(!$pass)throw new RuntimeException('B6D1_PWA_FAIL: '.$error);}
foreach([192,512] as $size){
    $img=lc_pwa_icon_png($size);
    pa(substr($img,0,8)==="\x89PNG\r\n\x1a\n",'PNG signature');
    $dimensions=unpack('Nwidth/Nheight',substr($img,16,8));
    pa($dimensions['width']===$size&&$dimensions['height']===$size,'real image dimensions');
    pa(strlen($img)>500,'image pixels encoded');
}
$sw=lc_pwa_sw_source();
pa(str_contains($sw,"req.mode==='navigate'"),'SW must bypass navigation');
pa(str_contains($sw,"req.headers.has('Authorization')"),'SW must bypass auth');
pa(str_contains($sw,'/^\/assets\//'),'SW static whitelist');
pa(!str_contains($sw,"caches.match('/admin')"),'admin never cached');
pa(!str_contains($sw,'clients.openWindow("/financeiro")'),'private navigation not present');
echo "B6D1_PWA_SECURITY_OK\n";
