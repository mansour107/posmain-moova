<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryUnpaidSaleReclassificationService.php';
require_once $root . '/classes/Inventory/InventoryAccountingReconciliationService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-unpaid-sale-reclassification-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = 'posmain_inventory_unpaid_reclass_' . getmypid();
$backup = sys_get_temp_dir() . '/posmain_unpaid_reclass_' . getmypid() . '.sql';
$conn->query("DROP DATABASE IF EXISTS `{$db}`");
$conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$conn->select_db($db);

try {
    (new SyncSchemaManager())->apply($conn);
    $conn->query("CREATE TABLE ot_head (
        id INT PRIMARY KEY,
        payment_status VARCHAR(32) NOT NULL,
        invoice_status VARCHAR(32) NOT NULL DEFAULT 'draft',
        order_status VARCHAR(32) NOT NULL DEFAULT 'active',
        closed INT NOT NULL DEFAULT 0,
        isdeleted TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query('CREATE TABLE order_payments (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, amount DECIMAL(18,2) NOT NULL, is_voided TINYINT DEFAULT 0) ENGINE=InnoDB');
    $conn->query("CREATE TABLE myitems (
        id INT PRIMARY KEY,
        itmqty DECIMAL(18,6) NOT NULL,
        item_type VARCHAR(32) NOT NULL DEFAULT 'ingredient',
        track_stock TINYINT NOT NULL DEFAULT 1,
        isdeleted TINYINT DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query('CREATE TABLE settings (id INT PRIMARY KEY, def_pos_store INT NOT NULL) ENGINE=InnoDB');
    $conn->query('CREATE TABLE acc_head (id INT PRIMARY KEY, is_stock TINYINT NOT NULL DEFAULT 0, isdeleted TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $conn->query('CREATE TABLE journal_heads (id INT AUTO_INCREMENT PRIMARY KEY, details VARCHAR(255) NULL) ENGINE=InnoDB');
    $conn->query('CREATE TABLE journal_entries (id INT AUTO_INCREMENT PRIMARY KEY, journal_id INT NOT NULL, debit DECIMAL(18,2) NOT NULL DEFAULT 0, credit DECIMAL(18,2) NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $conn->query("INSERT INTO ot_head (id,payment_status) VALUES (101,'unpaid'),(102,'paid'),(103,'partial'),(104,'unpaid')");
    $conn->query("INSERT INTO myitems VALUES (7001,4.000000,'ingredient',1,0)");
    $conn->query("INSERT INTO myitems VALUES (7002,0.000000,'service',0,0)");
    $conn->query('INSERT INTO settings VALUES (1,27)');
    $conn->query('INSERT INTO acc_head VALUES (27,1,0),(274,1,0)');
    $conn->query('INSERT INTO order_payments (order_id,amount) VALUES (102,20.00),(103,1.00)');
    file_put_contents($backup, 'isolated unpaid-sale reclassification backup');

    $flags = new InventoryFeatureFlags([
        'inventory' => ['ledger_mode' => 'live', 'legacy_mirror' => false],
        'sync' => ['operational_sync_enabled' => false],
    ]);
    $ledger = new InventoryLedgerService($flags);
    unpaidReclassMovement($conn, $ledger, 'purchase', 0, '10.000000', 0, 'seed:purchase');
    $unpaidId = unpaidReclassMovement($conn, $ledger, 'sale_direct', 101, '3.000000', 1, 'seed:unpaid');
    $paidId = unpaidReclassMovement($conn, $ledger, 'sale_direct', 102, '2.000000', 2, 'seed:paid');
    $partialId = unpaidReclassMovement($conn, $ledger, 'sale_direct', 103, '1.000000', 3, 'seed:partial');
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 27],
        'item_id' => 7002,
        'movement_type' => 'sale_direct',
        'source_type' => 'fat_details',
        'source_id' => 4,
        'order_id' => 104,
        'fat_detail_id' => 4,
        'qty_out' => '1.000000',
        'idempotency_key' => 'seed:non-stock-unpaid',
    ], ['id' => 7002, 'item_type' => 'ingredient', 'track_stock' => 1], ['enforce_negative_policy' => false]);
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 274],
        'item_id' => 7001,
        'movement_type' => 'purchase',
        'source_type' => 'manual',
        'qty_in' => '5.000000',
        'unit_cost' => '10.000000',
        'total_cost' => '50.000000',
        'idempotency_key' => 'seed:legacy-store',
    ], ['id' => 7001, 'item_type' => 'ingredient', 'track_stock' => 1]);

    $service = new InventoryUnpaidSaleReclassificationService($ledger);
    $blocked = $service->plan($conn);
    unpaidReclassAssert(!$blocked['ok'], 'partial order with captured tender must block automatic stock reclassification');
    unpaidReclassAssert(
        ($blocked['blockers'][0]['code'] ?? '') === 'unpaid_order_has_captured_payment',
        'captured tender conflict should be explicit'
    );

    $conn->query('UPDATE order_payments SET is_voided=1 WHERE order_id=103');
    $plan = $service->plan($conn);
    unpaidReclassAssert($plan['ok'] && (int) $plan['summary']['entry_count'] === 2, 'only zero-tender unpaid/partial sales should be planned');
    unpaidReclassAssert((int) $plan['summary']['skipped_count'] === 1, 'non-stock draft residue must be delegated to non-stock neutralization');
    unpaidReclassAssert(
        array_column($plan['entries'], 'source_movement_id') === [$unpaidId, $partialId],
        'paid sale must remain untouched'
    );

    $before = unpaidReclassScalar($conn, 'SELECT COUNT(*) FROM inventory_movements');
    $rehearsal = $service->rehearse($conn);
    unpaidReclassAssert($rehearsal['ok'] && unpaidReclassScalar($conn, 'SELECT COUNT(*) FROM inventory_movements') === $before, 'rehearsal must roll back');
    try {
        $service->apply($conn, str_repeat('a', 64), $backup);
        unpaidReclassAssert(false, 'changed manifest must be rejected');
    } catch (RuntimeException $exception) {
        unpaidReclassAssert($exception->getMessage() === 'INVENTORY_UNPAID_SALE_RECLASS_MANIFEST_CHANGED', 'expected manifest mismatch');
    }

    $applied = $service->apply($conn, (string) $plan['manifest_hash'], $backup);
    unpaidReclassAssert($applied['ok'] && (int) $applied['summary']['applied_entry_count'] === 2, 'reviewed repair should apply both draft corrections');
    $balance = $conn->query('SELECT qty_on_hand,qty_reserved,qty_available FROM inventory_item_balances WHERE store_id=27 AND item_id=7001')->fetch_assoc();
    unpaidReclassAssert(InventoryDecimal::normalize($balance['qty_on_hand']) === '8.000000', 'only the paid sale may remain depleted');
    unpaidReclassAssert(InventoryDecimal::normalize($balance['qty_reserved']) === '4.000000', 'draft quantities must become reservations');
    unpaidReclassAssert(InventoryDecimal::normalize($balance['qty_available']) === '4.000000', 'availability must reflect restored stock less draft reservations');
    unpaidReclassAssert(
        InventoryDecimal::normalize($conn->query('SELECT itmqty FROM myitems WHERE id=7001')->fetch_assoc()['itmqty']) === '8.000000',
        'legacy compatibility mirror must match durable on-hand'
    );
    unpaidReclassAssert(
        unpaidReclassScalar($conn, "SELECT COUNT(*) FROM inventory_movements WHERE reversed_movement_id={$unpaidId}") === 1,
        'restoration must retain an explicit immutable link to the source sale'
    );
    unpaidReclassAssert(
        unpaidReclassScalar($conn, "SELECT COUNT(*) FROM inventory_movements WHERE id={$paidId} AND movement_type='sale_direct'") === 1,
        'paid sale source must remain unchanged'
    );
    $accounting = (new InventoryAccountingReconciliationService())->review($conn, ['limit' => 20]);
    $accountingKeys = array_column($accounting['rows'], 'review_key');
    unpaidReclassAssert(
        !in_array('missing:sale_direct:fat_details:1', $accountingKeys, true)
            && !in_array('missing:refund_reversal:fat_details:1', $accountingKeys, true),
        'exact linked draft reclassification pair must be recognized as intentional net-zero accounting'
    );
    unpaidReclassAssert(
        in_array('missing:sale_direct:fat_details:2', $accountingKeys, true),
        'ordinary paid sale without a journal must remain an accounting blocker: ' . json_encode($accounting)
    );
    $replay = $service->apply($conn, (string) $plan['manifest_hash'], $backup);
    unpaidReclassAssert(!empty($replay['replayed']) && unpaidReclassScalar($conn, 'SELECT COUNT(*) FROM inventory_movements') === $before + 4, 'repair replay must be idempotent');
    unpaidReclassAssert((int) $service->plan($conn)['summary']['entry_count'] === 0, 'corrected drafts must no longer be candidates');

    echo "inventory-unpaid-sale-reclassification-service-ok\n";
} finally {
    if (is_file($backup)) {
        unlink($backup);
    }
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function unpaidReclassMovement(
    mysqli $conn,
    InventoryLedgerService $ledger,
    string $type,
    int $orderId,
    string $quantity,
    int $fatDetailId,
    string $key
): int {
    $movement = [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 27],
        'item_id' => 7001,
        'movement_type' => $type,
        'source_type' => $type === 'purchase' ? 'manual' : 'fat_details',
        'source_id' => $fatDetailId > 0 ? $fatDetailId : null,
        'order_id' => $orderId > 0 ? $orderId : null,
        'fat_detail_id' => $fatDetailId > 0 ? $fatDetailId : null,
        $type === 'purchase' ? 'qty_in' : 'qty_out' => $quantity,
        'unit_cost' => '10.000000',
        'total_cost' => InventoryDecimal::multiply($quantity, '10.000000'),
        'idempotency_key' => $key,
    ];
    $result = $ledger->recordMovement(
        $conn,
        $movement,
        ['id' => 7001, 'item_type' => 'ingredient', 'track_stock' => 1],
        ['enforce_negative_policy' => false]
    );

    return (int) $result['movement_id'];
}

function unpaidReclassScalar(mysqli $conn, string $sql): int
{
    return (int) ($conn->query($sql)->fetch_row()[0] ?? 0);
}

function unpaidReclassAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
