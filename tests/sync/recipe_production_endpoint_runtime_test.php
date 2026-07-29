<?php

if (($argv[1] ?? '') === '--child') {
    recipeProductionEndpointRuntimeChild($argv[2] ?? '');
}

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_recipe_production_endpoint_' . getmypid();
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "recipe-production-endpoint-runtime-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipeProductionEndpointRuntimeCreateSchema($conn);
    recipeProductionEndpointRuntimeSeedCommonRows($conn);
    recipeProductionEndpointRuntimeSeedRecipe($conn);
    recipeProductionEndpointRuntimeSeedBalance($conn, 3001, '20.000000');
    recipeProductionEndpointRuntimeSeedBalance($conn, 2001, '0.000000');

    $draftChild = recipeProductionEndpointRuntimeRunChild($db, [
        'action' => 'create_draft',
        'recipe_id' => 101,
        'planned_output_qty' => '2.000000',
        'store_id' => 0,
        'notes' => 'endpoint production smoke',
    ]);

    $batch = recipeProductionEndpointRuntimeOne($conn, 'SELECT * FROM production_batches WHERE recipe_id = 101 ORDER BY id DESC LIMIT 1');
    $draftError = trim((string) ($draftChild['stderr'] ?? ''));
    recipeProductionEndpointRuntimeAssert(
        $batch !== null,
        $draftError !== ''
            ? 'production page should create a draft batch: ' . $draftError
            : 'production page should create a draft batch'
    );
    recipeProductionEndpointRuntimeAssert($batch['status'] === 'draft', 'production page should create draft status');
    recipeProductionEndpointRuntimeAssert((int) $batch['created_by'] === 1, 'production page should stamp draft creator');
    recipeProductionEndpointRuntimeAssert(recipeProductionEndpointRuntimeDecimalEquals($batch['planned_output_qty'], '2.000000'), 'production page should persist planned output qty');
    $batchId = (int) $batch['id'];

    $commitChild = recipeProductionEndpointRuntimeRunChild($db, [
        'action' => 'commit',
        'batch_id' => $batchId,
        'actual_output_qty' => '2.000000',
        'variance_reason' => '',
    ]);

    $committed = recipeProductionEndpointRuntimeOne($conn, "SELECT * FROM production_batches WHERE id = {$batchId}");
    recipeProductionEndpointRuntimeAssert($committed !== null, 'committed batch should still exist');
    $commitError = trim((string) ($commitChild['stderr'] ?? ''));
    recipeProductionEndpointRuntimeAssert(
        $committed['status'] === 'committed',
        $commitError !== ''
            ? 'production page should commit draft batch: ' . $commitError
            : 'production page should commit draft batch'
    );
    recipeProductionEndpointRuntimeAssert((int) $committed['committed_by'] === 1, 'production page should stamp committer');
    recipeProductionEndpointRuntimeAssert(recipeProductionEndpointRuntimeDecimalEquals($committed['actual_output_qty'], '2.000000'), 'production page should persist actual output qty');

    $input = recipeProductionEndpointRuntimeOne($conn, "SELECT * FROM inventory_movements WHERE production_batch_id = {$batchId} AND movement_type = 'production_input'");
    $output = recipeProductionEndpointRuntimeOne($conn, "SELECT * FROM inventory_movements WHERE production_batch_id = {$batchId} AND movement_type = 'production_output'");
    recipeProductionEndpointRuntimeAssert($input !== null, 'production commit should write input movement');
    recipeProductionEndpointRuntimeAssert($output !== null, 'production commit should write output movement');
    recipeProductionEndpointRuntimeAssert((int) $input['item_id'] === 3001, 'production input should consume ingredient item');
    recipeProductionEndpointRuntimeAssert((int) $output['item_id'] === 2001, 'production output should create prepared item');
    recipeProductionEndpointRuntimeAssert(recipeProductionEndpointRuntimeDecimalEquals($input['qty_out'], '6.000000'), 'production input should consume exploded ingredient qty');
    recipeProductionEndpointRuntimeAssert(recipeProductionEndpointRuntimeDecimalEquals($input['total_cost'], '15.000000'), 'production input should persist ingredient cost');
    recipeProductionEndpointRuntimeAssert(recipeProductionEndpointRuntimeDecimalEquals($output['qty_in'], '2.000000'), 'production output should add actual output qty');
    recipeProductionEndpointRuntimeAssert(recipeProductionEndpointRuntimeDecimalEquals($output['total_cost'], '15.000000'), 'production output should carry total input cost');

    recipeProductionEndpointRuntimeAssert(
        recipeProductionEndpointRuntimeCount($conn, "SELECT COUNT(*) AS c FROM production_batch_lines WHERE batch_id = {$batchId} AND line_type = 'input'") === 1,
        'production commit should write one input batch line'
    );
    recipeProductionEndpointRuntimeAssert(
        recipeProductionEndpointRuntimeCount($conn, "SELECT COUNT(*) AS c FROM production_batch_lines WHERE batch_id = {$batchId} AND line_type = 'output'") === 1,
        'production commit should write one output batch line'
    );

    $ingredientBalance = recipeProductionEndpointRuntimeBalance($conn, 3001);
    $outputBalance = recipeProductionEndpointRuntimeBalance($conn, 2001);
    recipeProductionEndpointRuntimeAssert(recipeProductionEndpointRuntimeDecimalEquals($ingredientBalance['qty_on_hand'], '14.000000'), 'production commit should reduce ingredient on-hand balance');
    recipeProductionEndpointRuntimeAssert(recipeProductionEndpointRuntimeDecimalEquals($outputBalance['qty_on_hand'], '2.000000'), 'production commit should increase prepared-item on-hand balance');

    recipeProductionEndpointRuntimeRunChild($db, [
        'action' => 'commit',
        'batch_id' => $batchId,
        'actual_output_qty' => '2.000000',
        'variance_reason' => '',
    ]);
    recipeProductionEndpointRuntimeAssert(
        recipeProductionEndpointRuntimeCount($conn, "SELECT COUNT(*) AS c FROM inventory_movements WHERE production_batch_id = {$batchId}") === 2,
        'production commit replay should not duplicate movements after batch is committed'
    );

    echo "recipe-production-endpoint-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipeProductionEndpointRuntimeChild(string $json): void
{
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid child payload.\n");
        exit(9);
    }

    $csrf = 'recipe-production-endpoint-csrf-fixed';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['PHP_SELF'] = 'recipe_production.php';
    $_SERVER['SCRIPT_NAME'] = 'recipe_production.php';
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
    $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN'] = $csrf;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'recipe-production-endpoint-runtime-test';
    $_POST = array_merge($payload, [
        'csrf_token' => $csrf,
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => (int) ($payload['store_id'] ?? 0),
    ]);

    session_id('recipeproduction' . getmypid());
    require_once dirname(__DIR__, 2) . '/includes/session_bootstrap.php';
    $_SESSION['login'] = 'recipe_production_smoke';
    $_SESSION['userid'] = 1;
    $_SESSION['user_id'] = 1;
    $_SESSION['usrole'] = 1;
    $_SESSION['userrole'] = 1;
    $_SESSION['usty'] = 2;
    $_SESSION['posmain_csrf_tokens'] = [
        'recipe_production' => $csrf,
    ];

    register_shutdown_function(static function (): void {
        $flash = $_SESSION['recipe_production_flash'] ?? null;
        if (!is_array($flash) || ($flash['type'] ?? '') !== 'danger') {
            return;
        }

        $message = trim((string) ($flash['message'] ?? ''));
        if ($message !== '') {
            fwrite(STDERR, $message . "\n");
        }
    });

    chdir(dirname(__DIR__, 2));
    require dirname(__DIR__, 2) . '/recipe_production.php';
    exit(0);
}

