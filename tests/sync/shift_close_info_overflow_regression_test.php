<?php

/** Regression: long cashier notes remain in the JSON close snapshot. */

require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionCloseSummaryService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_close_info_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function closeInfoAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query("CREATE TABLE drawer_session_close_summaries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        uuid CHAR(36) NOT NULL,
        drawer_session_id BIGINT UNSIGNED NOT NULL,
        shift_number VARCHAR(64) NOT NULL,
        total_orders INT UNSIGNED NOT NULL DEFAULT 0,
        total_sales DECIMAL(12,3) NOT NULL DEFAULT 0,
        cash_sales DECIMAL(12,3) NOT NULL DEFAULT 0,
        non_cash_sales DECIMAL(12,3) NOT NULL DEFAULT 0,
        discount_total DECIMAL(12,3) NOT NULL DEFAULT 0,
        return_total DECIMAL(12,3) NOT NULL DEFAULT 0,
        expense_total DECIMAL(12,3) NOT NULL DEFAULT 0,
        expense_notes VARCHAR(500) NULL,
        expected_non_cash DECIMAL(12,3) NULL,
        counted_non_cash DECIMAL(12,3) NULL,
        non_cash_difference DECIMAL(12,3) NULL,
        close_path VARCHAR(120) NOT NULL,
        report_snapshot_json JSON NULL,
        payment_summary_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_summary_session (drawer_session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $longNotes = str_repeat('long cashier note ', 80) . 'END';
    $sessionToken = bin2hex(random_bytes(16));
    $summary = (new DrawerSessionCloseSummaryService())->createForSession($conn, 7, [
        'shift_number' => 'LONG-NOTE',
        'expense_notes' => $longNotes,
        'close_path' => 'close_shift.php',
        'report_snapshot' => [
            'cashier_notes' => $longNotes,
            'shift_session_token' => $sessionToken,
        ],
    ]);

    closeInfoAssert((int) ($summary['id'] ?? 0) > 0, 'long-note close summary should be created');
    closeInfoAssert(strlen((string) $summary['expense_notes']) === 500, 'bounded text column must truncate safely');
    $snapshot = json_decode((string) $summary['report_snapshot_json'], true);
    closeInfoAssert(($snapshot['cashier_notes'] ?? '') === $longNotes, 'full cashier note must remain in report snapshot');
    closeInfoAssert(($snapshot['shift_session_token'] ?? '') === $sessionToken, 'session token must remain in report snapshot');

    echo "shift-close-info-overflow-regression-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}
