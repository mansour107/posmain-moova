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
frontendCutoverAssert(strpos($posOrderApi, "route === 'orders.payment'") !== false, 'pos_order_api should build payment payload for table cash');
frontendCutoverAssert(strpos($posOrderApi, 'clearCashierEditState') !== false, 'pos_order_api should clear stale takeaway edit state');
frontendCutoverAssert(strpos($posOrderApi, 'age === 2') !== false, 'pos_order_api readEditId should scope table mode to selected_order_id');

frontendCutoverAssert(strpos($posBarcode, 'clearCashierEditState') !== false, 'pos_barcode should clear stale edit state when switching modes');
frontendCutoverAssert(strpos($posContent, 'POSShowOrderSuccess') !== false, 'pos_content should expose API-driven success modal');
frontendCutoverAssert(strpos($posContent, 'submitPOS (Inline Override)') === false, 'pos_content should not duplicate inline submitPOS');

frontendCutoverAssert(strpos($posBarcode, 'window.submitPOS = function') !== false, 'pos_barcode should own submitPOS');
frontendCutoverAssert(strpos($posBarcode, 'submitFromForm') !== false, 'pos_barcode submitPOS should use POSOrderApi.submitFromForm');

$posOrderDraft = file_get_contents($root . '/js/pos_order_draft.js');
frontendCutoverAssert(strpos($posContent, 'pos_order_draft.js') !== false, 'pos_content should load pos_order_draft.js');
frontendCutoverAssert(strpos($posOrderDraft, 'window.POSOrderDraft') !== false, 'pos_order_draft should export POSOrderDraft');
frontendCutoverAssert(strpos($posOrderApi, 'markSaved(body)') !== false || strpos($posOrderApi, 'draft.markSaved') !== false, 'pos_order_api should integrate draft saved state');

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
