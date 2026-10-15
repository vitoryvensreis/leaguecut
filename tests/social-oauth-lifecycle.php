<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/social-oauth-lifecycle.php';
function assertStatus(array $row,string $expected,?int $now=null):void {
 $actual=lc_social_oauth_status($row,$now);
 if($actual!==$expected)throw new RuntimeException("Expected $expected, got $actual");
}
assertStatus(['revoked_at'=>'2026-01-01','access_token_enc'=>'encrypted'],'disconnected');
assertStatus(['access_token_enc'=>''],'disconnected');
assertStatus(['access_token_enc'=>'encrypted'],'unknown');
assertStatus(['access_token_enc'=>'encrypted','expires_at'=>'2026-01-01 00:00:00'],'expired',1800000000);
assertStatus(['access_token_enc'=>'encrypted','expires_at'=>'2030-01-01 00:00:00'],'connected',1800000000);
if(lc_social_oauth_refresh_tiktok(['provider'=>'instagram'])!==false)throw new RuntimeException('Wrong provider');
if(lc_social_oauth_refresh_tiktok(['provider'=>'tiktok','refresh_token_enc'=>'','revoked_at'=>null])!==false)throw new RuntimeException('Missing refresh');
echo "OAuth lifecycle policy OK\n";
