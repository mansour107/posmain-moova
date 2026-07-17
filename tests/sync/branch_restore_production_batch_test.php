<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/OperationalSyncEventService.php';
require_once __DIR__ . '/../../classes/Sync/ProductionBatchSyncPayloadService.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';
require_once __DIR__ . '/../../classes/Sync/RestoreEventPhase.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$sourceDb = 'posmain_restore_production_source_' . getmypid();
$targetDb = 'posmain_restore_production_target_' . getmypid();
$admin = new mysqli($host, $user, $pass, '', $port);
$source = null;
$target = null;

try {
    foreach ([$sourceDb, $targetDb] as $db) {
        $admin->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }
    $source = new mysqli($host, $user, $pass, $sourceDb, $port);
    $target = new mysqli($host, $user, $pass, $targetDb, $port);
    foreach ([$source, $target] as $conn) {
        (new SyncSchemaManager())->apply($conn);
    }

    $config = branchRestoreProductionConfig();
    (new SyncBranchIdentity())->ensure($source, $config);
    branchRestoreProductionSeedMovements($source);
    branchRestoreProductionSeedMovements($target);
    branchRestoreProductionSeedBatch($source);
    (new OperationalSyncEventService())->recordProductionBatchSnapshot($source, 91, [
        'config' => $config,
        'source_system' => 'production_batch',
    ]);
    $event = branchRestoreProductionEvent($source);
    branchRestoreProductionAssert(
        RestoreEventPhase::classify($event) === RestoreEventPhase::OPERATIONAL,
        'production batches must restore only in the guarded operational phase'
    );
    $validator = new ProductionBatchSyncPayloadService();
    $unknown = $event['payload'];
    $unknown['unexpected'] = true;
    branchRestoreProductionPayloadRehash($unknown);
    branchRestoreProductionExpectCode(
        static fn() => $validator->assertValid($unknown, branchRestoreProductionBranchUuid(), $event),
        'PRODUCTION_BATCH_SYNC_PAYLOAD_INVALID'
    );
    $crossParent = $event['payload'];
    $crossParent['production_batch_lines'][0]['batch_id'] = 92;
    branchRestoreProductionPayloadRehash($crossParent);
    branchRestoreProductionExpectCode(
        static fn() => $validator->assertValid($crossParent, branchRestoreProductionBranchUuid(), $event),
        'PRODUCTION_BATCH_SYNC_LINE_INVALID'
    );
    branchRestoreProductionExpectCode(
        static fn() => $validator->assertMovementOwnership($source, 92, $event['payload']['production_batch_lines']),
        'PRODUCTION_BATCH_SYNC_MOVEMENT_SCOPE_INVALID'
    );

    $movementCountBefore = branchRestoreProductionCount($target, 'inventory_movements');
    $balanceCountBefore = branchRestoreProductionCount($target, 'inventory_item_balances');
    $inbox = new SyncInboxService();
    $applied = $inbox->receiveBranchEvent(
        $target,
        branchRestoreProductionBranchUuid(),
        $event,
        SyncApplyMode::LIVE_APPLY
    );
    branchRestoreProductionAssert($applied['status'] === 'processed', 'production batch bundle should apply');
    $restored = $target->query('SELECT * FROM production_batches WHERE id = 91')->fetch_assoc();
    branchRestoreProductionAssert(is_array($restored), 'production parent should restore');
    branchRestoreProductionAssert((string) $restored['batch_uuid'] === branchRestoreProductionBatchUuid(), 'native production identity should restore');
    branchRestoreProductionAssert((string) $restored['status'] === 'committed', 'terminal workflow status should restore');
    branchRestoreProductionAssert((string) $restored['planned_output_qty'] === '10.000000', 'planned quantity should restore');
    branchRestoreProductionAssert((string) $restored['actual_output_qty'] === '9.000000', 'actual quantity should restore');
    branchRestoreProductionAssert((string) $restored['variance_reason'] === 'evaporation', 'variance evidence should restore');
    branchRestoreProductionAssert((int) $restored['sync_revision'] === 2, 'strict production revision should restore');
    branchRestoreProductionAssert(branchRestoreProductionCount($target, 'production_batch_lines') === 3, 'complete input/output line evidence should restore');
    branchRestoreProductionAssert(branchRestoreProductionCount($target, 'inventory_movements') === $movementCountBefore, 'document restore must not duplicate stock movements');
    branchRestoreProductionAssert(branchRestoreProductionCount($target, 'inventory_item_balances') === $balanceCountBefore, 'document restore must not mutate stock balances');

    $duplicate = $inbox->receiveBranchEvent(
        $target,
        branchRestoreProductionBranchUuid(),
        $event,
        SyncApplyMode::LIVE_APPLY
    );
    branchRestoreProductionAssert($duplicate['status'] === 'duplicate', 'exact production replay should be idempotent');
    branchRestoreProductionAssert(branchRestoreProductionCount($target, 'production_batch_lines') === 3, 'exact replay must not duplicate production lines');

    $stale = $event;
    branchRestoreProductionNewIdentity($stale, 'stale');
    $stale['event_version'] = 1;
    $stale['payload']['sync_revision'] = 1;
    $stale['payload']['production_batch']['sync_revision'] = 1;
    $stale['payload']['production_batch']['notes'] = 'Older production note';
    branchRestoreProductionRehash($stale);
    $staleResult = $inbox->receiveBranchEvent($target, branchRestoreProductionBranchUuid(), $stale, SyncApplyMode::LIVE_APPLY);
    branchRestoreProductionAssert($staleResult['status'] === 'stale', 'older production revision should be acknowledged stale');
    branchRestoreProductionAssert((string) $target->query('SELECT notes FROM production_batches WHERE id = 91')->fetch_assoc()['notes'] === 'Yield verification', 'stale event must not rewind production data');

    $sameVersion = $event;
    branchRestoreProductionNewIdentity($sameVersion, 'same-version');
    $sameVersion['payload']['production_batch']['notes'] = 'Conflicting same-version note';
    branchRestoreProductionRehash($sameVersion);
    $sameVersionResult = $inbox->receiveBranchEvent($target, branchRestoreProductionBranchUuid(), $sameVersion, SyncApplyMode::LIVE_APPLY);
    branchRestoreProductionAssert($sameVersionResult['status'] === 'conflict', 'changed same-version production content must fail closed');

    $immutable = $event;
    branchRestoreProductionNewIdentity($immutable, 'immutable');
    $immutable['event_version'] = 3;
    $immutable['payload']['sync_revision'] = 3;
    $immutable['payload']['production_batch']['sync_revision'] = 3;
    $immutable['payload']['production_batch']['planned_output_qty'] = '11.000000';
    branchRestoreProductionRehash($immutable);
    branchRestoreProductionExpectCode(
        static fn() => $inbox->receiveBranchEvent($target, branchRestoreProductionBranchUuid(), $immutable, SyncApplyMode::LIVE_APPLY),
        'PRODUCTION_BATCH_SYNC_PARENT_IDENTITY_CONFLICT'
    );

    $missingLine = $event;
    branchRestoreProductionNewIdentity($missingLine, 'missing-line');
    $missingLine['event_version'] = 3;
    $missingLine['payload']['sync_revision'] = 3;
    $missingLine['payload']['production_batch']['sync_revision'] = 3;
    array_shift($missingLine['payload']['production_batch_lines']);
    $missingLine['payload']['totals']['line_count'] = 2;
    branchRestoreProductionRehash($missingLine);
    branchRestoreProductionExpectCode(
        static fn() => $inbox->receiveBranchEvent($target, branchRestoreProductionBranchUuid(), $missingLine, SyncApplyMode::LIVE_APPLY),
        'PRODUCTION_BATCH_SYNC_MISSING_LINE_CONFLICT'
    );
    branchRestoreProductionAssert(branchRestoreProductionCount($target, 'production_batch_lines') === 3, 'absence in a newer bundle must never delete a stored production line');
    $cursor = $target->query("SELECT last_event_version FROM sync_projection_versions WHERE aggregate_type = 'production_batch' AND aggregate_uuid = '" . branchRestoreProductionBatchUuid() . "'")->fetch_assoc();
    branchRestoreProductionAssert((int) $cursor['last_event_version'] === 2, 'failed production projections must not advance the version cursor');

    echo "branch-restore-production-batch-ok source={$sourceDb} target={$targetDb}\n";
} finally {
    foreach ([$source, $target] as $conn) {
        if ($conn instanceof mysqli) {
            $conn->close();
        }
    }
    foreach ([$sourceDb, $targetDb] as $db) {
        $admin->query("DROP DATABASE IF EXISTS `{$db}`");
    }
    $admin->close();
}

