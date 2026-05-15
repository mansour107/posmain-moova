<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_drawer_sessions_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $service = new DrawerSessionService();
    $session = $service->openSession($conn, [
        'user_id' => 7,
        'opened_by' => 99,
        'tenant' => 3,
        'branch' => 4,
        'fund_account_id' => 5001,
        'opening_cash' => '100.125',
        'opened_at' => '2026-05-13 09:00:00',
        'notes' => 'صباح',
    ]);
    phase4DrawerAssert($session['status'] === 'open', 'session should be open');
    phase4DrawerAssert($session['opening_cash'] === '100.125', 'opening cash should preserve decimal string');
    phase4DrawerAssert($session['fund_account_id'] === 5001, 'fund account expected');
    phase4DrawerAssert(strlen($session['uuid']) === 36, 'uuid should be stored');

    $open = $service->findOpenSession($conn, 7, 3, 4);
    phase4DrawerAssert($open !== null && $open['id'] === $session['id'], 'open session lookup expected');

    phase4DrawerExpectException(function () use ($service, $conn) {
        $service->openSession($conn, [
            'user_id' => 7,
            'tenant' => 3,
            'branch' => 4,
            'opening_cash' => '5.000',
        ]);
    }, 'DRAWER_SESSION_ALREADY_OPEN');

    $sale = $service->recordMovement($conn, $session['id'], [
        'movement_type' => 'sale_cash',
        'amount' => '50.250',
        'order_id' => 300,
        'payment_id' => 400,
        'reason' => 'table payment',
        'created_by' => 99,
    ]);
    phase4DrawerAssert($sale['amount'] === '50.250', 'sale movement amount expected');
    phase4DrawerAssert($sale['order_id'] === 300, 'sale order id expected');

    $service->recordMovement($conn, $session['id'], [
        'movement_type' => 'refund_cash',
        'amount' => '10.000',
        'created_by' => 99,
    ]);
    $service->recordMovement($conn, $session['id'], [
        'movement_type' => 'paid_in',
        'amount' => '20.125',
        'created_by' => 99,
    ]);
    $service->recordMovement($conn, $session['id'], [
        'movement_type' => 'paid_out',
        'amount' => '5.500',
        'created_by' => 99,
    ]);
    $service->recordMovement($conn, $session['id'], [
        'movement_type' => 'safe_drop',
        'amount' => '30.000',
        'created_by' => 99,
    ]);

    phase4DrawerAssert($service->expectedCash($conn, $session['id']) === '125.000', 'expected cash should match signed movement math');
    phase4DrawerAssert(count($service->movementsForSession($conn, $session['id'])) === 5, 'five drawer movements expected');

    $closed = $service->closeSession($conn, $session['id'], [
        'closed_by' => 101,
        'counted_cash' => '124.500',
        'closed_at' => '2026-05-13 17:00:00',
        'notes' => 'نقص بسيط',
    ]);
    phase4DrawerAssert($closed['status'] === 'closed', 'session should close');
    phase4DrawerAssert($closed['expected_cash'] === '125.000', 'closed expected cash mismatch');
    phase4DrawerAssert($closed['counted_cash'] === '124.500', 'counted cash mismatch');
    phase4DrawerAssert($closed['difference'] === '-0.500', 'difference should be counted minus expected');
    phase4DrawerAssert($service->findOpenSession($conn, 7, 3, 4) === null, 'closed session should no longer be open');

    phase4DrawerExpectException(function () use ($service, $conn, $session) {
        $service->recordMovement($conn, $session['id'], [
            'movement_type' => 'sale_cash',
            'amount' => '1.000',
            'created_by' => 1,
        ]);
    }, 'DRAWER_SESSION_NOT_OPEN');

    phase4DrawerExpectException(function () use ($service, $conn, $session) {
        $service->closeSession($conn, $session['id'], [
            'closed_by' => 1,
            'counted_cash' => '1.000',
        ]);
    }, 'DRAWER_SESSION_NOT_OPEN');

    $forced = $service->openSession($conn, [
        'user_id' => 8,
        'tenant' => 3,
        'branch' => 4,
        'opening_cash' => '0.000',
    ]);
    $forcedClosed = $service->forceCloseSession($conn, $forced['id'], [
        'closed_by' => 102,
        'counted_cash' => '0.000',
        'notes' => 'force close test',
    ]);
    phase4DrawerAssert($forcedClosed['status'] === 'forced_closed', 'force close status expected');

    phase4DrawerExpectException(function () use ($service, $conn, $forced) {
        $service->recordMovement($conn, $forced['id'], [
            'movement_type' => 'tip',
            'amount' => '1.000',
            'created_by' => 1,
        ]);
    }, 'DRAWER_SESSION_NOT_OPEN');

    $invalid = $service->openSession($conn, [
        'user_id' => 9,
        'opening_cash' => '0.000',
    ]);
    phase4DrawerExpectException(function () use ($service, $conn, $invalid) {
        $service->recordMovement($conn, $invalid['id'], [
            'movement_type' => 'tip',
            'amount' => '1.000',
            'created_by' => 1,
        ]);
    }, 'DRAWER_MOVEMENT_TYPE_INVALID');
    phase4DrawerExpectException(function () use ($service, $conn, $invalid) {
        $service->recordMovement($conn, $invalid['id'], [
            'movement_type' => 'sale_cash',
            'amount' => '0',
            'created_by' => 1,
        ]);
    }, 'DRAWER_AMOUNT_INVALID');

    echo "phase4-drawer-session-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4DrawerExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4DrawerAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4DrawerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
