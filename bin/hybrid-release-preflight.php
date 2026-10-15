<?php
declare(strict_types=1);
/** Read-only release preflight. No network requests, database writes or secrets printed. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/app/core.php';
$flags=['HYBRID_SOCIAL_OAUTH_ENABLED','HYBRID_TIKTOK_SYNC_ENABLED','HYBRID_INSTAGRAM_SYNC_ENABLED','HYBRID_YOUTUBE_SYNC_ENABLED','HYBRID_BACKFILL_ENABLED'];
$enabled=array_values(array_filter($flags,static fn(string $f):bool=>lc_env($f,'0')==='1'));
$requirements=[
 'php_84'=>PHP_VERSION_ID>=80400,
 'pdo_mysql'=>extension_loaded('pdo_mysql'),
 'openssl'=>extension_loaded('openssl'),
 'curl'=>extension_loaded('curl'),
 'app_key_present'=>lc_env('APP_KEY')!=='' && strlen((string)base64_decode(lc_env('APP_KEY'),true))===32,
 'https_app_url'=>preg_match('~^https://[a-z0-9.-]+(?::443)?$~iD',rtrim(lc_env('APP_URL'),'/'))===1,
 'migration_file'=>is_file(LC_ROOT.'/database/migrations/20261014_hybrid_verification.sql')
];
$issues=[];
foreach($requirements as $name=>$ok)if(!$ok)$issues[]=$name;
if($enabled)$issues[]='feature_flags_enabled_without_approval';
$ok=$issues===[];
echo json_encode(['ready_for_staging_checks'=>$ok,'requirements'=>$requirements,
 'flags_enabled'=>$enabled,'issues'=>$issues,'database_checked'=>false,
 'remote_api_checked'=>false,'production_modified'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit($ok?0:2);
