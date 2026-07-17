<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosCustomerService.php';

final class FailingCustomerSyncEventService extends OperationalSyncEventService
{
    public function recordCustomerSnapshot(mysqli $conn, int $customerId, array $options = []): ?array
    {
        throw new RuntimeException('CUSTOMER_SYNC_CAPTURE_FAILED');
    }
}

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_customer_sync_atomicity_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "pos-customer-sync-atomicity-skipped mysql-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);
    $config = customerSyncConfig();
    $service = new PosCustomerService();

    $first = $service->saveCustomer($conn, [
        'display_name' => 'Atomic Customer',
        'phones' => [['phone' => '01005550001', 'is_primary' => true]],
        'addresses' => [['address_text' => 'Atomic Address', 'is_default' => true]],
    ], ['config' => $config]);
    $customerId = (int) $first['id'];
    customerSyncAssert(customerSyncVersions($conn, $customerId) === [1], 'create should emit customer revision 1');

    $service->saveCustomer($conn, [
        'id' => $customerId,
        'display_name' => 'Atomic Customer v2',
        'notes' => 'second revision',
    ], ['config' => $config]);
    $conn->query("DELETE FROM document_counters WHERE counter_type = 'customer_sync'");
    $service->saveCustomer($conn, [
        'id' => $customerId,
        'display_name' => 'Atomic Customer v3',
        'notes' => 'counter repaired from outbox history',
    ], ['config' => $config]);
    customerSyncAssert(
        customerSyncVersions($conn, $customerId) === [1, 2, 3],
        'rapid updates and counter loss must retain strictly increasing revisions'
    );

    $conn->begin_transaction();
    $rolledBack = $service->upsertForDelivery(
        $conn,
        '01005550002',
        'Outer Transaction Customer',
        'Outer Transaction Address',
        null,
        ['config' => $config, 'in_transaction' => true]
    );
    $rolledBackId = (int) $rolledBack['id'];
    $transaction = $conn->query('SELECT @@session.in_transaction AS active')->fetch_assoc();
    customerSyncAssert((int) ($transaction['active'] ?? 0) === 1, 'customer save must not commit its caller-owned transaction');
    customerSyncAssert(customerSyncVersions($conn, $rolledBackId) === [1], 'caller-owned transaction should contain its uncommitted outbox event');
    $conn->rollback();
    customerSyncAssert(customerSyncCustomerCount($conn, $rolledBackId) === 0, 'outer rollback must remove the customer');
    customerSyncAssert(customerSyncVersions($conn, $rolledBackId) === [], 'outer rollback must remove the customer event');

    $failing = new PosCustomerService(null, new FailingCustomerSyncEventService());
    try {
        $failing->saveCustomer($conn, [
            'display_name' => 'Must Roll Back',
            'phones' => [['phone' => '01005550003', 'is_primary' => true]],
        ], ['config' => $config]);
        throw new RuntimeException('Expected customer capture failure.');
    } catch (RuntimeException $exception) {
        customerSyncAssert($exception->getMessage() === 'CUSTOMER_SYNC_CAPTURE_FAILED', 'capture failure should propagate unchanged');
    }
    customerSyncAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM pos_customers WHERE display_name = 'Must Roll Back'")->fetch_assoc()['c'] === 0,
        'capture failure must roll back the customer mutation'
    );

    $source = $service->saveCustomer($conn, [
        'display_name' => 'Merge Source',
        'phones' => [['phone' => '01005550004', 'is_primary' => true]],
        'addresses' => [['address_text' => 'Moved Address', 'is_default' => true]],
    ], ['config' => $config]);
    $sourceId = (int) $source['id'];
    $service->mergeCustomers($conn, $sourceId, $customerId, ['config' => $config]);
    $mergeRows = $conn->query(
        "SELECT aggregate_local_id, event_type FROM sync_outbox WHERE event_type IN ('customer.merged_target', 'customer.merged_source') ORDER BY id"
    )->fetch_all(MYSQLI_ASSOC);
    customerSyncAssert(count($mergeRows) === 2, 'merge should emit target and source bundles in one transaction');
    customerSyncAssert((int) $mergeRows[0]['aggregate_local_id'] === $customerId, 'merge target bundle should be first');
    customerSyncAssert((int) $mergeRows[1]['aggregate_local_id'] === $sourceId, 'merge source tombstone bundle should follow');

    $older = customerSyncEventAtVersion($conn, $customerId, 3);
    $newerVersion = max(customerSyncVersions($conn, $customerId));
    $newer = customerSyncEventAtVersion($conn, $customerId, $newerVersion);

    $conn->query('DELETE FROM pos_customer_addresses');
    $conn->query('DELETE FROM pos_customer_phones');
    $conn->query('DELETE FROM pos_customers');
    $conn->query('DELETE FROM sync_inbox');
    $conn->query('DELETE FROM sync_projection_versions');

    $inbox = new SyncInboxService();
    $applied = $inbox->receiveBranchEvent($conn, $config['branch']['uuid'], $newer, SyncApplyMode::LIVE_APPLY);
    $stale = $inbox->receiveBranchEvent($conn, $config['branch']['uuid'], $older, SyncApplyMode::LIVE_APPLY);
    $duplicate = $inbox->receiveBranchEvent($conn, $config['branch']['uuid'], $newer, SyncApplyMode::LIVE_APPLY);
    customerSyncAssert($applied['status'] === 'processed', 'newer customer bundle should project');
    customerSyncAssert($stale['status'] === 'stale', 'older customer bundle should be acknowledged stale');
    customerSyncAssert($duplicate['status'] === 'duplicate', 'exact replay should be idempotent');
    $projected = $conn->query("SELECT display_name, isdeleted FROM pos_customers WHERE id = {$customerId}")->fetch_assoc();
    customerSyncAssert((string) $projected['display_name'] === 'Atomic Customer v3', 'stale delivery must not overwrite the newer customer');
    customerSyncAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM pos_customer_phones WHERE customer_id = {$customerId} AND isdeleted = 0")->fetch_assoc()['c'] === 2,
        'merge target projection should contain both active phones'
    );

    echo "pos-customer-sync-atomicity-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function customerSyncConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => '71717171-7171-4171-8171-717171717171',
            'name' => 'Customer Atomicity',
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

