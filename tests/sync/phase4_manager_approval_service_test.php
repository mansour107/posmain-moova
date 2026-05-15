<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ManagerApprovalService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_manager_approval_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $service = new ManagerApprovalService();
    $request = $service->requestApproval($conn, [
        'action_type' => 'discount.override',
        'target_type' => 'pos_order',
        'requested_by' => 7,
        'reason' => 'VIP discount',
        'metadata' => ['discount' => 15.5],
    ]);
    phase4ManagerApprovalAssert((int) $request['requested_by'] === 7, 'requester expected');
    phase4ManagerApprovalAssert($request['status'] === 'requested', 'requested status expected');
    phase4ManagerApprovalAssert($request['metadata']['discount'] === 15.5, 'metadata round trip expected');

    $disabled = $service->requireApprovedIfNeeded($conn, 'discount.override', 'pos_order', null, 50.0, [], []);
    phase4ManagerApprovalAssert($disabled === null, 'approval should not be required when disabled');
    $below = $service->requireApprovedIfNeeded($conn, 'discount.override', 'pos_order', null, 5.0, [], [
        'require_discount_approval' => true,
        'discount_approval_threshold' => 10.0,
    ]);
    phase4ManagerApprovalAssert($below === null, 'approval should not be required below threshold');

    phase4ManagerApprovalExpectException(function () use ($service, $conn) {
        $service->requireApprovedIfNeeded($conn, 'discount.override', 'pos_order', null, 20.0, [], [
            'require_discount_approval' => true,
            'discount_approval_threshold' => 10.0,
        ]);
    }, 'MANAGER_APPROVAL_REQUIRED');

    $approved = $service->decide($conn, (int) $request['id'], [
        'approved_by' => 2,
        'status' => 'approved',
    ]);
    phase4ManagerApprovalAssert($approved['status'] === 'approved', 'approved status expected');
    phase4ManagerApprovalAssert((int) $approved['approved_by'] === 2, 'approved_by expected');

    $passed = $service->requireApprovedIfNeeded($conn, 'discount.override', 'pos_order', null, 20.0, [
        'manager_approval_id' => $request['id'],
    ], [
        'require_discount_approval' => true,
        'discount_approval_threshold' => 10.0,
    ]);
    phase4ManagerApprovalAssert((int) $passed['id'] === (int) $request['id'], 'approved row should pass');

    $declined = $service->requestApproval($conn, [
        'action_type' => 'discount.override',
        'target_type' => 'pos_order',
        'target_id' => 100,
        'requested_by' => 7,
    ]);
    $service->decide($conn, (int) $declined['id'], [
        'approved_by' => 2,
        'status' => 'declined',
    ]);
    phase4ManagerApprovalExpectException(function () use ($service, $conn, $declined) {
        $service->requireApprovedIfNeeded($conn, 'discount.override', 'pos_order', 100, 20.0, [
            'manager_approval_id' => $declined['id'],
        ], [
            'require_discount_approval' => true,
        ]);
    }, 'MANAGER_APPROVAL_NOT_APPROVED');

    $targeted = $service->requestApproval($conn, [
        'action_type' => 'discount.override',
        'target_type' => 'pos_order',
        'target_id' => 200,
        'requested_by' => 7,
    ]);
    $service->decide($conn, (int) $targeted['id'], [
        'approved_by' => 2,
        'status' => 'approved',
    ]);
    phase4ManagerApprovalExpectException(function () use ($service, $conn, $targeted) {
        $service->requireApprovedIfNeeded($conn, 'discount.override', 'pos_order', 201, 20.0, [
            'manager_approval_id' => $targeted['id'],
        ], [
            'require_discount_approval' => true,
        ]);
    }, 'MANAGER_APPROVAL_TARGET_MISMATCH');

    echo "phase4-manager-approval-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4ManagerApprovalExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4ManagerApprovalAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4ManagerApprovalAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
