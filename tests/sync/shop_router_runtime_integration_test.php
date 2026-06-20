<?php

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$suffix = (string) getmypid();
$routerDb = 'posmain_router_rt_' . $suffix;
$shopADb = 'posmain_router_shop_a_' . $suffix;
$shopBDb = 'posmain_router_shop_b_' . $suffix;
$keyFile = sys_get_temp_dir() . '/posmain-router-test-key-' . $suffix . '.key';

putenv('POSMAIN_CONFIG_ENCRYPTION_KEY_FILE=' . $keyFile);
putenv('POSMAIN_CONFIG_ENCRYPTION_KEY=router-runtime-test-key-' . $suffix);
putenv('POSMAIN_ROUTER_ENABLED=1');
putenv('POSMAIN_ROUTER_DB_HOST=' . $host);
putenv('POSMAIN_ROUTER_DB_PORT=' . (string) $port);
putenv('POSMAIN_ROUTER_DB_NAME=' . $routerDb);
putenv('POSMAIN_ROUTER_DB_USER=' . $user);
putenv('POSMAIN_ROUTER_DB_PASS=' . $pass);

$_ENV['POSMAIN_CONFIG_ENCRYPTION_KEY_FILE'] = $keyFile;
$_ENV['POSMAIN_CONFIG_ENCRYPTION_KEY'] = 'router-runtime-test-key-' . $suffix;
$_ENV['POSMAIN_ROUTER_ENABLED'] = '1';
$_ENV['POSMAIN_ROUTER_DB_HOST'] = $host;
$_ENV['POSMAIN_ROUTER_DB_PORT'] = (string) $port;
$_ENV['POSMAIN_ROUTER_DB_NAME'] = $routerDb;
$_ENV['POSMAIN_ROUTER_DB_USER'] = $user;
$_ENV['POSMAIN_ROUTER_DB_PASS'] = $pass;

require_once __DIR__ . '/../../includes/db_bootstrap.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = new mysqli($host, $user, $pass, '', $port);

