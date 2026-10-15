<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/social-oauth-callback.php';
$examples=[
 ['The redirect_uri is invalid','redirect_mismatch'],
 ['Authorization code has expired','code_expired'],
 ['Authorization code already used','code_reused'],
 ['Invalid authorization code','invalid_code'],
 ['Invalid client_secret','client_credentials'],
 ['Other unexpected reason: access_token=secret','unknown']
];
foreach($examples as [$message,$expected]){
 $payload=json_encode(['error_type'=>'OAuthException','code'=>400,'error_message'=>$message]);
 if(lc_instagram_oauth_error_category($payload)!==$expected)throw new RuntimeException('Incorrect category '.$expected);
}
$reason='oauth_http_instagram_code_400_code400_categorycode_expired';
if(lc_oauth_safe_error_reason(new RuntimeException($reason))!==$reason)throw new RuntimeException('Known category rejected');
if(lc_oauth_safe_error_reason(new RuntimeException($reason.'_token=abc'))!=='unspecified')throw new RuntimeException('Unsafe details exposed');
echo "INSTAGRAM_ERROR_CLASSIFICATION_OK\n";
