<?php

if (($argv[1] ?? '') === '--child') {
    inventoryAdjustmentEndpointRuntimeChild($argv[2] ?? '');
}

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryDecimal.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';

inventoryAdjustmentEndpointRuntimeAssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-adjustment-endpoint-runtime-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = 'posmain_inventory_adjustment_endpoint_' . getmypid();
$conn->query("DROP DATABASE IF EXISTS `{$db}`");
$conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$conn->select_db($db);
$conn->set_charset('utf8mb4');

try {
    inventoryAdjustmentEndpointRuntimeCreateLegacyTables($conn);
    (new SyncSchemaManager())->apply($conn);
    inventoryAdjustmentEndpointRuntimeSeedCommonRows($conn);
    inventoryAdjustmentEndpointRuntimeSeedStock($conn, 7701, '10.000000', '3.000000', 'seed-7701');

    $wasteUuid = '77777777-aaaa-4aaa-8aaa-777777777701';
    $wasteResponse = inventoryAdjustmentEndpointRuntimeRunChild($db, [
        'action' => 'waste',
        'operation_uuid' => $wasteUuid,
        'store_id' => 3,
        'item_id' => 7701,
        'qty' => '2.000000',
        'unit_cost' => '3.000000',
        'reason' => 'inventory endpoint waste smoke',
        'occurred_at' => date('Y-m-d'),
    ]);
    inventoryAdjustmentEndpointRuntimeAssert(!empty($wasteResponse['success']), 'inventory adjustment endpoint should record waste');
    inventoryAdjustmentEndpointRuntimeAssert(($wasteResponse['movement_type'] ?? '') === 'waste', 'waste response should identify movement type');

    $waste = inventoryAdjustmentEndpointRuntimeOne($conn, "SELECT * FROM inventory_movements WHERE source_uuid = 'waste:{$wasteUuid}' LIMIT 1");
    inventoryAdjustmentEndpointRuntimeAssert($waste['movement_type'] === 'waste', 'waste endpoint should write waste movement type');
    inventoryAdjustmentEndpointRuntimeAssert($waste['source_type'] === 'adjustment', 'waste endpoint should use inventory adjustment source type');
    inventoryAdjustmentEndpointRuntimeAssert(inventoryAdjustmentEndpointRuntimeDecimalEquals($waste['qty_out'], '2.000000'), 'waste endpoint should write qty_out');
    inventoryAdjustmentEndpointRuntimeAssert(inventoryAdjustmentEndpointRuntimeDecimalEquals($waste['total_cost'], '6.000000'), 'waste endpoint should write total cost');
    inventoryAdjustmentEndpointRuntimeAssert((int) $waste['created_by'] === 1, 'waste endpoint should stamp actor user');

    $balanceAfterWaste = inventoryAdjustmentEndpointRuntimeBalance($conn, 7701);
    inventoryAdjustmentEndpointRuntimeAssert(inventoryAdjustmentEndpointRuntimeDecimalEquals($balanceAfterWaste['qty_on_hand'], '8.000000'), 'waste endpoint should reduce on-hand balance');
    inventoryAdjustmentEndpointRuntimeAssert(inventoryAdjustmentEndpointRuntimeDecimalEquals($balanceAfterWaste['qty_available'], '8.000000'), 'waste endpoint should reduce available balance');

    $wasteReplay = inventoryAdjustmentEndpointRuntimeRunChild($db, [
        'action' => 'waste',
        'operation_uuid' => $wasteUuid,
        'store_id' => 3,
        'item_id' => 7701,
        'qty' => '2.000000',
        'unit_cost' => '3.000000',
        'reason' => 'inventory endpoint waste smoke',
        'occurred_at' => date('Y-m-d'),
    ]);
    inventoryAdjustmentEndpointRuntimeAssert(!empty($wasteReplay['idempotent_replay']), 'waste endpoint replay should report idempotency');
    inventoryAdjustmentEndpointRuntimeAssert(
        inventoryAdjustmentEndpointRuntimeCount($conn, "SELECT COUNT(*) AS c FROM inventory_movements WHERE source_uuid = 'waste:{$wasteUuid}'") === 1,
        'waste endpoint replay should not duplicate movement'
    );
    $balanceAfterReplay = inventoryAdjustmentEndpointRuntimeBalance($conn, 7701);
    inventoryAdjustmentEndpointRuntimeAssert(inventoryAdjustmentEndpointRuntimeDecimalEquals($balanceAfterReplay['qty_on_hand'], '8.000000'), 'waste endpoint replay should not change balance');

    $adjustmentUuid = '77777777-bbbb-4bbb-8bbb-777777777702';
    $adjustmentResponse = inventoryAdjustmentEndpointRuntimeRunChild($db, [
        'action' => 'adjustment',
        'operation_uuid' => $adjustmentUuid,
        'store_id' => 3,
        'item_id' => 7701,
        'direction' => 'increase',
        'qty' => '5.000000',
        'unit_cost' => '4.000000',
        'reason' => 'inventory endpoint adjustment smoke',
        'occurred_at' => date('Y-m-d'),
    ]);
    inventoryAdjustmentEndpointRuntimeAssert(!empty($adjustmentResponse['success']), 'inventory adjustment endpoint should record stock adjustment');
    inventoryAdjustmentEndpointRuntimeAssert(($adjustmentResponse['movement_type'] ?? '') === 'adjustment', 'adjustment response should identify movement type');

    $adjustment = inventoryAdjustmentEndpointRuntimeOne($conn, "SELECT * FROM inventory_movements WHERE source_uuid = 'adjustment:{$adjustmentUuid}' LIMIT 1");
    inventoryAdjustmentEndpointRuntimeAssert($adjustment['movement_type'] === 'adjustment', 'adjustment endpoint should write adjustment movement type');
    inventoryAdjustmentEndpointRuntimeAssert($adjustment['source_type'] === 'adjustment', 'adjustment endpoint should use inventory adjustment source type');
    inventoryAdjustmentEndpointRuntimeAssert(inventoryAdjustmentEndpointRuntimeDecimalEquals($adjustment['qty_in'], '5.000000'), 'increase adjustment should write qty_in');
    inventoryAdjustmentEndpointRuntimeAssert(inventoryAdjustmentEndpointRuntimeDecimalEquals($adjustment['qty_out'], '0.000000'), 'increase adjustment should not write qty_out');
    inventoryAdjustmentEndpointRuntimeAssert(inventoryAdjustmentEndpointRuntimeDecimalEquals($adjustment['total_cost'], '20.000000'), 'adjustment endpoint should write total cost');

    $balanceAfterAdjustment = inventoryAdjustmentEndpointRuntimeBalance($conn, 7701);
    inventoryAdjustmentEndpointRuntimeAssert(inventoryAdjustmentEndpointRuntimeDecimalEquals($balanceAfterAdjustment['qty_on_hand'], '13.000000'), 'adjustment endpoint should increase on-hand balance');
    inventoryAdjustmentEndpointRuntimeAssert(inventoryAdjustmentEndpointRuntimeDecimalEquals($balanceAfterAdjustment['qty_available'], '13.000000'), 'adjustment endpoint should increase available balance');

    echo "inventory-adjustment-endpoint-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function inventoryAdjustmentEndpointRuntimeChild(string $json): void
{
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid child payload.\n");
        exit(9);
    }

    $csrf = 'inventory-adjustment-endpoint-csrf-fixed';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['PHP_SELF'] = 'ajax/inventory_adjustment.php';
    $_SERVER['SCRIPT_NAME'] = 'ajax/inventory_adjustment.php';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
    $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN'] = $csrf;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'inventory-adjustment-endpoint-runtime-test';
    $_POST = array_merge($payload, [
        'csrf_token' => $csrf,
        'pos_tenant' => 0,
        'pos_branch' => 0,
    ]);
    $_FILES = [];

    session_id('invadjust' . getmypid());
    require_once dirname(__DIR__, 2) . '/includes/session_bootstrap.php';
    $_SESSION['login'] = 'inventory_adjustment_smoke';
    $_SESSION['userid'] = 1;
    $_SESSION['user_id'] = 1;
    $_SESSION['usrole'] = 1;
    $_SESSION['userrole'] = 1;
    $_SESSION['usty'] = 2;
    $_SESSION['posmain_auth_version'] = 1;
    $_SESSION['posmain_csrf_tokens'] = [
        'inventory_adjustment' => $csrf,
    ];

    chdir(dirname(__DIR__, 2));
    require dirname(__DIR__, 2) . '/ajax/inventory_adjustment.php';
    exit(0);
}