function branchRestoreProductionBranchUuid(): string
{
    return '97979797-9797-4797-8797-979797979797';
}

function branchRestoreProductionBatchUuid(): string
{
    return '98989898-9898-4898-8898-989898989898';
}

function branchRestoreProductionConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => branchRestoreProductionBranchUuid(),
            'name' => 'Production Restore',
            'pos_tenant' => 1,
            'pos_branch' => 1,
        ],
        'sync' => [
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'operational_sync_enabled' => true,
        ],
    ];
}

function branchRestoreProductionSeedBatch(mysqli $conn): void
{
    $branchUuid = branchRestoreProductionBranchUuid();
    $batchUuid = branchRestoreProductionBatchUuid();
    $stmt = $conn->prepare("INSERT INTO production_batches (
        id, batch_uuid, pos_tenant, pos_branch, branch_uuid, store_id, recipe_id,
        output_item_id, planned_output_qty, actual_output_qty, status, committed_at,
        created_by, committed_by, variance_reason, notes, sync_revision, created_at, updated_at
    ) VALUES (
        91, ?, 1, 1, ?, 1, 31, 9303, '10.000000', '9.000000', 'committed',
        '2026-07-16 12:05:00', 14, 15, 'evaporation', 'Yield verification', 2,
        '2026-07-16 12:00:00', '2026-07-16 12:05:00'
    )");
    $stmt->bind_param('ss', $batchUuid, $branchUuid);
    $stmt->execute();
    $stmt->close();
    $conn->query("INSERT INTO production_batch_lines (
        id, batch_id, line_type, item_id, planned_qty, actual_qty, unit_cost,
        total_cost, inventory_movement_id, created_at
    ) VALUES
        (911, 91, 'input', 9301, '6.000000', '6.000000', '2.000000', '12.000000', 901, '2026-07-16 12:05:00'),
        (912, 91, 'input', 9302, '4.000000', '4.000000', '3.000000', '12.000000', 902, '2026-07-16 12:05:00'),
        (913, 91, 'output', 9303, '10.000000', '9.000000', '2.666667', '24.000000', 903, '2026-07-16 12:05:00')");
}

