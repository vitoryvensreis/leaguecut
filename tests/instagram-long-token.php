<?php
declare(strict_types=1);
function lc_env(string $key,string $default=''):string{return $default;}
require dirname(__DIR__).'/app/social-oauth-callback.php';
$ok=lc_instagram_long_lived_token(['access_token'=>str_repeat('x',20),'expires_in'=>5184000]);
if($ok['expires_in']!==5184000)throw new RuntimeException('Valid token rejected');
foreach([[],['access_token'=>'short','expires_in'=>5184000],['access_token'=>str_repeat('x',20),'expires_in'=>0],['access_token'=>str_repeat('x',20),'expires_in'=>'fake']] as $invalid){
 try{lc_instagram_long_lived_token($invalid);throw new LogicException('Invalid token accepted');}
 catch(RuntimeException $e){}
}
echo "Instagram long-lived token validation OK\n";
