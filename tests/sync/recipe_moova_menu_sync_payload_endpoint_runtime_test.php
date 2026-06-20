<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/MoovaPosIntegration.php';

if (($argv[1] ?? '') === '--child') {
    recipeMoovaMenuSyncPayloadEndpointRuntimeChild($argv[2] ?? '');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_moova_menu_sync_' . getmypid();
$token = 'runtime-token-' . getmypid();
$branchId = 'moova-branch-runtime';
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipeMoovaMenuSyncPayloadEndpointRuntimeCreateLegacySchema($conn);
    (new SyncSchemaManager())->apply($conn);
    MoovaPosIntegration::ensureSchema($conn);
    recipeMoovaMenuSyncPayloadEndpointRuntimeSeedRows($conn, $token, $branchId);

    $enabledPayload = recipeMoovaMenuSyncPayloadEndpointRuntimeRunChild($db, $token, $branchId, true);
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledPayload['success'] ?? false) === true, 'enabled menu sync endpoint should return success JSON');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(is_array($enabledPayload['menu']['items'] ?? null), 'enabled menu sync payload should include menu items');

    $enabledItem = recipeMoovaMenuSyncPayloadEndpointRuntimeFindItem($enabledPayload, 'pos-item-9101');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert($enabledItem !== null, 'enabled menu sync payload should include runtime recipe item');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(isset($enabledItem['recipe_availability']) && is_array($enabledItem['recipe_availability']), 'enabled menu sync item should include nested recipe availability');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledItem['recipe_availability']['recipe_enabled'] ?? false) === true, 'recipe availability should mark active recipe as enabled');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert((int) ($enabledItem['recipe_availability']['active_recipe_version'] ?? 0) === 4, 'recipe availability should use the Moova link shop scope active recipe');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(recipeMoovaMenuSyncPayloadEndpointRuntimeDecimalEquals($enabledItem['recipe_availability']['computed_available_qty'] ?? '', '0.000000'), 'recipe availability should expose zero computed availability');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(recipeMoovaMenuSyncPayloadEndpointRuntimeDecimalEquals($enabledItem['recipe_availability']['effective_available_qty'] ?? '', '0.000000'), 'recipe availability should expose zero effective availability');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledItem['recipe_availability']['effective_is_available'] ?? true) === false, 'recipe availability should expose unavailable boolean');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert((int) ($enabledItem['recipe_availability']['availability_revision'] ?? 0) === 44, 'recipe availability should expose Moova scope revision');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledItem['recipe_availability']['unavailable_reason'] ?? '') === 'Delivery packaging is out of stock.', 'recipe availability should expose safe unavailable reason');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledItem['available'] ?? true) === false, 'unavailable recipe item should mark available false');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledItem['deliveryAvailable'] ?? true) === false, 'unavailable recipe item should mark deliveryAvailable false');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledItem['isOrderable'] ?? true) === false, 'unavailable recipe item should mark isOrderable false');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledItem['unavailableReason'] ?? '') === 'Delivery packaging is out of stock.', 'unavailable recipe item should expose top-level reason');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledItem['availabilityRevision'] ?? 0) === 44, 'unavailable recipe item should expose top-level revision alias');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledItem['price'] ?? null) === 9500, 'Moova menu payload should expose item price in cents');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledItem['priceCents'] ?? null) === 9500, 'Moova menu payload should expose explicit priceCents alias');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(recipeMoovaMenuSyncPayloadEndpointRuntimeDecimalEquals($enabledItem['priceMajor'] ?? '', '95.000000'), 'Moova menu payload should preserve POS major price alias');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledPayload['rawPayload']['priceUnit'] ?? '') === 'minor', 'Moova menu payload should declare minor-unit prices');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert((int) ($enabledPayload['rawPayload']['priceUnitScale'] ?? 0) === 100, 'Moova menu payload should declare cents scale');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($enabledPayload['rawPayload']['posPriceUnit'] ?? '') === 'major', 'Moova menu payload should keep POS price-unit provenance');

    $sensitivePaths = recipeMoovaMenuSyncPayloadEndpointRuntimeSensitivePaths($enabledPayload);
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert($sensitivePaths === [], 'enabled Moova menu payload must not expose sensitive cost fields: ' . implode(', ', $sensitivePaths));

    $disabledPayload = recipeMoovaMenuSyncPayloadEndpointRuntimeRunChild($db, $token, $branchId, false);
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($disabledPayload['success'] ?? false) === true, 'disabled menu sync endpoint should return success JSON');
    $disabledItem = recipeMoovaMenuSyncPayloadEndpointRuntimeFindItem($disabledPayload, 'pos-item-9101');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert($disabledItem !== null, 'disabled menu sync payload should still include runtime item');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(!array_key_exists('recipe_availability', $disabledItem), 'disabled Moova recipe sync should preserve legacy menu shape without recipe availability');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($disabledItem['available'] ?? false) === true, 'disabled Moova recipe sync should leave legacy available flag true');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($disabledItem['deliveryAvailable'] ?? false) === true, 'disabled Moova recipe sync should leave legacy deliveryAvailable flag true');
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(($disabledItem['isOrderable'] ?? false) === true, 'disabled Moova recipe sync should leave legacy isOrderable flag true');
    $disabledSensitivePaths = recipeMoovaMenuSyncPayloadEndpointRuntimeSensitivePaths($disabledPayload);
    recipeMoovaMenuSyncPayloadEndpointRuntimeAssert($disabledSensitivePaths === [], 'disabled Moova menu payload must still sanitize sensitive cost fields: ' . implode(', ', $disabledSensitivePaths));

    echo "recipe-moova-menu-sync-payload-endpoint-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipeMoovaMenuSyncPayloadEndpointRuntimeChild(string $json): void
{
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid child payload.\n");
        exit(9);
    }

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_SELF'] = 'ajax/moova_menu_sync_payload.php';
    $_SERVER['SCRIPT_NAME'] = 'ajax/moova_menu_sync_payload.php';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['HTTP_X_MOOVA_DEVICE_TOKEN'] = (string) ($payload['token'] ?? '');
    $_SERVER['HTTP_X_MOOVA_BRANCH_ID'] = (string) ($payload['branch_id'] ?? '');
    $_GET = ['mode' => 'full'];

    session_id('recipemoovamenu' . getmypid());
    require_once dirname(__DIR__, 2) . '/includes/session_bootstrap.php';
    chdir(dirname(__DIR__, 2) . '/ajax');
    require dirname(__DIR__, 2) . '/ajax/moova_menu_sync_payload.php';
    exit(0);
}

