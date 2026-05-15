<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderFulfillmentService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase5_fulfillment_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);

    $manager = new SyncSchemaManager();
    $planned = $manager->plannedStatements();
    phase5FulfillmentAssert(isset($planned['order_fulfillment']), 'order_fulfillment planned statement missing');
    phase5FulfillmentAssert(strpos($planned['order_fulfillment'], 'UNIQUE KEY uq_order_fulfillment_order (order_id)') !== false, 'order fulfillment unique order key missing');
    $conn->query($planned['order_fulfillment']);

    $service = new OrderFulfillmentService();
    $created = $service->upsertMoovaFulfillment($conn, 123, [
        'cofeOrderId' => 'MOOVA-DEL-001',
        'branchId' => 'BR-1',
        'tableNumber' => 'D1',
        'fulfillmentType' => 'delivery',
        'orderChannel' => 'moova_delivery',
        'customer' => [
            'name' => 'Nour Test',
            'phone' => '01000000000',
        ],
        'delivery' => [
            'address' => 'Building 4, Test Street',
            'zone' => 'Downtown',
            'fee' => '15.500',
            'status' => 'pending',
            'promisedAt' => '2026-05-13 19:30:00',
        ],
        'notes' => 'Ring bell',
    ], ['require_table' => true]);

    phase5FulfillmentAssert($created['persisted'] === true, 'first fulfillment write was not persisted');
    phase5FulfillmentAssert($created['order_channel'] === 'moova_delivery', 'delivery channel not stored');
    phase5FulfillmentAssert($created['fulfillment_type'] === 'delivery', 'delivery fulfillment type not stored');
    phase5FulfillmentAssert($created['external_provider'] === 'moova', 'external provider not stored');
    phase5FulfillmentAssert($created['external_order_id'] === 'MOOVA-DEL-001', 'external order id not stored');
    phase5FulfillmentAssert($created['customer_name'] === 'Nour Test', 'customer name not stored');
    phase5FulfillmentAssert($created['customer_phone'] === '01000000000', 'customer phone not stored');
    phase5FulfillmentAssert($created['customer_address'] === 'Building 4, Test Street', 'customer address not stored');
    phase5FulfillmentAssert($created['delivery_zone'] === 'Downtown', 'delivery zone not stored');
    phase5FulfillmentAssert(abs($created['delivery_fee'] - 15.5) < 0.001, 'delivery fee not stored');
    phase5FulfillmentAssert($created['delivery_status'] === 'pending', 'delivery status not stored');
    phase5FulfillmentAssert($created['promised_at'] === '2026-05-13 19:30:00', 'promised time not stored');
    phase5FulfillmentAssert(($created['metadata']['table_number'] ?? null) === 'D1', 'metadata table number missing');

    $updated = $service->upsertMoovaFulfillment($conn, 123, [
        'cofeOrderId' => 'MOOVA-DEL-001',
        'branchId' => 'BR-1',
        'tableNumber' => 'D1',
        'fulfillmentType' => 'delivery',
        'customerName' => 'Nour Updated',
        'customerPhone' => '01111111111',
        'customerAddress' => 'Updated Address',
        'deliveryFee' => '20',
        'deliveryStatus' => 'accepted',
    ], ['require_table' => true]);

    phase5FulfillmentAssert($updated['persisted'] === true, 'updated fulfillment write was not persisted');
    phase5FulfillmentAssert($updated['customer_name'] === 'Nour Updated', 'customer name was not updated');
    phase5FulfillmentAssert($updated['delivery_status'] === 'accepted', 'delivery status was not updated');
    phase5FulfillmentAssert(abs($updated['delivery_fee'] - 20.0) < 0.001, 'delivery fee was not updated');
    phase5FulfillmentAssert(phase5FulfillmentCount($conn) === 1, 'upsert created duplicate fulfillment rows');

    $qr = $service->upsertMoovaFulfillment($conn, 124, [
        'cofeOrderId' => 'MOOVA-QR-001',
        'branchId' => 'BR-1',
        'tableNumber' => '7',
        'items' => [
            ['itemId' => '10', 'qty' => 1],
        ],
    ], ['require_table' => true]);

    phase5FulfillmentAssert($qr['order_channel'] === 'moova_qr', 'Moova table order channel not classified');
    phase5FulfillmentAssert($qr['fulfillment_type'] === 'table', 'Moova table order fulfillment not classified');
    phase5FulfillmentAssert($qr['delivery_status'] === 'none', 'Moova table order delivery status should be none');
    phase5FulfillmentAssert(phase5FulfillmentCount($conn) === 2, 'second order fulfillment row missing');

    echo "phase5-order-fulfillment-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase5FulfillmentCount(mysqli $conn): int
{
    $result = $conn->query('SELECT COUNT(*) AS row_count FROM order_fulfillment');
    $row = $result->fetch_assoc();

    return (int) $row['row_count'];
}

function phase5FulfillmentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
