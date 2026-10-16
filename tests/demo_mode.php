<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/demo-mode.php';
require dirname(__DIR__).'/app/account-delete.php';

function assert_demo(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('DEMO_MODE_TEST_FAILED: '.$message);
}
$campaigns=lc_demo_campaigns();
$rank=lc_demo_ranking(50);
assert_demo(count($campaigns)===16,'16 demo campaigns are available');
assert_demo(count($rank)===30,'30 demo clippers are available');
assert_demo(count(array_unique(array_column($campaigns,'slug')))==count($campaigns),'unique fixture campaign slugs');
assert_demo(count(array_unique(array_column($rank,'nickname')))==count($rank),'unique demo nicknames');
foreach($campaigns as $campaign){
    assert_demo(($campaign['is_demo']??false)===true,'each campaign is labeled demo');
    assert_demo($campaign['id']<0,'demo has no real positive database IDs');
    assert_demo(lc_demo_campaign($campaign['slug'])!==null,'demo slug lookup works');
}
foreach($rank as $user){
    assert_demo($user['views']>0 && $user['clips']>0 && !empty($user['is_demo']),'demo rank values are illustrative');
}
assert_demo(lc_demo_slug('Árvore de Histórias')==='arvore-de-historias','accent slug normalization');
try{
    lc_account_delete_execute(1,1,'any@example.invalid');
    throw new RuntimeException('self deletion should have failed');
}catch(InvalidArgumentException $e){
    assert_demo(str_contains($e->getMessage(),'própria'),'own admin account cannot be deleted');
}
echo "DEMO_MODE_AND_ACCOUNT_DELETE_GUARDS_OK\n";
