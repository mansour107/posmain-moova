<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/OperationalSyncEventService.php';
require_once __DIR__ . '/../../classes/Sync/PurchaseReceiptSyncPayloadService.php';
require_once __DIR__ . '/../../classes/Sync/CloudOperationalMirrorService.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';
require_once __DIR__ . '/../../classes/Sync/RestoreEventPhase.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$sourceDb = 'posmain_restore_receipt_source_' . getmypid();
$targetDb = 'posmain_restore_receipt_target_' . getmypid();
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
        branchRestoreReceiptSeedMovements($conn);
    }
    branchRestoreReceiptSeedDocument($source);

    $config = branchRestoreReceiptConfig();
    (new SyncBranchIdentity())->ensure($source, $config);
    (new OperationalSyncEventService())->recordPurchaseReceiptSnapshot($source, 81, ['config' => $config]);
    (new OperationalSyncEventService())->recordPurchaseReceiptSnapshot($source, 82, ['config' => $config]);
    $event = branchRestoreReceiptEvent($source, 81);
    $returnEvent = branchRestoreReceiptEvent($source, 82);
    $outboxCountBeforeHostedAttempt = branchRestoreReceiptCount($source, 'sync_outbox');
    $hostedConfig = $config;
    $hostedConfig['role'] = 'cloud';
    (new OperationalSyncEventService())->recordPurchaseReceiptSnapshot($source, 81, ['config' => $hostedConfig]);
    branchRestoreReceiptAssert(
        branchRestoreReceiptCount($source, 'sync_outbox') === $outboxCountBeforeHostedAttempt,
        'hosted-role receipt handling must not emit an automatic reverse event'
    );
    branchRestoreReceiptAssert(
        RestoreEventPhase::classify($event) === RestoreEventPhase::OPERATIONAL,
        'purchase receipts must restore only in the guarded operational phase'
    );

    $validator = new PurchaseReceiptSyncPayloadService();
    $unknown = $event['payload'];
    $unknown['unexpected'] = true;
    branchRestoreReceiptPayloadRehash($unknown);
    branchRestoreReceiptExpectCode(
        static fn() => $validator->assertValid($unknown, branchRestoreReceiptBranchUuid(), $event),
        'PURCHASE_RECEIPT_SYNC_PAYLOAD_INVALID'
    );
    $wrongParent = $event['payload'];
    $wrongParent['purchase_receipt_lines'][0]['purchase_receipt_id'] = 82;
    branchRestoreReceiptPayloadRehash($wrongParent);
    branchRestoreReceiptExpectCode(
        static fn() => $validator->assertValid($wrongParent, branchRestoreReceiptBranchUuid(), $event),
        'PURCHASE_RECEIPT_SYNC_LINE_INVALID'
    );
    branchRestoreReceiptExpectCode(
        static fn() => $validator->assertMovementOwnership(
            $source,
            82,
            $event['payload']['purchase_receipt'],
            $event['payload']['purchase_receipt_lines']
        ),
        'PURCHASE_RECEIPT_SYNC_MOVEMENT_SCOPE_INVALID'
    );

    $movementCount = branchRestoreReceiptCount($target, 'inventory_movements');
    $balanceCount = branchRestoreReceiptCount($target, 'inventory_item_balances');
    $inbox = new SyncInboxService();
    $applied = $inbox->receiveBranchEvent($target, branchRestoreReceiptBranchUuid(), $event, SyncApplyMode::LIVE_APPLY);
    branchRestoreReceiptAssert($applied['status'] === 'processed', 'receipt bundle should apply');
    $restored = $target->query('SELECT * FROM inventory_purchase_receipts WHERE id = 81')->fetch_assoc();
    branchRestoreReceiptAssert(is_array($restored), 'receipt parent should restore');
    branchRestoreReceiptAssert((string) $restored['purchase_receipt_uuid'] === branchRestoreReceiptUuid(), 'native receipt identity should restore');
    branchRestoreReceiptAssert((string) $restored['status'] === 'posted', 'terminal receipt status should restore');
    branchRestoreReceiptAssert((string) $restored['supplier_invoice_no'] === 'SUP-CLOUD-81', 'supplier invoice evidence should restore');
    branchRestoreReceiptAssert(branchRestoreReceiptCount($target, 'inventory_purchase_receipt_lines') === 2, 'complete receipt line evidence should restore');
    branchRestoreReceiptAssert(branchRestoreReceiptCount($target, 'inventory_movements') === $movementCount, 'document restore must not duplicate movements');
    branchRestoreReceiptAssert(branchRestoreReceiptCount($target, 'inventory_item_balances') === $balanceCount, 'document restore must not mutate balances');

    $returnApplied = $inbox->receiveBranchEvent($target, branchRestoreReceiptBranchUuid(), $returnEvent, SyncApplyMode::LIVE_APPLY);
    branchRestoreReceiptAssert($returnApplied['status'] === 'processed', 'purchase return bundle should apply');
    $restoredReturn = $target->query('SELECT * FROM inventory_purchase_receipts WHERE id = 82')->fetch_assoc();
    branchRestoreReceiptAssert((string) ($restoredReturn['status'] ?? '') === 'returned', 'terminal return status should restore');
    $restoredReturnLine = $target->query('SELECT received_qty, returned_qty FROM inventory_purchase_receipt_lines WHERE id = 821')->fetch_assoc();
    branchRestoreReceiptAssert((string) $restoredReturnLine['received_qty'] === '0.000000', 'return restore should preserve zero received quantity');
    branchRestoreReceiptAssert((string) $restoredReturnLine['returned_qty'] === '1.000000', 'return restore should preserve returned quantity');
    branchRestoreReceiptAssert(branchRestoreReceiptCount($target, 'inventory_movements') === $movementCount, 'return document restore must not duplicate movements');

    $duplicate = $inbox->receiveBranchEvent($target, branchRestoreReceiptBranchUuid(), $event, SyncApplyMode::LIVE_APPLY);
    branchRestoreReceiptAssert($duplicate['status'] === 'duplicate', 'exact immutable receipt replay should be idempotent');
    branchRestoreReceiptAssert(
        (int) $target->query('SELECT COUNT(*) AS c FROM inventory_purchase_receipt_lines WHERE purchase_receipt_id = 81')->fetch_assoc()['c'] === 2,
        'exact replay must not duplicate lines'
    );

    $changed = $event;
    branchRestoreReceiptNewIdentity($changed, 'changed');
    $changed['payload']['purchase_receipt']['notes'] = 'Wrong older content';
    branchRestoreReceiptRehash($changed);
    $conflict = $inbox->receiveBranchEvent($target, branchRestoreReceiptBranchUuid(), $changed, SyncApplyMode::LIVE_APPLY);
    branchRestoreReceiptAssert($conflict['status'] === 'conflict', 'changed same-version receipt must fail closed');
    branchRestoreReceiptAssert((string) $target->query('SELECT notes FROM inventory_purchase_receipts WHERE id = 81')->fetch_assoc()['notes'] === 'Dock delivery', 'conflict must not overwrite receipt data');

    $missing = $event;
    array_shift($missing['payload']['purchase_receipt_lines']);
    $missing['payload']['totals']['line_count'] = 1;
    branchRestoreReceiptRehash($missing);
    branchRestoreReceiptExpectCode(
        static fn() => (new CloudOperationalMirrorService())->applyFromBranchEvent($target, branchRestoreReceiptBranchUuid(), $missing),
        'PURCHASE_RECEIPT_SYNC_MISSING_LINE_CONFLICT'
    );
    branchRestoreReceiptAssert(
        (int) $target->query('SELECT COUNT(*) AS c FROM inventory_purchase_receipt_lines WHERE purchase_receipt_id = 81')->fetch_assoc()['c'] === 2,
        'absence must never delete a stored receipt line'
    );

    echo "branch-restore-purchase-receipt-ok source={$sourceDb} target={$targetDb}\n";
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

