<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/automation-engine.php';
require dirname(__DIR__).'/app/automation-whatsapp.php';

// Synthetic PNG-signature bytes: sufficient for testing our response decoder
// without needing an active WhatsApp account, QR, token or network.
$png="\x89PNG\r\n\x1a\n".str_repeat('X',180);
$base64=base64_encode($png);
$expected='data:image/png;base64,'.$base64;
$fixtures=[
    [['type'=>'image/png','body'=>$png,'json'=>null],$expected],
    [['type'=>'application/json','body'=>'','json'=>['qrcode'=>$base64]],$expected],
    [['type'=>'application/json','body'=>'','json'=>['qrcode'=>$expected]],$expected],
    [['type'=>'application/json','body'=>'','json'=>['data'=>['qrCode'=>$expected]]],$expected],
    [['type'=>'application/json','body'=>'','json'=>['qrcode'=>'not-a-qr']],null],
    [['type'=>'image/png','body'=>'not-a-png','json'=>null],null],
    [['type'=>'application/json','body'=>'','json'=>['status'=>'INITIALIZING','qrcode'=>null]],null],
];
foreach($fixtures as $index=>$case){
    [$response,$expectedQr]=$case;
    if(lc_wpp_admin_extract_qr($response)!==$expectedQr)
        throw new RuntimeException('QR decode fixture failed: '.$index);
}
echo "WPP_QR_EXTRACTION_OK\n";
