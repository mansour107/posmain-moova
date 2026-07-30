<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderFulfillmentService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_delivery_status_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $manager = new SyncSchemaManager();
    $conn->query($manager->plannedStatements()['order_fulfillment']);
    $conn->query("CREATE TABLE ot_head (
        id BIGINT NOT NULL PRIMARY KEY,
        fat_net DECIMAL(19,2) NOT NULL DEFAULT 0,
        remaining_amount DECIMAL(19,2) NOT NULL DEFAULT 0,
        mutation_version BIGINT UNSIGNED NOT NULL DEFAULT 1
    ) ENGINE=InnoDB");
    $conn->query("INSERT INTO ot_head (id, fat_net, remaining_amount) VALUES (501, 120.00, 120.00), (502, 240.00, 240.00), (503, 80.00, 80.00)");

    $service = new OrderFulfillmentService();
    $transitionOptions = [
        'actor_user_id' => 1,
        'config' => [
            'role' => 'branch',
            'sync' => [
                'outbox_enabled' => false,
                'cloud_to_branch_publish_enabled' => false,
            ],
        ],
    ];
    $service->upsertForOrder($conn, 501, [
        'order_channel' => 'cashier',
        'fulfillment_type' => 'delivery',
        'customer_name' => 'Status Test',
        'customer_phone' => '01009998877',
        'customer_address' => 'Address',
        'delivery_status' => 'pending',
    ], ['require_table' => true]);

    $accepted = $service->transitionDeliveryStatus($conn, 501, 'accepted', $transitionOptions);
    deliveryStatusAssert(($accepted['delivery_status'] ?? '') === 'accepted', 'pending -> accepted should work');

    $preparing = $service->transitionDeliveryStatus($conn, 501, 'preparing', $transitionOptions);
    deliveryStatusAssert(($preparing['delivery_status'] ?? '') === 'preparing', 'accepted -> preparing should work');

    $ready = $service->transitionDeliveryStatus($conn, 501, 'ready', $transitionOptions);
    deliveryStatusAssert(($ready['delivery_status'] ?? '') === 'ready', 'preparing -> ready should work');

    $failed = false;
    try {
        $service->transitionDeliveryStatus($conn, 501, 'pending', $transitionOptions);
    } catch (Throwable $e) {
        $failed = true;
    }
    deliveryStatusAssert($failed, 'backward transition should be rejected');

    $service->upsertForOrder($conn, 502, [
        'fulfillment_type' => 'delivery',
        'delivery_status' => 'pending',
    ], ['require_table' => true]);
    $externalPickup = $service->transitionDeliveryStatus($conn, 502, 'picked_up', [
        'cashier_dispatch' => true,
        'courier_source' => 'external',
        'driver_name' => 'External Courier',
    ] + $transitionOptions);
    deliveryStatusAssert(($externalPickup['delivery_status'] ?? '') === 'picked_up', 'cashier should dispatch directly from the pre-pickup lifecycle');
    deliveryStatusAssert(($externalPickup['courier_source'] ?? '') === 'external', 'external courier choice should persist on pickup');
    $failedDelivery = $service->transitionDeliveryStatus($conn, 502, 'failed', [
        'failure_reason' => 'Customer unavailable',
    ] + $transitionOptions);
    deliveryStatusAssert(($failedDelivery['metadata']['failure_reason'] ?? '') === 'Customer unavailable', 'failed delivery should preserve the reason');
    deliveryStatusAssert(($failedDelivery['metadata']['failure_order_value'] ?? '') === '240.00', 'failed delivery should snapshot the order value at canonical currency precision');

    $service->upsertForOrder($conn, 503, [
        'fulfillment_type' => 'delivery',
        'delivery_status' => 'pending',
    ], ['require_table' => true]);
    $missingWorkerBlocked = false;
    try {
        $service->transitionDeliveryStatus($conn, 503, 'picked_up', ['cashier_dispatch' => true] + $transitionOptions);
    } catch (Throwable $e) {
        $missingWorkerBlocked = $e->getMessage() === 'DELIVERY_WORKER_REQUIRED_BEFORE_PICKUP';
    }
    deliveryStatusAssert($missingWorkerBlocked, 'cashier in-house dispatch must still require a registered worker');

    echo "delivery_status_transition_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function deliveryStatusAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "delivery_status_transition_test FAILED: {$message}\n");
        exit(1);
    }
}
