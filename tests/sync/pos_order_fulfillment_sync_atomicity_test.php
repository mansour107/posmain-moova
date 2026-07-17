<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncOutboxEventService.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderFulfillmentService.php';

final class FailingFulfillmentSyncOutbox extends SyncOutboxEventService
{
    public function recordOrderSnapshot(mysqli $conn, int $orderId, array $options = []): ?array
    {
        throw new RuntimeException('ORDER_FULFILLMENT_SYNC_CAPTURE_FAILED');
    }
}

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_fulfillment_sync_atomicity_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "pos-order-fulfillment-sync-atomicity-skipped mysql-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    fulfillmentCreateLegacySchema($conn);
    (new SyncSchemaManager())->apply($conn);
    fulfillmentSeedOrder($conn, 4101);

    $config = fulfillmentSyncConfig();
    $service = new OrderFulfillmentService();
    $service->upsertForOrder($conn, 4101, [
        'order_channel' => 'call_center',
        'fulfillment_type' => 'delivery',
        'customer_name' => 'Atomic Delivery',
        'customer_phone' => '01009990001',
        'customer_address' => 'Atomic Address',
        'pos_customer_id' => 71,
        'delivery_zone' => 'Zone A',
        'delivery_fee' => '12.500',
        'delivery_status' => 'pending',
        'metadata_json' => ['driver_name' => 'Driver A'],
    ]);

    $accepted = $service->transitionDeliveryStatus($conn, 4101, 'accepted', ['config' => $config]);
    fulfillmentAssert((string) $accepted['delivery_status'] === 'accepted', 'status transition should persist');
    $eventsAfterAccepted = fulfillmentEvents($conn, 4101);
    fulfillmentAssert(count($eventsAfterAccepted) === 1, 'standalone transition should capture exactly one order event');
    $acceptedPayload = json_decode((string) $eventsAfterAccepted[0]['payload_json'], true);
    fulfillmentAssert((int) ($acceptedPayload['schema_version'] ?? 0) === 4, 'new order snapshot should be schema v4');
    fulfillmentAssert(
        (string) ($acceptedPayload['fulfillment']['delivery_status'] ?? '') === 'accepted',
        'captured fulfillment should contain the committed status'
    );
    fulfillmentAssert(
        !array_key_exists('metadata_json', $acceptedPayload['fulfillment']),
        'raw metadata_json column must not be copied into the payload'
    );

    $conn->begin_transaction();
    $service->transitionDeliveryStatus($conn, 4101, 'preparing', [
        'config' => $config,
        'in_transaction' => true,
    ]);
    $transaction = $conn->query('SELECT @@session.in_transaction AS active')->fetch_assoc();
    fulfillmentAssert((int) ($transaction['active'] ?? 0) === 1, 'caller-owned transaction must remain active');
    fulfillmentAssert(count(fulfillmentEvents($conn, 4101)) === 2, 'caller transaction should contain its uncommitted event');
    $conn->rollback();
    fulfillmentAssert(fulfillmentStatus($conn, 4101) === 'accepted', 'outer rollback must restore the previous status');
    fulfillmentAssert(count(fulfillmentEvents($conn, 4101)) === 1, 'outer rollback must remove the outbox event');

    $failing = new OrderFulfillmentService(new FailingFulfillmentSyncOutbox());
    try {
        $failing->transitionDeliveryStatus($conn, 4101, 'preparing', ['config' => $config]);
        throw new RuntimeException('Expected fulfillment capture failure.');
    } catch (RuntimeException $exception) {
        fulfillmentAssert(
            $exception->getMessage() === 'ORDER_FULFILLMENT_SYNC_CAPTURE_FAILED',
            'capture failure should propagate unchanged'
        );
    }
    fulfillmentAssert(fulfillmentStatus($conn, 4101) === 'accepted', 'capture failure must roll back status mutation');
    fulfillmentAssert(count(fulfillmentEvents($conn, 4101)) === 1, 'capture failure must not leave an event');

    $invalid = $acceptedPayload;
    $invalid['fulfillment']['unknown_column'] = 'unsafe';
    fulfillmentExpectFailure(
        static fn () => PosOrderSnapshotBuilder::assertFulfillmentSnapshotScope($invalid, 4101),
        'ORDER_FULFILLMENT_SCOPE_INVALID'
    );
    $invalid = $acceptedPayload;
    $invalid['fulfillment']['metadata']['request_payload'] = ['raw' => true];
    fulfillmentExpectFailure(
        static fn () => PosOrderSnapshotBuilder::assertFulfillmentSnapshotScope($invalid, 4101),
        'ORDER_FULFILLMENT_METADATA_SENSITIVE'
    );

    echo "pos-order-fulfillment-sync-atomicity-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function fulfillmentSyncConfig(): array
{
    return [
        'role' => 'branch',
        'timezone' => 'Africa/Cairo',
        'branch' => [
            'uuid' => '81818181-8181-4181-8181-818181818181',
            'name' => 'Fulfillment Atomicity',
            'pos_tenant' => 1,
            'pos_branch' => 1,
        ],
        'sync' => [
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'cloud_to_branch_publish_enabled' => false,
        ],
    ];
}

