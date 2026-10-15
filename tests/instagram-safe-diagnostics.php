<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/social-oauth-callback.php';
foreach(['oauth_http_instagram_code_400','oauth_http_instagram_long_401','oauth_http_instagram_long_network'] as $reason){
    if(lc_oauth_safe_error_reason(new RuntimeException($reason))!==$reason)throw new RuntimeException('Safe code hidden');
}
foreach(['token=secret','oauth_http_unknown_400','oauth_http_instagram_code_777',''] as $unsafe){
    if(lc_oauth_safe_error_reason(new RuntimeException($unsafe))!=='unspecified')throw new RuntimeException('Unsafe detail leaked');
}
echo "Instagram OAuth safe diagnostics OK\n";
