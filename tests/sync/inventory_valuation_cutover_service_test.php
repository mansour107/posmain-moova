<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryValuationCutoverService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(
    getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
    getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
    getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
    '',
    (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307)
);
if ($conn->connect_error) {
    echo "inventory-valuation-cutover-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = 'posmain_inventory_valuation_cutover_' . getmypid();
$backup = sys_get_temp_dir() . '/posmain_inventory_valuation_cutover_' . getmypid() . '.sql';
$conn->query('DROP DATABASE IF EXISTS `' . $db . '`');
$conn->query('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($db);

try {
    (new SyncSchemaManager())->apply($conn);
    valuationCutoverSchema($conn);
    $conn->query("INSERT INTO inventory_item_balances
        (pos_tenant,pos_branch,store_id,item_id,qty_on_hand,qty_reserved,qty_available,moving_average_cost)
        VALUES (0,0,27,9001,10.000000,0.000000,10.000000,2.345500)");
    $options = [
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 27,
        'inventory_asset_account_id' => 1100,
        'offset_account_id' => 3100,
        'cutover_date' => '2026-07-26',
        'approved_by' => 'chief-accountant',
        'approval_reason' => 'Approved opening inventory valuation for local certification fixture.',
        'created_by' => 7,
        'app_config' => ['inventory' => ['sync' => false]],
    ];
    $service = new InventoryValuationCutoverService();
    $plan = $service->plan($conn, $options);
    valuationCutoverAssert($plan['ok'] === true && $plan['journal_required'] === true, 'reviewed valuation difference should be ready for cutover journal');
    valuationCutoverAssert($plan['valuation_raw_6dp'] === '23.455000', 'valuation review must retain the exact 6dp diagnostic value');
    valuationCutoverAssert($plan['difference_2dp'] === '23.46', 'valuation must use declared half-up 2dp journal boundary');

    $withoutApproval = $options;
    $withoutApproval['approved_by'] = '';
    valuationCutoverAssert(
        in_array('inventory_cutover_journal_requires_accountant_approval', $service->plan($conn, $withoutApproval)['blockers'], true),
        'nonzero valuation journal must require accountant approval'
    );

    $conn->query("INSERT INTO inventory_item_balances
        (pos_tenant,pos_branch,store_id,item_id,qty_on_hand,qty_reserved,qty_available,moving_average_cost)
        VALUES (0,0,27,9002,-1.000000,0.000000,-1.000000,1.000000)");
    valuationCutoverAssert(
        in_array('negative_inventory_quantities_require_count_or_review', $service->plan($conn, $options)['blockers'], true),
        'negative stock must block valuation cutover'
    );
    $conn->query('DELETE FROM inventory_item_balances WHERE item_id=9002');

    $rehearsal = $service->rehearse($conn, $options);
    valuationCutoverAssert(!empty($rehearsal['rehearsed']) && empty($rehearsal['journal']['noop']), 'rehearsal should post the exact cutover journal');
    valuationCutoverAssert(valuationCutoverScalar($conn, 'SELECT COUNT(*) FROM journal_heads') === 0, 'rehearsal must roll back journal head');

    file_put_contents($backup, 'local-test-backup');
    $applied = $service->apply($conn, $options, (string) $plan['manifest_hash'], $backup);
    valuationCutoverAssert(empty($applied['journal']['noop']), 'apply must post cutover journal');
    valuationCutoverAssert(valuationCutoverScalar($conn, 'SELECT COUNT(*) FROM journal_heads') === 1, 'one append-only cutover journal required');
    valuationCutoverAssert(valuationCutoverScalar($conn, 'SELECT COUNT(*) FROM journal_entries') === 2, 'cutover journal must have two entries');
    valuationCutoverAssert(valuationCutoverScalar($conn, 'SELECT COUNT(*) FROM (SELECT journal_id FROM journal_entries GROUP BY journal_id HAVING SUM(debit)<>SUM(credit)) x') === 0, 'cutover journal must balance');

    $review = (new InventoryValuationAccountingService())->review($conn, ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 27], 1100);
    valuationCutoverAssert($review['ok'] === true && $review['difference_2dp'] === '0.00', 'post-cutover valuation must exactly match inventory asset GL');
    $replayed = $service->apply($conn, $options, (string) $plan['manifest_hash'], $backup);
    valuationCutoverAssert(!empty($replayed['replayed']), 'same manifest must replay without duplicate journal');
    valuationCutoverAssert(valuationCutoverScalar($conn, 'SELECT COUNT(*) FROM journal_heads') === 1, 'replay must not duplicate cutover journal');

    echo "inventory-valuation-cutover-service-ok\n";
} finally {
    if (is_file($backup)) {
        unlink($backup);
    }
    $conn->query('DROP DATABASE IF EXISTS `' . $db . '`');
    $conn->close();
}

function valuationCutoverSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE acc_head (
        id INT NOT NULL PRIMARY KEY, code VARCHAR(20) NOT NULL, aname VARCHAR(100) NOT NULL,
        is_stock TINYINT(1) NOT NULL DEFAULT 0, isdeleted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query("INSERT INTO acc_head VALUES
        (1100,'1100','Inventory asset',0,0),(3100,'3100','Opening inventory clearing',0,0)");
    $conn->query("CREATE TABLE journal_heads (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_id INT NOT NULL,
        op_id BIGINT UNSIGNED NULL, total DECIMAL(18,6) NOT NULL DEFAULT 0, jdate DATE NULL,
        pro_tybe INT NULL, details VARCHAR(255) NULL, op2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
        isdeleted TINYINT(1) NOT NULL DEFAULT 0, user BIGINT UNSIGNED NULL, tenant INT NULL,
        branch INT NULL, source_type VARCHAR(64) NULL, source_id BIGINT UNSIGNED NULL,
        posting_kind VARCHAR(64) NULL, idempotency_key VARCHAR(191) NULL,
        reversal_of_journal_id BIGINT UNSIGNED NULL, UNIQUE KEY uq_valuation_cutover_key (idempotency_key)
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

function valuationCutoverScalar(mysqli $conn, string $sql): int
{
    return (int) $conn->query($sql)->fetch_row()[0];
}

function valuationCutoverAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
