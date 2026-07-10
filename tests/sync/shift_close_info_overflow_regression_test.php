<?php

/**
 * Regression: closing with a normal cashier note + session token must not
 * overflow legacy closed_orders.info (VARCHAR(50)). Token/full notes live in
 * json_details; info stays bounded user notes only.
 */

require_once __DIR__ . '/../../classes/Pos/Service/ShiftCloseService.php';
require_once __DIR__ . '/../../classes/ShiftReport.php';

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
    closeInfoCreateSchema($conn);

    $cashierId = 42;
    $cashierName = 'info_overflow_cashier';
    $conn->query("
        INSERT INTO users (id, uname, password, usrole)
        VALUES ({$cashierId}, '{$cashierName}', 'x', 3)
    ");

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $token = bin2hex(random_bytes(16));
    $_SESSION = [
        'login' => $cashierName,
        'userid' => $cashierId,
        'pos_tenant' => 1,
        'pos_branch' => 1,
        'pos_authenticated' => true,
        'pos_user_id' => $cashierId,
        'pos_shift_session_token' => $token,
    ];
    closeInfoAssert(strlen($token) === 32, 'session token should be 32 hex chars');

    $notes = 'e2e variance close';
    closeInfoAssert(strlen($notes . ' | shift_token:' . $token) > 50, 'fixture note+token exceeds VARCHAR(50)');

    $service = new ShiftCloseService();
    $closed = $service->closeShift($conn, $cashierId, [
        'expenses' => 0,
        'cash' => 0,
        'fund_after' => 0,
        'counted_cash' => 0,
        'notes' => $notes,
        'close_path' => 'close_shift.php',
    ]);
    closeInfoAssert((int) ($closed['closed_order_id'] ?? 0) > 0, 'close should insert closed_orders row');

    $row = $conn->query('SELECT info, json_details, CHAR_LENGTH(info) AS info_len FROM closed_orders ORDER BY id DESC LIMIT 1')->fetch_assoc();
    closeInfoAssert(is_array($row), 'closed_orders row readable');
    closeInfoAssert((int) $row['info_len'] <= 50, 'info must stay within VARCHAR(50)');
    closeInfoAssert((string) $row['info'] === $notes, 'info should store bounded cashier notes only');
    closeInfoAssert(strpos((string) $row['info'], 'shift_token:') === false, 'info must not contain shift_token suffix');

    $details = json_decode((string) $row['json_details'], true);
    closeInfoAssert(is_array($details), 'json_details must be valid JSON');
    closeInfoAssert(($details['shift_session_token'] ?? '') === $token, 'json_details stores session token');
    closeInfoAssert(($details['cashier_notes'] ?? '') === $notes, 'json_details stores full cashier notes');

    // Longer note: info truncates, full text preserved in json_details.
    unset($_SESSION['pos_shift_closed_for_session']);
    $token2 = bin2hex(random_bytes(16));
    $_SESSION['pos_shift_session_token'] = $token2;
    $_SESSION['pos_authenticated'] = true;
    $longNotes = str_repeat('long note pad ', 8) . 'END';
    closeInfoAssert(strlen($longNotes) > 50, 'long note exceeds info limit');

    $closed2 = $service->closeShift($conn, $cashierId, [
        'expenses' => 0,
        'cash' => 0,
        'fund_after' => 0,
        'counted_cash' => 0,
        'notes' => $longNotes,
        'close_path' => 'close_shift.php',
    ]);
    closeInfoAssert((int) ($closed2['closed_order_id'] ?? 0) > 0, 'long-note close succeeds');

    $row2 = $conn->query('SELECT info, json_details, CHAR_LENGTH(info) AS info_len FROM closed_orders ORDER BY id DESC LIMIT 1')->fetch_assoc();
    closeInfoAssert((int) $row2['info_len'] <= 50, 'long note info truncated to 50');
    $expectedInfo = function_exists('mb_substr') ? mb_substr($longNotes, 0, 50) : substr($longNotes, 0, 50);
    closeInfoAssert((string) $row2['info'] === $expectedInfo, 'info equals truncated notes');
    $details2 = json_decode((string) $row2['json_details'], true);
    closeInfoAssert(($details2['shift_session_token'] ?? '') === $token2, 'second close stores token in json_details');
    closeInfoAssert(($details2['cashier_notes'] ?? '') === $longNotes, 'full long notes preserved in json_details');

    echo "shift-close-info-overflow-regression-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function closeInfoCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(120) NOT NULL,
            password VARCHAR(255) NULL,
            usrole INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            pro_date DATE NULL,
            user VARCHAR(20) NULL,
            pro_tybe INT NULL,
            fat_total DECIMAL(15,4) NULL,
            fat_disc DECIMAL(15,4) NULL,
            fat_net DECIMAL(15,4) NULL,
            pro_value DECIMAL(15,4) NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            crtime DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    // Intentionally match production legacy width so overflow regressions fail loudly.
    $conn->query("
        CREATE TABLE closed_orders (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            shift VARCHAR(64) NULL,
            date DATE NULL,
            user VARCHAR(120) NULL,
            endtime TIME NULL,
            total_sales DECIMAL(15,4) NULL,
            expenses DECIMAL(15,4) NULL,
            exp_notes VARCHAR(30) NULL,
            cash DECIMAL(15,4) NULL,
            fund_after DECIMAL(15,4) NULL,
            info VARCHAR(50) NULL,
            json_details TEXT NULL,
            drawer_session_id BIGINT UNSIGNED NULL,
            created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}
