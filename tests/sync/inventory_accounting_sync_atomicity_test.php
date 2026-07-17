<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/InventoryAccountingSyncPayloadService.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryAccountingService.php';

final class FailingInventoryAccountingSyncEventService extends OperationalSyncEventService
{
    public function recordInventoryAccountingSnapshot(
        mysqli $conn,
        int $journalHeadId,
        array $movementIds,
        array $options = []
    ): ?array {
        throw new RuntimeException('INVENTORY_ACCOUNTING_SYNC_CAPTURE_FAILED');
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_inventory_accounting_sync_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);
    inventoryAccountingSyncCreateAccountingSchema($conn);

    $config = inventoryAccountingSyncConfig();
    (new SyncBranchIdentity())->ensure($conn, $config);
    inventoryAccountingSyncSeedAccounts($conn);
    $movementId = inventoryAccountingSyncSeedMovement($conn, 1, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1');
    $flags = new InventoryFeatureFlags($config);
    $service = new InventoryAccountingService($flags);
    $context = inventoryAccountingSyncContext($config);

    $conn->begin_transaction();
    $rolledBack = $service->postWaste($conn, $context, [$movementId]);
    inventoryAccountingSyncAssert((int) $rolledBack['journal_head_id'] > 0, 'journal should be posted inside the caller transaction');
    inventoryAccountingSyncAssert(inventoryAccountingSyncCount($conn, 'journal_heads') === 1, 'journal should be visible before caller commit');
    inventoryAccountingSyncAssert(inventoryAccountingSyncOutboxCount($conn) === 1, 'typed journal event should be captured before caller commit');
    $conn->rollback();
    inventoryAccountingSyncAssert(inventoryAccountingSyncCount($conn, 'journal_heads') === 0, 'caller rollback must remove the journal');
    inventoryAccountingSyncAssert(inventoryAccountingSyncCount($conn, 'journal_entries') === 0, 'caller rollback must remove journal entries');
    inventoryAccountingSyncAssert(inventoryAccountingSyncOutboxCount($conn) === 0, 'caller rollback must remove the outbox event');
    inventoryAccountingSyncAssert(inventoryAccountingSyncMovementJournal($conn, $movementId) === 0, 'caller rollback must remove the movement link');

    $conn->begin_transaction();
    $posted = $service->postWaste($conn, $context, [$movementId]);
    $conn->commit();
    $journalHeadId = (int) $posted['journal_head_id'];
    inventoryAccountingSyncAssert($journalHeadId > 0, 'successful posting should return the journal head');
    inventoryAccountingSyncAssert(inventoryAccountingSyncCount($conn, 'journal_entries') === 2, 'successful posting should keep both balanced entries');
    inventoryAccountingSyncAssert(inventoryAccountingSyncMovementJournal($conn, $movementId) === $journalHeadId, 'successful posting should attach the movement');
    $firstEvent = inventoryAccountingSyncEvent($conn);
    $firstPayload = json_decode((string) $firstEvent['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    inventoryAccountingSyncAssert((string) $firstPayload['snapshot_type'] === 'inventory_journal_bundle', 'event should use the typed journal bundle');
    inventoryAccountingSyncAssert((string) $firstPayload['captured_at_utc'] === date('Y-m-d') . 'T00:00:00Z', 'immutable capture metadata should derive from the journal date');
    inventoryAccountingSyncAssert(count($firstPayload['journal_entries']) === 2, 'event should contain both journal entries');
    inventoryAccountingSyncAssert(count($firstPayload['movements']) === 1, 'event should contain the exact movement link');

    $conn->query("DELETE FROM sync_outbox WHERE aggregate_type = 'inventory_journal'");
    $healed = $service->postWaste($conn, $context, [$movementId]);
    inventoryAccountingSyncAssert(!empty($healed['existing']), 'replay should reuse the existing journal');
    inventoryAccountingSyncAssert(inventoryAccountingSyncCount($conn, 'journal_heads') === 1, 'replay must not duplicate the journal');
    $healedEvent = inventoryAccountingSyncEvent($conn);
    inventoryAccountingSyncAssert((string) $healedEvent['payload_hash'] === (string) $firstEvent['payload_hash'], 'healed event must reproduce the immutable payload hash');
    inventoryAccountingSyncAssert((string) $healedEvent['payload_json'] === (string) $firstEvent['payload_json'], 'healed event must reproduce byte-equivalent payload content');

    $validator = new InventoryAccountingSyncPayloadService();
    $unknown = $firstPayload;
    $unknown['unexpected'] = true;
    inventoryAccountingSyncExpectCode(
        static fn() => $validator->assertValid($unknown, inventoryAccountingSyncBranchUuid()),
        'INVENTORY_JOURNAL_SYNC_PAYLOAD_INVALID'
    );
    $unbalanced = $firstPayload;
    $unbalanced['journal_entries'][0]['debit'] = '9.00';
    inventoryAccountingSyncRehash($unbalanced);
    inventoryAccountingSyncExpectCode(
        static fn() => $validator->assertValid($unbalanced, inventoryAccountingSyncBranchUuid()),
        'INVENTORY_JOURNAL_SYNC_UNBALANCED'
    );
    $wrongBranch = $firstPayload;
    $wrongBranch['movements'][0]['branch_uuid'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    inventoryAccountingSyncRehash($wrongBranch);
    inventoryAccountingSyncExpectCode(
        static fn() => $validator->assertValid($wrongBranch, inventoryAccountingSyncBranchUuid()),
        'INVENTORY_JOURNAL_SYNC_MOVEMENT_INVALID'
    );

    $failingMovementId = inventoryAccountingSyncSeedMovement($conn, 2, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2');
    $failingService = new InventoryAccountingService(
        $flags,
        null,
        null,
        new FailingInventoryAccountingSyncEventService()
    );
    $conn->begin_transaction();
    inventoryAccountingSyncExpectCode(
        static fn() => $failingService->postWaste($conn, $context, [$failingMovementId]),
        'INVENTORY_ACCOUNTING_SYNC_CAPTURE_FAILED'
    );
    inventoryAccountingSyncAssert((int) $conn->query('SELECT @@session.in_transaction AS active')->fetch_assoc()['active'] === 1, 'capture failure should leave the caller in control of its transaction');
    inventoryAccountingSyncAssert(inventoryAccountingSyncCount($conn, 'journal_heads') === 1, 'capture failure must roll back only the failed journal before the caller decides what to do');
    inventoryAccountingSyncAssert(inventoryAccountingSyncMovementJournal($conn, $failingMovementId) === 0, 'capture failure must remove the failed journal link at its savepoint');
    $conn->rollback();
    inventoryAccountingSyncAssert(inventoryAccountingSyncCount($conn, 'journal_heads') === 1, 'capture failure rollback must not leave a second journal');
    inventoryAccountingSyncAssert(inventoryAccountingSyncMovementJournal($conn, $failingMovementId) === 0, 'capture failure rollback must remove the movement link');

    $selfManagedMovementId = inventoryAccountingSyncSeedMovement($conn, 4, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa4');
    inventoryAccountingSyncExpectCode(
        static fn() => $failingService->postWaste($conn, $context, [$selfManagedMovementId]),
        'INVENTORY_ACCOUNTING_SYNC_CAPTURE_FAILED'
    );
    inventoryAccountingSyncAssert((int) $conn->query('SELECT @@session.in_transaction AS active')->fetch_assoc()['active'] === 0, 'self-managed capture failure should close its transaction');
    inventoryAccountingSyncAssert(inventoryAccountingSyncCount($conn, 'journal_heads') === 1, 'self-managed capture failure must roll back the failed journal');
    inventoryAccountingSyncAssert(inventoryAccountingSyncMovementJournal($conn, $selfManagedMovementId) === 0, 'self-managed capture failure must roll back the movement link');

    $hostedMovementId = inventoryAccountingSyncSeedMovement($conn, 3, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3');
    $hostedConfig = $config;
    $hostedConfig['role'] = 'cloud';
    $hostedConfig['sync']['cloud_to_branch_publish_enabled'] = true;
    $hostedContext = inventoryAccountingSyncContext($hostedConfig);
    $beforeOutbox = inventoryAccountingSyncOutboxCount($conn);
    $conn->begin_transaction();
    $service->postWaste($conn, $hostedContext, [$hostedMovementId]);
    inventoryAccountingSyncAssert(inventoryAccountingSyncOutboxCount($conn) === $beforeOutbox, 'hosted accounting must not create an automatic reverse-sync event');
    $conn->rollback();

    echo "inventory-accounting-sync-atomicity-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function inventoryAccountingSyncBranchUuid(): string
{
    return '81818181-8181-4181-8181-818181818181';
}

function inventoryAccountingSyncConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => inventoryAccountingSyncBranchUuid(),
            'name' => 'Inventory Accounting Atomicity',
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
        ],
    ];
}

function inventoryAccountingSyncContext(array $syncConfig): array
{
    return [
        'pos_tenant' => 1,
        'pos_branch' => 1,
        'store_id' => 1,
        'operation_id' => 77,
        'user_id' => 9,
        'inventory_asset_account_id' => 10,
        'waste_expense_account_id' => 11,
        'sync_config' => $syncConfig,
    ];
}

function inventoryAccountingSyncCreateAccountingSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE acc_head (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY, code VARCHAR(20) NOT NULL, deletable INT DEFAULT 1,
        editable INT DEFAULT 1, aname VARCHAR(100) NOT NULL, constant INT DEFAULT 0, is_stock INT DEFAULT 0,
        is_fund INT DEFAULT 0, rentable INT NULL, parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0, nature INT NULL,
        kind INT NULL, is_basic INT NOT NULL DEFAULT 0, isdeleted TINYINT NOT NULL DEFAULT 0,
        tenant INT DEFAULT 0, branch INT DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE journal_heads (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_id BIGINT UNSIGNED NOT NULL,
        total DECIMAL(24,6) NOT NULL, jdate DATE NOT NULL, op_id BIGINT NULL, pro_tybe INT NULL,
        details VARCHAR(250) NULL, op2 BIGINT DEFAULT 0, isdeleted TINYINT DEFAULT 0, user BIGINT NULL,
        tenant INT DEFAULT 0, branch INT DEFAULT 0, source_type VARCHAR(64) NULL, source_id BIGINT NULL,
        posting_kind VARCHAR(64) NULL, idempotency_key VARCHAR(191) NULL, reversal_of_journal_id BIGINT NULL,
        UNIQUE KEY uq_inventory_sync_idempotency (idempotency_key)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE journal_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL, debit DECIMAL(24,6) NOT NULL DEFAULT 0,
        credit DECIMAL(24,6) NOT NULL DEFAULT 0, tybe INT NOT NULL DEFAULT 0, op2 BIGINT DEFAULT 0,
        op_id BIGINT DEFAULT 0, isdeleted TINYINT DEFAULT 0, tenant INT DEFAULT 0, branch INT DEFAULT 0
    ) ENGINE=InnoDB");
}

function inventoryAccountingSyncSeedAccounts(mysqli $conn): void
{
    $conn->query("INSERT INTO acc_head (id, code, aname, parent_id, is_basic, tenant, branch) VALUES
        (1, '1', 'Inventory accounting root', 0, 1, 1, 1),
        (10, '110', 'Inventory asset', 1, 0, 1, 1),
        (11, '510', 'Inventory waste', 1, 0, 1, 1)");
}

function inventoryAccountingSyncSeedMovement(mysqli $conn, int $sourceId, string $uuid): int
{
    $stmt = $conn->prepare("INSERT INTO inventory_movements (
        movement_uuid, pos_tenant, pos_branch, branch_uuid, store_id, item_id, movement_type,
        source_type, source_id, qty_out, unit_cost, total_cost, idempotency_key
    ) VALUES (?, 1, 1, ?, 1, ?, 'waste', 'adjustment', ?, '2.000000', '3.000000', '6.000000', ?)");
    $branchUuid = inventoryAccountingSyncBranchUuid();
    $itemId = 9000 + $sourceId;
    $key = 'inventory-accounting-sync:' . $sourceId;
    $stmt->bind_param('ssiis', $uuid, $branchUuid, $itemId, $sourceId, $key);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

function inventoryAccountingSyncEvent(mysqli $conn): array
{
    $row = $conn->query("SELECT * FROM sync_outbox WHERE aggregate_type = 'inventory_journal' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('inventory accounting outbox event is missing');
    }

    return $row;
}

function inventoryAccountingSyncMovementJournal(mysqli $conn, int $movementId): int
{
    return (int) $conn->query('SELECT COALESCE(accounting_journal_id, 0) AS id FROM inventory_movements WHERE id = ' . $movementId)->fetch_assoc()['id'];
}

function inventoryAccountingSyncOutboxCount(mysqli $conn): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'inventory_journal'")->fetch_assoc()['c'];
}

function inventoryAccountingSyncCount(mysqli $conn, string $table): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM `{$table}`")->fetch_assoc()['c'];
}

function inventoryAccountingSyncRehash(array &$payload): void
{
    unset($payload['payload_hash']);
    $payload['payload_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
}

function inventoryAccountingSyncExpectCode(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        inventoryAccountingSyncAssert($exception->getMessage() === $code, 'expected ' . $code . ', got ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('expected failure: ' . $code);
}

function inventoryAccountingSyncAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
