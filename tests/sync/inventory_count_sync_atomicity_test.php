<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/InventoryCountSyncPayloadService.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryLedgerService.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryCountService.php';

final class InventoryCountSyncScopeResolver extends InventoryScopeResolver
{
    public function resolveForConn(mysqli $conn, array $context = [], string $mode = 'write'): array
    {
        return [
            'pos_tenant' => 1,
            'pos_branch' => 1,
            'branch_uuid' => inventoryCountSyncBranchUuid(),
            'store_id' => 1,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'source' => 'inventory_count',
        ];
    }
}

final class FailingInventoryCountSyncEventService extends OperationalSyncEventService
{
    public function recordInventoryCountSnapshot(mysqli $conn, int $countId, array $options = []): ?array
    {
        throw new RuntimeException('INVENTORY_COUNT_SYNC_CAPTURE_FAILED');
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_inventory_count_sync_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);
    inventoryCountSyncCreateAccountingSchema($conn);
    inventoryCountSyncCreateItemSchema($conn);

    $config = inventoryCountSyncConfig();
    (new SyncBranchIdentity())->ensure($conn, $config);
    $flags = new InventoryFeatureFlags($config);
    $ledger = new InventoryLedgerService($flags);
    $scopeResolver = new InventoryCountSyncScopeResolver($config);
    $service = new InventoryCountService($flags, $ledger, null, $scopeResolver);
    inventoryCountSyncSeedStock($conn, $ledger, 7101, '10.000000');
    inventoryCountSyncSeedStock($conn, $ledger, 7102, '4.000000');
    $conn->query('DELETE FROM sync_outbox');

