<?php
declare(strict_types=1);
// Safe CLI cleanup, never exposed via HTTP.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/app/core.php';
$count=0;
for($i=0;$i<10;$i++){
    // Remove even unsuccessful payloads after one day; used rows remain
    // without payload for audit until they age out.
    $stmt=lc_query("DELETE FROM account_otp_challenges
        WHERE created_at<DATE_SUB(NOW(),INTERVAL 1 DAY) LIMIT 2000");
    $count+=$stmt->rowCount();
    if($stmt->rowCount()<2000)break;
}
lc_query("UPDATE account_otp_challenges SET status='expired',payload_enc=''
 WHERE status IN ('sending','ready') AND expires_at < NOW()");
echo 'OTP_PRUNE_OK removed='.$count."\n";
