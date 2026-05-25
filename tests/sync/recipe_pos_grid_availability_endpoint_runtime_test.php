<?php

if (($argv[1] ?? '') === '--child') {
    recipePosGridAvailabilityEndpointRuntimeChild($argv[2] ?? '');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_recipe_pos_availability_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipePosGridAvailabilityEndpointRuntimeCreateSchema($conn);
    recipePosGridAvailabilityEndpointRuntimeSeedRows($conn);

    $payload = recipePosGridAvailabilityEndpointRuntimeRunChild($db, ['category_id' => 7]);
    recipePosGridAvailabilityEndpointRuntimeAssert(($payload['success'] ?? false) === true, 'category endpoint should return success JSON');
    recipePosGridAvailabilityEndpointRuntimeAssert(is_array($payload['items'] ?? null), 'category endpoint should return items array');

    $items = [];
    foreach ($payload['items'] as $item) {
        $items[(int) ($item['id'] ?? 0)] = $item;
    }

    recipePosGridAvailabilityEndpointRuntimeAssert(isset($items[7001]), 'category endpoint should include unavailable recipe item');
    recipePosGridAvailabilityEndpointRuntimeAssert(isset($items[7002]), 'category endpoint should include low-stock recipe item');

    $unavailable = $items[7001];
    recipePosGridAvailabilityEndpointRuntimeAssert((int) ($unavailable['is_available'] ?? 1) === 0, 'recipe item with missing ingredient should be unavailable');
    recipePosGridAvailabilityEndpointRuntimeAssert(($unavailable['availability_status'] ?? '') === 'recipe_unavailable', 'unavailable recipe item should expose recipe_unavailable status');
    recipePosGridAvailabilityEndpointRuntimeAssert(($unavailable['availability_can_add'] ?? true) === false, 'unavailable recipe item should not be addable');
    recipePosGridAvailabilityEndpointRuntimeAssert(($unavailable['recipe_enabled'] ?? false) === true, 'unavailable recipe item should expose recipe_enabled');
    recipePosGridAvailabilityEndpointRuntimeAssert((int) ($unavailable['recipe_id'] ?? 0) === 101, 'unavailable recipe item should expose active recipe id');
    recipePosGridAvailabilityEndpointRuntimeAssert(recipePosGridAvailabilityEndpointRuntimeDecimalEquals($unavailable['recipe_effective_available_qty'] ?? '', '0.000000'), 'unavailable recipe item should expose zero effective qty');
    recipePosGridAvailabilityEndpointRuntimeAssert(($unavailable['unavailable_reason'] ?? '') === 'Required ingredient out of stock.', 'unavailable recipe item should expose cashier reason');

    $lowStock = $items[7002];
    recipePosGridAvailabilityEndpointRuntimeAssert((int) ($lowStock['is_available'] ?? 0) === 1, 'low-stock recipe item should remain available');
    recipePosGridAvailabilityEndpointRuntimeAssert(($lowStock['availability_status'] ?? '') === 'recipe_low', 'low-stock recipe item should expose recipe_low status');
    recipePosGridAvailabilityEndpointRuntimeAssert(($lowStock['availability_low_stock'] ?? false) === true, 'low-stock recipe item should expose low-stock flag');
    recipePosGridAvailabilityEndpointRuntimeAssert(recipePosGridAvailabilityEndpointRuntimeDecimalEquals($lowStock['recipe_effective_available_qty'] ?? '', '3.000000'), 'low-stock recipe item should expose makeable qty');

    foreach ([$unavailable, $lowStock] as $item) {
        foreach (['cost_price', 'unit_cost', 'total_cost', 'ingredient_cost_json', 'internal_cost_per_sell_unit'] as $sensitiveKey) {
            recipePosGridAvailabilityEndpointRuntimeAssert(!array_key_exists($sensitiveKey, $item), 'POS availability payload should not expose cost key ' . $sensitiveKey);
        }
    }

    recipePosGridAvailabilityEndpointRuntimeAssert(
        recipePosGridAvailabilityEndpointRuntimeCount($conn, 'SELECT COUNT(*) AS c FROM recipe_availability_cache WHERE channel = \'pos\' AND order_type = \'takeaway\'') === 2,
        'category endpoint should refresh recipe availability cache for decorated items'
    );

    echo "recipe-pos-grid-availability-endpoint-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipePosGridAvailabilityEndpointRuntimeChild(string $json): void
{
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid child payload.\n");
        exit(9);
    }

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_SELF'] = 'ajax/get_category_items.php';
    $_SERVER['SCRIPT_NAME'] = 'ajax/get_category_items.php';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_GET = [
        'category_id' => (int) ($payload['category_id'] ?? 0),
    ];

    session_id('recipeposavailability' . getmypid());
    require_once dirname(__DIR__, 2) . '/includes/session_bootstrap.php';
    chdir(dirname(__DIR__, 2) . '/ajax');
    require dirname(__DIR__, 2) . '/ajax/get_category_items.php';
    exit(0);
}