try {
    foreach ([$routerDb, $shopADb, $shopBDb] as $dbName) {
        $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
        $root->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }

    posmainRouterRuntimeCreateShopSchema($root, $shopADb, 'alpha_user');
    posmainRouterRuntimeCreateShopSchema($root, $shopBDb, 'beta_user');

    $router = new PosmainShopRouter();
    $routerConn = PosmainShopRouter::connectRouter(posmain_app_config());
    $router->install($routerConn);

    $shopA = $router->registerShop($routerConn, [
        'slug' => 'alpha-shop',
        'display_name' => 'Alpha Shop',
        'require_encryption' => true,
        'database' => [
            'host' => $host,
            'port' => $port,
            'name' => $shopADb,
            'user' => $user,
            'pass' => $pass,
            'charset' => 'utf8mb4',
        ],
    ]);
    $shopB = $router->registerShop($routerConn, [
        'slug' => 'beta-shop',
        'display_name' => 'Beta Shop',
        'require_encryption' => true,
        'database' => [
            'host' => $host,
            'port' => $port,
            'name' => $shopBDb,
            'user' => $user,
            'pass' => $pass,
            'charset' => 'utf8mb4',
        ],
    ]);

    $router->addLoginAlias($routerConn, [
        'shop_id' => $shopA['id'],
        'alias' => 'Owner@Alpha.Example',
        'target_user_id' => 1,
    ]);
    $router->addLoginAlias($routerConn, [
        'shop_id' => $shopB['id'],
        'alias' => '+20 100 000 0002',
        'target_user_id' => 1,
    ]);

    $duplicateRejected = false;
    try {
        $router->addLoginAlias($routerConn, [
            'shop_id' => $shopB['id'],
            'alias' => 'owner@alpha.example',
            'target_user_id' => 1,
        ]);
    } catch (InvalidArgumentException $expected) {
        $duplicateRejected = true;
    }
    posmainRouterRuntimeAssert($duplicateRejected, 'duplicate normalized alias should be rejected globally');

    $alphaRoute = $router->resolveLoginAlias($routerConn, ' owner@ALPHA.example ');
    posmainRouterRuntimeAssert((int) ($alphaRoute['id'] ?? 0) === (int) $shopA['id'], 'email alias should resolve Alpha shop');
    posmainRouterRuntimeAssert((int) ($alphaRoute['target_user_id'] ?? 0) === 1, 'email alias should preserve target user id');
    $alphaConn = $router->connectShopFromRoute($alphaRoute);
    posmainRouterRuntimeAssert(posmainRouterRuntimeScalar($alphaConn, 'SELECT DATABASE()') === $shopADb, 'Alpha alias should connect Alpha database');
    posmainRouterRuntimeAssert(posmainRouterRuntimeScalar($alphaConn, 'SELECT uname FROM users WHERE id = 1') === 'alpha_user', 'Alpha user should be read from Alpha DB');
    $alphaConn->close();

    $betaRoute = $router->resolveLoginAlias($routerConn, '+201000000002');
    posmainRouterRuntimeAssert((int) ($betaRoute['id'] ?? 0) === (int) $shopB['id'], 'phone alias should resolve Beta shop');
    $betaConn = $router->connectShopFromRoute($betaRoute);
    posmainRouterRuntimeAssert(posmainRouterRuntimeScalar($betaConn, 'SELECT DATABASE()') === $shopBDb, 'Beta alias should connect Beta database');
    posmainRouterRuntimeAssert(posmainRouterRuntimeScalar($betaConn, 'SELECT uname FROM users WHERE id = 1') === 'beta_user', 'Beta user should be read from Beta DB');
    $betaConn->close();

    $router->addBranchRoute($routerConn, [
        'shop_id' => $shopA['id'],
        'branch_uuid' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
    ]);
    $router->addBranchRoute($routerConn, [
        'shop_id' => $shopB['id'],
        'branch_uuid' => 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb',
    ]);

    $branchAConn = posmain_db_connect_for_branch_uuid('aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', posmain_app_config());
    posmainRouterRuntimeAssert(posmainRouterRuntimeScalar($branchAConn, 'SELECT DATABASE()') === $shopADb, 'branch A should route to Alpha DB');
    $branchAConn->close();

    $branchBConn = posmain_db_connect_for_branch_uuid('bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb', posmain_app_config());
    posmainRouterRuntimeAssert(posmainRouterRuntimeScalar($branchBConn, 'SELECT DATABASE()') === $shopBDb, 'branch B should route to Beta DB');
    $branchBConn->close();

    $stored = $routerConn->query('SELECT db_pass_encrypted FROM router_shops ORDER BY id ASC LIMIT 1')->fetch_assoc();
    posmainRouterRuntimeAssert(is_array($stored), 'router shop credential row should exist');
    posmainRouterRuntimeAssert(strpos((string) $stored['db_pass_encrypted'], 'v1:') === 0, 'router DB password should be encrypted');
    posmainRouterRuntimeAssert((string) $stored['db_pass_encrypted'] !== $pass, 'router DB password should not be stored as plaintext');

    $routerConn->close();
    echo "shop-router-runtime-integration-ok router={$routerDb} shops={$shopADb},{$shopBDb}\n";
} finally {
    foreach ([$routerDb, $shopADb, $shopBDb] as $dbName) {
        $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
    }
    $root->close();
    @unlink($keyFile);
}

function posmainRouterRuntimeCreateShopSchema(mysqli $root, string $dbName, string $username): void
{
    $root->select_db($dbName);
    $root->query("
        CREATE TABLE users (
          id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
          uname VARCHAR(191) NOT NULL,
          password VARCHAR(255) NOT NULL,
          userrole INT NOT NULL DEFAULT 1,
          usertype INT NOT NULL DEFAULT 1,
          isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $stmt = $root->prepare('INSERT INTO users (uname, password, userrole, usertype, isdeleted) VALUES (?, ?, 1, 1, 0)');
    $hash = password_hash('secret', PASSWORD_DEFAULT);
    $stmt->bind_param('ss', $username, $hash);
    $stmt->execute();
    $stmt->close();
}

function posmainRouterRuntimeScalar(mysqli $conn, string $sql): string
{
    $row = $conn->query($sql)->fetch_row();
    return (string) ($row[0] ?? '');
}

function posmainRouterRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
