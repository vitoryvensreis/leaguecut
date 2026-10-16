<?php
declare(strict_types=1);
// Run daily by cron (leaguecut OS user), never by a web route.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/app/core.php';
$days=(int)lc_env('AUDIT_RETENTION_DAYS','90');
if($days<30||$days>365)throw new RuntimeException('AUDIT_RETENTION_DAYS deve ficar entre 30 e 365.');
$deleted=0;
for($i=0;$i<20;$i++){
    $stmt=lc_query('DELETE FROM request_audit WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 5000',[$days]);
    $rows=$stmt->rowCount();$deleted+=$rows;
    if($rows<5000)break;
}
echo 'AUDIT_RETENTION_OK pruned='.$deleted."\n";
