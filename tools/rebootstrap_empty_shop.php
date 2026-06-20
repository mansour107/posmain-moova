<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Router/ShopRouter.php';
require_once __DIR__ . '/../classes/Sync/EmptyShopBootstrap.php';

$options = getopt('', ['shop-id:', 'slug:', 'username:', 'password:', 'json', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/rebootstrap_empty_shop.php --shop-id=3 [--username=admin --password=1234]\n");
    exit(0);
}

$shopId = max(0, (int) ($options['shop-id'] ?? 0));
$slug = trim((string) ($options['slug'] ?? ''));
if ($shopId < 1 && $slug === '') {
    fwrite(STDERR, "Provide --shop-id or --slug.\n");
    exit(1);
}

try {
    $router = new PosmainShopRouter();
    $routerConn = posmain_router_db_connect();
    $shop = $shopId > 0 ? $router->findShopById($routerConn, $shopId) : $router->findShopBySlug($routerConn, $slug);
    if (!$shop) {
        throw new InvalidArgumentException('Shop not found.');
    }

    $db = $router->databaseConfigFromShop($shop);
    $shopConn = new mysqli((string) $db['host'], (string) $db['user'], (string) $db['pass'], (string) $db['name'], (int) $db['port']);
    $shopConn->set_charset((string) ($db['charset'] ?: 'utf8mb4'));
    rebootstrapDropAllTables($shopConn);
    $bootstrap = (new EmptyShopBootstrap())->bootstrap($shopConn, [
        'admin_username' => trim((string) ($options['username'] ?? 'admin')),
        'admin_password' => (string) ($options['password'] ?? '1234'),
    ]);
    $shopConn->close();
    $routerConn->close();

    $result = [
        'ok' => true,
        'shop' => $router->publicShop($shop),
        'bootstrap' => $bootstrap,
    ];
    if (isset($options['json'])) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo 'Rebootstrapped empty shop #' . (int) $shop['id'] . ' ' . (string) $shop['slug'] . PHP_EOL;
    }
} catch (Throwable $e) {
    if (isset($options['json'])) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
    }
    exit(1);
}

function rebootstrapDropAllTables(mysqli $conn): void
{
    $conn->query('SET FOREIGN_KEY_CHECKS=0');
    $result = $conn->query('SHOW TABLES');
    while ($row = $result->fetch_array(MYSQLI_NUM)) {
        $table = str_replace('`', '``', (string) $row[0]);
        $conn->query("DROP TABLE IF EXISTS `{$table}`");
    }
    $conn->query('SET FOREIGN_KEY_CHECKS=1');
}
