<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/automation-engine.php';
require dirname(__DIR__).'/app/automation-whatsapp.php';

$examples=[
    [['status'=>true,'message'=>'Connected'],true,'CONNECTED'],
    [['status'=>false,'message'=>'Disconnected'],false,'DISCONNECTED'],
    [['status'=>false,'message'=>'Connected'],false,'DISCONNECTED'],
    [['status'=>1,'message'=>'Connected'],true,'CONNECTED'],
    [['status'=>0,'message'=>'Disconnected'],false,'DISCONNECTED'],
    [['status'=>'1','message'=>'Connected'],true,'CONNECTED'],
    [['status'=>'CONNECTED'],true,'CONNECTED'],
    [['status'=>'CLOSED'],false,'DISCONNECTED'],
    [['status'=>'INITIALIZING'],false,'DISCONNECTED'],
    [['status'=>'DISCONNECTED'],false,'DISCONNECTED'],
    [['status'=>'CONNECTING'],false,'CONNECTING'],
    [['connected'=>true],true,'CONNECTED'],
    [['isConnected'=>true],true,'CONNECTED'],
    [['message'=>'Connected'],true,'CONNECTED'],
    [['status'=>null,'message'=>'Disconnected'],false,'DISCONNECTED'],
    [[],false,'DISCONNECTED'],
];
foreach($examples as $case){
    [$payload,$expectedConnected,$expectedStatus]=$case;
    $actual=lc_wpp_parse_connection($payload);
    if($actual!==['connected'=>$expectedConnected,'status'=>$expectedStatus]){
        throw new RuntimeException('Unexpected connection normalization: '.json_encode($payload));
    }
}
echo "WPP_CONNECTION_NORMALIZATION_OK\n";
