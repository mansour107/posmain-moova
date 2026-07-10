<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftCountService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_drawer_lock_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function lockAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);
    $conn->query("CREATE TABLE users (id INT PRIMARY KEY, uname VARCHAR(50) NOT NULL DEFAULT '', display_name VARCHAR(100) NULL)");
    $conn->query("INSERT INTO users (id, uname) VALUES (1, 'a'), (2, 'b'), (9, 'mgr')");

    $drawer = new DrawerSessionService();
    $first = $drawer->openSession($conn, [
        'user_id' => 1,
        'opened_by' => 1,
        'tenant' => 5,
        'branch' => 8,
        'opening_cash' => '50.000',
    ]);
    lockAssert((int) $first['id'] > 0, 'first open succeeds');

    $lockCol = $conn->query("SHOW COLUMNS FROM drawer_sessions LIKE 'open_branch_lock'");
    lockAssert($lockCol && $lockCol->num_rows > 0, 'open_branch_lock column exists');
    $lockVal = $conn->query('SELECT open_branch_lock FROM drawer_sessions WHERE id = ' . (int) $first['id'])->fetch_assoc();
    lockAssert(($lockVal['open_branch_lock'] ?? '') === '5:8', 'open branch lock set');

    $blocked = false;
    try {
        $drawer->openSession($conn, [
            'user_id' => 2,
            'opened_by' => 2,
            'tenant' => 5,
            'branch' => 8,
            'opening_cash' => '50.000',
        ]);
    } catch (RuntimeException $exception) {
        $blocked = $exception->getMessage() === 'BRANCH_DRAWER_ALREADY_OPEN';
    }
    lockAssert($blocked, 'second open on same branch blocked');

    $drawer->recordMovement($conn, (int) $first['id'], [
        'movement_type' => 'sale_cash',
        'amount' => '20.000',
        'created_by' => 1,
    ]);

    // Mirror forceCloseDrawerForUser money path without full RBAC schema.
    $expectedBefore = (float) $drawer->expectedCash($conn, (int) $first['id']);
    lockAssert(abs($expectedBefore - 70.0) < 0.01, 'expected before force close is 70');
    $forced = $drawer->forceCloseSession($conn, (int) $first['id'], [
        'closed_by' => 9,
        'counted_cash' => '60.000',
        'notes' => 'test_force',
    ]);
    lockAssert(($forced['status'] ?? '') === 'forced_closed', 'force closed status');
    lockAssert(abs((float) ($forced['difference'] ?? 0) + 10.0) < 0.01, 'force close difference is -10');

    $conn->query("
        UPDATE drawer_sessions
        SET variance_status = 'unresolved',
            variance_type = 'closing',
            close_expected_snapshot = '70.000'
        WHERE id = " . (int) $first['id']
    );

    $row = $conn->query('SELECT variance_status, variance_type, open_branch_lock FROM drawer_sessions WHERE id = ' . (int) $first['id'])->fetch_assoc();
    lockAssert(($row['variance_status'] ?? '') === 'unresolved', 'force close enqueued as unresolved');
    lockAssert($row['open_branch_lock'] === null || $row['open_branch_lock'] === '', 'open branch lock cleared');

    $count = new ShiftCountService();
    $unresolved = $count->unresolvedSessions($conn, 5, 8);
    lockAssert(count($unresolved) >= 1, 'unresolved list includes force-closed session');
    lockAssert(abs((float) ($unresolved[0]['difference'] ?? 0) + 10.0) < 0.01, 'unresolved shows true short');

    $second = $drawer->openSession($conn, [
        'user_id' => 2,
        'opened_by' => 2,
        'tenant' => 5,
        'branch' => 8,
        'opening_cash' => '60.000',
    ]);
    lockAssert((int) $second['id'] > 0, 'new open after force close succeeds');

    echo "drawer_branch_lock_force_close_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}
