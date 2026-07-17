<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';
require_once __DIR__ . '/../../classes/Sync/SyncOutboxEventService.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderFulfillmentService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$sourceDb = 'posmain_restore_fulfillment_source_' . getmypid();
$targetDb = 'posmain_restore_fulfillment_target_' . getmypid();
$source = @new mysqli($host, $user, $pass, '', $port);
if ($source->connect_error) {
    echo "branch-restore-order-fulfillment-skipped mysql-unavailable\n";
    exit(0);
}
$target = @new mysqli($host, $user, $pass, '', $port);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    foreach ([[$source, $sourceDb], [$target, $targetDb]] as [$conn, $db]) {
        $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $conn->select_db($db);
        restoreFulfillmentCreateLegacySchema($conn);
        (new SyncSchemaManager())->apply($conn);
    }

    restoreFulfillmentSeedOrder($source, 5101);
    $config = restoreFulfillmentBranchConfig();
    $service = new OrderFulfillmentService();
    $service->upsertForOrder($source, 5101, [
        'order_channel' => 'moova_delivery',
        'fulfillment_type' => 'delivery',
        'external_provider' => 'moova',
        'external_order_id' => 'MOOVA-5101',
        'customer_name' => 'Restore Customer',
        'customer_phone' => '01008880001',
        'customer_address' => 'Restore Address',
        'pos_customer_id' => 81,
        'delivery_zone' => 'Restore Zone',
        'delivery_fee' => '15.000',
        'delivery_status' => 'pending',
        'promised_at' => '2026-07-16 21:30:00',
        'metadata_json' => ['driver_name' => 'Restore Driver', 'notes' => 'Gate 2'],
    ]);
    (new SyncOutboxEventService())->recordOrderSnapshot($source, 5101, [
        'event_type' => 'order.saved',
        'source_system' => 'pos_delivery',
        'config' => $config,
    ]);
    $service->transitionDeliveryStatus($source, 5101, 'accepted', ['config' => $config]);

    $events = restoreFulfillmentEvents($source, 5101);
    restoreFulfillmentAssert(count($events) === 2, 'source should contain old and new order snapshots');
    $older = $events[0];
    $newer = $events[1];

    $inbox = new SyncInboxService();
    $targetConfig = ['sync' => ['legacy_pos_mirror_enabled' => true]];
    $applied = $inbox->receiveBranchEvent(
        $target,
        $config['branch']['uuid'],
        $newer,
        SyncApplyMode::LIVE_APPLY,
        $targetConfig
    );
    $stale = $inbox->receiveBranchEvent(
        $target,
        $config['branch']['uuid'],
        $older,
        SyncApplyMode::LIVE_APPLY,
        $targetConfig
    );
    $duplicate = $inbox->receiveBranchEvent(
        $target,
        $config['branch']['uuid'],
        $newer,
        SyncApplyMode::LIVE_APPLY,
        $targetConfig
    );

    restoreFulfillmentAssert($applied['status'] === 'processed', 'newer event should apply');
    restoreFulfillmentAssert($stale['status'] === 'stale', 'older event should be acknowledged stale');
    restoreFulfillmentAssert($duplicate['status'] === 'duplicate', 'exact replay should be idempotent');
    $restored = $target->query('SELECT * FROM order_fulfillment WHERE order_id = 5101')->fetch_assoc();
    restoreFulfillmentAssert(is_array($restored), 'restore should create fulfillment after order parent');
    restoreFulfillmentAssert((string) $restored['delivery_status'] === 'accepted', 'stale event must not rewind status');
    restoreFulfillmentAssert((int) $restored['pos_customer_id'] === 81, 'restore should preserve modern customer link');
    restoreFulfillmentAssert((int) $target->query('SELECT COUNT(*) AS c FROM order_fulfillment')->fetch_assoc()['c'] === 1, 'replay should retain one fulfillment row');
    $metadata = json_decode((string) $restored['metadata_json'], true);
    restoreFulfillmentAssert(($metadata['driver_name'] ?? '') === 'Restore Driver', 'sanitized dispatch metadata should restore');

    $invalid = restoreFulfillmentMutateEvent($newer, static function (array &$payload): void {
        $payload['fulfillment']['order_id'] = 9999;
    }, 1);
    try {
        $inbox->receiveBranchEvent(
            $target,
            $config['branch']['uuid'],
            $invalid,
            SyncApplyMode::LIVE_APPLY,
            $targetConfig
        );
        throw new RuntimeException('Expected cross-order fulfillment rejection.');
    } catch (RuntimeException $exception) {
        restoreFulfillmentAssert(
            $exception->getMessage() === 'ORDER_FULFILLMENT_SCOPE_INVALID',
            'cross-order fulfillment should fail closed'
        );
    }
    restoreFulfillmentAssert(
        (string) $target->query('SELECT delivery_status FROM order_fulfillment WHERE order_id = 5101')->fetch_assoc()['delivery_status'] === 'accepted',
        'rejected payload must not mutate restored fulfillment'
    );

    echo "branch-restore-order-fulfillment-ok source={$sourceDb} target={$targetDb}\n";
} finally {
    $source->query("DROP DATABASE IF EXISTS `{$sourceDb}`");
    $target->query("DROP DATABASE IF EXISTS `{$targetDb}`");
    $source->close();
    $target->close();
}

function restoreFulfillmentBranchConfig(): array
{
    return [
        'role' => 'branch',
        'timezone' => 'Africa/Cairo',
        'branch' => [
            'uuid' => '91919191-9191-4191-8191-919191919191',
            'name' => 'Fulfillment Restore',
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

function restoreFulfillmentCreateLegacySchema(mysqli $conn): void
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

function restoreFulfillmentSeedOrder(mysqli $conn, int $orderId): void
{
    $stmt = $conn->prepare("INSERT INTO ot_head (
        id, pro_id, pro_tybe, order_type, pro_date, crtime, pro_value, fat_total, fat_net,
        paid_amount, remaining_amount, payment_status, invoice_status, order_status,
        isdeleted, closed, user, tenant, branch, mdtime, kitchen_revision
    ) VALUES (?, 'D-5101', 9, 'delivery', CURDATE(), NOW(), 115, 100, 115, 0, 115,
        'unpaid', 'draft', 'active', 0, 0, 1, 1, 1, NOW(), 1)");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $stmt->close();
}

function restoreFulfillmentEvents(mysqli $conn, int $orderId): array
{
    $stmt = $conn->prepare("SELECT * FROM sync_outbox
        WHERE aggregate_type = 'order' AND aggregate_local_id = ? ORDER BY event_version");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map(static function (array $row): array {
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
    }, $rows);
}

function restoreFulfillmentMutateEvent(array $event, callable $mutator, int $revisionBump): array
{
    $payload = $event['payload'];
    $mutator($payload);
    $event['event_version'] = (int) $event['event_version'] + $revisionBump;
    $payload['order']['sync_revision'] = (int) $event['event_version'];
    unset($payload['payload_hash']);
    $payload['payload_hash'] = hash('sha256', restoreFulfillmentJson($payload));
    $event['payload'] = $payload;
    $event['payload_hash'] = hash('sha256', restoreFulfillmentJson($payload));
    $event['event_uuid'] = SyncBranchIdentity::generateUuidV4();
    $event['idempotency_key'] = hash('sha256', 'mutated:' . $event['event_uuid']);

    return $event;
}

function restoreFulfillmentJson($value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('test json encode failed');
    }

    return $json;
}

function restoreFulfillmentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('branch-restore-order-fulfillment failed: ' . $message);
    }
}