function recipeProductionEndpointRuntimeRunChild(string $db, array $payload): array
{
    $env = array_merge($_ENV, [
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_SESSION_DRIVER' => 'file',
        'POSMAIN_SYNC_OUTBOX_ENABLED' => '0',
        'POSMAIN_BRANCH_SYNC_ENABLED' => '0',
        'POSMAIN_OPERATIONAL_SYNC_ENABLED' => '0',
        'POSMAIN_RECIPE_MODE' => 'consume_pilot',
        'POSMAIN_RECIPE_CONSUMPTION' => '1',
        'POSMAIN_RECIPE_PILOT_ITEM_IDS' => '2001',
        'POSMAIN_RECIPE_ACCOUNTING' => '0',
        'POSMAIN_RECIPE_AVAILABILITY' => '0',
        'POSMAIN_ROUTER_ENABLED' => '0',
        'POSMAIN_INVENTORY_LEDGER_MODE' => 'off',
        'POSMAIN_INVENTORY_QUANTITY_TRACKING' => '1',
        'POSMAIN_ENV' => 'test',
        'POSMAIN_PRODUCTION_MODE' => '0',
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
        throw new RuntimeException('Unable to start production endpoint child process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Production endpoint child failed with code {$exitCode}: {$stderr}\n{$stdout}");
    }

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function recipeProductionEndpointRuntimeCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lang VARCHAR(20) NULL DEFAULT 'ar',
            edit_pass VARCHAR(191) NULL DEFAULT '',
            def_pos_store INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO settings (lang, edit_pass, def_pos_store) VALUES ('ar', '', 3)");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(191) NULL,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO acc_head (id, code, aname, is_stock, isdeleted)
        VALUES (3, 'STORE-3', 'Operational store', 1, 0)");
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
            edit_items TINYINT(1) NOT NULL DEFAULT 0,
            sid_accounts TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            iname VARCHAR(191) NOT NULL,
            cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            group1 BIGINT UNSIGNED NULL,
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
            requires_recipe_for_sale TINYINT(1) NOT NULL DEFAULT 0,
            allow_sale_without_stock TINYINT(1) NOT NULL DEFAULT 0,
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
        CREATE TABLE production_batches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_uuid CHAR(36) NOT NULL,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            branch_uuid CHAR(36) NULL,
            store_id INT NOT NULL DEFAULT 0,
            recipe_id BIGINT UNSIGNED NOT NULL,
            output_item_id BIGINT UNSIGNED NOT NULL,
            planned_output_qty DECIMAL(18,6) NOT NULL,
            actual_output_qty DECIMAL(18,6) NULL,
            status ENUM('draft','committed','cancelled') NOT NULL DEFAULT 'draft',
            started_at DATETIME NULL,
            committed_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            committed_by BIGINT UNSIGNED NULL,
            variance_reason VARCHAR(255) NULL,
            notes TEXT NULL,
            sync_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_production_batch_uuid (batch_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE production_batch_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id BIGINT UNSIGNED NOT NULL,
            line_type ENUM('input','output','variance') NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            planned_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            actual_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            unit_id BIGINT UNSIGNED NULL,
            unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            total_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            inventory_movement_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_batch_lines_batch (batch_id)
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
            movement_type ENUM('purchase','sale_direct','recipe_consumption','production_input','production_output','waste','adjustment','transfer_in','transfer_out','reservation','reservation_release','refund_reversal','sync_replay','opening_balance') NOT NULL,
            source_type ENUM('order','order_line','invoice','fat_details','recipe','recipe_order_line_usage','production_batch','purchase_invoice','adjustment','reservation','sync_event','manual') NOT NULL,
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
            payload_hash CHAR(64) NOT NULL DEFAULT '',
            metadata_json JSON NULL,
            reversed_movement_id BIGINT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inventory_movement_uuid (movement_uuid),
            UNIQUE KEY uq_inventory_idempotency (pos_tenant, pos_branch, store_id, idempotency_key)
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
}

function recipeProductionEndpointRuntimeSeedCommonRows(mysqli $conn): void
{
    $conn->query("INSERT INTO users (id, uname, password, userrole, usertype, isdeleted) VALUES (1, 'recipe_production_smoke', '', 1, 2, 0)");
    $conn->query("INSERT INTO usr_pwrs (id, rollname, add_stock, edit_stock, edit_items, sid_accounts, isdeleted) VALUES (1, 'admin', 1, 1, 1, 1, 0)");
}

function recipeProductionEndpointRuntimeSeedRecipe(mysqli $conn): void
{
    $conn->query("INSERT INTO myitems (id, iname, cost_price, group1, isdeleted) VALUES (2001, 'Prepared Sauce', '0.000000', 10, 0)");
    $conn->query("INSERT INTO myitems (id, iname, cost_price, group1, isdeleted) VALUES (3001, 'Tomatoes', '2.500000', 11, 0)");
    $conn->query("
        INSERT INTO recipe_headers (
            id, recipe_uuid, pos_tenant, pos_branch, sellable_item_id, recipe_name, recipe_type,
            status, version_number, yield_qty, costing_method, created_by, approved_by, approved_at
        ) VALUES (
            101, '00000000-0000-4000-8000-000000000201', 0, 0, 2001, 'Prepared Sauce Batch', 'batch_prepared',
            'active', 1, '1.000000', 'item_cost_price', 1, 1, NOW()
        )
    ");
    $conn->query("
        INSERT INTO recipe_lines (
            id, recipe_id, line_uuid, ingredient_item_id, line_type, qty_per_yield,
            unit_conversion_to_base, wastage_percent, is_required, order_type, channel, sort_order
        ) VALUES (
            201, 101, '00000000-0000-4000-8000-000000000202', 3001, 'ingredient', '3.000000',
            '1.00000000', '0.0000', 1, 'any', 'any', 1
        )
    ");
}

function recipeProductionEndpointRuntimeSeedBalance(mysqli $conn, int $itemId, string $qty): void
{
    $conn->query("
        INSERT INTO inventory_item_balances
            (pos_tenant, pos_branch, branch_uuid, store_id, item_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost)
        VALUES
            (0, 0, NULL, 3, {$itemId}, '{$qty}', '0.000000', '{$qty}', '0.000000')
    ");
}

function recipeProductionEndpointRuntimeBalance(mysqli $conn, int $itemId): array
{
    $row = recipeProductionEndpointRuntimeOne($conn, "SELECT * FROM inventory_item_balances WHERE item_id = {$itemId} LIMIT 1");
    recipeProductionEndpointRuntimeAssert($row !== null, 'expected inventory balance row');

    return $row;
}

function recipeProductionEndpointRuntimeOne(mysqli $conn, string $sql): ?array
{
    $row = $conn->query($sql)->fetch_assoc();

    return $row ?: null;
}

function recipeProductionEndpointRuntimeCount(mysqli $conn, string $sql): int
{
    $row = recipeProductionEndpointRuntimeOne($conn, $sql);

    return (int) ($row['c'] ?? 0);
}

function recipeProductionEndpointRuntimeDecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === number_format((float) $expected, 6, '.', '');
}

function recipeProductionEndpointRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
