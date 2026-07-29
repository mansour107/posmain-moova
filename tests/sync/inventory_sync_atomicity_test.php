<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryLedgerService.php';

final class FailingInventorySyncEventService extends OperationalSyncEventService
{
    public function recordRowSnapshot(mysqli $conn, string $domain, int $rowId, array $options = []): ?array
    {
        throw new RuntimeException('INVENTORY_SYNC_CAPTURE_FAILED');
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_inventory_sync_atomicity_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $config = inventorySyncConfig();
    $flags = new InventoryFeatureFlags($config);
    $ledger = new InventoryLedgerService($flags);
    $item = ['item_id' => 8101, 'item_type' => 'ingredient', 'track_stock' => 1];

    $first = $ledger->recordMovement($conn, inventorySyncMovement(8101, 'atomic:first', '4.000000'), $item);
    $firstMovementId = (int) $first['movement_id'];
    $balanceId = (int) $first['balance_id'];
    inventorySyncAssert($firstMovementId > 0 && $balanceId > 0, 'canonical movement and balance should be created');
    inventorySyncAssert(
        inventorySyncOutboxCount($conn, 'inventory_movement', $firstMovementId, $firstMovementId) === 1,
        'movement snapshot should use movement id as its revision'
    );
    inventorySyncAssert(
        inventorySyncOutboxCount($conn, 'inventory_balance', $balanceId, $firstMovementId) === 1,
        'balance snapshot should use the producing movement id as its revision'
    );

    $second = $ledger->recordMovement($conn, inventorySyncMovement(8101, 'atomic:second', '2.000000'), $item);
    $secondMovementId = (int) $second['movement_id'];
    inventorySyncAssert($secondMovementId > $firstMovementId, 'movement ids should be monotonic');
    $balanceVersions = [];
    $result = $conn->query(
        "SELECT event_version FROM sync_outbox WHERE aggregate_type = 'inventory_balance'"
        . ' AND aggregate_local_id = ' . $balanceId . ' ORDER BY event_version ASC'
    );
    while ($row = $result->fetch_assoc()) {
        $balanceVersions[] = (int) $row['event_version'];
    }
    inventorySyncAssert(
        $balanceVersions === [$firstMovementId, $secondMovementId],
        'rapid balance changes should retain strictly increasing movement revisions'
    );

    $conn->query(
        "DELETE FROM sync_outbox WHERE (aggregate_type = 'inventory_movement' AND aggregate_local_id = {$firstMovementId})"
        . " OR (aggregate_type = 'inventory_balance' AND aggregate_local_id = {$balanceId} AND event_version = {$secondMovementId})"
    );
    $replay = $ledger->recordMovement($conn, inventorySyncMovement(8101, 'atomic:first', '4.000000'), $item);
    inventorySyncAssert(!empty($replay['idempotent_replay']), 'existing movement should be recognized as an idempotent replay');
    inventorySyncAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE idempotency_key = 'atomic:first'")->fetch_assoc()['c'] === 1,
        'replay must not duplicate the stock movement'
    );
    inventorySyncAssert(
        inventorySyncDecimal($conn, 8101, 'qty_on_hand') === '6.000000',
        'replay must not change the current stock balance'
    );
    inventorySyncAssert(
        inventorySyncOutboxCount($conn, 'inventory_movement', $firstMovementId, $firstMovementId) === 1,
        'replay should heal a missing movement outbox event'
    );
    inventorySyncAssert(
        inventorySyncOutboxCount($conn, 'inventory_balance', $balanceId, $secondMovementId) === 1,
        'replay should heal the current balance event at its latest movement revision'
    );

