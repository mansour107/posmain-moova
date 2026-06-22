<?php

$root = dirname(__DIR__, 2);

deliveryCreateAssert(strpos(file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php'), 'createDeliveryOrder') !== false, 'createDeliveryOrder missing');
deliveryCreateAssert(strpos(file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php'), 'SCOPE_DELIVERY_CREATE') !== false, 'delivery idempotency scope missing');
deliveryCreateAssert(strpos(file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php'), 'DeliveryClientService') !== false, 'delivery create should upsert clients');
deliveryCreateAssert(strpos(file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php'), 'OrderFulfillmentService') !== false, 'delivery create should write fulfillment');
deliveryCreateAssert(strpos(file_get_contents($root . '/do/doadd_invoice.php'), 'route_delivery_service') !== false, 'doadd_invoice should route delivery to service');
deliveryCreateAssert(strpos(file_get_contents($root . '/config/app_config.php'), 'POSMAIN_DELIVERY_V2') !== false, 'delivery v2 feature flag should be documented in config');

echo "delivery_order_create_service_test: OK\n";

function deliveryCreateAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "delivery_order_create_service_test FAILED: {$message}\n");
        exit(1);
    }
}
