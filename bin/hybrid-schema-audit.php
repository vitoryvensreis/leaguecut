<?php
declare(strict_types=1);
/** Read-only schema audit. Never outputs connection secrets or personal records. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}
require dirname(__DIR__).'/app/core.php';
$required=[
 'clips'=>['id','social_account_id','published_url','verified_views'],
 'social_accounts'=>['id','user_id','network','oauth_verified'],
 'clip_verification_state'=>['clip_id','network','status'],
 'clip_verification_observations'=>['id','clip_id','source'],
 'social_oauth_connections'=>['id','social_account_id','provider','provider_user_id'],
 'meta_deauthorization_inbox'=>['id','event_hash','app_scoped_user_hash']
];
try{
 $stmt=lc_db()->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()");
 $present=[];
 foreach($stmt as $row)$present[(string)$row['TABLE_NAME']][(string)$row['COLUMN_NAME']]=true;
 $missing=[];
 foreach($required as $table=>$columns)foreach($columns as $column)
   if(!isset($present[$table][$column]))$missing[]=$table.'.'.$column;
 echo json_encode(['inspection'=>'read_only','schema_ready'=>!$missing,
   'missing_columns'=>$missing,'changes_performed'=>false,
   'oauth_enabled'=>lc_env('HYBRID_SOCIAL_OAUTH_ENABLED','0')==='1'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
 exit($missing?2:0);
}catch(Throwable $e){
 fwrite(STDERR,"Schema audit unavailable; no changes performed.\n");
 exit(3);
}
