<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Financial/FinancialLegacyRepairService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_financial_legacy_repair_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    fwrite(STDERR, "financial-legacy-repair-failed-db-unavailable\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$backup = tempnam(sys_get_temp_dir(), 'financial-repair-backup-');
if (!is_string($backup) || file_put_contents($backup, '-- verified financial repair fixture') === false) {
    throw new RuntimeException('unable to create backup fixture');
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    foreach ([
        "CREATE TABLE ot_head (id INT PRIMARY KEY, pro_tybe INT NOT NULL, fat_net DECIMAL(19,2) NOT NULL, fat_tax DECIMAL(19,2) NOT NULL DEFAULT 0, emp_id INT NULL, user INT NULL, payment_date DATETIME NULL, payment_status VARCHAR(20) NULL, invoice_status VARCHAR(20) NULL, order_status VARCHAR(20) NULL, payment_method VARCHAR(50) NULL, isdeleted TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB",
        "CREATE TABLE fat_details (id INT PRIMARY KEY, fatid INT NOT NULL, qty_in DECIMAL(19,6) NOT NULL DEFAULT 0, qty_out DECIMAL(19,6) NOT NULL DEFAULT 0, price DECIMAL(19,6) NOT NULL DEFAULT 0, discount DECIMAL(19,2) NOT NULL DEFAULT 0, det_value DECIMAL(19,2) NOT NULL DEFAULT 0, cost_price DECIMAL(19,6) NOT NULL DEFAULT 0, isdeleted TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB",
        "CREATE TABLE order_payments (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, amount DECIMAL(19,2) NOT NULL, payment_method VARCHAR(50) NULL, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
        "CREATE TABLE acc_head (id INT PRIMARY KEY, balance DECIMAL(19,6) NOT NULL DEFAULT 0) ENGINE=InnoDB",
        "CREATE TABLE journal_entries (id INT AUTO_INCREMENT PRIMARY KEY, journal_id INT NOT NULL, account_id INT NOT NULL, debit DECIMAL(19,6) NOT NULL DEFAULT 0, credit DECIMAL(19,6) NOT NULL DEFAULT 0) ENGINE=InnoDB",
    ] as $sql) {
        $conn->query($sql);
    }
    (new SyncSchemaManager())->apply($conn);

    $conn->query("INSERT INTO acc_head (id, balance) VALUES (10, 0), (11, 0)");
    $conn->query("INSERT INTO journal_entries (journal_id, account_id, debit, credit) VALUES (1, 10, 100, 0), (1, 11, 0, 100)");
    $conn->query("INSERT INTO payment_methods (code, name_ar, type, is_active, requires_reference, sort_order) VALUES ('P6-DEMO-CASH', 'Demo', 'cash', 1, 0, 1)");
    $conn->query("
        INSERT INTO ot_head (id, pro_tybe, fat_net, fat_tax, emp_id, user, payment_date, payment_status, invoice_status, order_status, payment_method, isdeleted) VALUES
        (100, 9, 50, 0, 7, 7, '2026-06-01 10:00:00', 'paid', 'completed', 'completed', 'cash', 0),
        (101, 9, 30, 0, 7, 7, '2026-06-01 11:00:00', 'paid', 'completed', 'completed', 'card', 0),
        (102, 9, 20, 0, 7, 7, NULL, 'unpaid', 'draft', 'active', 'cash', 0)
    ");
    $conn->query("
        INSERT INTO fat_details (id, fatid, qty_in, qty_out, price, discount, det_value, cost_price, isdeleted, posted_net) VALUES
        (1001, 100, 0, 0.333333, 150, 0, 50, 12.345678, 0, NULL),
        (1002, 101, 0, 1, 30, 0, 30, 8, 0, 30),
        (1003, 102, 0, 1, 20, 0, 20, 5, 0, NULL)
    ");

    $service = new FinancialLegacyRepairService();
    $plan = $service->plan($conn);
    financialRepairAssert(count($plan['payment_candidates']) === 1 && (int) $plan['payment_candidates'][0]['order_id'] === 100, 'only completed legacy cash orders may become payment candidates');
    financialRepairAssert(count($plan['snapshot_candidates']) === 1 && (int) $plan['snapshot_candidates'][0]['line_id'] === 1001, 'only provable finalized lines may be snapshotted');
    financialRepairAssert(count($plan['account_candidates']) === 2, 'journal-derived cache differences must be planned');
    financialRepairAssert($plan['blockers'] === [], 'balanced journals and unused demo tenders must be repairable');

    try {
        $service->apply($conn, str_repeat('0', 64), $backup);
        throw new RuntimeException('stale manifest must fail');
    } catch (RuntimeException $exception) {
        financialRepairAssert($exception->getMessage() === 'FINANCIAL_REPAIR_LIVE_ROWS_CHANGED', 'apply must reject a manifest that was not reviewed from live rows');
    }

    $applied = $service->apply($conn, (string) $plan['manifest_hash'], $backup);
    financialRepairAssert($applied['applied'] === ['payments' => 1, 'snapshots' => 1, 'account_caches' => 2, 'demo_tenders' => 1], 'reviewed repair must apply the exact classified changes');
    financialRepairAssert((int) $conn->query('SELECT COUNT(*) AS c FROM order_payments WHERE order_id = 100')->fetch_assoc()['c'] === 1, 'cash payment must be reconstructed once');
    financialRepairAssert((int) $conn->query('SELECT COUNT(*) AS c FROM order_payments WHERE order_id IN (101,102)')->fetch_assoc()['c'] === 0, 'card and active unpaid orders must remain untouched');
    financialRepairAssert((string) $conn->query('SELECT posted_net FROM fat_details WHERE id = 1001')->fetch_assoc()['posted_net'] === '50.00', 'finalized line snapshot must be backfilled');
    $snapshot = $conn->query('SELECT posted_qty, posted_gross, posted_unit_cost, posted_total_cost FROM fat_details WHERE id = 1001')->fetch_assoc();
    financialRepairAssert((string) $snapshot['posted_qty'] === '0.333333', 'snapshot quantity must preserve exact six-decimal quantity');
    financialRepairAssert((string) $snapshot['posted_gross'] === '50.00', 'snapshot gross must use exact decimal multiplication at currency precision');
    financialRepairAssert((string) $snapshot['posted_unit_cost'] === '12.345678', 'snapshot unit cost must preserve the immutable six-decimal cost');
    financialRepairAssert((string) $snapshot['posted_total_cost'] === '4.115222', 'snapshot total cost must use exact six-decimal multiplication');
    financialRepairAssert((string) $conn->query('SELECT posted_net FROM fat_details WHERE id = 1003')->fetch_assoc()['posted_net'] === '', 'active unpaid line must remain outside repair');
    financialRepairAssert((int) $conn->query("SELECT is_active FROM payment_methods WHERE code = 'P6-DEMO-CASH'")->fetch_assoc()['is_active'] === 0, 'unused demo tender must be deactivated');

    $replayed = $service->apply($conn, (string) $plan['manifest_hash'], $backup);
    financialRepairAssert(($replayed['replayed'] ?? false) === true, 'successful repair manifest must be replay-safe');
    financialRepairAssert((int) $conn->query('SELECT COUNT(*) AS c FROM order_payments WHERE order_id = 100')->fetch_assoc()['c'] === 1, 'repair replay must not duplicate payment history');

    $conn->query("INSERT INTO journal_entries (journal_id, account_id, debit, credit) VALUES (2, 10, 1, 0)");
    $blockedPlan = $service->plan($conn);
    financialRepairAssert(in_array('journal_imbalance_must_be_zero', $blockedPlan['blockers'], true), 'cache rebuild must block when any journal is imbalanced');
    try {
        $service->apply($conn, (string) $blockedPlan['manifest_hash'], $backup);
        throw new RuntimeException('imbalanced journal repair must fail');
    } catch (RuntimeException $exception) {
        financialRepairAssert($exception->getMessage() === 'journal_imbalance_must_be_zero', 'journal imbalance must block financial repair transaction');
    }

    echo "financial-legacy-repair-ok\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
    @unlink($backup);
}

function financialRepairAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
