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

    $service = new OrderFulfillmentService();
    $service->upsertForOrder($conn, 501, [
        'order_channel' => 'cashier',
        'fulfillment_type' => 'delivery',
        'customer_name' => 'Status Test',
        'customer_phone' => '01009998877',
        'customer_address' => 'Address',
        'delivery_status' => 'pending',
    ], ['require_table' => true]);

    $accepted = $service->transitionDeliveryStatus($conn, 501, 'accepted', ['actor_user_id' => 1]);
    deliveryStatusAssert(($accepted['delivery_status'] ?? '') === 'accepted', 'pending -> accepted should work');

    $preparing = $service->transitionDeliveryStatus($conn, 501, 'preparing', ['actor_user_id' => 1]);
    deliveryStatusAssert(($preparing['delivery_status'] ?? '') === 'preparing', 'accepted -> preparing should work');

    $ready = $service->transitionDeliveryStatus($conn, 501, 'ready', ['actor_user_id' => 1]);
    deliveryStatusAssert(($ready['delivery_status'] ?? '') === 'ready', 'preparing -> ready should work');

    $failed = false;
    try {
        $service->transitionDeliveryStatus($conn, 501, 'pending', ['actor_user_id' => 1]);
    } catch (Throwable $e) {
        $failed = true;
    }
    deliveryStatusAssert($failed, 'backward transition should be rejected');

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
