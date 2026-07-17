<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ManagerApprovalService.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';

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
    $branchUuid = 'ec37eaf7-e759-4f74-a779-163a1302405e';
    $syncConfig = [
        'role' => 'branch',
        'branch' => ['uuid' => $branchUuid, 'name' => 'Approval test', 'pos_tenant' => 1, 'pos_branch' => 1],
        'sync' => [
            'branch_sync_enabled' => true,
            'outbox_enabled' => true,
            'operational_sync_enabled' => true,
        ],
    ];

    $service = new ManagerApprovalService();
    $request = $service->requestApproval($conn, [
        'action_type' => 'discount.override',
        'target_type' => 'pos_order',
        'requested_by' => 7,
        'reason' => 'VIP discount',
        'metadata' => ['discount' => 15.5],
        'sync_config' => $syncConfig,
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
        'sync_config' => $syncConfig,
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
    $consumed = $service->consumeApproval($conn, (int) $request['id'], 7, ['sync_config' => $syncConfig]);
    phase4ManagerApprovalAssert(!empty($consumed['consumed_at']), 'approved audit row should be consumable');
    $lifecycleEvents = phase4ManagerApprovalOutboxRows($conn, (int) $request['id']);
    phase4ManagerApprovalAssert(count($lifecycleEvents) === 3, 'request, decision and consumption must each append an outbox snapshot');
    phase4ManagerApprovalAssert(
        array_map('intval', array_column($lifecycleEvents, 'event_version')) === [1, 2, 3],
        'approval lifecycle revisions must be strictly monotonic 1, 2, 3'
    );

    $recipeOverride = $service->requestApproval($conn, [
        'action_type' => 'recipe.stock_override',
        'target_type' => 'item',
        'target_id' => 501,
        'requested_by' => 7,
        'reason' => 'ingredient stock override',
        'metadata' => ['unavailable_reason' => 'Required ingredient out of stock.'],
    ]);
    $service->decide($conn, (int) $recipeOverride['id'], [
        'approved_by' => 2,
        'status' => 'approved',
    ]);
    $recipePassed = $service->requireApprovedIfNeeded($conn, 'recipe.stock_override', 'item', 501, 1.0, [
        'manager_approval_id' => $recipeOverride['id'],
    ], [
        'require_manager_approval' => true,
    ]);
    phase4ManagerApprovalAssert((int) $recipePassed['id'] === (int) $recipeOverride['id'], 'recipe stock override approval should pass');

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
        $service->decide($conn, (int) $declined['id'], [
            'approved_by' => 3,
            'status' => 'approved',
        ]);
    }, 'APPROVAL_ALREADY_DECIDED');
    phase4ManagerApprovalExpectException(function () use ($service, $conn, $declined) {
        $service->consumeApproval($conn, (int) $declined['id'], 7);
    }, 'MANAGER_APPROVAL_NOT_APPROVED');
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

    // Hosted reordering proof: consumed revision first, then the old requested revision.
    $approvalId = (int) $request['id'];
    $conn->query("
        UPDATE manager_approvals
        SET status = 'requested', approved_by = NULL, decided_at = NULL,
            consumed_at = NULL, performed_by = NULL
        WHERE id = {$approvalId}
    ");
    $inbox = new SyncInboxService();
    $newer = $inbox->receiveBranchEvent(
        $conn,
        $branchUuid,
        phase4ManagerApprovalEventFromOutbox($lifecycleEvents[2]),
        SyncApplyMode::LIVE_APPLY
    );
    phase4ManagerApprovalAssert($newer['status'] === 'processed', 'consumed approval revision must apply');
    $hosted = $service->approvalById($conn, $approvalId, false);
    phase4ManagerApprovalAssert($hosted['status'] === 'approved' && !empty($hosted['consumed_at']), 'newer hosted approval must be consumed');
    $older = $inbox->receiveBranchEvent(
        $conn,
        $branchUuid,
        phase4ManagerApprovalEventFromOutbox($lifecycleEvents[0]),
        SyncApplyMode::LIVE_APPLY
    );
    phase4ManagerApprovalAssert($older['status'] === 'stale', 'older requested approval must be rejected as stale');
    $hosted = $service->approvalById($conn, $approvalId, false);
    phase4ManagerApprovalAssert($hosted['status'] === 'approved' && !empty($hosted['consumed_at']), 'stale request must not clear consumed state');

    // Caller-owned rollback proof: approval and outbox are one transaction.
    $conn->begin_transaction();
    $rolledBack = $service->requestApproval($conn, [
        'action_type' => 'discount.override',
        'target_type' => 'pos_order',
        'target_id' => 300,
        'requested_by' => 7,
        'sync_config' => $syncConfig,
    ]);
    phase4ManagerApprovalAssert(
        count(phase4ManagerApprovalOutboxRows($conn, (int) $rolledBack['id'])) === 1,
        'caller transaction must see approval outbox before rollback'
    );
    $rolledBackId = (int) $rolledBack['id'];
    $conn->rollback();
    phase4ManagerApprovalAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM manager_approvals WHERE id = {$rolledBackId}")->fetch_assoc()['c'] === 0,
        'caller rollback must remove approval row'
    );
    phase4ManagerApprovalAssert(
        count(phase4ManagerApprovalOutboxRows($conn, $rolledBackId)) === 0,
        'caller rollback must remove approval outbox row'
    );

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

function phase4ManagerApprovalOutboxRows(mysqli $conn, int $approvalId): array
{
    $result = $conn->query(
        "SELECT * FROM sync_outbox WHERE aggregate_type = 'manager_approval'"
        . ' AND aggregate_local_id = ' . $approvalId
        . ' ORDER BY event_version ASC, id ASC'
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function phase4ManagerApprovalEventFromOutbox(array $row): array
{
    return [
        'event_uuid' => (string) $row['event_uuid'],
        'idempotency_key' => (string) $row['idempotency_key'],
        'payload_hash' => (string) $row['payload_hash'],
        'event_type' => (string) $row['event_type'],
        'event_version' => (int) $row['event_version'],
        'source_system' => (string) $row['source_system'],
        'aggregate_type' => (string) $row['aggregate_type'],
        'aggregate_uuid' => (string) $row['aggregate_uuid'],
        'aggregate_local_id' => (int) $row['aggregate_local_id'],
        'entity_type' => (string) $row['entity_type'],
        'entity_uuid' => (string) $row['entity_uuid'],
        'entity_local_id' => (int) $row['entity_local_id'],
        'payload' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
    ];
}
