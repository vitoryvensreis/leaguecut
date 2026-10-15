<?php
declare(strict_types=1);
/**
 * Production-safe read-only gate. Exits nonzero until explicitly approved.
 * Never connects to DB or contacts Meta.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}
require dirname(__DIR__).'/app/core.php';
$flags=['HYBRID_SOCIAL_OAUTH_ENABLED','HYBRID_INSTAGRAM_SYNC_ENABLED',
'HYBRID_TIKTOK_SYNC_ENABLED','HYBRID_YOUTUBE_SYNC_ENABLED','HYBRID_BACKFILL_ENABLED'];
$active=array_values(array_filter($flags,static fn($key)=>lc_env($key,'0')!=='0'));
$ready=[
 'https_app_url'=>rtrim(lc_env('APP_URL'),'/')==='https://leaguecut.com',
 'php_84'=>PHP_VERSION_ID>=80400,
 'app_key'=>strlen((string)base64_decode(lc_env('APP_KEY'),true))===32,
 'meta_app_secret_present'=>lc_env('META_APP_SECRET')!=='',
 'instagram_app_id_present'=>lc_env('INSTAGRAM_APP_ID')!=='',
 'instagram_app_secret_present'=>lc_env('INSTAGRAM_APP_SECRET')!=='',
 'all_hybrid_flags_disabled'=>count($active)===0,
 'hybrid_migration_available'=>is_file(dirname(__DIR__).'/database/migrations/20261014_hybrid_verification.sql')
];
$failed=array_keys(array_filter($ready,static fn($v)=>!$v));
echo json_encode(['ready_for_manual_review'=>!$failed,'checks'=>$ready,
 'failed'=>$failed,'database_checked'=>false,'api_tested'=>false,
 'deploy_performed'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit($failed?2:0);
