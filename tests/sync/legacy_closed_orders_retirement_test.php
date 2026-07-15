<?php

require_once dirname(__DIR__, 2) . '/classes/Pos/Service/LegacyClosedOrdersRetirementService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "legacy-closed-orders-retirement-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_legacy_close_retirement_' . getmypid();
$backupFile = tempnam(sys_get_temp_dir(), 'posmain-backup-');
file_put_contents($backupFile, '-- verified test backup');
$conn->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$conn->select_db($dbName);

try {
    legacyCloseCreateTables($conn);
    $conn->query("INSERT INTO drawer_sessions (id, uuid) VALUES (10, '11111111-1111-4111-8111-111111111111')");
    $conn->query("INSERT INTO closed_orders (id, drawer_session_id, shift, total_sales, total_cash, total_visa, json_details, crtime) VALUES
        (1, 10, 'S-1', 100, 60, 40, 'legacy-not-json', '2026-01-01 10:00:00'),
        (2, 999, 'S-2', 50, 50, 0, NULL, '2026-01-02 10:00:00'),
        (3, NULL, 'S-3', 25, 25, 0, NULL, '2026-01-03 10:00:00')");

    $service = new LegacyClosedOrdersRetirementService();
    $plan = $service->inspect($conn);
    legacyCloseAssert($plan['counts']['linked'] === 1, 'linkable row should be classified');
    legacyCloseAssert($plan['counts']['missing_drawer'] === 1, 'missing drawer should remain unresolved');
    legacyCloseAssert($plan['counts']['unlinkable'] === 1, 'NULL drawer should remain unlinkable');

    $approved = [];
    foreach ($plan['rows'] as $row) {
        if (in_array($row['link_status'], ['missing_drawer', 'unlinkable'], true)) {
            $approved[] = $row['row_hash'];
        }
    }
    $archived = $service->archive($conn, $backupFile, $approved);
    legacyCloseAssert($archived['archived'] === 3, 'every source row should be archived');
    legacyCloseAssert((int) $conn->query('SELECT COUNT(*) AS c FROM closed_orders')->fetch_assoc()['c'] === 3, 'archive must not delete source rows');
    legacyCloseAssert((int) $conn->query('SELECT COUNT(*) AS c FROM drawer_session_close_summaries')->fetch_assoc()['c'] === 1, 'linkable close should backfill once');
    legacyCloseAssert((string) $conn->query('SELECT JSON_UNQUOTE(JSON_EXTRACT(report_snapshot_json, "$.legacy_raw")) AS raw_value FROM drawer_session_close_summaries')->fetch_assoc()['raw_value'] === 'legacy-not-json', 'invalid legacy report text must be preserved inside valid JSON');

    $again = $service->archive($conn, $backupFile, $approved);
    legacyCloseAssert((int) $conn->query('SELECT COUNT(*) AS c FROM legacy_closed_orders_archive')->fetch_assoc()['c'] === 3, 'archive should be idempotent');
    legacyCloseAssert((int) $conn->query('SELECT COUNT(*) AS c FROM drawer_session_close_summaries')->fetch_assoc()['c'] === 1, 'backfill should be idempotent');

    $mismatchRejected = false;
    try {
        $service->drop($conn, $backupFile, str_repeat('0', 64));
    } catch (RuntimeException $exception) {
        $mismatchRejected = $exception->getMessage() === 'LEGACY_CLOSE_MANIFEST_CHANGED';
    }
    legacyCloseAssert($mismatchRejected, 'drop must reject a changed manifest');

    $drop = $service->drop($conn, $backupFile, (string) $again['manifest_hash']);
    legacyCloseAssert(!empty($drop['dropped']), 'approved, hash-matched archive should permit explicit drop');
    legacyCloseAssert($conn->query("SHOW TABLES LIKE 'closed_orders'")->num_rows === 0, 'explicit retirement should remove source only after verification');

    $conn->query('CREATE TABLE closed_orders (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, total_sales DECIMAL(12,3) NULL) ENGINE=InnoDB');
    $conn->query('INSERT INTO closed_orders (id, total_sales) VALUES (9, 9)');
    $noLinkPlan = $service->inspect($conn);
    legacyCloseAssert($noLinkPlan['counts']['unlinkable'] === 1, 'schema without drawer_session_id must be preserved as unlinkable');
    echo "legacy-closed-orders-retirement-ok\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$dbName}`");
    $conn->close();
    @unlink($backupFile);
}

function legacyCloseCreateTables(mysqli $conn): void
{
    $conn->query("CREATE TABLE drawer_sessions (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, uuid CHAR(36) NOT NULL) ENGINE=InnoDB");
    $conn->query("CREATE TABLE drawer_session_close_summaries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, uuid CHAR(36) NOT NULL,
        drawer_session_id BIGINT UNSIGNED NOT NULL, shift_number VARCHAR(64) NOT NULL,
        total_orders INT NOT NULL DEFAULT 0, total_sales DECIMAL(12,3) NOT NULL DEFAULT 0,
        cash_sales DECIMAL(12,3) NOT NULL DEFAULT 0, non_cash_sales DECIMAL(12,3) NOT NULL DEFAULT 0,
        discount_total DECIMAL(12,3) NOT NULL DEFAULT 0, return_total DECIMAL(12,3) NOT NULL DEFAULT 0,
        expense_total DECIMAL(12,3) NOT NULL DEFAULT 0, expense_notes VARCHAR(500) NULL,
        expected_non_cash DECIMAL(12,3) NULL, counted_non_cash DECIMAL(12,3) NULL,
        non_cash_difference DECIMAL(12,3) NULL, close_path VARCHAR(120) NOT NULL,
        report_snapshot_json JSON NULL, payment_summary_json JSON NULL, created_at DATETIME NOT NULL,
        UNIQUE KEY uq_summary_drawer (drawer_session_id), UNIQUE KEY uq_summary_uuid (uuid)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE legacy_closed_orders_archive (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, batch_id CHAR(36) NOT NULL,
        legacy_row_id VARCHAR(191) NOT NULL, raw_json JSON NOT NULL, row_hash CHAR(64) NOT NULL,
        link_status ENUM('linked','backfilled','missing_drawer','unlinkable','approved_unlinkable') NOT NULL,
        resolved_drawer_uuid CHAR(36) NULL, resolved_summary_uuid CHAR(36) NULL,
        manifest_hash CHAR(64) NOT NULL, source_created_at DATETIME NULL,
        archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_archive_row (legacy_row_id, row_hash)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE closed_orders (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY, drawer_session_id BIGINT UNSIGNED NULL,
        shift VARCHAR(64) NULL, total_sales DECIMAL(12,3) NULL, total_cash DECIMAL(12,3) NULL,
        total_visa DECIMAL(12,3) NULL, json_details TEXT NULL, crtime DATETIME NULL
    ) ENGINE=InnoDB");
}

function legacyCloseAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
