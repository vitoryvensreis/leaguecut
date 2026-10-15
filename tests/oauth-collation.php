<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
$db=lc_db();
$checks=[
"SELECT a.id FROM social_accounts a LEFT JOIN social_oauth_connections o ON o.social_account_id=a.id AND o.provider COLLATE utf8mb4_general_ci = CAST(a.network AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci WHERE a.user_id=? LIMIT 1",
"SELECT o.id FROM social_oauth_connections o JOIN social_accounts s ON s.id=o.social_account_id WHERE o.social_account_id=? AND s.user_id=? AND s.network=? AND o.provider COLLATE utf8mb4_general_ci = CAST(s.network AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci LIMIT 1"
];
foreach($checks as $i=>$query){
  $stmt=$db->prepare($query);
  $stmt->execute($i===0?[0]:[0,0,'instagram']);
}
echo "OAUTH_COLLATION_SQL_OK\n";
