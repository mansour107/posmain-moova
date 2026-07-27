<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_cofe_create_endpoint_' . getmypid();
$root = dirname(__DIR__, 2);
$sessionDir = sys_get_temp_dir() . '/posmain-cofe-session-' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);
$server = null;

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipeCofeEndpointCreateLegacySchema($conn);
    (new SyncSchemaManager())->apply($conn);
    recipeCofeEndpointSeedData($conn);
    recipeCofeEndpointSeedRecipe($conn);

    if (!is_dir($sessionDir) && !mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
        throw new RuntimeException('Unable to create temp session directory.');
    }

    $server = recipeCofeEndpointStartServer($root, $db, $sessionDir);
    $payload = [
        'cofeOrderId' => 'cofe-endpoint-order-' . getmypid(),
        'idempotencyKey' => 'cofe-endpoint-idem-' . getmypid(),
        'tableNumber' => '1',
        'items' => [
            [
                'externalLineId' => 'cofe-endpoint-line-1',
                'itemId' => 'cofe-sku-5001',
                'qty' => 2,
                'modifiers' => [
                    ['option_id' => 10, 'qty' => 1],
                ],
            ],
        ],
    ];

    $legacyBaseline = [
        'orders' => (int) $conn->query("SELECT COUNT(*) AS c FROM ot_head")->fetch_assoc()['c'],
        'details' => (int) $conn->query("SELECT COUNT(*) AS c FROM fat_details")->fetch_assoc()['c'],
        'movements' => (int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements")->fetch_assoc()['c'],
    ];
    $legacy = recipeCofeEndpointPost($server['legacy_url'], $payload);
    recipeCofeEndpointAssert($legacy['status'] === 410, 'legacy Cofe endpoint should remain quarantined');
    recipeCofeEndpointAssert(
        ($legacy['json']['error'] ?? '') === 'ENDPOINT_QUARANTINED',
        'legacy Cofe endpoint should return the stable quarantine error'
    );
    recipeCofeEndpointAssert($legacyBaseline === [
        'orders' => (int) $conn->query("SELECT COUNT(*) AS c FROM ot_head")->fetch_assoc()['c'],
        'details' => (int) $conn->query("SELECT COUNT(*) AS c FROM fat_details")->fetch_assoc()['c'],
        'movements' => (int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements")->fetch_assoc()['c'],
    ], 'quarantined legacy Cofe request must not mutate durable order, detail, or inventory state');

    $first = recipeCofeEndpointPost($server['url'], $payload);
    recipeCofeEndpointAssert($first['status'] === 200, 'first Cofe endpoint POST should return HTTP 200: ' . $first['raw']);
    recipeCofeEndpointAssert(($first['json']['success'] ?? false) === true, 'first Cofe endpoint POST should succeed');
    recipeCofeEndpointAssert((int) ($first['json']['orderId'] ?? 0) > 0, 'first response should include orderId');
    recipeCofeEndpointAssert(($first['json']['providerReferenceId'] ?? '') === $payload['idempotencyKey'], 'first response should echo idempotency key');

    $second = recipeCofeEndpointPost($server['url'], $payload);
    recipeCofeEndpointAssert($second['status'] === 200, 'replayed Cofe endpoint POST should return HTTP 200: ' . $second['raw']);
    recipeCofeEndpointAssert(($second['json']['success'] ?? false) === true, 'replayed Cofe endpoint POST should succeed');
    recipeCofeEndpointAssert((int) $second['json']['orderId'] === (int) $first['json']['orderId'], 'replay should return the first order id');

    $orderId = (int) $first['json']['orderId'];
    $orderCount = (int) $conn->query("SELECT COUNT(*) AS c FROM ot_head WHERE pro_tybe = 9")->fetch_assoc()['c'];
    $cashCount = (int) $conn->query("SELECT COUNT(*) AS c FROM ot_head WHERE pro_tybe = 1")->fetch_assoc()['c'];
    $detailCount = (int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$orderId}")->fetch_assoc()['c'];
    $storedKey = (string) $conn->query("SELECT cofe_idempotency_key FROM ot_head WHERE id = {$orderId}")->fetch_assoc()['cofe_idempotency_key'];
    $requestKeyCount = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM pos_request_keys
         WHERE scope = 'pos.integration.cofe.create'
           AND idempotency_key = '" . $conn->real_escape_string($payload['idempotencyKey']) . "'
           AND status = 'completed'"
    )->fetch_assoc()['c'];
    $usageRows = recipeCofeEndpointRows($conn, 'recipe_order_line_usage', "order_id = {$orderId}");
    $movementRows = recipeCofeEndpointRows($conn, 'inventory_movements', "order_id = {$orderId} AND movement_type = 'reservation'");
    $mapRows = recipeCofeEndpointRows($conn, 'external_order_line_map', "external_order_id = '" . $conn->real_escape_string($payload['cofeOrderId']) . "'");
    $balance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 1, 6001);

    recipeCofeEndpointAssert($orderCount === 1, 'replay should not create a second Cofe sale order');
    recipeCofeEndpointAssert($cashCount === 0, 'unpaid Cofe table order must not create a cash receipt before tender capture');
    recipeCofeEndpointAssert($detailCount === 1, 'replay should not create duplicate fat_details rows');
    recipeCofeEndpointAssert($storedKey === '', 'canonical Cofe order should not depend on the legacy header idempotency column');
    recipeCofeEndpointAssert($requestKeyCount === 1, 'canonical Cofe replay should keep one completed scoped idempotency record');
    recipeCofeEndpointAssert(count($usageRows) === 1, 'recipe usage should be created once');
    recipeCofeEndpointAssert((string) $usageRows[0]['status'] === 'reserved', 'unpaid Cofe table order recipe usage should remain reserved');
    recipeCofeEndpointAssert(
        (string) $usageRows[0]['source_line_uuid'] === 'cofe:cofe-endpoint-line-1',
        'recipe usage should preserve Cofe provider line identity: ' . json_encode($usageRows, JSON_UNESCAPED_SLASHES)
    );
    recipeCofeEndpointAssert(count($movementRows) === 1, 'recipe reservation movement should be written once');
    recipeCofeEndpointAssert(count($mapRows) === 1, 'external Cofe line map should be written once');
    recipeCofeEndpointAssert(
        (string) $balance['qty_on_hand'] === '10.000000'
            && (string) $balance['qty_reserved'] === '2.000000'
            && (string) $balance['qty_available'] === '8.000000',
        'unpaid Cofe table order should reserve ingredient stock exactly once without consuming on-hand quantity: ' . json_encode([
        'balance' => $balance,
        'movements' => $movementRows,
        'usage' => $usageRows,
    ], JSON_UNESCAPED_SLASHES)
    );

    echo "recipe-cofe-create-order-endpoint-runtime-ok db={$db}\n";
} finally {
    if (is_array($server ?? null)) {
        recipeCofeEndpointStopServer($server);
    }
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
    recipeCofeEndpointRemoveDir($sessionDir);
}

function recipeCofeEndpointCreateLegacySchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lang VARCHAR(20) NULL DEFAULT 'ar',
            edit_pass VARCHAR(191) NULL DEFAULT '',
            def_pos_store INT NOT NULL DEFAULT 1,
            def_pos_employee INT NOT NULL DEFAULT 35,
            def_pos_client INT NOT NULL DEFAULT 12,
            def_pos_fund INT NOT NULL DEFAULT 91,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO settings (lang, edit_pass, def_pos_store, def_pos_employee, def_pos_client, def_pos_fund) VALUES ('ar', '', 1, 35, 12, 91)");
    $conn->query("
        CREATE TABLE towns (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tname VARCHAR(191) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL PRIMARY KEY,
            rollname VARCHAR(191) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE stores (
            id INT NOT NULL PRIMARY KEY,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(50) NULL,
            aname VARCHAR(191) NULL,
            parent_id INT NULL,
            is_basic TINYINT(1) NOT NULL DEFAULT 0,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            is_fund TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE item_group (
            id INT NOT NULL PRIMARY KEY,
            gname VARCHAR(191) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            uuid CHAR(36) NULL,
            iname VARCHAR(255) NULL,
            name2 VARCHAR(255) NULL,
            barcode VARCHAR(191) NULL,
            cofe_item_id VARCHAR(191) NULL,
            price1 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            itmqty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            group1 BIGINT UNSIGNED NOT NULL DEFAULT 7,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NULL,
            pro_id VARCHAR(80) NULL,
            pro_tybe INT NULL,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            is_journal TINYINT(1) NOT NULL DEFAULT 0,
            journal_tybe INT NULL,
            info TEXT NULL,
            pro_date DATE NULL,
            accural_date DATE NULL,
            pro_pattren INT NULL,
            pro_serial INT NULL,
            price_list INT NULL,
            store_id INT NULL,
            emp_id INT NULL,
            emp2_id INT NULL,
            acc1 INT NULL,
            acc2 INT NULL,
            pro_value DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            cost_center INT NULL,
            profit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_total DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_disc DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_disc_per DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_plus DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_plus_per DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_tax DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_tax_per DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_net DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            `user` INT NULL,
            op2 INT NULL,
            table_id INT NULL,
            order_type VARCHAR(40) NULL,
            payment_status VARCHAR(40) NOT NULL DEFAULT 'unpaid',
            invoice_status VARCHAR(40) NOT NULL DEFAULT 'draft',
            order_status VARCHAR(40) NOT NULL DEFAULT 'active',
            paid_amount DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            remaining_amount DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            payment_method VARCHAR(40) NULL,
            payment_notes VARCHAR(255) NULL,
            payment_date DATETIME NULL,
            completed_at DATETIME NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            crtime DATETIME NULL,
            mdtime DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_heads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            total DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            jdate DATE NULL,
            details TEXT NULL,
            `user` INT NULL,
            op_id INT NULL,
            op2 INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            account_id INT NOT NULL,
            debit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            credit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            tybe INT NOT NULL DEFAULT 0,
            op_id INT NULL,
            op2 INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NULL,
            pro_tybe INT NULL,
            pro_id INT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            discount DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            det_value DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fatid BIGINT UNSIGNED NOT NULL,
            fat_tybe INT NULL,
            det_store BIGINT UNSIGNED NULL,
            cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            profit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(191) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE process (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(191) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function recipeCofeEndpointSeedData(mysqli $conn): void
{
    $owner = $conn->query("SELECT id, role_key FROM usr_pwrs WHERE id = 1 LIMIT 1")->fetch_assoc();
    recipeCofeEndpointAssert(
        is_array($owner) && ($owner['role_key'] ?? '') === 'owner',
        'schema setup should seed the owner role used by the endpoint fixture'
    );
    $conn->query("INSERT INTO stores (id, isdeleted) VALUES (1, 0)");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, isdeleted, is_fund) VALUES
            (1, '130001', 'Operational Stock', 0, 0, 1, 0, 0),
            (12, '122001', 'Walk-in Customer', 12, 0, 0, 0, 0),
            (35, '350001', 'Cofe Employee', 35, 0, 0, 0, 0),
            (91, '101001', 'Cash Fund', 0, 0, 0, 0, 1)
    ");
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'Cofe Endpoint Table', 0, 0)");
    $conn->query("INSERT INTO item_group (id, gname) VALUES (7, 'Endpoint Recipes')");
    $conn->query("
        INSERT INTO modifier_groups (
            id, name_ar, name_en, selection_min, selection_max, is_required, is_active, tenant, branch, sort_order
        ) VALUES (1, 'إضافة تجريبية', 'Endpoint option', 0, 2, 0, 1, 0, 0, 1)
    ");
    $conn->query("
        INSERT INTO modifier_options (id, group_id, name_ar, name_en, price_delta, is_active, sort_order)
        VALUES (10, 1, 'اختيار تجريبي', 'Endpoint choice', 0.000, 1, 1)
    ");
    $conn->query("INSERT INTO item_modifier_groups (item_id, group_id, sort_order) VALUES (5001, 1, 1)");
    $conn->query("
        INSERT INTO myitems (id, iname, barcode, cofe_item_id, price1, cost_price, itmqty, group1, item_type, track_stock, isdeleted)
        VALUES
          (5001, 'Endpoint Burger', '5001', 'cofe-sku-5001', 12.000000, 0.000000, 0.000000, 7, 'sellable', 0, 0),
          (6001, 'Endpoint Patty', '6001', NULL, 0.000000, 4.000000, 10.000000, 7, 'ingredient', 1, 0)
    ");
}

function recipeCofeEndpointSeedRecipe(mysqli $conn): void
{
    (new InventoryBalanceRepository())->putBalance($conn, [
        'item_id' => 6001,
        'store_id' => 1,
        'qty_on_hand' => '10.000000',
        'qty_reserved' => '0.000000',
        'qty_available' => '10.000000',
    ]);

    $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
        'recipe' => [
            'enabled' => true,
            'mode' => 'shadow',
        ],
    ]));
    $actor = new RecipeActorContext(1, 0, 0, null, ['recipe.manage', 'recipe.approve']);
    $recipe = $definition->createDraft($conn, [
        'sellable_item_id' => 5001,
        'recipe_name' => 'Endpoint Cofe recipe',
    ], $actor);
    $definition->addLine($conn, (int) $recipe['id'], [
        'ingredient_item_id' => 6001,
        'qty_per_yield' => '1.000000',
    ], $actor);
    $definition->activate($conn, (int) $recipe['id'], $actor);
}

function recipeCofeEndpointStartServer(string $root, string $db, string $sessionDir): array
{
    $port = recipeCofeEndpointFreePort();
    $env = array_merge(getenv() ?: [], [
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_SESSION_DRIVER' => 'file',
        'POSMAIN_SESSION_SAVE_PATH' => $sessionDir,
        'POSMAIN_ROUTER_ENABLED' => '0',
        'POSMAIN_SYNC_OUTBOX_ENABLED' => '0',
        'POSMAIN_MENU_SYNC_ENABLED' => '0',
        'POSMAIN_RECIPE_MODE' => 'consume_pilot',
        'POSMAIN_RECIPE_MODE' => 'consume_pilot',
        'POSMAIN_RECIPE_RESERVATIONS' => '1',
        'POSMAIN_RECIPE_CONSUMPTION' => '1',
        'POSMAIN_RECIPE_ACCOUNTING' => '0',
        'POSMAIN_RECIPE_AVAILABILITY' => '0',
        'POSMAIN_RECIPE_MOOVA_SYNC' => '0',
        'POSMAIN_RECIPE_STRICT_STOCK' => '0',
        'POSMAIN_RECIPE_PILOT_POS_BRANCH' => '0',
        'POSMAIN_ALLOW_OPEN_INTEGRATIONS' => '1',
    ]);

    $process = proc_open([
        PHP_BINARY,
        '-S',
        '127.0.0.1:' . $port,
        '-t',
        $root,
    ], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PHP built-in server for Cofe endpoint runtime test.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    recipeCofeEndpointWaitForServer($port, $process, $pipes);

    return [
        'process' => $process,
        'pipes' => $pipes,
        'url' => 'http://127.0.0.1:' . $port . '/api/pos/integrations/cofe/orders',
        'legacy_url' => 'http://127.0.0.1:' . $port . '/ajax/cofe_create_order.php',
    ];
}

function recipeCofeEndpointStopServer(array $server): void
{
    foreach (($server['pipes'] ?? []) as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($server['process'] ?? null)) {
        proc_terminate($server['process']);
        proc_close($server['process']);
    }
}

function recipeCofeEndpointFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$socket) {
        throw new RuntimeException('Unable to reserve local test port: ' . $errstr);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    $parts = explode(':', (string) $name);

    return (int) end($parts);
}

function recipeCofeEndpointWaitForServer(int $port, $process, array $pipes): void
{
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
            throw new RuntimeException('Cofe endpoint test server exited early: ' . $stderr);
        }
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($fp) {
            fclose($fp);
            return;
        }
        usleep(100000);
    }

    throw new RuntimeException('Cofe endpoint test server did not become ready.');
}

function recipeCofeEndpointPost(string $url, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
    ];
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $header, $matches)) {
            $status = (int) $matches[1];
            break;
        }
    }
    if (!is_string($raw)) {
        throw new RuntimeException('Cofe endpoint POST failed without response body.');
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Cofe endpoint returned non-JSON response: ' . $raw);
    }

    return [
        'status' => $status,
        'raw' => $raw,
        'json' => $json,
    ];
}

function recipeCofeEndpointRows(mysqli $conn, string $table, string $where): array
{
    $result = $conn->query("SELECT * FROM {$table} WHERE {$where} ORDER BY id");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function recipeCofeEndpointRemoveDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($dir);
}

function recipeCofeEndpointAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
