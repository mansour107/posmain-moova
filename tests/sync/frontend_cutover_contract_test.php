<?php

$root = realpath(__DIR__ . '/../..');
$posTables = file_get_contents($root . '/js/pos_tables.js');
$posOrderApi = file_get_contents($root . '/js/pos_order_api.js');
$posContent = file_get_contents($root . '/includes/pos_content.php');
$posBarcode = file_get_contents($root . '/js/pos_barcode.js');

frontendCutoverAssert(strpos($posTables, 'function saveOrder()') !== false, 'pos_tables should define saveOrder');
frontendCutoverAssert(strpos($posTables, 'saveOrder().done') !== false, 'openPayment should await saveOrder success');
frontendCutoverAssert(strpos($posTables, 'api/pos/index.php?route=orders.table') !== false, 'pos_tables should call API router for table save');

frontendCutoverAssert(strpos($posOrderApi, 'api/pos/index.php') !== false, 'pos_order_api should target API router');
frontendCutoverAssert(strpos($posOrderApi, 'resolveRoute') !== false, 'pos_order_api should resolve routes');
frontendCutoverAssert(strpos($posOrderApi, 'postOrderRoute') !== false, 'pos_order_api should post to router');
frontendCutoverAssert(strpos($posOrderApi, 'orders.takeaway') !== false, 'pos_order_api should route takeaway save to API');
frontendCutoverAssert(strpos($posOrderApi, 'orders.payment') !== false, 'pos_order_api should route table cash to payment API');
frontendCutoverAssert(strpos($posOrderApi, 'orders.edit') !== false, 'pos_order_api should route edit mode to orders.edit');

frontendCutoverAssert(strpos($posContent, 'pos_order_api.js') !== false, 'pos_content should load pos_order_api.js');
frontendCutoverAssert(strpos($posContent, 'POSShowOrderSuccess') !== false, 'pos_content should expose API-driven success modal');
frontendCutoverAssert(strpos($posContent, 'submitPOS (Inline Override)') === false, 'pos_content should not duplicate inline submitPOS');

frontendCutoverAssert(strpos($posBarcode, 'window.submitPOS = function') !== false, 'pos_barcode should own submitPOS');
frontendCutoverAssert(strpos($posBarcode, 'submitFromForm') !== false, 'pos_barcode submitPOS should use POSOrderApi.submitFromForm');

$saveOrder = file_get_contents($root . '/ajax/save_order.php');
frontendCutoverAssert(strpos($saveOrder, 'pos_api_dispatch') !== false, 'save_order shim should delegate to dispatch');

$routeMap = file_get_contents($root . '/docs/production/active_route_map.md');
frontendCutoverAssert(strpos($routeMap, 'orders.edit') !== false, 'route map should document orders.edit');
frontendCutoverAssert(strpos($routeMap, 'orders.table.free') !== false, 'route map should document orders.table.free');
frontendCutoverAssert(strpos($routeMap, 'Cashier action matrix') !== false, 'route map should include cashier action matrix');

echo "frontend-cutover-contract-ok\n";

function frontendCutoverAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