function recipePosGridAvailabilityEndpointRuntimeRunChild(string $db, array $payload): array
{
    $env = array_merge($_ENV, [
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_SESSION_DRIVER' => 'file',
        'POSMAIN_SYNC_OUTBOX_ENABLED' => '0',
        'POSMAIN_ENABLE_RECIPES' => '1',
        'POSMAIN_RECIPE_MODE' => 'availability_pilot',
        'POSMAIN_RECIPE_AVAILABILITY' => '1',
        'POSMAIN_RECIPE_PILOT_ITEM_IDS' => '7001,7002',
        'POSMAIN_RECIPE_MOOVA_SYNC' => '0',
        'POSMAIN_RECIPE_COST_PUBLIC_PAYLOADS' => '0',
        'POSMAIN_ROUTER_ENABLED' => '0',
    ]);
    $command = [
        PHP_BINARY,
        __FILE__,
        '--child',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2), $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start POS recipe availability endpoint child process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("POS recipe availability endpoint child failed with code {$exitCode}: {$stderr}\n{$stdout}");
    }

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("POS recipe availability endpoint child did not return JSON: {$stderr}\n{$stdout}");
    }

    return $decoded;
}

function recipePosGridAvailabilityEndpointRuntimeCreateSchema(mysqli $conn): void
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
        CREATE TABLE item_availability (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            channel VARCHAR(40) NOT NULL DEFAULT 'all',
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            unavailable_reason VARCHAR(255) NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_availability_scope (item_id, tenant, branch, channel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            iname VARCHAR(191) NOT NULL,
            price1 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            group1 BIGINT UNSIGNED NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE recipe_headers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipe_uuid CHAR(36) NOT NULL,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            branch_uuid CHAR(36) NULL,
            sellable_item_id BIGINT UNSIGNED NOT NULL,
            recipe_name VARCHAR(255) NOT NULL,
            recipe_type ENUM('make_to_order','batch_prepared','hybrid','packaging_bundle','modifier_only','sub_recipe') NOT NULL DEFAULT 'make_to_order',
            status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
            version_number INT UNSIGNED NOT NULL DEFAULT 1,
            yield_qty DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            yield_unit_id BIGINT UNSIGNED NULL,
            default_wastage_percent DECIMAL(9,4) NOT NULL DEFAULT 0.0000,
            effective_from DATETIME NULL,
            effective_to DATETIME NULL,
            costing_method ENUM('item_cost_price','moving_average','last_purchase','manual_snapshot') NOT NULL DEFAULT 'item_cost_price',
            created_by BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_recipe_uuid (recipe_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE recipe_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipe_id BIGINT UNSIGNED NOT NULL,
            line_uuid CHAR(36) NOT NULL,
            ingredient_item_id BIGINT UNSIGNED NULL,
            sub_recipe_id BIGINT UNSIGNED NULL,
            line_type ENUM('ingredient','packaging','sub_recipe','modifier_ingredient','labor_placeholder') NOT NULL DEFAULT 'ingredient',
            ingredient_item_type_snapshot VARCHAR(64) NULL,
            qty_per_yield DECIMAL(18,6) NOT NULL,
            unit_id BIGINT UNSIGNED NULL,
            unit_conversion_to_base DECIMAL(18,8) NOT NULL DEFAULT 1.00000000,
            wastage_percent DECIMAL(9,4) NOT NULL DEFAULT 0.0000,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            modifier_group_id BIGINT UNSIGNED NULL,
            modifier_option_id BIGINT UNSIGNED NULL,
            modifier_behavior ENUM('additive','substitution_remove','substitution_add') NOT NULL DEFAULT 'additive',
            substitution_group VARCHAR(64) NULL,
            order_type ENUM('any','dine_in','takeaway','delivery') NOT NULL DEFAULT 'any',
            channel ENUM('any','pos','table','moova','cofe','api') NOT NULL DEFAULT 'any',
            sort_order INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_recipe_line_uuid (line_uuid),
            KEY idx_recipe_lines_recipe (recipe_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE inventory_item_balances (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            branch_uuid CHAR(36) NULL,
            store_id INT NOT NULL DEFAULT 0,
            item_id BIGINT UNSIGNED NOT NULL,
            qty_on_hand DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_reserved DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_available DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            moving_average_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            last_movement_id BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inventory_balance_item (pos_tenant, pos_branch, store_id, item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE recipe_availability_cache (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            branch_uuid CHAR(36) NULL,
            store_id INT NOT NULL DEFAULT 0,
            sellable_item_id BIGINT UNSIGNED NOT NULL,
            recipe_id BIGINT UNSIGNED NULL,
            order_type ENUM('any','dine_in','takeaway','delivery') NOT NULL DEFAULT 'any',
            channel ENUM('any','pos','table','moova','cofe','api') NOT NULL DEFAULT 'any',
            computed_available_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            effective_available_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            effective_is_available TINYINT(1) NOT NULL DEFAULT 1,
            blocking_item_id BIGINT UNSIGNED NULL,
            unavailable_reason VARCHAR(255) NULL,
            availability_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
            calculated_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_recipe_availability_item (pos_tenant, pos_branch, store_id, sellable_item_id, order_type, channel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function recipePosGridAvailabilityEndpointRuntimeSeedRows(mysqli $conn): void
{
    $conn->query("INSERT INTO myitems (id, iname, price1, group1, isdeleted) VALUES (7001, 'Unavailable Burger', '90.000000', 7, 0)");
    $conn->query("INSERT INTO myitems (id, iname, price1, group1, isdeleted) VALUES (7002, 'Low Stock Latte', '55.000000', 7, 0)");
    $conn->query("INSERT INTO myitems (id, iname, price1, group1, isdeleted) VALUES (8001, 'Missing Patty', '0.000000', 8, 0)");
    $conn->query("INSERT INTO myitems (id, iname, price1, group1, isdeleted) VALUES (8002, 'Oat Milk', '0.000000', 8, 0)");
    recipePosGridAvailabilityEndpointRuntimeSeedRecipe($conn, 101, 7001, 8001, 'Unavailable Burger Recipe');
    recipePosGridAvailabilityEndpointRuntimeSeedRecipe($conn, 102, 7002, 8002, 'Low Stock Latte Recipe');
    recipePosGridAvailabilityEndpointRuntimeSeedBalance($conn, 8001, '0.000000');
    recipePosGridAvailabilityEndpointRuntimeSeedBalance($conn, 8002, '3.000000');
}

function recipePosGridAvailabilityEndpointRuntimeSeedRecipe(mysqli $conn, int $recipeId, int $sellableItemId, int $ingredientItemId, string $name): void
{
    $recipeUuid = sprintf('00000000-0000-4000-8000-%012d', $recipeId);
    $lineUuid = sprintf('00000000-0000-4000-8001-%012d', $recipeId);
    $stmt = $conn->prepare("
        INSERT INTO recipe_headers (
            id, recipe_uuid, pos_tenant, pos_branch, sellable_item_id, recipe_name,
            recipe_type, status, version_number, yield_qty, approved_at
        ) VALUES (?, ?, 0, 0, ?, ?, 'make_to_order', 'active', 1, '1.000000', NOW())
    ");
    $stmt->bind_param('isis', $recipeId, $recipeUuid, $sellableItemId, $name);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO recipe_lines (
            recipe_id, line_uuid, ingredient_item_id, line_type, qty_per_yield,
            unit_conversion_to_base, wastage_percent, is_required, order_type, channel, sort_order
        ) VALUES (?, ?, ?, 'ingredient', '1.000000', '1.00000000', '0.0000', 1, 'any', 'any', 1)
    ");
    $stmt->bind_param('isi', $recipeId, $lineUuid, $ingredientItemId);
    $stmt->execute();
    $stmt->close();
}

function recipePosGridAvailabilityEndpointRuntimeSeedBalance(mysqli $conn, int $itemId, string $qty): void
{
    $conn->query("
        INSERT INTO inventory_item_balances
            (pos_tenant, pos_branch, branch_uuid, store_id, item_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost)
        VALUES
            (0, 0, NULL, 0, {$itemId}, '{$qty}', '0.000000', '{$qty}', '0.000000')
    ");
}

function recipePosGridAvailabilityEndpointRuntimeCount(mysqli $conn, string $sql): int
{
    $row = $conn->query($sql)->fetch_assoc();

    return (int) ($row['c'] ?? 0);
}

function recipePosGridAvailabilityEndpointRuntimeDecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === number_format((float) $expected, 6, '.', '');
}

function recipePosGridAvailabilityEndpointRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