function recipeMoovaMenuSyncPayloadEndpointRuntimeRunChild(string $db, string $token, string $branchId, bool $recipeSyncEnabled): array
{
    $env = array_merge($_ENV, [
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_SESSION_DRIVER' => 'file',
        'POSMAIN_SYNC_OUTBOX_ENABLED' => '0',
        'POSMAIN_MENU_SYNC_ENABLED' => '1',
        'POSMAIN_RECIPE_MODE' => $recipeSyncEnabled ? 'consume_pilot' : 'off',
        'POSMAIN_RECIPE_MODE' => $recipeSyncEnabled ? 'availability_pilot' : 'off',
        'POSMAIN_RECIPE_AVAILABILITY' => $recipeSyncEnabled ? '1' : '0',
        'POSMAIN_RECIPE_MOOVA_SYNC' => $recipeSyncEnabled ? '1' : '0',
        'POSMAIN_RECIPE_PILOT_POS_BRANCH' => $recipeSyncEnabled ? '3' : '',
        'POSMAIN_RECIPE_PILOT_ITEM_IDS' => $recipeSyncEnabled ? '9101' : '',
        'POSMAIN_RECIPE_COST_PUBLIC_PAYLOADS' => '0',
        'POSMAIN_ROUTER_ENABLED' => '0',
    ]);
    $command = [
        PHP_BINARY,
        __FILE__,
        '--child',
        json_encode(['token' => $token, 'branch_id' => $branchId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2), $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Moova menu sync endpoint child process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Moova menu sync endpoint child failed with code {$exitCode}: {$stderr}\n{$stdout}");
    }

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Moova menu sync endpoint child did not return JSON: {$stderr}\n{$stdout}");
    }

    return $decoded;
}

