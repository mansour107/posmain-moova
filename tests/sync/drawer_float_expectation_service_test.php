<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerFloatExpectationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_drawer_float_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function drawerFloatAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $drawer = new DrawerSessionService();
    $service = new DrawerFloatExpectationService();

    $coldStart = $service->expectedOpeningFloat($conn, 1, 2);
    drawerFloatAssert(!empty($coldStart['baseline_required']), 'cold start requires baseline');
    drawerFloatAssert($coldStart['expected'] === '0.00', 'expected is unassigned only when baseline missing');

    $floatRejected = false;
    try {
        $service->setOpeningBaseline($conn, 1, 2, 500.0, 10);
    } catch (RuntimeException $exception) {
        $floatRejected = $exception->getMessage() === 'BASELINE_AMOUNT_INVALID';
    }
    drawerFloatAssert($floatRejected, 'opening baseline must reject PHP floats at the exact-decimal service boundary');

    $service->setOpeningBaseline($conn, 1, 2, '500.000', 10);
    $withBaseline = $service->expectedOpeningFloat($conn, 1, 2);
    drawerFloatAssert(empty($withBaseline['baseline_required']), 'baseline set clears requirement');
    drawerFloatAssert($withBaseline['expected'] === '500.00', 'expected uses baseline before first close');

    $service->setOpeningBaseline($conn, 1, 2, '550.000', 10);
    $corrected = $service->expectedOpeningFloat($conn, 1, 2);
    drawerFloatAssert($corrected['expected'] === '550.00', 'baseline can be corrected before first session');

    $openOnly = $drawer->openSession($conn, [
        'user_id' => 10,
        'opened_by' => 10,
        'tenant' => 1,
        'branch' => 2,
        'opening_cash' => '550.000',
    ]);

    $baselineLocked = false;
    try {
        $service->setOpeningBaseline($conn, 1, 2, '600.000', 10);
    } catch (RuntimeException $exception) {
        $baselineLocked = $exception->getMessage() === 'BASELINE_LOCKED';
    }
    drawerFloatAssert($baselineLocked, 'baseline locked after first drawer session opens');

    $first = $openOnly;
    $drawer->recordMovement($conn, (int) $first['id'], [
        'movement_type' => 'sale_cash',
        'amount' => '50.000',
        'created_by' => 10,
    ]);
    $drawer->closeSession($conn, (int) $first['id'], [
        'closed_by' => 10,
        'counted_cash' => '150.000',
    ]);

    $baselineNotRequired = false;
    try {
        $service->setOpeningBaseline($conn, 1, 2, '700.000', 10);
    } catch (RuntimeException $exception) {
        $baselineNotRequired = $exception->getMessage() === 'BASELINE_NOT_REQUIRED';
    }
    drawerFloatAssert($baselineNotRequired, 'baseline not required after first close');

    $stmt = $conn->prepare("
        INSERT INTO drawer_movements (drawer_session_id, tenant, branch, movement_type, amount, created_by)
        VALUES (NULL, 1, 2, 'paid_in', '25.000', 10)
    ");
    $stmt->execute();
    $stmt->close();
    $conn->query("UPDATE drawer_movements SET created_at = DATE_ADD(NOW(), INTERVAL 2 SECOND) WHERE drawer_session_id IS NULL");

    $expected = $service->expectedOpeningFloat($conn, 1, 2);
    drawerFloatAssert($expected['base_counted'] === '150.00', 'base counted should be last close');
    drawerFloatAssert($expected['unassigned_net'] === '25.00', 'unassigned pay-in should add to expected');
    drawerFloatAssert($expected['expected'] === '175.00', 'expected opening should include post-close pay-in');
    drawerFloatAssert($service->amountsMatch('175.00', '175.00', '0.01'), 'tolerance match expected');
    drawerFloatAssert(!$service->amountsMatch('170.00', '175.00', '0.01'), 'mismatch outside tolerance');

    $afterClose = $service->expectedOpeningFloat($conn, 1, 2);
    drawerFloatAssert($afterClose['base_counted'] === '150.00', 'after first close baseline ignored');
    drawerFloatAssert(empty($afterClose['baseline_required']), 'baseline not required after close');

    echo "drawer_float_expectation_service_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}
