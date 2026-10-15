<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/social-oauth-callback.php';
$short=['access_token'=>'short-test-token-000000','user_id'=>'1234'];
$long=['access_token'=>'long-test-token-9999999','expires_in'=>5184000];
$result=lc_instagram_exchange_result($short,$long);
if($result['access_token']!==$long['access_token'] || $result['access_token']===$short['access_token'])throw new RuntimeException('Short-lived token leaked into result');
if($result['remote_id']!=='1234'||$result['expires_in']!==5184000)throw new RuntimeException('Incorrect Instagram response');
try{lc_instagram_exchange_result($short,['access_token'=>'bad','expires_in'=>20]);throw new RuntimeException('Invalid token accepted');}
catch(RuntimeException $e){if($e->getMessage()==='Invalid token accepted')throw $e;}
echo "Instagram callback long-token regression OK\n";