function recipeMoovaMenuSyncPayloadEndpointRuntimeCreateLegacySchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lang VARCHAR(20) NULL DEFAULT 'ar',
            edit_pass VARCHAR(191) NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO settings (lang, edit_pass) VALUES ('ar', '')");
    $conn->query("
        CREATE TABLE towns (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tname VARCHAR(191) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE item_group (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            gname VARCHAR(191) NOT NULL,
            info TEXT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            mdtime DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            uuid CHAR(36) NULL,
            iname VARCHAR(255) NOT NULL,
            name2 VARCHAR(255) NULL,
            info TEXT NULL,
            barcode VARCHAR(191) NULL,
            price1 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            price2 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            price3 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            group1 BIGINT UNSIGNED NOT NULL DEFAULT 0,
            group2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            mdtime DATETIME NULL,
            created_at DATETIME NULL,
            item_type VARCHAR(64) NOT NULL DEFAULT 'sellable',
            track_stock TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function recipeMoovaMenuSyncPayloadEndpointRuntimeSeedRows(mysqli $conn, string $token, string $branchId): void
{
    $conn->query("INSERT INTO towns (tname) VALUES ('Runtime town')");
    $conn->query("INSERT INTO item_group (id, gname, info, isdeleted, mdtime) VALUES (17, 'Runtime burgers', 'Runtime category', 0, NOW())");
    $conn->query("
        INSERT INTO myitems
          (id, uuid, iname, name2, info, barcode, price1, price2, price3, cost_price, group1, group2, isdeleted, mdtime, created_at, item_type, track_stock)
        VALUES
          (9101, '11111111-1111-4111-8111-111111111111', 'Runtime Delivery Burger', 'Runtime Delivery Burger EN', 'Recipe payload item', 'RUNTIME-9101', 95.000000, 0.000000, 0.000000, 33.000000, 17, 0, 0, NOW(), NOW(), 'sellable', 1)
    ");

    MoovaPosIntegration::saveActiveLinkForScope($conn, ['tenant' => 2, 'branch' => 3], [
        'moova_shop_id' => 'runtime-shop',
        'moova_branch_id' => $branchId,
        'moova_device_token' => $token,
        'widget_url' => 'https://withmoova.com/pos-widget',
        'locale' => 'ar',
    ]);

    $conn->query("
        INSERT INTO recipe_headers
          (id, recipe_uuid, pos_tenant, pos_branch, sellable_item_id, recipe_name, recipe_type, status, version_number, yield_qty, created_at, approved_at, updated_at)
        VALUES
          (4101, '22222222-2222-4222-8222-222222222222', 2, 3, 9101, 'Runtime Burger Recipe', 'make_to_order', 'active', 4, 1.000000, NOW(), NOW(), NOW())
    ");
    $conn->query("
        INSERT INTO recipe_headers
          (id, recipe_uuid, pos_tenant, pos_branch, sellable_item_id, recipe_name, recipe_type, status, version_number, yield_qty, created_at, approved_at, updated_at)
        VALUES
          (4102, '33333333-3333-4333-8333-333333333333', 0, 0, 9101, 'Wrong Scope Burger Recipe', 'make_to_order', 'active', 99, 1.000000, NOW(), NOW(), NOW())
    ");
    $conn->query("
        INSERT INTO recipe_availability_cache
          (pos_tenant, pos_branch, store_id, sellable_item_id, recipe_id, order_type, channel, computed_available_qty, effective_available_qty, effective_is_available, unavailable_reason, availability_revision, calculated_at)
        VALUES
          (2, 3, 0, 9101, 4101, 'delivery', 'moova', 0.000000, 0.000000, 0, 'Delivery packaging is out of stock.', 44, NOW()),
          (0, 0, 0, 9101, 4102, 'delivery', 'moova', 99.000000, 99.000000, 1, NULL, 99, NOW())
    ");
}

function recipeMoovaMenuSyncPayloadEndpointRuntimeFindItem(array $payload, string $providerItemId): ?array
{
    foreach (($payload['menu']['items'] ?? []) as $item) {
        if (($item['providerItemId'] ?? $item['id'] ?? '') === $providerItemId) {
            return is_array($item) ? $item : null;
        }
    }

    return null;
}

function recipeMoovaMenuSyncPayloadEndpointRuntimeSensitivePaths(array $payload, string $prefix = ''): array
{
    $sensitive = [
        'cost',
        'cost_price',
        'unit_cost',
        'total_cost',
        'ingredient_cost_json',
        'internal_cost_per_sell_unit',
        'recipe_cost_snapshot',
        'recipe_cost_snapshot_id',
        'moving_average_cost',
        'last_purchase_cost',
        'supplier_cost',
        'margin',
        'profit',
    ];
    $paths = [];
    foreach ($payload as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower((string) $key)) ?: '';
        $sensitiveNormalized = array_map(static function (string $key): string {
            return preg_replace('/[^a-z0-9]+/', '', strtolower($key)) ?: '';
        }, $sensitive);
        if (in_array($normalized, $sensitiveNormalized, true)) {
            $paths[] = $path;
        }
        if (is_array($value)) {
            $paths = array_merge($paths, recipeMoovaMenuSyncPayloadEndpointRuntimeSensitivePaths($value, $path));
        }
    }

    return $paths;
}

function recipeMoovaMenuSyncPayloadEndpointRuntimeDecimalEquals($actual, string $expected): bool
{
    if (!is_numeric($actual) || !is_numeric($expected)) {
        return false;
    }

    return abs((float) $actual - (float) $expected) < 0.000001;
}

function recipeMoovaMenuSyncPayloadEndpointRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
