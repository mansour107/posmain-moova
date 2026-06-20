<?php

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$suffix = (string) getmypid();
$routerDb = 'posmain_pair_router_' . $suffix;
$shopDb = 'posmain_pair_shop_' . $suffix;
$keyFile = sys_get_temp_dir() . '/posmain-pair-test-key-' . $suffix . '.key';
$branchUuid = 'cccccccc-3333-4333-8333-cccccccccccc';
$secret = 'pairing-test-secret-' . $suffix;

putenv('POSMAIN_CONFIG_ENCRYPTION_KEY_FILE=' . $keyFile);
putenv('POSMAIN_CONFIG_ENCRYPTION_KEY=pairing-test-key-' . $suffix);
putenv('POSMAIN_ROUTER_ENABLED=1');
putenv('POSMAIN_ROUTER_DB_HOST=' . $host);
putenv('POSMAIN_ROUTER_DB_PORT=' . (string) $port);
putenv('POSMAIN_ROUTER_DB_NAME=' . $routerDb);
putenv('POSMAIN_ROUTER_DB_USER=' . $user);
putenv('POSMAIN_ROUTER_DB_PASS=' . $pass);
putenv('POSMAIN_ROLE=cloud');

$_ENV['POSMAIN_CONFIG_ENCRYPTION_KEY_FILE'] = $keyFile;
$_ENV['POSMAIN_CONFIG_ENCRYPTION_KEY'] = 'pairing-test-key-' . $suffix;
$_ENV['POSMAIN_ROUTER_ENABLED'] = '1';
$_ENV['POSMAIN_ROUTER_DB_HOST'] = $host;
$_ENV['POSMAIN_ROUTER_DB_PORT'] = (string) $port;
$_ENV['POSMAIN_ROUTER_DB_NAME'] = $routerDb;
$_ENV['POSMAIN_ROUTER_DB_USER'] = $user;
$_ENV['POSMAIN_ROUTER_DB_PASS'] = $pass;
$_ENV['POSMAIN_ROLE'] = 'cloud';

require_once __DIR__ . '/../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../classes/Sync/BranchPairingService.php';
require_once __DIR__ . '/../../classes/Sync/BranchSecretProviderFactory.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = new mysqli($host, $user, $pass, '', $port);

try {
    foreach ([$routerDb, $shopDb] as $dbName) {
        $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
        $root->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }

    $router = new PosmainShopRouter();
    $routerConn = PosmainShopRouter::connectRouter(posmain_app_config());
    $router->install($routerConn);
    $shop = $router->registerShop($routerConn, [
        'slug' => 'pair-shop',
        'display_name' => 'Pair Shop',
        'require_encryption' => true,
        'database' => [
            'host' => $host,
            'port' => $port,
            'name' => $shopDb,
            'user' => $user,
            'pass' => $pass,
            'charset' => 'utf8mb4',
        ],
    ]);

    $config = posmain_app_config();
    $pairing = new BranchPairingService();
    $contextConn = new mysqli($host, $user, $pass, $shopDb, $port);
    $result = $pairing->pairHosted($contextConn, $config, [
        'branch_uuid' => $branchUuid,
        'secret' => $secret,
        'router_shop_id' => (int) $shop['id'],
        'cloud_base_url' => 'https://hosted.example',
    ]);

    branchPairingAssert($result['identity_source'] === 'router_branch_routes', 'hosted pairing should use router identity');
    branchPairingAssert($result['branch_uuid'] === $branchUuid, 'branch uuid should be stored');

    $shopConn = posmain_db_connect_for_branch_uuid($branchUuid, $config);
    $provider = BranchSecretProviderFactory::fromConfig($shopConn, $config);
    branchPairingAssert($provider->isBranchActive($branchUuid), 'router-backed branch should be active');
    branchPairingAssert($provider->getSecretForBranch($branchUuid) === $secret, 'router-backed secret should match');

    $timestamp = (string) time();
    $nonce = 'pairing-test-nonce';
    $body = '{"events":[]}';
    $auth = (new CloudAuthService())->verifyRequest(
        $provider,
        $branchUuid,
        $timestamp,
        $nonce,
        $body,
        CloudAuthService::sign($secret, $timestamp, $nonce, $body),
        (int) $timestamp
    );
    branchPairingAssert(!empty($auth['ok']), 'cloud auth should accept router-backed secret');
    $shopConn->close();

    $routes = $pairing->listHostedBranches($contextConn, $config);
    branchPairingAssert(count($routes) === 1, 'hosted branch list should contain one router route');
    branchPairingAssert($routes[0]['branch_uuid'] === $branchUuid, 'listed route should match paired branch');

    $routerConn->close();
    $contextConn->close();
    echo "branch-pairing-router-runtime-ok router={$routerDb} shop={$shopDb}\n";
} finally {
    foreach ([$routerDb, $shopDb] as $dbName) {
        $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
    }
    $root->close();
    @unlink($keyFile);
}

function branchPairingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
