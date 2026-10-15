<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/social-oauth-lifecycle.php';
$now=strtotime('2026-10-14 12:00:00 UTC');
$base=['provider'=>'instagram','access_token_enc'=>'enc','revoked_at'=>null,
'connected_at'=>'2026-09-01 12:00:00','expires_at'=>'2026-10-20 12:00:00'];
if(!lc_instagram_refresh_due($base,$now))throw new RuntimeException('Due token not selected');
foreach([
 array_merge($base,['provider'=>'tiktok']),
 array_merge($base,['revoked_at'=>'2026-10-01']),
 array_merge($base,['connected_at'=>'2026-10-14 01:00:00']),
 array_merge($base,['expires_at'=>'2026-12-01 12:00:00']),
 array_merge($base,['expires_at'=>'2026-10-13 12:00:00']),
] as $notDue)if(lc_instagram_refresh_due($notDue,$now))throw new RuntimeException('Unsafe refresh eligibility');
echo "Instagram refresh policy OK\n";