function customerSyncVersions(mysqli $conn, int $customerId): array
{
    $stmt = $conn->prepare(
        "SELECT event_version FROM sync_outbox WHERE aggregate_type = 'pos_customer' AND aggregate_local_id = ? ORDER BY event_version"
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $versions = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $versions[] = (int) $row['event_version'];
    }
    $stmt->close();

    return $versions;
}

function customerSyncEventAtVersion(mysqli $conn, int $customerId, int $version): array
{
    $stmt = $conn->prepare(
        "SELECT * FROM sync_outbox WHERE aggregate_type = 'pos_customer' AND aggregate_local_id = ? AND event_version = ? LIMIT 1"
    );
    $stmt->bind_param('ii', $customerId, $version);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('customer outbox fixture not found');
    }

    return [
        'event_uuid' => (string) $row['event_uuid'],
        'idempotency_key' => (string) $row['idempotency_key'],
        'aggregate_type' => (string) $row['aggregate_type'],
        'aggregate_uuid' => (string) $row['aggregate_uuid'],
        'aggregate_local_id' => (int) $row['aggregate_local_id'],
        'entity_type' => (string) $row['entity_type'],
        'entity_uuid' => (string) $row['entity_uuid'],
        'event_type' => (string) $row['event_type'],
        'event_version' => (int) $row['event_version'],
        'source_system' => (string) $row['source_system'],
        'payload_hash' => (string) $row['payload_hash'],
        'payload' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
    ];
}

function customerSyncCustomerCount(mysqli $conn, int $customerId): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM pos_customers WHERE id = {$customerId}")->fetch_assoc()['c'];
}

function customerSyncAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