function inventoryAdjustmentEndpointRuntimeAssertSourceContracts(string $root): void
{
    $page = inventoryAdjustmentEndpointRuntimeSource($root . '/inventory_adjustments.php');
    foreach ([
        'inventoryAdjustmentMovementLabel',
        'inventoryAdjustmentDirectionLabel',
        "'waste' => 'هالك'",
        "'adjustment' => 'تسوية مخزون'",
        "'in' => 'إضافة'",
        "'out' => 'خصم'",
    ] as $needle) {
        inventoryAdjustmentEndpointRuntimeAssert(strpos($page, $needle) !== false, 'adjustment page should label recent movement tokens in Arabic: ' . $needle);
    }

    $endpoint = inventoryAdjustmentEndpointRuntimeSource($root . '/ajax/inventory_adjustment.php');
    foreach ([
        'InventoryAdjustmentService.php',
        "require_csrf('inventory_adjustment')",
        "require_permission('inventory.edit'",
        'inventoryAdjustmentStoreWastePhoto',
        'posmain_store_image_upload_with_details',
        'uploads/inventory_waste',
        'recordWaste',
        'recordAdjustment',
        'WASTE_PHOTO_WASTE_ONLY',
    ] as $needle) {
        inventoryAdjustmentEndpointRuntimeAssert(strpos($endpoint, $needle) !== false, 'adjustment endpoint should preserve guarded runtime contract: ' . $needle);
    }

    $service = inventoryAdjustmentEndpointRuntimeSource($root . '/classes/Inventory/InventoryAdjustmentService.php');
    foreach ([
        'recordWaste',
        'recordAdjustment',
        "'movement_type' => \$movementType",
        "'source_type' => 'adjustment'",
        "\$movementType . ':' . \$normalized['operation_uuid']",
        'InventoryLedgerService',
        'postWaste',
        'postAdjustment',
        'NON_STOCK_ITEM_CANNOT_BE_ADJUSTED',
        'NEGATIVE_RESULT_APPROVAL_REQUIRED',
        'metadata',
        'photo_attachment',
    ] as $needle) {
        inventoryAdjustmentEndpointRuntimeAssert(strpos($service, $needle) !== false, 'adjustment service should preserve ledger write contract: ' . $needle);
    }

    $docs = inventoryAdjustmentEndpointRuntimeSource($root . '/docs/inventory/phase9_adjustment_contracts.md');
    foreach ([
        '`ajax/inventory_adjustment.php` is POST-only',
        'Photo attachment for waste',
        'InventoryAdjustmentService',
        'idempotent waste replay',
        'stock adjustment balance assertions',
        'recent-operation table translates',
    ] as $needle) {
        inventoryAdjustmentEndpointRuntimeAssert(strpos($docs, $needle) !== false, 'phase9 docs should preserve endpoint runtime proof: ' . $needle);
    }
}

