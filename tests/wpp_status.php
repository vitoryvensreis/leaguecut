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
// Validate WPPConnect lifecycle states independently from the legacy
// check-connection-session boolean response.
$sessionStates=[
    ['CONNECTED',true,'CONNECTED'],
    ['isLogged',true,'CONNECTED'],
    ['INCHAT',true,'CONNECTED'],
    ['OPEN',true,'CONNECTED'],
    ['QRCODE',false,'CONNECTING'],
    ['INITIALIZING',false,'CONNECTING'],
    ['STARTING',false,'CONNECTING'],
    ['PHONECODE',false,'CONNECTING'],
    ['CONNECTING',false,'CONNECTING'],
    ['CLOSED',false,'DISCONNECTED'],
    ['CLOSING',false,'DISCONNECTED'],
    ['ERROR',false,'DISCONNECTED'],
    ['DISCONNECTED',false,'DISCONNECTED'],
    ['mystery',false,'UNKNOWN'],
];
foreach($sessionStates as [$value,$connected,$status]){
    $actual=lc_wpp_parse_session_state(['status'=>$value]);
    if($actual['connected']!==$connected || $actual['status']!==$status)
        throw new RuntimeException('Unexpected lifecycle state: '.$value);
}
// Regression guard: WPPConnect Server exposes GET /status-session,
// not /get-session-state. Using the wrong path silently hides login success.
$wppAdminSource=file_get_contents(dirname(__DIR__).'/app/automation-whatsapp.php');
if(!is_string($wppAdminSource) || !str_contains($wppAdminSource,"'status-session'")
    || str_contains($wppAdminSource,"'get-session-state'")){
    throw new RuntimeException('WPP session status route is not supported by the provider.');
}
// A Connect click must never call close-session before start-session. A
// close during INITIALIZING/QRCODE may interrupt Chromium and raise HTTP 500.
$whatsappSource=file_get_contents(dirname(__DIR__).'/app/automation-whatsapp.php');
if(!is_string($whatsappSource))throw new RuntimeException('WhatsApp source unreadable');
$startRoute=explode("if(\$method==='POST'&&\$path==='/admin/whatsapp/api/start')",$whatsappSource,2)[1]??'';
$startRoute=explode("if(\$method==='POST'&&\$path==='/admin/whatsapp/api/pause')",$startRoute,2)[0]??'';
if($startRoute===''||str_contains($startRoute,"lc_wpp_admin_request('close-session'"))
    || !str_contains($startRoute,"'reused'=>true")){
    throw new RuntimeException('Unsafe pairing restart in WhatsApp start route');
}
echo "WPP_CONNECTION_NORMALIZATION_OK\n";
