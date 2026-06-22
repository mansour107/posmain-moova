<?php

require_once __DIR__ . '/../../classes/Moova/MoovaLocalIngestService.php';

$root = dirname(__DIR__, 2);
$posOrderSource = file_get_contents($root . '/classes/PosOrderService.php');
$ingest = new MoovaLocalIngestService();

$deliveryPayload = $ingest->normalizeNewOrderForPos([
    'cofeOrderId' => 'MOOVA-DEL-NO-TABLE',
    'branchId' => 'BR-1',
    'items' => [['itemId' => '10', 'qty' => '1']],
    'fulfillmentType' => 'delivery',
    'orderChannel' => 'moova_delivery',
    'customerName' => 'Delivery Only',
    'customerPhone' => '01005556677',
    'customerAddress' => 'Street 9',
]);

moovaDeliveryTypeAssert($deliveryPayload['fulfillmentType'] === 'delivery', 'delivery payload should preserve fulfillment type');
moovaDeliveryTypeAssert(!isset($deliveryPayload['tableId']) && !isset($deliveryPayload['tableNumber']), 'delivery payload should not require table when fulfillment is delivery');

moovaDeliveryTypeAssert(strpos($posOrderSource, 'createOrMergeMoovaDeliveryOrder') !== false, 'PosOrderService should include delivery order path');
moovaDeliveryTypeAssert(strpos($posOrderSource, "NULL, 'delivery'") !== false, 'Moova delivery header should use order_type delivery');
moovaDeliveryTypeAssert(strpos($posOrderSource, 'isMoovaDeliveryPayload') !== false, 'PosOrderService should detect Moova delivery payloads');

echo "moova_delivery_order_type_test: OK\n";

function moovaDeliveryTypeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "moova_delivery_order_type_test FAILED: {$message}\n");
        exit(1);
    }
}
