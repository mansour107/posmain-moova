<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Moova/MoovaLocalIngestService.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderFulfillmentService.php';

$root = dirname(__DIR__, 2);
$schemaSource = file_get_contents($root . '/classes/Sync/SchemaManager.php');
$applySource = file_get_contents($root . '/classes/Moova/MoovaNewOrderApplyService.php');
$serviceSource = file_get_contents($root . '/classes/Pos/Service/OrderFulfillmentService.php');
$docSource = file_get_contents($root . '/docs/production/moova_delivery_foundation.md');

$manager = new SyncSchemaManager();
$planned = $manager->plannedStatements();
phase5DeliveryAssert(isset($planned['order_fulfillment']), 'order_fulfillment planned statement missing');
$sql = $planned['order_fulfillment'];

foreach ([
    'CREATE TABLE IF NOT EXISTS order_fulfillment',
    'order_channel VARCHAR(40) NOT NULL DEFAULT',
    'fulfillment_type VARCHAR(40) NOT NULL DEFAULT',
    'external_provider VARCHAR(40) NULL',
    'external_order_id VARCHAR(120) NULL',
    'customer_name VARCHAR(160) NULL',
    'customer_phone VARCHAR(60) NULL',
    'customer_address VARCHAR(500) NULL',
    'delivery_zone VARCHAR(120) NULL',
    'delivery_fee DECIMAL(12,3) NOT NULL DEFAULT 0.000',
    'delivery_status VARCHAR(40) NOT NULL DEFAULT',
    'promised_at DATETIME NULL',
    'metadata_json JSON NULL',
    'UNIQUE KEY uq_order_fulfillment_order (order_id)',
    'KEY idx_order_fulfillment_channel',
] as $needle) {
    phase5DeliveryAssert(strpos($sql, $needle) !== false, "schema missing {$needle}");
}

phase5DeliveryAssert(strpos($schemaSource, 'ALTER TABLE ot_head ADD COLUMN order_channel') === false, 'T008 should avoid ot_head delivery churn');
phase5DeliveryAssert(strpos($serviceSource, 'class OrderFulfillmentService') !== false, 'fulfillment service missing');
phase5DeliveryAssert(strpos($serviceSource, 'upsertMoovaFulfillment') !== false, 'Moova upsert method missing');
phase5DeliveryAssert(strpos($serviceSource, 'ORDER_FULFILLMENT_TABLE_MISSING') !== false, 'missing-table nonblocking guard missing');
phase5DeliveryAssert(strpos($applySource, "require_once __DIR__ . '/../Pos/Service/OrderFulfillmentService.php'") !== false, 'Moova apply does not load fulfillment service');
phase5DeliveryAssert(strpos($applySource, 'upsertMoovaFulfillment') !== false, 'Moova apply does not persist fulfillment metadata');

$ingest = new MoovaLocalIngestService();
$posPayload = $ingest->normalizeNewOrderForPos([
    'cofeOrderId' => 'MOOVA-DEL-001',
    'branchId' => 'BR-1',
    'tableNumber' => 'D1',
    'items' => [
        ['itemId' => '10', 'qty' => '1'],
    ],
    'fulfillmentType' => 'delivery',
    'orderChannel' => 'moova_delivery',
    'customer' => [
        'name' => 'Test Customer',
        'phone' => '01000000000',
    ],
    'delivery' => [
        'address' => 'Street 1',
        'zone' => 'Downtown',
        'fee' => '12.500',
        'status' => 'pending',
        'promisedAt' => '2026-05-13 19:30:00',
    ],
]);

foreach (['fulfillmentType', 'orderChannel', 'customerName', 'customerPhone', 'customerAddress', 'deliveryZone', 'deliveryFee', 'deliveryStatus', 'promisedAt', 'delivery'] as $key) {
    phase5DeliveryAssert(array_key_exists($key, $posPayload), "normalized POS payload missing {$key}");
}
phase5DeliveryAssert($posPayload['orderChannel'] === 'moova_delivery', 'order channel was not preserved');
phase5DeliveryAssert($posPayload['fulfillmentType'] === 'delivery', 'fulfillment type was not preserved');
phase5DeliveryAssert($posPayload['customerAddress'] === 'Street 1', 'delivery address was not promoted to structured field');

$service = new OrderFulfillmentService();
$deliveryData = $service->extractFromMoovaPayload($posPayload);
phase5DeliveryAssert($deliveryData['order_channel'] === 'moova_delivery', 'service did not classify Moova delivery channel');
phase5DeliveryAssert($deliveryData['fulfillment_type'] === 'delivery', 'service did not classify delivery fulfillment');
phase5DeliveryAssert($deliveryData['delivery_status'] === 'pending', 'service did not default delivery status');

$qrData = $service->extractFromMoovaPayload([
    'cofeOrderId' => 'MOOVA-QR-001',
    'branchId' => 'BR-1',
    'tableNumber' => '7',
    'items' => [
        ['itemId' => '10', 'qty' => 1],
    ],
]);
phase5DeliveryAssert($qrData['order_channel'] === 'moova_qr', 'QR/table Moova order should be reportable as moova_qr');
phase5DeliveryAssert($qrData['fulfillment_type'] === 'table', 'QR/table Moova order should be reportable as table fulfillment');

foreach ([
    'Use the additive `order_fulfillment` table',
    'QR/table orders default to `order_channel=moova_qr`',
    'Delivery-like payloads default to `order_channel=moova_delivery`',
] as $needle) {
    phase5DeliveryAssert(strpos($docSource, $needle) !== false, "delivery foundation doc missing {$needle}");
}

echo "moova-delivery-foundation-ok\n";

function phase5DeliveryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
