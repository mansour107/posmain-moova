<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/OperationalSyncEventService.php';
require_once __DIR__ . '/../../classes/Sync/InventoryCountSyncPayloadService.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';
require_once __DIR__ . '/../../classes/Sync/RestoreEventPhase.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$sourceDb = 'posmain_restore_inventory_count_source_' . getmypid();
$targetDb = 'posmain_restore_inventory_count_target_' . getmypid();
$conflictDb = 'posmain_restore_inventory_count_conflict_' . getmypid();
$admin = new mysqli($host, $user, $pass, '', $port);
$source = null;
$target = null;
$conflict = null;

try {
    foreach ([$sourceDb, $targetDb, $conflictDb] as $db) {
        $admin->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }
    $source = new mysqli($host, $user, $pass, $sourceDb, $port);
    $target = new mysqli($host, $user, $pass, $targetDb, $port);
    $conflict = new mysqli($host, $user, $pass, $conflictDb, $port);
    foreach ([$source, $target, $conflict] as $conn) {
        (new SyncSchemaManager())->apply($conn);
    }

    $config = branchRestoreInventoryCountConfig();
    (new SyncBranchIdentity())->ensure($source, $config);
    branchRestoreInventoryCountSeed($source);
    (new OperationalSyncEventService())->recordInventoryCountSnapshot($source, 81, [
        'config' => $config,
        'source_system' => 'inventory_count',
    ]);
    $event = branchRestoreInventoryCountEvent($source, 81);
    branchRestoreInventoryCountAssert(
        RestoreEventPhase::classify($event) === RestoreEventPhase::OPERATIONAL,
        'inventory count must restore only in the guarded operational phase'
    );

    $inbox = new SyncInboxService();
    $applied = $inbox->receiveBranchEvent(
        $target,
        branchRestoreInventoryCountBranchUuid(),
        $event,
        SyncApplyMode::LIVE_APPLY
    );
    branchRestoreInventoryCountAssert($applied['status'] === 'processed', 'typed inventory count event should apply');
    $restored = $target->query('SELECT * FROM inventory_counts WHERE id = 81')->fetch_assoc();
    branchRestoreInventoryCountAssert(is_array($restored), 'count parent should restore');
    branchRestoreInventoryCountAssert((string) $restored['count_uuid'] === branchRestoreInventoryCountUuid(), 'native count identity should restore');
    branchRestoreInventoryCountAssert((string) $restored['status'] === 'approved', 'workflow status should restore');
    branchRestoreInventoryCountAssert((int) $restored['hide_expected_qty'] === 1, 'blind-count policy should restore');
    branchRestoreInventoryCountAssert((int) $restored['approved_by'] === 13, 'approval audit evidence should restore');
    branchRestoreInventoryCountAssert((int) $restored['sync_revision'] === 3, 'strict parent revision should restore');
    branchRestoreInventoryCountAssert(branchRestoreInventoryCountTableCount($target, 'inventory_count_lines') === 2, 'the complete line set should restore');
    $firstLine = $target->query('SELECT * FROM inventory_count_lines WHERE id = 811')->fetch_assoc();
    branchRestoreInventoryCountAssert((string) $firstLine['snapshot_qty'] === '8.000000', 'snapshot evidence should restore');
    branchRestoreInventoryCountAssert((string) $firstLine['counted_qty'] === '7.000000', 'counted quantity should restore');
    branchRestoreInventoryCountAssert((int) $firstLine['counted_by'] === 11, 'counter audit evidence should restore');

    $duplicate = $inbox->receiveBranchEvent(
        $target,
        branchRestoreInventoryCountBranchUuid(),
        $event,
        SyncApplyMode::LIVE_APPLY
    );
    branchRestoreInventoryCountAssert($duplicate['status'] === 'duplicate', 'exact replay should be idempotent');
    branchRestoreInventoryCountAssert(branchRestoreInventoryCountTableCount($target, 'inventory_count_lines') === 2, 'exact replay must not duplicate lines');

    $stale = $event;
    $stale['event_uuid'] = SyncBranchIdentity::generateUuidV4();
    $stale['idempotency_key'] = 'inventory-count-stale:' . $stale['event_uuid'];
    $stale['event_version'] = 2;
    $stale['payload']['sync_revision'] = 2;
    $stale['payload']['inventory_count']['sync_revision'] = 2;
    $stale['payload']['inventory_count']['notes'] = 'Older submitted evidence';
    branchRestoreInventoryCountRehash($stale);
    $staleResult = $inbox->receiveBranchEvent(
        $target,
        branchRestoreInventoryCountBranchUuid(),
        $stale,
        SyncApplyMode::LIVE_APPLY
    );
    branchRestoreInventoryCountAssert($staleResult['status'] === 'stale', 'older count revision should be acknowledged stale');
    branchRestoreInventoryCountAssert((string) $target->query('SELECT notes FROM inventory_counts WHERE id = 81')->fetch_assoc()['notes'] === 'Manager-approved blind count', 'stale event must not rewind parent data');

    $sameVersionConflict = $event;
    $sameVersionConflict['event_uuid'] = SyncBranchIdentity::generateUuidV4();
    $sameVersionConflict['idempotency_key'] = 'inventory-count-same-version:' . $sameVersionConflict['event_uuid'];
    $sameVersionConflict['payload']['inventory_count']['notes'] = 'Conflicting same-version evidence';
    branchRestoreInventoryCountRehash($sameVersionConflict);
    $conflictResult = $inbox->receiveBranchEvent(
        $target,
        branchRestoreInventoryCountBranchUuid(),
        $sameVersionConflict,
        SyncApplyMode::LIVE_APPLY
    );
    branchRestoreInventoryCountAssert($conflictResult['status'] === 'conflict', 'changed same-version content must fail closed');
    branchRestoreInventoryCountAssert((string) $target->query('SELECT notes FROM inventory_counts WHERE id = 81')->fetch_assoc()['notes'] === 'Manager-approved blind count', 'same-version conflict must not overwrite parent data');

    $missingLine = $event;
    $missingLine['event_uuid'] = SyncBranchIdentity::generateUuidV4();
    $missingLine['idempotency_key'] = 'inventory-count-missing-line:' . $missingLine['event_uuid'];
    $missingLine['event_version'] = 4;
    $missingLine['payload']['sync_revision'] = 4;
    $missingLine['payload']['inventory_count']['sync_revision'] = 4;
    $missingLine['payload']['inventory_count']['updated_at'] = '2026-07-16 12:04:00';
    array_pop($missingLine['payload']['inventory_count_lines']);
    $missingLine['payload']['totals']['line_count'] = 1;
    branchRestoreInventoryCountRehash($missingLine);
    branchRestoreInventoryCountExpectCode(
        static fn() => $inbox->receiveBranchEvent(
            $target,
            branchRestoreInventoryCountBranchUuid(),
            $missingLine,
            SyncApplyMode::LIVE_APPLY
        ),
        'INVENTORY_COUNT_SYNC_MISSING_LINE_CONFLICT'
    );
    branchRestoreInventoryCountAssert(branchRestoreInventoryCountTableCount($target, 'inventory_count_lines') === 2, 'absence in a newer payload must never delete a stored line');
    branchRestoreInventoryCountAssert((int) $target->query('SELECT sync_revision FROM inventory_counts WHERE id = 81')->fetch_assoc()['sync_revision'] === 3, 'failed non-deletion projection must roll back parent revision');
    $cursor = $target->query("SELECT last_event_version FROM sync_projection_versions WHERE aggregate_type = 'inventory_count' AND aggregate_uuid = '" . branchRestoreInventoryCountUuid() . "'")->fetch_assoc();
    branchRestoreInventoryCountAssert((int) $cursor['last_event_version'] === 3, 'failed projection must not advance its version cursor');

    branchRestoreInventoryCountSeedConflictingParent($conflict);
    branchRestoreInventoryCountExpectCode(
        static fn() => (new SyncInboxService())->receiveBranchEvent(
            $conflict,
            branchRestoreInventoryCountBranchUuid(),
            $event,
            SyncApplyMode::LIVE_APPLY
        ),
        'INVENTORY_COUNT_SYNC_PARENT_IDENTITY_CONFLICT'
    );
    branchRestoreInventoryCountAssert(branchRestoreInventoryCountTableCount($conflict, 'inventory_count_lines') === 0, 'identity conflict must not insert child lines');
    branchRestoreInventoryCountAssert((string) $conflict->query('SELECT count_uuid FROM inventory_counts WHERE id = 81')->fetch_assoc()['count_uuid'] === '81818181-8181-4181-8181-818181818189', 'identity conflict must preserve the existing parent');
    branchRestoreInventoryCountAssert(branchRestoreInventoryCountTableCount($conflict, 'sync_projection_versions') === 0, 'identity conflict must roll back its cursor reservation');

    echo "branch-restore-inventory-count-ok source={$sourceDb} target={$targetDb}\n";
} finally {
    foreach ([$source, $target, $conflict] as $conn) {
        if ($conn instanceof mysqli) {
            $conn->close();
        }
    }
    foreach ([$sourceDb, $targetDb, $conflictDb] as $db) {
        $admin->query("DROP DATABASE IF EXISTS `{$db}`");
    }
    $admin->close();
}

