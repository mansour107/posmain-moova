<?php

/**
 * Unassigned drawer movements remain readable for historical cash-flow reports,
 * but PaymentService must fail closed without an open drawer session.
 */
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';
require_once __DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_cash_flow_unassigned_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $_SESSION = ['pos_tenant' => 2, 'pos_branch' => 3];
    $methods = new PaymentMethodService();
    $methods->saveMethod($conn, [
        'code' => 'cash',
        'name_ar' => 'Cash',
        'name_en' => 'Cash',
        'type' => 'cash',
        'account_id' => 51,
        'requires_reference' => false,
    ]);

    $paymentService = new PaymentService();
    $failedClosed = false;
    try {
        $paymentService->recordCashDrawerMovementForPayment(
            $conn,
            'cash',
            '45.00',
            501,
            7,
            [
                'tenant' => 2,
                'branch' => 3,
                'drawer_reason' => 'test_unassigned',
                'idempotency_key' => 'cash-flow-unassigned:order:501',
            ],
            null,
            null,
            null
        );
    } catch (RuntimeException $exception) {
        $failedClosed = $exception->getMessage() === 'DRAWER_SESSION_REQUIRED';
    }
    cashFlowUnassignedAssert($failedClosed, 'PaymentService must require an open drawer session');

    $drawer = new DrawerSessionService();
    $movement = $drawer->recordUnassignedMovement($conn, [
        'movement_type' => 'sale_cash',
        'amount' => '45.00',
        'order_id' => 501,
        'created_by' => 7,
        'reason' => 'historical_unassigned',
        'tenant' => 2,
        'branch' => 3,
    ]);
    cashFlowUnassignedAssert($movement !== null, 'historical unassigned movement should still be recordable for archive reads');
    cashFlowUnassignedAssert(($movement['is_unassigned'] ?? false) === true, 'movement should be flagged unassigned');

    $period = new CashFlowPeriodService();
    $today = date('Y-m-d');
    $summary = $period->summary($conn, ['date_from' => $today, 'date_to' => $today, 'tenant' => 2, 'branch' => 3]);
    cashFlowUnassignedAssert(abs((float) ($summary['unassigned_total'] ?? 0) - 45.0) < 0.01, 'unassigned total should match sale');
    cashFlowUnassignedAssert((int) ($summary['unassigned_count'] ?? 0) >= 1, 'unassigned count should be positive');

    echo "cash-flow-unassigned-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function cashFlowUnassignedAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