function inventoryAdjustmentEndpointRuntimeRunChild(string $db, array $payload): array
{
    $env = array_merge($_ENV, [
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: (getenv('POSMAIN_DB_PORT') ?: '3307'),
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: (getenv('POSMAIN_DB_USER') ?: 'root'),
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: (getenv('POSMAIN_DB_PASS') ?: ''),
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_SESSION_DRIVER' => 'file',
        'POSMAIN_SYNC_OUTBOX_ENABLED' => '0',
        'POSMAIN_INVENTORY_LEDGER_MODE' => 'bridge',
        'POSMAIN_INVENTORY_ACCOUNTING' => '0',
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
        throw new RuntimeException('Unable to start inventory adjustment endpoint child process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Inventory adjustment endpoint child failed with code {$exitCode}: {$stderr}\n{$stdout}");
    }

    $decoded = json_decode(trim((string) $stdout), true);
    if (!is_array($decoded) || empty($decoded['success'])) {
        throw new RuntimeException("Inventory adjustment endpoint child returned failure: {$stderr}\n{$stdout}");
    }

    return $decoded;
}

function inventoryAdjustmentEndpointRuntimeCreateLegacyTables(mysqli $conn): void
{
    $conn->query("
CREATE TABLE settings (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  lang VARCHAR(20) NULL DEFAULT 'ar',
  edit_pass VARCHAR(191) NULL DEFAULT '',
  def_pos_store INT NOT NULL DEFAULT 3,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("INSERT INTO settings (lang, def_pos_store, isdeleted) VALUES ('ar', 3, 0)");
    $conn->query("
CREATE TABLE towns (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tname VARCHAR(191) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE users (
  id INT NOT NULL PRIMARY KEY,
  uname VARCHAR(191) NOT NULL,
  password VARCHAR(255) NULL,
  userrole INT NULL,
  usertype INT NULL,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE usr_pwrs (
  id INT NOT NULL PRIMARY KEY,
  rollname VARCHAR(191) NULL,
  info VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  add_stock TINYINT(1) NOT NULL DEFAULT 0,
  edit_stock TINYINT(1) NOT NULL DEFAULT 0,
  sid_reports TINYINT(1) NOT NULL DEFAULT 0,
  sid_accounts TINYINT(1) NOT NULL DEFAULT 0,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE myitems (
  id INT NOT NULL PRIMARY KEY,
  iname VARCHAR(200) NOT NULL,
  barcode VARCHAR(100) NULL,
  itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  last_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
  track_stock TINYINT(1) NOT NULL DEFAULT 1,
  base_unit_id INT NULL,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE acc_head (
  id INT NOT NULL PRIMARY KEY,
  code VARCHAR(50) NULL,
  aname VARCHAR(191) NULL,
  parent_id INT NULL,
  is_basic TINYINT(1) NOT NULL DEFAULT 0,
  is_stock TINYINT(1) NOT NULL DEFAULT 0,
  is_fund TINYINT(1) NOT NULL DEFAULT 0,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryAdjustmentEndpointRuntimeSeedCommonRows(mysqli $conn): void
{
    $conn->query("INSERT INTO users (id, uname, password, userrole, usertype, isdeleted) VALUES (1, 'inventory_adjustment_smoke', '', 1, 2, 0)");
    $conn->query("INSERT INTO acc_head (id, code, aname, is_stock, isdeleted) VALUES (3, '130003', 'Operational Stock', 1, 0)");
    $owner = inventoryAdjustmentEndpointRuntimeOne($conn, "SELECT id, role_key FROM usr_pwrs WHERE id = 1 LIMIT 1");
    inventoryAdjustmentEndpointRuntimeAssert(
        is_array($owner) && ($owner['role_key'] ?? '') === 'owner',
        'schema setup should seed the owner role used by the endpoint fixture'
    );
    $conn->query("
        INSERT INTO myitems (id, iname, barcode, itmqty, cost_price, last_price, item_type, track_stock, isdeleted)
        VALUES (7701, 'Inventory endpoint ingredient', 'IE-7701', 0.000000, 0.000000, 0.000000, 'ingredient', 1, 0)
    ");
}

function inventoryAdjustmentEndpointRuntimeSeedStock(mysqli $conn, int $itemId, string $qty, string $unitCost, string $key): void
{
    $flags = new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]);
    $ledger = new InventoryLedgerService($flags);
    $conn->begin_transaction();
    $ledger->recordMovement($conn, [
        'scope' => [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 3,
        ],
        'item_id' => $itemId,
        'movement_type' => 'opening_balance',
        'source_type' => 'manual',
        'source_uuid' => 'inventory-endpoint:' . $key,
        'qty_in' => $qty,
        'unit_cost' => $unitCost,
        'total_cost' => InventoryDecimal::multiply($qty, $unitCost),
        'idempotency_key' => 'inventory-adjustment-endpoint:' . $key,
        'metadata' => ['source' => 'inventory_adjustment_endpoint_test'],
        'created_by' => 1,
    ], ['id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1], ['manage_transaction' => false]);
    $conn->commit();
}

function inventoryAdjustmentEndpointRuntimeBalance(mysqli $conn, int $itemId): array
{
    return inventoryAdjustmentEndpointRuntimeOne($conn, "SELECT * FROM inventory_item_balances WHERE item_id = {$itemId} AND store_id = 3 LIMIT 1");
}

function inventoryAdjustmentEndpointRuntimeOne(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryAdjustmentEndpointRuntimeAssert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryAdjustmentEndpointRuntimeCount(mysqli $conn, string $sql): int
{
    $row = inventoryAdjustmentEndpointRuntimeOne($conn, $sql);

    return (int) ($row['c'] ?? 0);
}

function inventoryAdjustmentEndpointRuntimeDecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === $expected;
}

function inventoryAdjustmentEndpointRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inventoryAdjustmentEndpointRuntimeSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}