function branchRestoreInventoryCountBranchUuid(): string
{
    return '80808080-8080-4080-8080-808080808080';
}

function branchRestoreInventoryCountUuid(): string
{
    return '81818181-8181-4181-8181-818181818181';
}

function branchRestoreInventoryCountConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => branchRestoreInventoryCountBranchUuid(),
            'name' => 'Inventory Count Restore',
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

function branchRestoreInventoryCountSeed(mysqli $conn): void
{
    $branchUuid = branchRestoreInventoryCountBranchUuid();
    $countUuid = branchRestoreInventoryCountUuid();
    $stmt = $conn->prepare("INSERT INTO inventory_counts (
        id, count_uuid, pos_tenant, pos_branch, branch_uuid, store_id, status, count_type,
        hide_expected_qty, include_zero_stock, assigned_user_id, created_by, submitted_by,
        approved_by, created_at, submitted_at, approved_at, updated_at, notes, sync_revision
    ) VALUES (
        81, ?, 1, 1, ?, 1, 'approved', 'selected', 1, 0, 10, 10, 12, 13,
        '2026-07-16 12:00:00', '2026-07-16 12:02:00', '2026-07-16 12:03:00',
        '2026-07-16 12:03:00', 'Manager-approved blind count', 3
    )");
    $stmt->bind_param('ss', $countUuid, $branchUuid);
    $stmt->execute();
    $stmt->close();
    $conn->query("INSERT INTO inventory_count_lines (
        id, count_id, item_id, unit_conversion_to_base, snapshot_qty, counted_qty,
        variance_qty, variance_percent, variance_cost, snapshot_last_movement_id,
        stale_count_conflict, counted_by, counted_at, notes, created_at, updated_at
    ) VALUES
        (811, 81, 9101, '1.00000000', '8.000000', '7.000000', '-1.000000', '-12.500000', '2.000000', 701, 0, 11, '2026-07-16 12:01:00', 'Front shelf', '2026-07-16 12:00:00', '2026-07-16 12:01:00'),
        (812, 81, 9102, '1.00000000', '3.000000', '3.000000', '0.000000', '0.000000', '0.000000', 702, 1, 11, '2026-07-16 12:01:30', 'Stale evidence approved', '2026-07-16 12:00:00', '2026-07-16 12:01:30')");
}

