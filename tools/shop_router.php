<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Router/ShopRouter.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', [
    'install',
    'register-shop',
    'add-alias',
    'add-branch-route',
    'validate-shop',
    'list-shops',
    'shop-id:',
    'slug:',
    'name::',
    'status::',
    'alias:',
    'target-user-id::',
    'target-uname::',
    'branch-uuid:',
    'db-host:',
    'db-port::',
    'db-name:',
    'db-user:',
    'db-pass::',
    'db-charset::',
    'json',
    'help',
]);

if (isset($options['help']) || !$options) {
    shopRouterUsage();
    exit(0);
}

$actions = array_values(array_filter([
    isset($options['install']) ? 'install' : null,
    isset($options['register-shop']) ? 'register-shop' : null,
    isset($options['add-alias']) ? 'add-alias' : null,
    isset($options['add-branch-route']) ? 'add-branch-route' : null,
    isset($options['validate-shop']) ? 'validate-shop' : null,
    isset($options['list-shops']) ? 'list-shops' : null,
]));

if (count($actions) !== 1) {
    fwrite(STDERR, "Choose exactly one action.\n");
    shopRouterUsage(STDERR);
    exit(1);
}

try {
    $router = new PosmainShopRouter();
    $conn = posmain_router_db_connect(posmain_app_config());
    $action = $actions[0];

    if ($action === 'install') {
        $result = ['ok' => true, 'installed' => $router->install($conn)];
    } elseif ($action === 'register-shop') {
        $result = ['ok' => true, 'shop' => $router->registerShop($conn, [
            'slug' => $options['slug'] ?? '',
            'display_name' => $options['name'] ?? ($options['slug'] ?? ''),
            'status' => $options['status'] ?? 'active',
            'db_host' => $options['db-host'] ?? '',
            'db_port' => $options['db-port'] ?? 3306,
            'db_name' => $options['db-name'] ?? '',
            'db_user' => $options['db-user'] ?? '',
            'db_pass' => $options['db-pass'] ?? '',
            'db_charset' => $options['db-charset'] ?? 'utf8mb4',
            'require_encryption' => (bool) (posmain_app_config()['router']['require_encryption'] ?? true),
        ])];
    } elseif ($action === 'add-alias') {
        $result = ['ok' => true, 'alias' => $router->addLoginAlias($conn, [
            'shop_id' => $options['shop-id'] ?? 0,
            'alias' => $options['alias'] ?? '',
            'target_user_id' => $options['target-user-id'] ?? null,
            'target_uname' => $options['target-uname'] ?? null,
            'status' => $options['status'] ?? 'active',
        ])];
    } elseif ($action === 'add-branch-route') {
        $result = ['ok' => true, 'route' => $router->addBranchRoute($conn, [
            'shop_id' => $options['shop-id'] ?? 0,
            'branch_uuid' => $options['branch-uuid'] ?? '',
            'status' => $options['status'] ?? 'active',
        ])];
    } elseif ($action === 'validate-shop') {
        $result = $router->validateShopConnection($conn, (int) ($options['shop-id'] ?? 0));
    } else {
        $result = ['ok' => true, 'shops' => shopRouterListShops($conn, $router)];
    }

    $conn->close();
    shopRouterPrint($result, isset($options['json']));
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    if (isset($options['json'])) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
    }
    exit(1);
}

function shopRouterListShops(mysqli $conn, PosmainShopRouter $router): array
{
    $result = $conn->query("
        SELECT *
          FROM router_shops
         ORDER BY id ASC
    ");

    $shops = [];
    while ($row = $result->fetch_assoc()) {
        $shops[] = $router->publicShop($row);
    }

    return $shops;
}

function shopRouterPrint(array $result, bool $json): void
{
    if ($json) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    if (!empty($result['installed'])) {
        echo 'Installed router tables: ' . implode(', ', $result['installed']) . PHP_EOL;
        return;
    }

    if (!empty($result['shop'])) {
        echo 'Shop #' . $result['shop']['id'] . ' ' . $result['shop']['slug'] . ' -> ' . $result['shop']['db_name'] . PHP_EOL;
        return;
    }

    if (!empty($result['alias'])) {
        echo 'Alias ' . $result['alias']['alias_normalized'] . ' -> shop #' . $result['alias']['shop_id'] . PHP_EOL;
        return;
    }

    if (!empty($result['route'])) {
        echo 'Branch ' . $result['route']['branch_uuid'] . ' -> shop #' . $result['route']['shop_id'] . PHP_EOL;
        return;
    }

    if (!empty($result['shops'])) {
        foreach ($result['shops'] as $shop) {
            echo '#' . $shop['id'] . ' ' . $shop['slug'] . ' ' . $shop['status'] . ' ' . $shop['db_name'] . PHP_EOL;
        }
        return;
    }

    echo !empty($result['ok']) ? "OK\n" : "Failed\n";
}

function shopRouterUsage($stream = null): void
{
    $stream = $stream ?: STDOUT;
    fwrite($stream, "Usage:\n");
    fwrite($stream, "  php tools/shop_router.php --install\n");
    fwrite($stream, "  php tools/shop_router.php --register-shop --slug=shop1 --name='Shop 1' --db-host=... --db-name=... --db-user=... [--db-pass=...]\n");
    fwrite($stream, "  php tools/shop_router.php --add-alias --shop-id=1 --alias=owner@example.com [--target-user-id=1|--target-uname=admin]\n");
    fwrite($stream, "  php tools/shop_router.php --add-branch-route --shop-id=1 --branch-uuid=xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx\n");
    fwrite($stream, "  php tools/shop_router.php --validate-shop --shop-id=1\n");
    fwrite($stream, "  php tools/shop_router.php --list-shops [--json]\n");
    fwrite($stream, "Requires POSMAIN_ROUTER_DB_* env vars. Saving DB passwords requires POSMAIN_CONFIG_ENCRYPTION_KEY.\n");
}