    $draft = $service->createDraft($conn, [
        'count_uuid' => '71717171-7171-4171-8171-717171717171',
        'store_id' => 1,
        'count_type' => 'selected',
        'hide_expected_qty' => 1,
        'notes' => 'Blind opening count',
        'lines' => [['item_id' => 7101]],
    ], inventoryCountSyncContext($config, 7));
    $countId = (int) $draft['count_id'];
    inventoryCountSyncAssert(inventoryCountSyncRevision($conn, $countId) === 1, 'draft creation must start at revision 1');
    $createEvent = inventoryCountSyncLatestEvent($conn, $countId);
    $createPayload = json_decode((string) $createEvent['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    inventoryCountSyncAssert((int) $createEvent['event_version'] === 1, 'draft event must use parent revision 1');
    inventoryCountSyncAssert((string) $createEvent['aggregate_uuid'] === '71717171-7171-4171-8171-717171717171', 'native count UUID must identify the aggregate');
    inventoryCountSyncAssert((string) $createPayload['snapshot_type'] === 'inventory_count_bundle', 'count event must use the typed bundle');
    inventoryCountSyncAssert(count($createPayload['inventory_count_lines']) === 1, 'draft event must contain the complete line snapshot');

    $conn->query("DELETE FROM sync_outbox WHERE aggregate_type = 'inventory_count'");
    $replay = $service->createDraft($conn, [
        'count_uuid' => '71717171-7171-4171-8171-717171717171',
        'store_id' => 1,
        'count_type' => 'selected',
        'hide_expected_qty' => 1,
        'lines' => [['item_id' => 7101]],
    ], inventoryCountSyncContext($config, 7));
    inventoryCountSyncAssert(!empty($replay['idempotent_replay']), 'exact draft replay must reuse the count');
    inventoryCountSyncAssert(inventoryCountSyncRevision($conn, $countId) === 1, 'exact draft replay must not advance revision');
    $healedEvent = inventoryCountSyncLatestEvent($conn, $countId);
    inventoryCountSyncAssert((string) $healedEvent['payload_hash'] === (string) $createEvent['payload_hash'], 'exact replay must heal the same deterministic payload');

    $service->saveLines($conn, $countId, [
        ['item_id' => 7101, 'counted_qty' => '12.000000', 'notes' => 'Shelf recount'],
    ], inventoryCountSyncContext($config, 8));
    $service->submit($conn, $countId, inventoryCountSyncContext($config, 9));
    $service->approve($conn, $countId, inventoryCountSyncContext($config, 10));
    $versions = inventoryCountSyncEventVersions($conn, $countId);
    inventoryCountSyncAssert($versions === [1, 2, 3, 4], 'same-second mutations must emit strictly increasing revisions');
    $submittedPayload = inventoryCountSyncEventPayload($conn, $countId, 3);
    inventoryCountSyncAssert((string) $submittedPayload['inventory_count']['status'] === 'submitted', 'transition event must capture current parent state');
    inventoryCountSyncAssert((string) $submittedPayload['inventory_count_lines'][0]['counted_qty'] === '12.000000', 'transition event must retain the complete current line state');

    $beforeCloseOutboxId = inventoryCountSyncMaxOutboxId($conn);
    $closed = $service->close($conn, $countId, inventoryCountSyncContext($config, 11));
    inventoryCountSyncAssert((string) $closed['status'] === 'closed', 'approved count must close');
    inventoryCountSyncAssert(inventoryCountSyncRevision($conn, $countId) === 5, 'close must advance exactly one count revision');
    $closeEvents = inventoryCountSyncOutboxAfter($conn, $beforeCloseOutboxId);
    $closeAggregateTypes = array_column($closeEvents, 'aggregate_type');
    $journalPosition = array_search('inventory_journal', $closeAggregateTypes, true);
    $countPosition = array_search('inventory_count', $closeAggregateTypes, true);
    inventoryCountSyncAssert($journalPosition !== false && $countPosition !== false && $journalPosition < $countPosition, 'valuation journal event must precede the final count snapshot');
    inventoryCountSyncAssert((string) end($closeEvents)['aggregate_type'] === 'inventory_count', 'count snapshot must follow its movement and balance events');
    inventoryCountSyncAssert((int) end($closeEvents)['event_version'] === 5, 'final close snapshot must carry revision 5');

    $service->reverseClosed($conn, $countId, array_merge(inventoryCountSyncContext($config, 12), ['reason' => 'Approved recount correction']));
    inventoryCountSyncAssert(inventoryCountSyncRevision($conn, $countId) === 6, 'reversal must advance exactly one count revision');
    $cancelledPayload = inventoryCountSyncEventPayload($conn, $countId, 6);
    inventoryCountSyncAssert((string) $cancelledPayload['inventory_count']['status'] === 'cancelled', 'reversal snapshot must preserve cancellation audit state');
    inventoryCountSyncAssert(strpos((string) $cancelledPayload['inventory_count']['notes'], 'Approved recount correction') !== false, 'reversal snapshot must retain its audit reason');

    $validator = new InventoryCountSyncPayloadService();
    $unknown = $cancelledPayload;
    $unknown['unexpected'] = true;
    inventoryCountSyncExpectCode(
        static fn() => $validator->assertValid($unknown, inventoryCountSyncBranchUuid()),
        'INVENTORY_COUNT_SYNC_PAYLOAD_INVALID'
    );
    $crossParent = $cancelledPayload;
    $crossParent['inventory_count_lines'][0]['count_id'] = $countId + 99;
    inventoryCountSyncRehash($crossParent);
    inventoryCountSyncExpectCode(
        static fn() => $validator->assertValid($crossParent, inventoryCountSyncBranchUuid()),
        'INVENTORY_COUNT_SYNC_LINE_INVALID'
    );

    $failureDraft = $service->createDraft($conn, [
        'count_uuid' => '72727272-7272-4272-8272-727272727272',
        'store_id' => 1,
        'lines' => [['item_id' => 7102]],
    ], inventoryCountSyncContext($config, 13));
    $failureId = (int) $failureDraft['count_id'];
    $failingService = new InventoryCountService(
        $flags,
        $ledger,
        null,
        $scopeResolver,
        null,
        new FailingInventoryCountSyncEventService()
    );
    $conn->begin_transaction();
    inventoryCountSyncExpectCode(
        static fn() => $failingService->saveLines(
            $conn,
            $failureId,
            [['item_id' => 7102, 'counted_qty' => '7.000000']],
            inventoryCountSyncContext($config, 14)
        ),
        'INVENTORY_COUNT_SYNC_CAPTURE_FAILED'
    );
    inventoryCountSyncAssert(inventoryCountSyncInTransaction($conn), 'caller-owned capture failure must leave the caller transaction active');
    inventoryCountSyncAssert(inventoryCountSyncRevision($conn, $failureId) === 1, 'savepoint rollback must restore the previous revision');
    inventoryCountSyncAssert(inventoryCountSyncCountedQty($conn, $failureId) === null, 'savepoint rollback must remove the failed line edit');
    $conn->rollback();

    $service->saveLines($conn, $failureId, [['item_id' => 7102, 'counted_qty' => '6.000000']], inventoryCountSyncContext($config, 14));
    $service->submit($conn, $failureId, inventoryCountSyncContext($config, 14));
    $service->approve($conn, $failureId, inventoryCountSyncContext($config, 14));
    $revisionBeforeFailedClose = inventoryCountSyncRevision($conn, $failureId);
    $conn->query('DELETE FROM sync_outbox');
    inventoryCountSyncExpectCode(
        static fn() => $failingService->close($conn, $failureId, inventoryCountSyncContext($config, 15)),
        'INVENTORY_COUNT_SYNC_CAPTURE_FAILED'
    );
    inventoryCountSyncAssert(!inventoryCountSyncInTransaction($conn), 'standalone capture failure must close its own transaction');
    inventoryCountSyncAssert(inventoryCountSyncStatus($conn, $failureId) === 'approved', 'failed close must roll back the count status');
    inventoryCountSyncAssert(inventoryCountSyncRevision($conn, $failureId) === $revisionBeforeFailedClose, 'failed close must roll back its count revision');
    inventoryCountSyncAssert(inventoryCountSyncMovementCount($conn, $failureId) === 0, 'failed close must roll back count movements');
    inventoryCountSyncAssert(inventoryCountSyncOutboxCount($conn) === 0, 'failed close must roll back movement, balance and count events');

    $hostedConfig = $config;
    $hostedConfig['role'] = 'cloud';
    $hostedConfig['sync']['cloud_to_branch_publish_enabled'] = true;
    $hostedFlags = new InventoryFeatureFlags($hostedConfig);
    $hostedService = new InventoryCountService(
        $hostedFlags,
        new InventoryLedgerService($hostedFlags),
        null,
        $scopeResolver
    );
    $hostedDraft = $hostedService->createDraft($conn, [
        'count_uuid' => '73737373-7373-4373-8373-737373737373',
        'store_id' => 1,
        'lines' => [['item_id' => 7102]],
    ], inventoryCountSyncContext($hostedConfig, 16));
    inventoryCountSyncAssert(inventoryCountSyncRevision($conn, (int) $hostedDraft['count_id']) === 1, 'hosted mutation should retain local revision semantics');
    inventoryCountSyncAssert(inventoryCountSyncOutboxCount($conn) === 0, 'hosted mutation must not create an automatic reverse event');

    $conn->query("INSERT INTO inventory_counts (
        count_uuid, pos_tenant, pos_branch, branch_uuid, store_id, status, count_type,
        created_by, notes, sync_revision
    ) VALUES (
        '74747474-7474-4474-8474-747474747474', 1, 1, NULL, 1, 'draft', 'selected',
        17, 'Legacy pre-sync count', 0
    )");
    $legacyCountId = (int) $conn->insert_id;
    $legacyReplay = $service->createDraft($conn, [
        'count_uuid' => '74747474-7474-4474-8474-747474747474',
        'store_id' => 1,
        'count_type' => 'selected',
    ], inventoryCountSyncContext($config, 17));
    inventoryCountSyncAssert(!empty($legacyReplay['idempotent_replay']), 'legacy count should enter sync through its existing identity');
    $legacyRow = $conn->query("SELECT branch_uuid, sync_revision FROM inventory_counts WHERE id = {$legacyCountId}")->fetch_assoc();
    inventoryCountSyncAssert((string) $legacyRow['branch_uuid'] === inventoryCountSyncBranchUuid(), 'first capture should backfill only the paired branch UUID');
    inventoryCountSyncAssert((int) $legacyRow['sync_revision'] === 1, 'first legacy capture should establish revision 1');
    inventoryCountSyncAssert((int) inventoryCountSyncLatestEvent($conn, $legacyCountId)['event_version'] === 1, 'legacy first capture should emit revision 1');

    echo "inventory-count-sync-atomicity-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function inventoryCountSyncBranchUuid(): string
{
    return '70707070-7070-4070-8070-707070707070';
}

function inventoryCountSyncConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => inventoryCountSyncBranchUuid(),
            'name' => 'Inventory Count Sync',
            'pos_tenant' => 1,
            'pos_branch' => 1,
        ],
        'sync' => [
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'operational_sync_enabled' => true,
            'cloud_to_branch_publish_enabled' => false,
        ],
        'inventory' => [
            'ledger_mode' => 'bridge',
            'accounting' => true,
            'accounts' => [
                'inventory_asset_account_id' => 10,
                'adjustment_gain_loss_account_id' => 11,
            ],
        ],
    ];
}