function branchRestoreReceiptBranchUuid(): string
{
    return '81818181-8181-4181-8181-818181818181';
}

function branchRestoreReceiptUuid(): string
{
    return '82828282-8282-4282-8282-828282828282';
}

function branchRestoreReturnUuid(): string
{
    return '85858585-8585-4585-8585-858585858585';
}

function branchRestoreReceiptConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => branchRestoreReceiptBranchUuid(),
            'name' => 'Receipt Restore',
            'pos_tenant' => 2,
            'pos_branch' => 3,
        ],
        'sync' => [
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'operational_sync_enabled' => true,
        ],
    ];
}

function branchRestoreReceiptSeedDocument(mysqli $conn): void
{
    $branch = branchRestoreReceiptBranchUuid();
    $uuid = branchRestoreReceiptUuid();
    $stmt = $conn->prepare("INSERT INTO inventory_purchase_receipts (
        id, purchase_receipt_uuid, pos_tenant, pos_branch, branch_uuid,
        supplier_account_id, destination_store_id, supplier_invoice_no, status,
        received_at, posted_at, created_by, posted_by, created_at, updated_at, notes
    ) VALUES (
        81, ?, 2, 3, ?, 44, 5, 'SUP-CLOUD-81', 'posted',
        '2026-07-16 13:00:00', '2026-07-16 13:00:00', 7, 7,
        '2026-07-16 13:00:00', '2026-07-16 13:00:00', 'Dock delivery'
    )");
    $stmt->bind_param('ss', $uuid, $branch);
    $stmt->execute();
    $stmt->close();
    $conn->query("INSERT INTO inventory_purchase_receipt_lines (
        id, purchase_receipt_id, item_id, received_qty, returned_qty, unit_cost,
        total_cost, inventory_movement_id, created_at, updated_at
    ) VALUES
        (811, 81, 501, '2.000000', '0.000000', '4.000000', '8.000000', 801, '2026-07-16 13:00:00', '2026-07-16 13:00:00'),
        (812, 81, 502, '3.000000', '0.000000', '5.000000', '15.000000', 802, '2026-07-16 13:00:00', '2026-07-16 13:00:00')");
    $returnUuid = branchRestoreReturnUuid();
    $stmt = $conn->prepare("INSERT INTO inventory_purchase_receipts (
        id, purchase_receipt_uuid, pos_tenant, pos_branch, branch_uuid,
        supplier_account_id, destination_store_id, supplier_invoice_no, status,
        received_at, posted_at, created_by, posted_by, created_at, updated_at, notes
    ) VALUES (
        82, ?, 2, 3, ?, 44, 5, 'SUP-RETURN-82', 'returned',
        '2026-07-16 14:00:00', '2026-07-16 14:00:00', 7, 7,
        '2026-07-16 14:00:00', '2026-07-16 14:00:00', 'Supplier return'
    )");
    $stmt->bind_param('ss', $returnUuid, $branch);
    $stmt->execute();
    $stmt->close();
    $conn->query("INSERT INTO inventory_purchase_receipt_lines (
        id, purchase_receipt_id, item_id, received_qty, returned_qty, unit_cost,
        total_cost, inventory_movement_id, created_at, updated_at
    ) VALUES
        (821, 82, 501, '0.000000', '1.000000', '4.000000', '4.000000', 803, '2026-07-16 14:00:00', '2026-07-16 14:00:00')");
}

