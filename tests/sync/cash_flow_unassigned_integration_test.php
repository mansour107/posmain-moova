<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentService.php';
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
    $paymentService = new PaymentService();
    $movement = $paymentService->recordCashDrawerMovementForPayment(
        $conn,
        'cash',
        45.0,
        501,
        7,
        ['tenant' => 2, 'branch' => 3, 'drawer_reason' => 'test_unassigned'],
        null,
        null,
        null
    );

    cashFlowUnassignedAssert($movement !== null, 'unassigned movement should be recorded');
    cashFlowUnassignedAssert(($movement['is_unassigned'] ?? false) === true, 'movement should be flagged unassigned');
    cashFlowUnassignedAssert((int) ($movement['tenant'] ?? 0) === 2, 'tenant should be stored on unassigned movement');
    cashFlowUnassignedAssert((int) ($movement['branch'] ?? 0) === 3, 'branch should be stored on unassigned movement');

    $period = new CashFlowPeriodService();
    $today = date('Y-m-d');
    $summary = $period->summary($conn, ['date_from' => $today, 'date_to' => $today, 'tenant' => 2, 'branch' => 3]);
    cashFlowUnassignedAssert(abs((float) ($summary['unassigned_total'] ?? 0) - 45.0) < 0.01, 'unassigned total should match sale');
    cashFlowUnassignedAssert((int) ($summary['unassigned_count'] ?? 0) >= 1, 'unassigned count should be positive');

    $listed = $period->movements($conn, [
        'date_from' => $today,
        'date_to' => $today,
        'only_unassigned' => true,
        'tenant' => 2,
        'branch' => 3,
    ]);
    cashFlowUnassignedAssert((int) ($listed['total'] ?? 0) >= 1, 'period movements should list unassigned rows');

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
