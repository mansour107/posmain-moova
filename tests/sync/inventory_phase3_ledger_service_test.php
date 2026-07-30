<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';

inventoryPhase3AssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase3-ledger-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase3_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    $manager = new SyncSchemaManager();
    $manager->apply($conn);
    inventoryPhase3Assert($manager->pendingStatements($conn) === [], 'phase3 temp schema should be idempotent after apply');

    $bridgeFlags = new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]);
    $ledger = new InventoryLedgerService($bridgeFlags);
    $scope = ['pos_tenant' => 3, 'pos_branch' => 5, 'store_id' => 7, 'branch_uuid' => '00000000-0000-4000-8000-000000000007'];
    $trackedItem = ['item_id' => 1001, 'item_type' => 'ingredient', 'track_stock' => 1];

    $purchase = [
        'scope' => $scope,
        'item_id' => 1001,
        'movement_type' => 'purchase',
        'source_type' => 'purchase_receipt',
        'source_uuid' => 'receipt-1',
        'qty_in' => '10.000000',
        'unit_cost' => '2.000000',
        'idempotency_key' => 'phase3:purchase:1',
        'metadata' => ['source' => 'phase3_test'],
        'created_by' => 9,
    ];
    $purchaseResult = $ledger->recordMovement($conn, $purchase, $trackedItem);
    inventoryPhase3Assert(!$purchaseResult['noop'], 'bridge mode should write tracked inventory movement');
    inventoryPhase3Assert($purchaseResult['writes']['inventory_movements'] !== [], 'purchase should write movement');
    inventoryPhase3Assert($purchaseResult['writes']['inventory_item_balances'] !== [], 'purchase should write balance');
    inventoryPhase3Assert($purchaseResult['writes']['recipe_audit_log'] !== [], 'purchase should write audit when audit table exists');
    $purchaseMovement = inventoryPhase3One($conn, "SELECT * FROM inventory_movements WHERE idempotency_key = 'phase3:purchase:1'");
    inventoryPhase3Assert($purchaseMovement['payload_hash'] !== '', 'purchase movement should store payload hash');
    inventoryPhase3Assert(strpos((string) $purchaseMovement['metadata_json'], 'phase3_test') !== false, 'purchase movement should store metadata json');
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($purchaseMovement['qty_in'], '10.000000'), 'purchase should write qty_in');
    $balance = inventoryPhase3Balance($conn, 1001);
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($balance['qty_on_hand'], '10.000000'), 'purchase should increase on hand');
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($balance['qty_available'], '10.000000'), 'purchase should increase available');
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($balance['moving_average_cost'], '2.000000'), 'first purchase should set moving average cost');

    $replay = $ledger->recordMovement($conn, $purchase, $trackedItem);
    inventoryPhase3Assert(!empty($replay['idempotent_replay']), 'same idempotency key and same payload should replay existing movement');
    inventoryPhase3Assert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE idempotency_key = 'phase3:purchase:1'")->fetch_assoc()['c'] === 1, 'idempotent replay should not duplicate movement');

    $conflict = $purchase;
    $conflict['qty_in'] = '11.000000';
    inventoryPhase3ExpectException(static function () use ($ledger, $conn, $conflict, $trackedItem): void {
        $ledger->recordMovement($conn, $conflict, $trackedItem);
    }, 'duplicate idempotency key with different payload should throw conflict');

    inventoryPhase3ExpectException(static function () use ($ledger, $conn, $scope, $trackedItem): void {
        $ledger->recordMovement($conn, [
            'scope' => $scope,
            'item_id' => 1001,
            'movement_type' => 'sale_direct',
            'source_type' => 'order_line',
            'source_id' => 500,
            'qty_in' => '1.000000',
            'idempotency_key' => 'phase3:sale:wrong-direction',
        ], $trackedItem);
    }, 'sale_direct should not accept inbound quantity');

    inventoryPhase3ExpectException(static function () use ($ledger, $conn, $scope, $trackedItem): void {
        $ledger->recordMovement($conn, [
            'scope' => $scope,
            'item_id' => 1001,
            'movement_type' => 'purchase',
            'source_type' => 'purchase_receipt',
            'source_uuid' => 'receipt-wrong-direction',
            'qty_out' => '1.000000',
            'idempotency_key' => 'phase3:purchase:wrong-direction',
        ], $trackedItem);
    }, 'purchase should not accept outbound quantity');

    inventoryPhase3ExpectException(static function () use ($ledger, $conn, $scope, $trackedItem): void {
        $ledger->recordMovement($conn, [
            'scope' => $scope,
            'item_id' => 1001,
            'movement_type' => 'unknown_movement',
            'source_type' => 'manual',
            'qty_out' => '1.000000',
            'idempotency_key' => 'phase3:unknown-movement',
        ], $trackedItem);
    }, 'unknown movement type should be rejected');

    $saleResult = $ledger->recordMovement($conn, [
        'scope' => $scope,
        'item_id' => 1001,
        'movement_type' => 'sale_direct',
        'source_type' => 'order_line',
        'source_id' => 501,
        'qty_out' => '3.000000',
        'unit_cost' => '2.000000',
        'idempotency_key' => 'phase3:sale:1',
    ], $trackedItem);
    inventoryPhase3Assert(!$saleResult['noop'], 'outbound movement should write in bridge mode');
    $balance = inventoryPhase3Balance($conn, 1001);
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($balance['qty_on_hand'], '7.000000'), 'outbound should decrease on hand');
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($balance['qty_available'], '7.000000'), 'outbound should decrease available');

    $reservationResult = $ledger->recordMovement($conn, [
        'scope' => $scope,
        'item_id' => 1001,
        'movement_type' => 'reservation',
        'source_type' => 'reservation',
        'source_id' => 77,
        'qty_reserved' => '2.000000',
        'idempotency_key' => 'phase3:reservation:1',
    ], $trackedItem);
    inventoryPhase3Assert(!$reservationResult['noop'], 'reservation should write neutral movement in bridge mode');
    $reservationMovement = inventoryPhase3One($conn, "SELECT * FROM inventory_movements WHERE idempotency_key = 'phase3:reservation:1'");
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($reservationMovement['qty_in'], '0.000000'), 'reservation movement should be neutral qty_in');
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($reservationMovement['qty_out'], '0.000000'), 'reservation movement should be neutral qty_out');
    inventoryPhase3Assert(strpos((string) $reservationMovement['metadata_json'], '2.000000') !== false, 'reservation qty should be stored in metadata');
    $balance = inventoryPhase3Balance($conn, 1001);
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($balance['qty_reserved'], '2.000000'), 'reservation should increase reserved');
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($balance['qty_available'], '5.000000'), 'reservation should decrease available');

    $ledger->recordMovement($conn, [
        'scope' => $scope,
        'item_id' => 1001,
        'movement_type' => 'reservation_release',
        'source_type' => 'reservation',
        'source_id' => 77,
        'qty_reserved' => '1.000000',
        'idempotency_key' => 'phase3:reservation-release:1',
    ], $trackedItem);
    $balance = inventoryPhase3Balance($conn, 1001);
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($balance['qty_reserved'], '1.000000'), 'reservation release should decrease reserved');
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($balance['qty_available'], '6.000000'), 'reservation release should increase available');

    $ledger->recordMovement($conn, [
        'scope' => $scope,
        'item_id' => 1001,
        'movement_type' => 'purchase',
        'source_type' => 'purchase_receipt',
        'source_uuid' => 'receipt-2',
        'qty_in' => '3.000000',
        'unit_cost' => '8.000000',
        'idempotency_key' => 'phase3:purchase:2',
    ], $trackedItem);
    $balance = inventoryPhase3Balance($conn, 1001);
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($balance['qty_on_hand'], '10.000000'), 'second purchase should increase on hand');
    inventoryPhase3Assert(inventoryPhase3DecimalEquals($balance['moving_average_cost'], '3.800000'), 'second purchase should update moving average cost');

    $strictLedger = new InventoryLedgerService(new InventoryFeatureFlags(['inventory' => [
        'ledger_mode' => 'bridge',
        'strict_stock' => '1',
    ]]));
    $strictSale = $strictLedger->recordMovement($conn, [
        'scope' => $scope,
        'item_id' => 1001,
        'movement_type' => 'sale_direct',
        'source_type' => 'order_line',
        'source_id' => 999,
        'qty_out' => '999.000000',
        'idempotency_key' => 'phase3:sale:strict-permissive',
    ], $trackedItem);
    inventoryPhase3Assert(
        InventoryDecimal::compare($strictSale['balance']['qty_on_hand'], '0') < 0,
        'legacy strict stock must not block an otherwise valid sale'
    );

    $strictReservation = $strictLedger->recordMovement($conn, [
        'scope' => $scope,
        'item_id' => 1001,
        'movement_type' => 'reservation',
        'source_type' => 'reservation',
        'source_id' => 999,
        'qty_reserved' => '999.000000',
        'idempotency_key' => 'phase3:reservation:strict-permissive',
    ], $trackedItem);
    inventoryPhase3Assert(
        InventoryDecimal::compare($strictReservation['balance']['qty_available'], '0') < 0,
        'legacy strict stock must not block a reservation required to complete a sale'
    );

    $conn->query("CREATE TABLE settings (
        id INT NOT NULL PRIMARY KEY,
        negative_stock_sale_policy ENUM('block','allow_with_warning') NULL
    ) ENGINE=InnoDB");
    $conn->query("INSERT INTO settings (id, negative_stock_sale_policy) VALUES (1, 'block')");

    $savedBlockLedger = new InventoryLedgerService(new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'bridge',
            'strict_stock' => false,
        ],
        'recipe' => [
            'allow_negative_stock_with_approval' => true,
        ],
    ]));
    $savedBlockResult = $savedBlockLedger->recordMovement($conn, [
        'scope' => $scope,
        'item_id' => 1101,
        'movement_type' => 'sale_direct',
        'source_type' => 'order_line',
        'source_id' => 1101,
        'qty_out' => '1.000000',
        'idempotency_key' => 'phase3:sale:saved-policy-permissive',
    ], ['item_id' => 1101, 'item_type' => 'sellable', 'track_stock' => 1]);
    inventoryPhase3Assert(
        inventoryPhase3DecimalEquals($savedBlockResult['balance']['qty_on_hand'], '-1.000000'),
        'legacy saved block policy must be adapted to the permissive V1 sale policy'
    );

    $conn->query("UPDATE settings SET negative_stock_sale_policy = 'allow_with_warning' WHERE id = 1");
    $savedAllowLedger = new InventoryLedgerService(new InventoryFeatureFlags(['inventory' => [
        'ledger_mode' => 'bridge',
        'strict_stock' => true,
    ]]));
    $savedAllowResult = $savedAllowLedger->recordMovement($conn, [
        'scope' => $scope,
        'item_id' => 1102,
        'movement_type' => 'sale_direct',
        'source_type' => 'order_line',
        'source_id' => 1102,
        'order_id' => 7002,
        'qty_out' => '2.000000',
        'idempotency_key' => 'phase3:sale:saved-policy-warn',
        'created_by' => 9,
    ], ['item_id' => 1102, 'item_type' => 'sellable', 'track_stock' => 1]);
    inventoryPhase3Assert(
        inventoryPhase3DecimalEquals($savedAllowResult['balance']['qty_on_hand'], '-2.000000'),
        'saved allow-with-warning policy should override legacy strict stock for sales'
    );
    inventoryPhase3Assert(
        count($savedAllowResult['writes']['security_audit_log']) === 1,
        'allowed negative sale should return its security audit write'
    );
    $warningAudit = inventoryPhase3One($conn, "SELECT * FROM security_audit_log WHERE event_type = 'negative_stock_sale_warning' ORDER BY id DESC LIMIT 1");
    inventoryPhase3Assert((int) $warningAudit['target_id'] === (int) $savedAllowResult['movement_id'], 'negative warning should identify the inventory movement');
    inventoryPhase3Assert(strpos((string) $warningAudit['metadata_json'], 'allow_with_warning') !== false, 'negative warning should record resolved policy');
    $warningCountBeforeReplay = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM security_audit_log WHERE event_type = 'negative_stock_sale_warning'"
    )->fetch_assoc()['c'];

    $savedAllowReplay = $savedAllowLedger->recordMovement($conn, [
        'scope' => $scope,
        'item_id' => 1102,
        'movement_type' => 'sale_direct',
        'source_type' => 'order_line',
        'source_id' => 1102,
        'order_id' => 7002,
        'qty_out' => '2.000000',
        'idempotency_key' => 'phase3:sale:saved-policy-warn',
        'created_by' => 9,
    ], ['item_id' => 1102, 'item_type' => 'sellable', 'track_stock' => 1]);
    inventoryPhase3Assert(!empty($savedAllowReplay['idempotent_replay']), 'allowed negative sale should remain idempotent');
    inventoryPhase3Assert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM security_audit_log WHERE event_type = 'negative_stock_sale_warning'")->fetch_assoc()['c'] === $warningCountBeforeReplay,
        'idempotent replay should not duplicate the negative-stock audit'
    );

    $serviceResult = $ledger->recordMovement($conn, [
        'scope' => $scope,
        'item_id' => 2002,
        'movement_type' => 'purchase',
        'source_type' => 'purchase_receipt',
        'qty_in' => '5.000000',
        'idempotency_key' => 'phase3:service:noop',
    ], ['item_id' => 2002, 'item_type' => 'service', 'track_stock' => 1]);
    inventoryPhase3Assert($serviceResult['noop'] === true, 'service item should not move stock');
    inventoryPhase3Assert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE item_id = 2002")->fetch_assoc()['c'] === 0, 'service item noop should not create movement');

    echo "inventory-phase3-ledger-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase3AssertSourceContracts(string $root): void
{
    $source = inventoryPhase3Source($root . '/classes/Inventory/InventoryLedgerService.php');
    foreach (['FOR UPDATE', 'payload_hash', 'findByIdempotencyKey', 'NegativeStockSalePolicyService', 'negative_stock_sale_warning', 'shouldMirrorLegacyStock'] as $needle) {
        inventoryPhase3Assert(strpos($source, $needle) !== false, 'ledger service should contain phase3 guard: ' . $needle);
    }
    $balanceRepository = inventoryPhase3Source($root . '/classes/Recipe/Repository/InventoryBalanceRepository.php');
    inventoryPhase3Assert(
        strpos($balanceRepository, 'id = LAST_INSERT_ID(id)') !== false,
        'balance upsert should return the existing balance row id on updates'
    );
    $runtimeReferences = inventoryPhase3RuntimeReferences($root, 'InventoryLedgerService');
    $allowedLaterPhaseReferences = [
        'classes/Recipe/RecipeInventoryMovementService.php',
    ];
    $unexpectedReferences = array_values(array_filter($runtimeReferences, static function (string $relative) use ($allowedLaterPhaseReferences): bool {
        return !in_array($relative, $allowedLaterPhaseReferences, true);
    }));
    inventoryPhase3Assert($unexpectedReferences === [], 'phase3 should not wire ledger service into unexpected runtime endpoints: ' . implode(', ', $unexpectedReferences));
}

