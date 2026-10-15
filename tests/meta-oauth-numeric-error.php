<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/social-oauth-callback.php';
$raw=json_encode(['error'=>['message'=>'secret access_token=do-not-log','code'=>100,'error_subcode'=>36008]]);
if(lc_meta_oauth_error_suffix($raw)!=='_code100_subcode36008')throw new RuntimeException('Meta numeric extraction failed');
if(lc_oauth_safe_error_reason(new RuntimeException('oauth_http_instagram_code_400_code100_subcode36008'))!=='oauth_http_instagram_code_400_code100_subcode36008')throw new RuntimeException('Allowlist rejected');
foreach(['oauth_http_instagram_code_400_secret','oauth_http_instagram_code_400_codeabc','oauth_http_instagram_code_400_code100_tokenSECRET'] as $bad){
 if(lc_oauth_safe_error_reason(new RuntimeException($bad))!=='unspecified')throw new RuntimeException('Sensitive error accepted');
}
echo "META_NUMERIC_DIAGNOSTICS_OK\n";
