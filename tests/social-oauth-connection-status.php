<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/social-oauth-lifecycle.php';
$now=strtotime('2026-10-14 12:00:00 UTC');
$active=['oauth_verified'=>1,'access_token_enc'=>'encrypted','expires_at'=>'2026-12-01 00:00:00','revoked_at'=>null];
if(lc_social_oauth_connection_label($active,$now)!=='Conectada')throw new RuntimeException('Valid token not recognized');
$expired=array_merge($active,['expires_at'=>'2026-10-01 00:00:00']);
if(lc_social_oauth_connection_label($expired,$now)!=='Expirada — reconectar')throw new RuntimeException('Expired token mislabeled');
$revoked=array_merge($active,['revoked_at'=>'2026-10-02 00:00:00']);
if(lc_social_oauth_connection_label($revoked,$now)!=='Informada manualmente')throw new RuntimeException('Revoked token shown connected');
if(lc_social_oauth_connection_label(['oauth_verified'=>1],$now)!=='Informada manualmente')throw new RuntimeException('Legacy flag must not prove authorization');
echo "OAuth connectivity statuses OK\n";