function branchRestoreReceiptSeedMovements(mysqli $conn): void
{
    $branch = branchRestoreReceiptBranchUuid();
    $receipt = branchRestoreReceiptUuid();
    $returnReceipt = branchRestoreReturnUuid();
    $metadata = json_encode(['purchase_receipt_id' => 81], JSON_THROW_ON_ERROR);
    $returnMetadata = json_encode(['purchase_receipt_id' => 82], JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare("INSERT INTO inventory_movements (
        id, movement_uuid, pos_tenant, pos_branch, branch_uuid, store_id, item_id,
        movement_type, source_type, source_id, source_uuid, qty_in, qty_out,
        unit_cost, total_cost, idempotency_key, metadata_json, created_at
    ) VALUES
        (801, '83838383-8383-4383-8383-838383838383', 2, 3, ?, 5, 501,
         'purchase', 'purchase_receipt', 811, ?, '2.000000', '0.000000',
         '4.000000', '8.000000', 'receipt-test-801', ?, '2026-07-16 13:00:00'),
        (802, '84848484-8484-4484-8484-848484848484', 2, 3, ?, 5, 502,
         'purchase', 'purchase_receipt', 812, ?, '3.000000', '0.000000',
         '5.000000', '15.000000', 'receipt-test-802', ?, '2026-07-16 13:00:00'),
        (803, '86868686-8686-4686-8686-868686868686', 2, 3, ?, 5, 501,
         'purchase_return', 'purchase_receipt', 821, ?, '0.000000', '1.000000',
         '4.000000', '4.000000', 'receipt-test-803', ?, '2026-07-16 14:00:00')");
    $sourceOne = 'purchase-receipt:' . $receipt . ':line:811';
    $sourceTwo = 'purchase-receipt:' . $receipt . ':line:812';
    $sourceThree = 'purchase-return:' . $returnReceipt . ':line:821';
    $stmt->bind_param('sssssssss', $branch, $sourceOne, $metadata, $branch, $sourceTwo, $metadata, $branch, $sourceThree, $returnMetadata);
    $stmt->execute();
    $stmt->close();
}

function branchRestoreReceiptEvent(mysqli $conn, int $receiptId): array
{
    $row = $conn->query("SELECT * FROM sync_outbox WHERE aggregate_type = 'purchase_receipt' AND aggregate_local_id = " . $receiptId . ' LIMIT 1')->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('purchase receipt restore event missing');
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

function branchRestoreReceiptNewIdentity(array &$event, string $suffix): void
{
    $event['event_uuid'] = SyncBranchIdentity::generateUuidV4();
    $event['idempotency_key'] = 'purchase-receipt-' . $suffix . ':' . $event['event_uuid'];
}

function branchRestoreReceiptRehash(array &$event): void
{
    branchRestoreReceiptPayloadRehash($event['payload']);
    $event['payload_hash'] = hash('sha256', PurchaseReceiptSyncPayloadService::encodeJson($event['payload']));
}

function branchRestoreReceiptPayloadRehash(array &$payload): void
{
    unset($payload['payload_hash']);
    $payload['payload_hash'] = hash('sha256', PurchaseReceiptSyncPayloadService::encodeJson($payload));
}

function branchRestoreReceiptCount(mysqli $conn, string $table): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM `{$table}`")->fetch_assoc()['c'];
}

function branchRestoreReceiptExpectCode(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        branchRestoreReceiptAssert($exception->getMessage() === $code, "expected {$code}, got {$exception->getMessage()}");
        return;
    }
    throw new RuntimeException("Expected {$code}");
}

function branchRestoreReceiptAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
