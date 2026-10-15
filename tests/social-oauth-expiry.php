<?php
declare(strict_types=1);
function lc_env(string $key,string $default=''):string {return $default;}
require dirname(__DIR__).'/app/social-oauth-callback.php';
function lc_query(string $sql,array $params=[]):never {throw new RuntimeException('Database must not be reached');}
function lc_encrypt(string $token):never {throw new RuntimeException('Encryption must not be reached');}
foreach([0,59,null] as $value){
 try{
  lc_social_oauth_save(['id'=>100],'instagram',[
   'expires_in'=>$value,'remote_id'=>'test','access_token'=>'some-token',
   'refresh_token'=>null,'scopes'=>'instagram_business_basic']);
  throw new LogicException('Accepted unbounded token expiry');
 }catch(RuntimeException $e){
  if($e->getMessage()!=='Token sem prazo de validade confirmado.')throw $e;
 }
}
echo "OAuth expiry guard OK\n";