function branchRestoreInventoryCountSeedConflictingParent(mysqli $conn): void
{
    $branchUuid = branchRestoreInventoryCountBranchUuid();
    $countUuid = '81818181-8181-4181-8181-818181818189';
    $stmt = $conn->prepare("INSERT INTO inventory_counts (
        id, count_uuid, pos_tenant, pos_branch, branch_uuid, store_id, status, count_type,
        hide_expected_qty, include_zero_stock, created_by, created_at, updated_at, sync_revision
    ) VALUES (81, ?, 1, 1, ?, 1, 'draft', 'selected', 0, 0, 99, '2026-07-15 10:00:00', '2026-07-15 10:00:00', 1)");
    $stmt->bind_param('ss', $countUuid, $branchUuid);
    $stmt->execute();
    $stmt->close();
}

function branchRestoreInventoryCountEvent(mysqli $conn, int $countId): array
{
    $row = $conn->query("SELECT * FROM sync_outbox WHERE aggregate_type = 'inventory_count' AND aggregate_local_id = {$countId} LIMIT 1")->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('inventory count restore event missing');
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

function branchRestoreInventoryCountRehash(array &$event): void
{
    unset($event['payload']['payload_hash']);
    $event['payload']['payload_hash'] = hash('sha256', InventoryCountSyncPayloadService::encodeJson($event['payload']));
    $event['payload_hash'] = hash('sha256', InventoryCountSyncPayloadService::encodeJson($event['payload']));
}

function branchRestoreInventoryCountTableCount(mysqli $conn, string $table): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM `{$table}`")->fetch_assoc()['c'];
}

function branchRestoreInventoryCountExpectCode(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        branchRestoreInventoryCountAssert($exception->getMessage() === $code, 'expected ' . $code . ', got ' . $exception->getMessage());
        return;
    }

    throw new RuntimeException('expected failure: ' . $code);
}

function branchRestoreInventoryCountAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