    $failing = new InventoryLedgerService(
        $flags,
        null,
        null,
        null,
        null,
        null,
        new FailingInventorySyncEventService()
    );
    inventorySyncExpectFailure(static function () use ($failing, $conn): void {
        $failing->recordMovement(
            $conn,
            inventorySyncMovement(8102, 'atomic:self-managed-failure', '1.000000'),
            ['item_id' => 8102, 'item_type' => 'ingredient', 'track_stock' => 1]
        );
    });
    inventorySyncAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE idempotency_key = 'atomic:self-managed-failure'")->fetch_assoc()['c'] === 0,
        'self-managed capture failure must roll back the movement'
    );
    inventorySyncAssert(inventorySyncBalanceCount($conn, 8102) === 0, 'self-managed capture failure must roll back the balance');

    $conn->begin_transaction();
    inventorySyncExpectFailure(static function () use ($failing, $conn): void {
        $failing->recordMovement(
            $conn,
            inventorySyncMovement(8103, 'atomic:caller-failure', '1.000000'),
            ['item_id' => 8103, 'item_type' => 'ingredient', 'track_stock' => 1],
            ['manage_transaction' => false]
        );
    });
    $transaction = $conn->query('SELECT @@session.in_transaction AS active')->fetch_assoc();
    inventorySyncAssert((int) ($transaction['active'] ?? 0) === 1, 'caller-owned transaction must remain active after propagated failure');
    inventorySyncAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE idempotency_key = 'atomic:caller-failure'")->fetch_assoc()['c'] === 1,
        'caller should still control its uncommitted movement'
    );
    $conn->rollback();
    inventorySyncAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE idempotency_key = 'atomic:caller-failure'")->fetch_assoc()['c'] === 0,
        'caller rollback must remove the movement after sync failure'
    );

    $shadowConfig = $config;
    $shadowConfig['inventory']['ledger_mode'] = 'shadow';
    $shadowConfig['inventory']['quantity_tracking'] = false;
    $shadowLedger = new InventoryLedgerService(new InventoryFeatureFlags($shadowConfig));
    $shadow = $shadowLedger->recordShadowMovement(
        $conn,
        inventorySyncMovement(8104, 'atomic:shadow', '1.000000'),
        ['item_id' => 8104, 'item_type' => 'ingredient', 'track_stock' => 1]
    );
    $shadowMovementId = (int) $shadow['movement_id'];
    inventorySyncAssert($shadowMovementId > 0, 'shadow movement fixture should create its local ledger row');
    inventorySyncAssert(
        inventorySyncOutboxCount($conn, 'inventory_movement', $shadowMovementId, $shadowMovementId) === 0,
        'shadow movement must remain excluded from automatic upload'
    );

    echo "inventory-sync-atomicity-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function inventorySyncConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => '81818181-8181-4181-8181-818181818181',
            'name' => 'Inventory Atomicity',
            'pos_tenant' => 1,
            'pos_branch' => 1,
        ],
        'sync' => [
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'operational_sync_enabled' => true,
        ],
        'inventory' => [
            'ledger_mode' => 'off',
            'quantity_tracking' => true,
            'accounting' => false,
        ],
    ];
}

function inventorySyncMovement(int $itemId, string $key, string $qty): array
{
    return [
        'scope' => [
            'pos_tenant' => 1,
            'pos_branch' => 1,
            'store_id' => 1,
            'branch_uuid' => '81818181-8181-4181-8181-818181818181',
        ],
        'item_id' => $itemId,
        'movement_type' => 'purchase',
        'source_type' => 'purchase_receipt',
        'source_uuid' => $key,
        'qty_in' => $qty,
        'unit_cost' => '1.000000',
        'idempotency_key' => $key,
    ];
}

function inventorySyncOutboxCount(mysqli $conn, string $aggregate, int $localId, int $revision): int
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS c FROM sync_outbox'
        . ' WHERE aggregate_type = ? AND aggregate_local_id = ? AND event_version = ?'
    );
    $stmt->bind_param('sii', $aggregate, $localId, $revision);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return $count;
}

function inventorySyncBalanceCount(mysqli $conn, int $itemId): int
{
    return (int) $conn->query(
        'SELECT COUNT(*) AS c FROM inventory_item_balances WHERE item_id = ' . $itemId
    )->fetch_assoc()['c'];
}

function inventorySyncDecimal(mysqli $conn, int $itemId, string $column): string
{
    $row = $conn->query(
        "SELECT {$column} FROM inventory_item_balances WHERE item_id = {$itemId} LIMIT 1"
    )->fetch_assoc();

    return number_format((float) ($row[$column] ?? 0), 6, '.', '');
}

function inventorySyncExpectFailure(callable $callback): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        inventorySyncAssert($exception->getMessage() === 'INVENTORY_SYNC_CAPTURE_FAILED', 'capture failure should propagate unchanged');
        return;
    }

    throw new RuntimeException('expected inventory sync capture failure');
}

function inventorySyncAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
