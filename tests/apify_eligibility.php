<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/apify.php';

$eligible=lc_apify_eligible_clips();
if(!$eligible)throw new RuntimeException('CI fixture missing approved clips');
$target=(int)$eligible[0]['id'];
$ids=static fn(array $items): array => array_map(static fn(array $row): int=>(int)$row['id'],$items);
if(!in_array($target,$ids($eligible),true))throw new RuntimeException('Approved clip omitted');

$db=lc_db();
$db->beginTransaction();
try {
    lc_query("UPDATE clips SET status='pending' WHERE id=?",[$target]);
    if(in_array($target,$ids(lc_apify_eligible_clips()),true))throw new RuntimeException('Pending clip wrongly eligible');
    lc_query("UPDATE clips SET status='rejected' WHERE id=?",[$target]);
    if(in_array($target,$ids(lc_apify_eligible_clips()),true))throw new RuntimeException('Rejected clip wrongly eligible');
    lc_query("UPDATE clips SET status='approved',drive_file_id=NULL WHERE id=?",[$target]);
    if(in_array($target,$ids(lc_apify_eligible_clips()),true))throw new RuntimeException('Legacy no-file clip wrongly eligible');
}finally{$db->rollBack();}
if(!in_array($target,$ids(lc_apify_eligible_clips()),true))throw new RuntimeException('Rollback failed');
echo "APIFY_ELIGIBILITY_OK approved_with_original_only\n";