function fulfillmentCreateLegacySchema(mysqli $conn): void
{
    $conn->query('CREATE TABLE tables (id INT PRIMARY KEY, tname VARCHAR(255) NULL) ENGINE=InnoDB');
    $conn->query('CREATE TABLE myitems (id INT PRIMARY KEY, iname VARCHAR(255) NULL, barcode VARCHAR(191) NULL) ENGINE=InnoDB');
    $conn->query('CREATE TABLE fat_details (
        id BIGINT PRIMARY KEY, fatid BIGINT NOT NULL, item_id INT NULL, qty_in DECIMAL(12,3) DEFAULT 0,
        qty_out DECIMAL(12,3) DEFAULT 0, price DECIMAL(12,2) DEFAULT 0, cost_price DECIMAL(12,2) DEFAULT 0,
        discount DECIMAL(12,2) DEFAULT 0, det_value DECIMAL(12,2) DEFAULT 0, profit DECIMAL(12,2) DEFAULT 0,
        isdeleted TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $conn->query('CREATE TABLE ot_head (
        id BIGINT PRIMARY KEY, uuid CHAR(36) NULL, pro_id VARCHAR(100) NULL, pro_tybe INT NULL,
        order_type VARCHAR(50) NULL, table_id INT NULL, info VARCHAR(200) NULL, pro_date DATE NULL,
        crtime DATETIME NULL, completed_at DATETIME NULL, payment_date DATETIME NULL,
        pro_value DECIMAL(12,2) DEFAULT 0, fat_total DECIMAL(12,2) DEFAULT 0,
        fat_net DECIMAL(12,2) DEFAULT 0, fat_disc DECIMAL(12,2) DEFAULT 0,
        paid_amount DECIMAL(12,2) DEFAULT 0, remaining_amount DECIMAL(12,2) DEFAULT 0,
        payment_status VARCHAR(50) NULL, invoice_status VARCHAR(50) NULL, order_status VARCHAR(50) NULL,
        isdeleted TINYINT NOT NULL DEFAULT 0, closed TINYINT NOT NULL DEFAULT 0,
        user INT NULL, waiter_id INT NULL, tenant INT NOT NULL DEFAULT 0, branch INT NOT NULL DEFAULT 0,
        store_id INT NULL, acc1 INT NULL, acc2 INT NULL, mdtime DATETIME NULL, op2 BIGINT NULL,
        kitchen_revision INT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB');
}

function fulfillmentSeedOrder(mysqli $conn, int $orderId): void
{
    $stmt = $conn->prepare("INSERT INTO ot_head (
        id, pro_id, pro_tybe, order_type, pro_date, crtime, pro_value, fat_total, fat_net,
        paid_amount, remaining_amount, payment_status, invoice_status, order_status,
        isdeleted, closed, user, tenant, branch, mdtime, kitchen_revision
    ) VALUES (?, 'D-4101', 9, 'delivery', CURDATE(), NOW(), 100, 100, 100, 0, 100,
        'unpaid', 'draft', 'active', 0, 0, 1, 1, 1, NOW(), 1)");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $stmt->close();
}

function fulfillmentEvents(mysqli $conn, int $orderId): array
{
    $stmt = $conn->prepare("SELECT event_version, payload_json FROM sync_outbox
        WHERE aggregate_type = 'order' AND aggregate_local_id = ? ORDER BY event_version");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function fulfillmentStatus(mysqli $conn, int $orderId): string
{
    $stmt = $conn->prepare('SELECT delivery_status FROM order_fulfillment WHERE order_id = ?');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (string) ($row['delivery_status'] ?? '');
}

function fulfillmentExpectFailure(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        fulfillmentAssert($exception->getMessage() === $message, "expected {$message}, got {$exception->getMessage()}");
        return;
    }
    throw new RuntimeException("Expected {$message}");
}

function fulfillmentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('pos-order-fulfillment-sync-atomicity failed: ' . $message);
    }
}
