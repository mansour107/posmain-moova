<?php

if (($argv[1] ?? '') === '--child') {
    recipeWasteAdjustmentEndpointRuntimeChild($argv[2] ?? '');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_recipe_waste_endpoint_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipeWasteAdjustmentEndpointRuntimeCreateSchema($conn);
    recipeWasteAdjustmentEndpointRuntimeSeedCommonRows($conn);
    recipeWasteAdjustmentEndpointRuntimeSeedBalance($conn, 3001, '10.000000');

    $wasteUuid = '00000000-0000-4000-8000-000000000101';
    recipeWasteAdjustmentEndpointRuntimeRunChild($db, [
        'action' => 'record_waste',
        'waste_uuid' => $wasteUuid,
        'item_id' => 3001,
        'qty' => '2.000000',
        'unit_cost' => '3.000000',
        'reason' => 'endpoint waste smoke',
        'occurred_at' => date('Y-m-d'),
    ]);

    $waste = recipeWasteAdjustmentEndpointRuntimeOne($conn, "SELECT * FROM inventory_movements WHERE source_uuid = '{$wasteUuid}'");
    recipeWasteAdjustmentEndpointRuntimeAssert($waste !== null, 'waste endpoint should write one inventory movement');
    recipeWasteAdjustmentEndpointRuntimeAssert($waste['movement_type'] === 'waste', 'waste endpoint should write waste movement type');
    recipeWasteAdjustmentEndpointRuntimeAssert($waste['source_type'] === 'manual', 'waste endpoint should use manual source type');
    recipeWasteAdjustmentEndpointRuntimeAssert(recipeWasteAdjustmentEndpointRuntimeDecimalEquals($waste['qty_out'], '2.000000'), 'waste endpoint should write qty_out');
    recipeWasteAdjustmentEndpointRuntimeAssert(recipeWasteAdjustmentEndpointRuntimeDecimalEquals($waste['total_cost'], '6.000000'), 'waste endpoint should write total cost');
    recipeWasteAdjustmentEndpointRuntimeAssert((int) $waste['created_by'] === 1, 'waste endpoint should stamp actor user');

    $balanceAfterWaste = recipeWasteAdjustmentEndpointRuntimeBalance($conn, 3001);
    recipeWasteAdjustmentEndpointRuntimeAssert(recipeWasteAdjustmentEndpointRuntimeDecimalEquals($balanceAfterWaste['qty_on_hand'], '8.000000'), 'waste endpoint should reduce on-hand balance');
    recipeWasteAdjustmentEndpointRuntimeAssert(recipeWasteAdjustmentEndpointRuntimeDecimalEquals($balanceAfterWaste['qty_available'], '8.000000'), 'waste endpoint should reduce available balance');
    recipeWasteAdjustmentEndpointRuntimeAssert(
        recipeWasteAdjustmentEndpointRuntimeCount($conn, "SELECT COUNT(*) AS c FROM recipe_audit_log WHERE action = 'record_waste'") === 1,
        'waste endpoint should audit the first write'
    );

    recipeWasteAdjustmentEndpointRuntimeRunChild($db, [
        'action' => 'record_waste',
        'waste_uuid' => $wasteUuid,
        'item_id' => 3001,
        'qty' => '2.000000',
        'unit_cost' => '3.000000',
        'reason' => 'endpoint waste smoke replay',
        'occurred_at' => date('Y-m-d'),
    ]);
    recipeWasteAdjustmentEndpointRuntimeAssert(
        recipeWasteAdjustmentEndpointRuntimeCount($conn, "SELECT COUNT(*) AS c FROM inventory_movements WHERE source_uuid = '{$wasteUuid}'") === 1,
        'waste endpoint replay should not duplicate movement'
    );
    recipeWasteAdjustmentEndpointRuntimeAssert(
        recipeWasteAdjustmentEndpointRuntimeCount($conn, "SELECT COUNT(*) AS c FROM recipe_audit_log WHERE action = 'record_waste'") === 1,
        'waste endpoint replay should not duplicate audit'
    );
    $balanceAfterWasteReplay = recipeWasteAdjustmentEndpointRuntimeBalance($conn, 3001);
    recipeWasteAdjustmentEndpointRuntimeAssert(recipeWasteAdjustmentEndpointRuntimeDecimalEquals($balanceAfterWasteReplay['qty_on_hand'], '8.000000'), 'waste endpoint replay should not change balance');

    $adjustmentUuid = '00000000-0000-4000-8000-000000000102';
    recipeWasteAdjustmentEndpointRuntimeRunChild($db, [
        'action' => 'record_adjustment',
        'adjustment_uuid' => $adjustmentUuid,
        'item_id' => 3001,
        'direction' => 'increase',
        'qty' => '5.000000',
        'unit_cost' => '4.000000',
        'reason' => 'endpoint adjustment smoke',
        'occurred_at' => date('Y-m-d'),
    ]);

    $adjustment = recipeWasteAdjustmentEndpointRuntimeOne($conn, "SELECT * FROM inventory_movements WHERE source_uuid = '{$adjustmentUuid}'");
    recipeWasteAdjustmentEndpointRuntimeAssert($adjustment !== null, 'adjustment endpoint should write one inventory movement');
    recipeWasteAdjustmentEndpointRuntimeAssert($adjustment['movement_type'] === 'adjustment', 'adjustment endpoint should write adjustment movement type');
    recipeWasteAdjustmentEndpointRuntimeAssert(recipeWasteAdjustmentEndpointRuntimeDecimalEquals($adjustment['qty_in'], '5.000000'), 'increase adjustment should write qty_in');
    recipeWasteAdjustmentEndpointRuntimeAssert(recipeWasteAdjustmentEndpointRuntimeDecimalEquals($adjustment['qty_out'], '0.000000'), 'increase adjustment should not write qty_out');
    recipeWasteAdjustmentEndpointRuntimeAssert(recipeWasteAdjustmentEndpointRuntimeDecimalEquals($adjustment['total_cost'], '20.000000'), 'adjustment endpoint should write total cost');

    $balanceAfterAdjustment = recipeWasteAdjustmentEndpointRuntimeBalance($conn, 3001);
    recipeWasteAdjustmentEndpointRuntimeAssert(recipeWasteAdjustmentEndpointRuntimeDecimalEquals($balanceAfterAdjustment['qty_on_hand'], '13.000000'), 'adjustment endpoint should increase on-hand balance');
    recipeWasteAdjustmentEndpointRuntimeAssert(recipeWasteAdjustmentEndpointRuntimeDecimalEquals($balanceAfterAdjustment['qty_available'], '13.000000'), 'adjustment endpoint should increase available balance');
    recipeWasteAdjustmentEndpointRuntimeAssert(
        recipeWasteAdjustmentEndpointRuntimeCount($conn, "SELECT COUNT(*) AS c FROM recipe_audit_log WHERE action = 'record_stock_adjustment'") === 1,
        'adjustment endpoint should audit the first write'
    );

    echo "recipe-waste-adjustment-endpoint-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipeWasteAdjustmentEndpointRuntimeChild(string $json): void
{
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid child payload.\n");
        exit(9);
    }

    $csrf = 'recipe-waste-endpoint-csrf-fixed';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['PHP_SELF'] = 'recipe_waste.php';
    $_SERVER['SCRIPT_NAME'] = 'recipe_waste.php';
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
    $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN'] = $csrf;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'recipe-waste-endpoint-runtime-test';
    $_POST = array_merge($payload, [
        'csrf_token' => $csrf,
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 0,
    ]);

    session_id('recipewaste' . getmypid());
    require_once dirname(__DIR__, 2) . '/includes/session_bootstrap.php';
    $_SESSION['login'] = 'recipe_waste_smoke';
    $_SESSION['userid'] = 1;
    $_SESSION['user_id'] = 1;
    $_SESSION['usrole'] = 1;
    $_SESSION['userrole'] = 1;
    $_SESSION['usty'] = 2;
    $_SESSION['posmain_csrf_tokens'] = [
        'recipe_waste_adjustment' => $csrf,
    ];

    chdir(dirname(__DIR__, 2));
    require dirname(__DIR__, 2) . '/recipe_waste.php';
    exit(0);
}

