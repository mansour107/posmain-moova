<?php

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$suffix = (string) getmypid();
$routerDb = 'posmain_prov_router_' . $suffix;
$newShopDb = 'posmain_prov_shop_' . $suffix;
$keyFile = sys_get_temp_dir() . '/posmain-prov-test-key-' . $suffix . '.key';
$branchUuid = 'dddddddd-4444-4444-8444-dddddddddddd';
$secret = 'provisioning-test-secret-' . $suffix;

putenv('POSMAIN_CONFIG_ENCRYPTION_KEY_FILE=' . $keyFile);
putenv('POSMAIN_CONFIG_ENCRYPTION_KEY=provisioning-test-key-' . $suffix);
putenv('POSMAIN_ROUTER_ENABLED=1');
putenv('POSMAIN_ROUTER_DB_HOST=' . $host);
putenv('POSMAIN_ROUTER_DB_PORT=' . (string) $port);
putenv('POSMAIN_ROUTER_DB_NAME=' . $routerDb);
putenv('POSMAIN_ROUTER_DB_USER=' . $user);
putenv('POSMAIN_ROUTER_DB_PASS=' . $pass);
putenv('POSMAIN_ROLE=cloud');

$_ENV['POSMAIN_CONFIG_ENCRYPTION_KEY_FILE'] = $keyFile;
$_ENV['POSMAIN_CONFIG_ENCRYPTION_KEY'] = 'provisioning-test-key-' . $suffix;
$_ENV['POSMAIN_ROUTER_ENABLED'] = '1';
$_ENV['POSMAIN_ROUTER_DB_HOST'] = $host;
$_ENV['POSMAIN_ROUTER_DB_PORT'] = (string) $port;
$_ENV['POSMAIN_ROUTER_DB_NAME'] = $routerDb;
$_ENV['POSMAIN_ROUTER_DB_USER'] = $user;
$_ENV['POSMAIN_ROUTER_DB_PASS'] = $pass;
$_ENV['POSMAIN_ROLE'] = 'cloud';

require_once __DIR__ . '/../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../classes/Sync/BranchPairingService.php';
require_once __DIR__ . '/../../classes/Sync/PairingStatusService.php';
require_once __DIR__ . '/../../classes/Sync/ShopProvisioningService.php';
require_once __DIR__ . '/../../classes/Sync/SyncRuntimeSettings.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = new mysqli($host, $user, $pass, '', $port);

try {
    $root->query("DROP DATABASE IF EXISTS `{$routerDb}`");
    $root->query("DROP DATABASE IF EXISTS `{$newShopDb}`");
    $root->query("CREATE DATABASE `{$routerDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

    $router = new PosmainShopRouter();
    $routerConn = PosmainShopRouter::connectRouter(posmain_app_config());
    $router->install($routerConn);
    $routerConn->close();

    $config = posmain_app_config();
    $contextConn = new mysqli($host, $user, $pass, $routerDb, $port);
    $pairing = new BranchPairingService();
    $result = $pairing->pairHosted($contextConn, $config, [
        'branch_uuid' => $branchUuid,
        'secret' => $secret,
        'provision_new_shop' => '1',
        'provision_shop_slug' => 'prov-shop-' . $suffix,
        'provision_shop_name' => 'Provisioned Shop',
        'provision_db_name' => $newShopDb,
        'cloud_base_url' => 'https://hosted.example',
    ]);

    shopProvisioningAssert(!empty($result['provisioned_shop']['provisioned']), 'pairing should report provisioned shop');
    shopProvisioningAssert(($result['provisioned_shop']['db_name'] ?? '') === $newShopDb, 'provisioned db name should match request');
    shopProvisioningAssert(!empty($result['hosted_status']['pairing_ok']), 'hosted status should report paired');

    $shopConn = posmain_db_connect_for_branch_uuid($branchUuid, $config);
    $probe = (new PairingStatusService())->hostedProbe($shopConn, $config, $branchUuid);
    shopProvisioningAssert($probe['shop_db_name'] === $newShopDb, 'hosted probe should return provisioned db name');
    shopProvisioningAssert(!empty($probe['sync_schema_ready']), 'provisioned shop should have sync schema applied');
    $runtime = (new SyncRuntimeSettings())->loadForUi($shopConn);
    shopProvisioningAssert((string) ($runtime['POSMAIN_CLOUD_APPLY_ENABLED']['value'] ?? '') === '1', 'hosted apply should remain enabled');
    shopProvisioningAssert((string) ($runtime['POSMAIN_CLOUD_PULL_ENABLED']['value'] ?? '') === '0', 'hosted pull must default off');
    shopProvisioningAssert((string) ($runtime['POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED']['value'] ?? '') === '0', 'hosted publish must default off');
    $shopConn->close();

    $contextConn->close();
    echo "shop-provisioning-runtime-ok router={$routerDb} shop={$newShopDb}\n";
} finally {
    foreach ([$routerDb, $newShopDb] as $dbName) {
        $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
    }
    $root->close();
    @unlink($keyFile);
}

function shopProvisioningAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