function inventoryPhase3RuntimeReferences(string $root, string $needle): array
{
    $matches = [];
    foreach (inventoryPhase3PhpFiles($root) as $relative) {
        $source = inventoryPhase3Source($root . '/' . $relative);
        if (strpos($source, $needle) !== false) {
            $matches[] = $relative;
        }
    }

    return $matches;
}

function inventoryPhase3PhpFiles(string $root): array
{
    $excludedDirs = ['.git', 'vendor', 'node_modules', 'tests', 'tools', 'docs', 'dbase', 'classes/Inventory'];
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current) use ($root, $excludedDirs): bool {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($current->getPathname(), strlen($root) + 1));
                foreach ($excludedDirs as $excludedDir) {
                    if ($relative === $excludedDir || strpos($relative, $excludedDir . '/') === 0) {
                        return false;
                    }
                }
                return true;
            }
        )
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }

    sort($files);
    return $files;
}

function inventoryPhase3One(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryPhase3Assert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryPhase3Balance(mysqli $conn, int $itemId): array
{
    return inventoryPhase3One($conn, "SELECT * FROM inventory_item_balances WHERE item_id = {$itemId} LIMIT 1");
}

function inventoryPhase3DecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === $expected;
}

function inventoryPhase3ExpectException(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return;
    }

    throw new RuntimeException($message);
}

function inventoryPhase3Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase3Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