function recipeWasteAdjustmentEndpointRuntimeRunChild(string $db, array $payload): void
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
        'POSMAIN_RECIPE_MODE' => 'shadow',
        'POSMAIN_RECIPE_SHADOW_LEDGER' => '1',
        'POSMAIN_RECIPE_ACCOUNTING' => '0',
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
        throw new RuntimeException('Unable to start waste adjustment endpoint child process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Waste adjustment endpoint child failed with code {$exitCode}: {$stderr}\n{$stdout}");
    }
}

function recipeWasteAdjustmentEndpointRuntimeCreateSchema(mysqli $conn): void
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
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(191) NOT NULL,
            password VARCHAR(255) NULL,
            userrole INT NULL,
            usertype INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL PRIMARY KEY,
            rollname VARCHAR(191) NULL,
            add_stock TINYINT(1) NOT NULL DEFAULT 0,
            edit_stock TINYINT(1) NOT NULL DEFAULT 0,
            sid_reports TINYINT(1) NOT NULL DEFAULT 0,
            sid_accounts TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE inventory_movements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            movement_uuid CHAR(36) NOT NULL,
            movement_group_uuid CHAR(36) NULL,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            branch_uuid CHAR(36) NULL,
            store_id INT NOT NULL DEFAULT 0,
            item_id BIGINT UNSIGNED NOT NULL,
            movement_type ENUM(
                'purchase',
                'sale_direct',
                'recipe_consumption',
                'production_input',
                'production_output',
                'waste',
                'adjustment',
                'transfer_in',
                'transfer_out',
                'reservation',
                'reservation_release',
                'refund_reversal',
                'sync_replay',
                'opening_balance'
            ) NOT NULL,
            source_type ENUM(
                'order',
                'order_line',
                'invoice',
                'fat_details',
                'recipe',
                'recipe_order_line_usage',
                'production_batch',
                'purchase_invoice',
                'adjustment',
                'reservation',
                'sync_event',
                'manual'
            ) NOT NULL,
            source_id BIGINT UNSIGNED NULL,
            source_uuid VARCHAR(128) NULL,
            order_id BIGINT UNSIGNED NULL,
            fat_detail_id BIGINT UNSIGNED NULL,
            order_line_uuid VARCHAR(64) NULL,
            recipe_order_line_usage_id BIGINT UNSIGNED NULL,
            recipe_id BIGINT UNSIGNED NULL,
            recipe_cost_snapshot_id BIGINT UNSIGNED NULL,
            production_batch_id BIGINT UNSIGNED NULL,
            qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            unit_id BIGINT UNSIGNED NULL,
            unit_conversion_to_base DECIMAL(18,8) NOT NULL DEFAULT 1.00000000,
            unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            total_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            accounting_journal_id BIGINT UNSIGNED NULL,
            idempotency_key VARCHAR(191) NOT NULL,
            reversed_movement_id BIGINT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inventory_movement_uuid (movement_uuid),
            UNIQUE KEY uq_inventory_idempotency (pos_tenant, pos_branch, store_id, idempotency_key),
            KEY idx_inventory_item_time (pos_tenant, pos_branch, item_id, created_at),
            KEY idx_inventory_source (source_type, source_id)
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
            UNIQUE KEY uq_inventory_balance_item (pos_tenant, pos_branch, store_id, item_id),
            KEY idx_inventory_balance_available (pos_tenant, pos_branch, item_id, qty_available)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE recipe_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            branch_uuid CHAR(36) NULL,
            recipe_id BIGINT UNSIGNED NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            action VARCHAR(64) NOT NULL,
            before_json JSON NULL,
            after_json JSON NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_recipe_audit_entity (entity_type, entity_id),
            KEY idx_recipe_audit_actor (actor_user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function recipeWasteAdjustmentEndpointRuntimeSeedCommonRows(mysqli $conn): void
{
    $conn->query("INSERT INTO users (id, uname, password, userrole, usertype, isdeleted) VALUES (1, 'recipe_waste_smoke', '', 1, 2, 0)");
    $conn->query("INSERT INTO usr_pwrs (id, rollname, add_stock, edit_stock, sid_reports, sid_accounts, isdeleted) VALUES (1, 'admin', 1, 1, 1, 1, 0)");
}

function recipeWasteAdjustmentEndpointRuntimeSeedBalance(mysqli $conn, int $itemId, string $qty): void
{
    $conn->query("
        INSERT INTO inventory_item_balances
            (pos_tenant, pos_branch, branch_uuid, store_id, item_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost)
        VALUES
            (0, 0, NULL, 0, {$itemId}, '{$qty}', '0.000000', '{$qty}', '3.000000')
    ");
}

function recipeWasteAdjustmentEndpointRuntimeBalance(mysqli $conn, int $itemId): array
{
    $row = recipeWasteAdjustmentEndpointRuntimeOne($conn, "SELECT * FROM inventory_item_balances WHERE item_id = {$itemId} LIMIT 1");
    recipeWasteAdjustmentEndpointRuntimeAssert($row !== null, 'expected inventory balance row');

    return $row;
}

function recipeWasteAdjustmentEndpointRuntimeOne(mysqli $conn, string $sql): ?array
{
    $row = $conn->query($sql)->fetch_assoc();

    return $row ?: null;
}

function recipeWasteAdjustmentEndpointRuntimeCount(mysqli $conn, string $sql): int
{
    $row = recipeWasteAdjustmentEndpointRuntimeOne($conn, $sql);

    return (int) ($row['c'] ?? 0);
}

function recipeWasteAdjustmentEndpointRuntimeDecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === number_format((float) $expected, 6, '.', '');
}

function recipeWasteAdjustmentEndpointRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
