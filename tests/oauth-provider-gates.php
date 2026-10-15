<?php
declare(strict_types=1);
function lc_env(string $name,string $default=''):string { $v=getenv($name);return $v===false?$default:$v; }
require dirname(__DIR__).'/app/social-oauth.php';
foreach(['HYBRID_SOCIAL_OAUTH_ENABLED','HYBRID_INSTAGRAM_OAUTH_ENABLED','HYBRID_TIKTOK_OAUTH_ENABLED'] as $key)putenv($key.'=0');
if(lc_social_oauth_provider_enabled('instagram')||lc_social_oauth_provider_enabled('tiktok'))throw new RuntimeException('Default deny failed');
putenv('HYBRID_SOCIAL_OAUTH_ENABLED=1');
if(lc_social_oauth_provider_enabled('instagram')||lc_social_oauth_provider_enabled('tiktok'))throw new RuntimeException('Global flag bypass');
putenv('HYBRID_INSTAGRAM_OAUTH_ENABLED=1');
if(lc_social_oauth_provider_enabled('instagram')||lc_social_oauth_provider_enabled('tiktok'))throw new RuntimeException('Instagram Meta OAuth must remain disabled');
putenv('HYBRID_TIKTOK_OAUTH_ENABLED=1');
if(!lc_social_oauth_provider_enabled('tiktok')||lc_social_oauth_provider_enabled('instagram')||lc_social_oauth_provider_enabled('youtube'))throw new RuntimeException('Provider authorization invalid');
putenv('HYBRID_SOCIAL_OAUTH_ENABLED=0');
if(lc_social_oauth_provider_enabled('instagram'))throw new RuntimeException('Global off bypass');
echo "Per-provider OAuth gates OK\n";
