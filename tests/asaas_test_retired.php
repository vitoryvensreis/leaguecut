<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function asaas_retired_assert(bool $pass,string $msg): void {
    if(!$pass)throw new RuntimeException('ASAAS_TEST_RETIREMENT_FAILED: '.$msg);
}
foreach([
    'app/asaas-pix-tests.php',
    'public/assets/asaas-pix-tests.css',
    'database/migrations/20261017_asaas_pix_tests.sql',
    'tests/asaas_pix_tests.php'
] as $path){
    asaas_retired_assert(!file_exists($root.'/'.$path),'old Pix test component remains: '.$path);
}
$routes=(string)file_get_contents($root.'/app/routes.php');
$ui=(string)file_get_contents($root.'/app/ui.php');
$gateway=(string)file_get_contents($root.'/app/asaas.php');
$migrate=(string)file_get_contents($root.'/bin/migrate.php');
$nav=(string)file_get_contents($root.'/app/admin-permissions.php');
foreach([$routes,$ui,$gateway,$migrate] as $content){
    asaas_retired_assert(!str_contains($content,'lc_asaas_test_'),'real Pix test operation is still reachable');
    asaas_retired_assert(!str_contains($content,'/admin/asaas/testes'),'old Pix test route is still linked');
    asaas_retired_assert(!str_contains($content,'ASAAS_LIVE_TEST_ENABLED'),'old live-test switch still used');
}
asaas_retired_assert(str_contains($routes,"lc_asaas_routes("),'main gateway route preserved');
asaas_retired_assert(str_contains($gateway,"/webhooks/asaas"),'webhook remains supported');
asaas_retired_assert(str_contains($gateway,'function lc_asaas_check_connection'),'read-only connectivity preserved');
asaas_retired_assert(str_contains($migrate,'MIGRACAO_ASAAS_FOUNDATION_OK'),'main gateway tables still installed');
asaas_retired_assert(str_contains($nav,"'/admin/asaas'=>'master'"),'master gateway access preserved');
echo "ASAAS_TEST_PANEL_REMOVAL_OK\n";