function branchRestoreProductionSeedMovements(mysqli $conn): void
{
    $branchUuid = branchRestoreProductionBranchUuid();
    $batchUuid = branchRestoreProductionBatchUuid();
    $stmt = $conn->prepare("INSERT INTO inventory_movements (
        id, movement_uuid, movement_group_uuid, pos_tenant, pos_branch, branch_uuid,
        store_id, item_id, movement_type, source_type, source_id, source_uuid,
        production_batch_id, qty_in, qty_out, unit_conversion_to_base, unit_cost,
        total_cost, idempotency_key, payload_hash, created_by, created_at
    ) VALUES
        (901, '91919191-9191-4191-8191-919191919191', ?, 1, 1, ?, 1, 9301, 'production_input', 'production_batch', 91, ?, 91, '0.000000', '6.000000', '1.00000000', '2.000000', '12.000000', 'production-test-input-1', '', 14, '2026-07-16 12:05:00'),
        (902, '92929292-9292-4292-8292-929292929292', ?, 1, 1, ?, 1, 9302, 'production_input', 'production_batch', 91, ?, 91, '0.000000', '4.000000', '1.00000000', '3.000000', '12.000000', 'production-test-input-2', '', 14, '2026-07-16 12:05:00'),
        (903, '93939393-9393-4393-8393-939393939393', ?, 1, 1, ?, 1, 9303, 'production_output', 'production_batch', 91, ?, 91, '9.000000', '0.000000', '1.00000000', '2.666667', '24.000000', 'production-test-output', '', 14, '2026-07-16 12:05:00')");
    $stmt->bind_param('sssssssss',
        $batchUuid, $branchUuid, $batchUuid,
        $batchUuid, $branchUuid, $batchUuid,
        $batchUuid, $branchUuid, $batchUuid
    );
    $stmt->execute();
    $stmt->close();
}

function branchRestoreProductionEvent(mysqli $conn): array
{
    $row = $conn->query("SELECT * FROM sync_outbox WHERE aggregate_type = 'production_batch' AND aggregate_local_id = 91 LIMIT 1")->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('production batch restore event missing');
    }

    return [
        'event_uuid' => (string) $row['event_uuid'],
        'idempotency_key' => (string) $row['idempotency_key'],
        'aggregate_type' => (string) $row['aggregate_type'],
        'aggregate_uuid' => (string) $row['aggregate_uuid'],
        'aggregate_local_id' => (int) $row['aggregate_local_id'],
        'entity_type' => (string) $row['entity_type'],
        'entity_uuid' => (string) $row['entity_uuid'],
        'entity_local_id' => (int) $row['entity_local_id'],
        'event_type' => (string) $row['event_type'],
        'event_version' => (int) $row['event_version'],
        'source_system' => (string) $row['source_system'],
        'payload_hash' => (string) $row['payload_hash'],
        'payload' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
    ];
}

function branchRestoreProductionNewIdentity(array &$event, string $suffix): void
{
    $event['event_uuid'] = SyncBranchIdentity::generateUuidV4();
    $event['idempotency_key'] = 'production-batch-' . $suffix . ':' . $event['event_uuid'];
}

function branchRestoreProductionRehash(array &$event): void
{
    branchRestoreProductionPayloadRehash($event['payload']);
    $event['payload_hash'] = hash('sha256', ProductionBatchSyncPayloadService::encodeJson($event['payload']));
}

function branchRestoreProductionPayloadRehash(array &$payload): void
{
    unset($payload['payload_hash']);
    $payload['payload_hash'] = hash('sha256', ProductionBatchSyncPayloadService::encodeJson($payload));
}

function branchRestoreProductionCount(mysqli $conn, string $table): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM `{$table}`")->fetch_assoc()['c'];
}

function branchRestoreProductionExpectCode(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        branchRestoreProductionAssert($exception->getMessage() === $code, "expected {$code}, got {$exception->getMessage()}");
        return;
    }
    throw new RuntimeException("Expected {$code}");
}

function branchRestoreProductionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