function inventoryCountSyncContext(array $config, int $userId): array
{
    return [
        'pos_tenant' => 1,
        'pos_branch' => 1,
        'branch_uuid' => inventoryCountSyncBranchUuid(),
        'store_id' => 1,
        'user_id' => $userId,
        'sync_config' => $config,
    ];
}

function inventoryCountSyncCreateItemSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE myitems (
        id INT NOT NULL PRIMARY KEY,
        iname VARCHAR(200) NOT NULL,
        itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
        cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
        last_price DECIMAL(18,6) NOT NULL DEFAULT 0,
        item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
        track_stock TINYINT(1) NOT NULL DEFAULT 1,
        base_unit_id INT NULL
    ) ENGINE=InnoDB");
    $conn->query("INSERT INTO myitems (id, iname, item_type, track_stock) VALUES
        (7101, 'Count sync one', 'ingredient', 1),
        (7102, 'Count sync two', 'ingredient', 1)");
}

function inventoryCountSyncCreateAccountingSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE acc_head (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        code VARCHAR(20) NOT NULL,
        deletable INT DEFAULT 1,
        editable INT DEFAULT 1,
        aname VARCHAR(100) NOT NULL,
        constant INT DEFAULT 0,
        is_stock INT DEFAULT 0,
        is_fund INT DEFAULT 0,
        rentable INT NULL,
        parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        nature INT NULL,
        kind INT NULL,
        is_basic INT NOT NULL DEFAULT 0,
        isdeleted TINYINT NOT NULL DEFAULT 0,
        tenant INT DEFAULT 0,
        branch INT DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query("INSERT INTO acc_head (id, code, aname, parent_id, is_basic, tenant, branch) VALUES
        (1, '1', 'Inventory count accounting root', 0, 1, 1, 1),
        (10, '110', 'Inventory asset', 1, 0, 1, 1),
        (11, '530', 'Inventory adjustment gain loss', 1, 0, 1, 1)");
    $conn->query("CREATE TABLE journal_heads (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        journal_id BIGINT UNSIGNED NOT NULL,
        total DECIMAL(24,6) NOT NULL,
        jdate DATE NOT NULL,
        op_id BIGINT NULL,
        pro_tybe INT NULL,
        details VARCHAR(250) NULL,
        op2 BIGINT DEFAULT 0,
        isdeleted TINYINT DEFAULT 0,
        user BIGINT NULL,
        tenant INT DEFAULT 0,
        branch INT DEFAULT 0,
        source_type VARCHAR(64) NULL,
        source_id BIGINT NULL,
        posting_kind VARCHAR(64) NULL,
        idempotency_key VARCHAR(191) NULL,
        reversal_of_journal_id BIGINT NULL,
        UNIQUE KEY uq_inventory_count_sync_journal (idempotency_key)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE journal_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        journal_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL,
        debit DECIMAL(24,6) NOT NULL DEFAULT 0,
        credit DECIMAL(24,6) NOT NULL DEFAULT 0,
        tybe INT NOT NULL DEFAULT 0,
        op2 BIGINT DEFAULT 0,
        op_id BIGINT DEFAULT 0,
        isdeleted TINYINT DEFAULT 0,
        tenant INT DEFAULT 0,
        branch INT DEFAULT 0
    ) ENGINE=InnoDB");
}

function inventoryCountSyncSeedStock(mysqli $conn, InventoryLedgerService $ledger, int $itemId, string $qty): void
{
    $conn->begin_transaction();
    $ledger->recordMovement($conn, [
        'scope' => [
            'pos_tenant' => 1,
            'pos_branch' => 1,
            'branch_uuid' => inventoryCountSyncBranchUuid(),
            'store_id' => 1,
        ],
        'item_id' => $itemId,
        'movement_type' => 'opening_balance',
        'source_type' => 'manual',
        'source_id' => $itemId,
        'source_uuid' => 'inventory-count-sync:' . $itemId,
        'qty_in' => $qty,
        'unit_cost' => '2.000000',
        'total_cost' => InventoryDecimal::multiply($qty, '2.000000'),
        'idempotency_key' => 'inventory-count-sync-seed:' . $itemId,
        'created_by' => 1,
    ], ['id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1], ['manage_transaction' => false]);
    $conn->commit();
}

function inventoryCountSyncLatestEvent(mysqli $conn, int $countId): array
{
    $row = $conn->query("SELECT * FROM sync_outbox WHERE aggregate_type = 'inventory_count' AND aggregate_local_id = {$countId} ORDER BY event_version DESC LIMIT 1")->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('inventory count outbox event is missing');
    }

    return $row;
}

function inventoryCountSyncEventPayload(mysqli $conn, int $countId, int $version): array
{
    $row = $conn->query("SELECT payload_json FROM sync_outbox WHERE aggregate_type = 'inventory_count' AND aggregate_local_id = {$countId} AND event_version = {$version} LIMIT 1")->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('inventory count versioned event is missing');
    }

    return json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
}

function inventoryCountSyncEventVersions(mysqli $conn, int $countId): array
{
    $result = $conn->query("SELECT event_version FROM sync_outbox WHERE aggregate_type = 'inventory_count' AND aggregate_local_id = {$countId} ORDER BY event_version ASC");
    $versions = [];
    while ($row = $result->fetch_assoc()) {
        $versions[] = (int) $row['event_version'];
    }

    return $versions;
}

function inventoryCountSyncOutboxAfter(mysqli $conn, int $id): array
{
    $result = $conn->query("SELECT id, aggregate_type, event_type, event_version FROM sync_outbox WHERE id > {$id} ORDER BY id ASC");
    return $result->fetch_all(MYSQLI_ASSOC);
}

function inventoryCountSyncRevision(mysqli $conn, int $countId): int
{
    return (int) $conn->query("SELECT sync_revision FROM inventory_counts WHERE id = {$countId}")->fetch_assoc()['sync_revision'];
}

function inventoryCountSyncStatus(mysqli $conn, int $countId): string
{
    return (string) $conn->query("SELECT status FROM inventory_counts WHERE id = {$countId}")->fetch_assoc()['status'];
}

function inventoryCountSyncCountedQty(mysqli $conn, int $countId): ?string
{
    $value = $conn->query("SELECT counted_qty FROM inventory_count_lines WHERE count_id = {$countId} LIMIT 1")->fetch_assoc()['counted_qty'];
    return $value === null ? null : (string) $value;
}

function inventoryCountSyncMovementCount(mysqli $conn, int $countId): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements m INNER JOIN inventory_count_lines l ON l.id = m.source_id WHERE m.source_type = 'inventory_count' AND l.count_id = {$countId}")->fetch_assoc()['c'];
}

function inventoryCountSyncMaxOutboxId(mysqli $conn): int
{
    return (int) $conn->query('SELECT COALESCE(MAX(id), 0) AS id FROM sync_outbox')->fetch_assoc()['id'];
}

function inventoryCountSyncOutboxCount(mysqli $conn): int
{
    return (int) $conn->query('SELECT COUNT(*) AS c FROM sync_outbox')->fetch_assoc()['c'];
}

function inventoryCountSyncInTransaction(mysqli $conn): bool
{
    return (int) $conn->query('SELECT @@session.in_transaction AS active')->fetch_assoc()['active'] === 1;
}

function inventoryCountSyncRehash(array &$payload): void
{
    unset($payload['payload_hash']);
    $payload['payload_hash'] = hash('sha256', InventoryCountSyncPayloadService::encodeJson($payload));
}

function inventoryCountSyncExpectCode(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        inventoryCountSyncAssert($exception->getMessage() === $code, 'expected ' . $code . ', got ' . $exception->getMessage());
        return;
    }

    throw new RuntimeException('expected failure: ' . $code);
}

function inventoryCountSyncAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
