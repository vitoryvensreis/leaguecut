<?php
declare(strict_types=1);
function lc_env(string $key,string $default=''):string{return $default;}
require dirname(__DIR__).'/app/social-oauth-callback.php';
function ok(bool $x,string $m):void{if(!$x)throw new RuntimeException($m);}
$account=['id'=>3,'network'=>'tiktok','username'=>'clipador'];
$token=['remote_id'=>'user_123','scopes'=>'user.info.basic,user.info.profile,video.list'];
ok(lc_social_oauth_verified_profile($account,'tiktok',$token,['id'=>'user_123','username'=>'clipador']),'matching owner');
ok(!lc_social_oauth_verified_profile($account,'tiktok',$token,['id'=>'user_666','username'=>'clipador']),'other remote id');
ok(!lc_social_oauth_verified_profile($account,'tiktok',$token,['id'=>'user_123','username'=>'other']),'other username');
ok(!lc_social_oauth_verified_profile($account,'tiktok',['remote_id'=>'user_123','scopes'=>'user.info.basic'],['id'=>'user_123','username'=>'clipador']),'missing profile scope');
ok(!lc_social_oauth_verified_profile($account,'instagram',$token,['id'=>'user_123','username'=>'clipador']),'network mismatch');
ok(lc_social_oauth_verified_profile(['network'=>'instagram','username'=>'creator'],'instagram',
 ['remote_id'=>'192837','scopes'=>''],['id'=>'192837','username'=>'Creator']),'instagram remote account');
echo "Social OAuth identity policy OK\n";
