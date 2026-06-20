<?php

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$suffix = (string) getmypid();
$routerDb = 'posmain_pub_router_' . $suffix;
$shopDbA = 'posmain_pub_shop_a_' . $suffix;
$shopDbB = 'posmain_pub_shop_b_' . $suffix;
$keyFile = sys_get_temp_dir() . '/posmain-pub-router-key-' . $suffix . '.key';
$branchA = 'aaaaaaaa-5555-4555-8555-aaaaaaaaaaaa';
$branchB = 'bbbbbbbb-6666-4666-8666-bbbbbbbbbbbb';

putenv('POSMAIN_CONFIG_ENCRYPTION_KEY_FILE=' . $keyFile);
putenv('POSMAIN_CONFIG_ENCRYPTION_KEY=publisher-router-key-' . $suffix);
putenv('POSMAIN_ROUTER_ENABLED=1');
putenv('POSMAIN_ROUTER_DB_HOST=' . $host);
putenv('POSMAIN_ROUTER_DB_PORT=' . (string) $port);
putenv('POSMAIN_ROUTER_DB_NAME=' . $routerDb);
putenv('POSMAIN_ROUTER_DB_USER=' . $user);
putenv('POSMAIN_ROUTER_DB_PASS=' . $pass);
putenv('POSMAIN_ROLE=cloud');

$_ENV['POSMAIN_CONFIG_ENCRYPTION_KEY_FILE'] = $keyFile;
$_ENV['POSMAIN_CONFIG_ENCRYPTION_KEY'] = 'publisher-router-key-' . $suffix;
$_ENV['POSMAIN_ROUTER_ENABLED'] = '1';
$_ENV['POSMAIN_ROUTER_DB_HOST'] = $host;
$_ENV['POSMAIN_ROUTER_DB_PORT'] = (string) $port;
$_ENV['POSMAIN_ROUTER_DB_NAME'] = $routerDb;
$_ENV['POSMAIN_ROUTER_DB_USER'] = $user;
$_ENV['POSMAIN_ROUTER_DB_PASS'] = $pass;
$_ENV['POSMAIN_ROLE'] = 'cloud';

require_once __DIR__ . '/../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../classes/Sync/CloudBranchSyncPublisher.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = new mysqli($host, $user, $pass, '', $port);

try {
    foreach ([$routerDb, $shopDbA, $shopDbB] as $dbName) {
        $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
        $root->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }

    $config = posmain_app_config([
        'role' => 'cloud',
        'branch' => [
            'uuid' => $branchB,
        ],
        'sync' => [
            'cloud_to_branch_publish_enabled' => true,
        ],
    ]);
    $router = new PosmainShopRouter();
    $routerConn = PosmainShopRouter::connectRouter($config);
    $router->install($routerConn);
    $shopA = $router->registerShop($routerConn, [
        'slug' => 'publisher-shop-a-' . $suffix,
        'display_name' => 'Publisher Shop A',
        'require_encryption' => true,
        'database' => [
            'host' => $host,
            'port' => $port,
            'name' => $shopDbA,
            'user' => $user,
            'pass' => $pass,
            'charset' => 'utf8mb4',
        ],
    ]);
    $shopB = $router->registerShop($routerConn, [
        'slug' => 'publisher-shop-b-' . $suffix,
        'display_name' => 'Publisher Shop B',
        'require_encryption' => true,
        'database' => [
            'host' => $host,
            'port' => $port,
            'name' => $shopDbB,
            'user' => $user,
            'pass' => $pass,
            'charset' => 'utf8mb4',
        ],
    ]);
    $router->pairBranchRoute($routerConn, [
        'shop_id' => (int) $shopA['id'],
        'branch_uuid' => $branchA,
        'secret' => 'secret-a',
        'require_encryption' => true,
    ]);
    $router->pairBranchRoute($routerConn, [
        'shop_id' => (int) $shopB['id'],
        'branch_uuid' => $branchB,
        'secret' => 'secret-b',
        'require_encryption' => true,
    ]);
    $routerConn->close();

    $shopConnA = new mysqli($host, $user, $pass, $shopDbA, $port);
    $shopConnB = new mysqli($host, $user, $pass, $shopDbB, $port);
    (new SyncSchemaManager())->apply($shopConnA);
    (new SyncSchemaManager())->apply($shopConnB);

    $published = (new CloudBranchSyncPublisher())->publish($shopConnA, [
        'event_type' => 'menu.item_saved',
        'event_version' => 1,
        'source_system' => 'cloud_pos',
        'aggregate_type' => 'menu_item',
        'aggregate_local_id' => 101,
        'aggregate_id' => 'myitems:101',
        'entity_type' => 'menu_item',
        'entity_local_id' => 101,
        'payload' => [
            'branch_uuid' => $branchB,
            'menu_item' => ['local_item_id' => 101, 'item_name' => 'Router Isolated Item'],
        ],
    ], $config);

    routerPublisherAssert(array_column($published, 'branch_uuid') === [$branchA], 'publisher must target only routes for the current shop DB');
    routerPublisherAssert(routerPublisherCount($shopConnA, $branchA) === 1, 'shop A queue should receive its branch event');
    routerPublisherAssert(routerPublisherCount($shopConnA, $branchB) === 0, 'shop A queue must not receive shop B branch events');
    routerPublisherAssert(routerPublisherCount($shopConnB, $branchB) === 0, 'shop B DB must not be touched by shop A publisher');

    $shopConnA->close();
    $shopConnB->close();
    echo "cloud-branch-sync-publisher-router-multishop-ok router={$routerDb}\n";
} finally {
    foreach ([$routerDb, $shopDbA, $shopDbB] as $dbName) {
        $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
    }
    $root->close();
    @unlink($keyFile);
}

function routerPublisherCount(mysqli $conn, string $branchUuid): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM cloud_sync_branch_events WHERE branch_uuid = ?');
    $stmt->bind_param('s', $branchUuid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['c'] ?? 0);
}

function routerPublisherAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
