<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryHistoricalAccountingRepairService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(
    getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
    getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
    getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
    '',
    (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307)
);
if ($conn->connect_error) {
    echo "inventory-historical-accounting-repair-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = 'posmain_inventory_accounting_repair_' . getmypid();
$backup = sys_get_temp_dir() . '/posmain_inventory_accounting_repair_' . getmypid() . '.sql';
$conn->query('DROP DATABASE IF EXISTS `' . $db . '`');
$conn->query('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($db);

try {
    (new SyncSchemaManager())->apply($conn);
    accountingRepairFixtureSchema($conn);
    accountingRepairMovement($conn, 1, 'sale_direct', 'fat_details', 501, 1001, '0', '2', '10', '20', 'inventory-invoice-bridge:v1:paid', null, '2026-01-02 10:00:00');
    accountingRepairMovement($conn, 2, 'adjustment', 'adjustment', null, null, '1', '0', '5', '5', 'inventory-adjustment:v1:historical', null, '2026-01-03 10:00:00');
    accountingRepairMovement($conn, 3, 'sale_direct', 'fat_details', 502, 1002, '0', '1', '7', '7', 'inventory-invoice-bridge:v1:cancelled', null, '2026-01-04 10:00:00');
    accountingRepairMovement($conn, 4, 'sale_direct', 'fat_details', 503, 1003, '0', '1', '9', '9', 'inventory-invoice-bridge:v1:draft', null, '2026-01-05 10:00:00');
    accountingRepairMovement($conn, 5, 'refund_reversal', 'fat_details', 503, 1003, '1', '0', '9', '9', 'inventory-unpaid-sale-reclass:v1:4:restore', 4, '2026-01-05 10:01:00');
    accountingRepairMovement($conn, 6, 'purchase', 'fat_details', 504, 1004, '5', '0', '2', '10', 'migration:fat_details:504:v1', null, '2026-01-01 10:00:00');

    $service = new InventoryHistoricalAccountingRepairService();
    $base = [
        'store_id' => 27,
        'accounts' => accountingRepairAccounts(),
        'app_config' => ['inventory' => ['sync' => false]],
    ];
    $blocked = $service->plan($conn, $base);
    accountingRepairAssert($blocked['ok'] === false, 'ambiguous historical movements must block');
    accountingRepairAssert(array_column($blocked['entries'], 'id') === [1], 'only settled paid sale is safe without decisions');
    accountingRepairAssert((int) $blocked['summary']['excluded_count'] === 3, 'migration and exact unpaid pair should be visible exclusions');

    $options = $base + [
        'created_by' => 99,
        'reviewed_decisions' => [
            ['movement_id' => 2, 'action' => 'post', 'approved_by' => 'inventory-controller', 'reason' => 'Verified adjustment source units and valuation.'],
            ['movement_id' => 3, 'action' => 'post', 'approved_by' => 'inventory-controller', 'reason' => 'Refund policy retains COGS as waste.', 'stock_disposition' => 'waste_no_restock'],
        ],
    ];
    $plan = $service->plan($conn, $options);
    accountingRepairAssert($plan['ok'] === true, 'complete reviewed decisions should unblock exact candidates');
    accountingRepairAssert(array_column($plan['entries'], 'id') === [1, 2, 3], 'reviewed manifest should contain exact posting set');

    file_put_contents($backup, 'local-test-backup');
    try {
        $service->apply($conn, $options, str_repeat('a', 64), $backup);
        accountingRepairAssert(false, 'stale manifest must fail');
    } catch (RuntimeException $exception) {
        accountingRepairAssert($exception->getMessage() === 'INVENTORY_ACCOUNTING_REPAIR_LIVE_ROWS_CHANGED', 'stale manifest failure should be explicit');
    }

    $rehearsal = $service->rehearse($conn, $options);
    accountingRepairAssert(!empty($rehearsal['rehearsed']) && count($rehearsal['posted']) === 3, 'rehearsal should execute the exact posting set');
    accountingRepairAssert(accountingRepairScalar($conn, 'SELECT COUNT(*) FROM journal_heads') === 0, 'rehearsal must roll back journals');
    accountingRepairAssert(accountingRepairScalar($conn, 'SELECT COUNT(*) FROM inventory_movements WHERE accounting_journal_id IS NOT NULL') === 0, 'rehearsal must roll back movement links');

    $applied = $service->apply($conn, $options, (string) $plan['manifest_hash'], $backup);
    accountingRepairAssert(count($applied['posted']) === 3, 'apply should post one journal per reviewed movement');
    accountingRepairAssert(accountingRepairScalar($conn, 'SELECT COUNT(*) FROM journal_heads') === 3, 'three immutable journal heads required');
    accountingRepairAssert(accountingRepairScalar($conn, 'SELECT COUNT(*) FROM journal_entries') === 6, 'each repaired journal must be balanced with two entries');
    accountingRepairAssert(accountingRepairScalar($conn, "SELECT COUNT(*) FROM journal_heads WHERE jdate IN ('2026-01-02','2026-01-03','2026-01-04')") === 3, 'repair journals must preserve movement dates');
    accountingRepairAssert(accountingRepairScalar($conn, 'SELECT COUNT(*) FROM inventory_movements WHERE id IN (1,2,3) AND accounting_journal_id IS NOT NULL') === 3, 'reviewed movements must attach to journals');
    accountingRepairAssert(accountingRepairScalar($conn, 'SELECT COUNT(*) FROM inventory_movements WHERE id IN (4,5,6) AND accounting_journal_id IS NOT NULL') === 0, 'migration and stock-state corrections must never receive commercial journals');
    accountingRepairAssert(accountingRepairScalar($conn, 'SELECT COUNT(*) FROM (SELECT journal_id FROM journal_entries GROUP BY journal_id HAVING SUM(debit)<>SUM(credit)) x') === 0, 'all repair journals must balance');

    $after = $service->plan($conn, $base);
    accountingRepairAssert($after['ok'] === true && (int) $after['summary']['entry_count'] === 0, 'applied movements must leave no repair candidates');
    $replayed = $service->apply($conn, $options, (string) $plan['manifest_hash'], $backup);
    accountingRepairAssert(!empty($replayed['replayed']), 'same reviewed manifest must replay without duplicate journals');
    accountingRepairAssert(accountingRepairScalar($conn, 'SELECT COUNT(*) FROM journal_heads') === 3, 'replay must not duplicate journals');

    echo "inventory-historical-accounting-repair-service-ok\n";
} finally {
    if (is_file($backup)) {
        unlink($backup);
    }
    $conn->query('DROP DATABASE IF EXISTS `' . $db . '`');
    $conn->close();
}

function accountingRepairFixtureSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE acc_head (
        id INT NOT NULL PRIMARY KEY, code VARCHAR(20) NOT NULL, aname VARCHAR(100) NOT NULL,
        is_stock TINYINT(1) NOT NULL DEFAULT 0, isdeleted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    foreach (accountingRepairAccounts() as $id) {
        $conn->query("INSERT INTO acc_head (id,code,aname) VALUES ({$id},'{$id}','Account {$id}')");
    }
    $conn->query("CREATE TABLE ot_head (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY, payment_status VARCHAR(30) NULL,
        invoice_status VARCHAR(30) NULL, order_status VARCHAR(30) NULL,
        closed TINYINT(1) NOT NULL DEFAULT 0, isdeleted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query("INSERT INTO ot_head VALUES
        (1001,'paid','completed','completed',0,0),
        (1002,'refunded','cancelled','cancelled',0,0),
        (1003,'unpaid','draft','active',0,0),
        (1004,'paid','completed','completed',0,0)");
    $conn->query("CREATE TABLE journal_heads (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_id INT NOT NULL,
        op_id BIGINT UNSIGNED NULL, total DECIMAL(18,6) NOT NULL DEFAULT 0, jdate DATE NULL,
        pro_tybe INT NULL, details VARCHAR(255) NULL, op2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
        isdeleted TINYINT(1) NOT NULL DEFAULT 0, user BIGINT UNSIGNED NULL, tenant INT NULL,
        branch INT NULL, source_type VARCHAR(64) NULL, source_id BIGINT UNSIGNED NULL,
        posting_kind VARCHAR(64) NULL, idempotency_key VARCHAR(191) NULL,
        reversal_of_journal_id BIGINT UNSIGNED NULL, UNIQUE KEY uq_accounting_repair_journal_key (idempotency_key)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE journal_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL, debit DECIMAL(18,6) NOT NULL DEFAULT 0,
        credit DECIMAL(18,6) NOT NULL DEFAULT 0, tybe INT NOT NULL DEFAULT 0,
        info VARCHAR(255) NULL, op_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        op2 BIGINT UNSIGNED NOT NULL DEFAULT 0, isdeleted TINYINT(1) NOT NULL DEFAULT 0,
        tenant INT NULL, branch INT NULL
    ) ENGINE=InnoDB");
}

function accountingRepairMovement(
    mysqli $conn,
    int $id,
    string $type,
    string $sourceType,
    ?int $sourceId,
    ?int $orderId,
    string $qtyIn,
    string $qtyOut,
    string $unitCost,
    string $totalCost,
    string $key,
    ?int $reversedId,
    string $createdAt
): void {
    $stmt = $conn->prepare("INSERT INTO inventory_movements
        (id,movement_uuid,pos_tenant,pos_branch,store_id,item_id,movement_type,source_type,source_id,order_id,
         qty_in,qty_out,unit_cost,total_cost,idempotency_key,payload_hash,reversed_movement_id,created_at)
        VALUES (?,UUID(),0,0,27,7001,?,?,?,?,?,?,?,?,?,'',?,?)");
    $stmt->bind_param(
        'issiisssssis',
        $id,
        $type,
        $sourceType,
        $sourceId,
        $orderId,
        $qtyIn,
        $qtyOut,
        $unitCost,
        $totalCost,
        $key,
        $reversedId,
        $createdAt
    );
    $stmt->execute();
    $stmt->close();
}

function accountingRepairAccounts(): array
{
    return [
        'inventory_asset_account_id' => 1100,
        'purchase_clearing_account_id' => 2100,
        'cogs_account_id' => 5100,
        'waste_expense_account_id' => 5200,
        'adjustment_gain_loss_account_id' => 5300,
    ];
}

function accountingRepairScalar(mysqli $conn, string $sql): int
{
    return (int) $conn->query($sql)->fetch_row()[0];
}

function accountingRepairAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
