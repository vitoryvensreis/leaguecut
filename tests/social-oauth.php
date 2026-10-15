<?php
declare(strict_types=1);
function lc_env(string $k,string $default=''):string {return match($k) {
 'APP_URL'=>'https://leaguecut.example',
 'TIKTOK_CLIENT_KEY'=>'testkey','TIKTOK_CLIENT_SECRET'=>'testsecret',
 'INSTAGRAM_APP_ID'=>'testid','INSTAGRAM_APP_SECRET'=>'testsecret',
 default=>$default
};}
require dirname(__DIR__).'/app/social-oauth.php';
$_SESSION=[];
$url=LcSocialOAuth::begin('tiktok',12,45);
if(!str_starts_with($url,'https://www.tiktok.com/v2/auth/authorize/?')||
 !str_contains($url,'video.list'))throw new RuntimeException('TikTok start');
$state=$_SESSION['lc_social_oauth']['state'];
$redirect=null;
if(LcSocialOAuth::consume('tiktok',12,$state,$redirect)!==45)throw new RuntimeException('state mismatch');
if($redirect!=='https://leaguecut.example/redes/oauth/tiktok/callback')throw new RuntimeException('Redirect binding mismatch');
try{LcSocialOAuth::consume('tiktok',12,$state);throw new LogicException('Replay allowed');}
catch(RuntimeException $e){}
LcSocialOAuth::begin('instagram',12,46);
try{LcSocialOAuth::consume('instagram',13,$_SESSION['lc_social_oauth']['state']);throw new LogicException('Cross user accepted');}
catch(RuntimeException $e){}
if(isset($_SESSION['lc_social_oauth']))throw new RuntimeException('Not consumed');
LcSocialOAuth::begin('instagram',12,46);
$_SESSION['lc_social_oauth']['redirect_uri']='https://other.example/redirect';
try{LcSocialOAuth::consume('instagram',12,$_SESSION['lc_social_oauth']['state']);throw new LogicException('Tampered redirect allowed');}
catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
echo "Social OAuth state tests OK\n";
