<?php

$root = realpath(__DIR__ . '/../..');
$posOrderApi = file_get_contents($root . '/js/pos_order_api.js');
$posBarcode = file_get_contents($root . '/js/pos_barcode.js');
$posContent = file_get_contents($root . '/includes/pos_content.php');
$dispatch = file_get_contents($root . '/includes/pos_api_dispatch.php');
$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');

$matrix = [
    ['takeaway', 'save', 'orders.takeaway'],
    ['takeaway', 'print_receipt', 'orders.takeaway'],
    ['takeaway', 'cash', 'orders.takeaway'],
    ['takeaway_edit', 'save', 'orders.edit'],
    ['table', 'save', 'orders.table'],
    ['table', 'print_receipt', 'orders.table'],
    ['table', 'cash', 'orders.payment'],
    ['table_edit', 'save', 'orders.table'],
    ['delivery', 'save', 'orders.delivery'],
    ['delivery', 'cash', 'orders.delivery'],
    ['any', 'split_cash', 'orders.split-payment'],
    ['table', 'free_table', 'orders.table.free'],
];

foreach ($matrix as [$channel, $action, $route]) {
    orderMatrixAssert(
        strpos($posOrderApi, "'" . $route . "'") !== false,
        'pos_order_api should reference route ' . $route
    );
}

orderMatrixAssert(strpos($posOrderApi, "action === 'save'") !== false, 'resolveRoute should handle save');
orderMatrixAssert(strpos($posOrderApi, 'orders.edit') !== false, 'resolveRoute should support edit route');
orderMatrixAssert(strpos($posOrderApi, 'orders.table.free') !== false, 'resolveRoute should support free table route');
orderMatrixAssert(strpos($posOrderApi, 'POSShowOrderSuccess') !== false || strpos($posOrderApi, 'showOrderSuccess') !== false, 'handleOrderResponse should show success without reload');
orderMatrixAssert(strpos($posOrderApi, 'window.location.href = \'pos_barcode.php') === false, 'save should not force full page reload');

orderMatrixAssert(strpos($posBarcode, 'POSOrderApi') !== false, 'pos_barcode submitPOS should use POSOrderApi');
orderMatrixAssert(strpos($posBarcode, 'HTMLFormElement.prototype.submit.call(form)') === false, 'pos_barcode should not legacy-submit cashier orders');
orderMatrixAssert(strpos($posContent, 'submitPOS is owned by js/pos_barcode.js') !== false, 'pos_content should not own duplicate submitPOS');

orderMatrixAssert(strpos($dispatch, "'orders.edit'") !== false, 'dispatch should register orders.edit');
orderMatrixAssert(strpos($dispatch, "'orders.table.free'") !== false, 'dispatch should register orders.table.free');
orderMatrixAssert(strpos($dispatch, 'updateOrder') !== false, 'dispatch should call updateOrder');
orderMatrixAssert(strpos($dispatch, 'freeTable') !== false, 'dispatch should call freeTable');

orderMatrixAssert(strpos($controller, 'function updateOrder') !== false, 'controller should implement updateOrder');
orderMatrixAssert(strpos($controller, 'function freeTable') !== false, 'controller should implement freeTable');
orderMatrixAssert(strpos($controller, 'updated_state') !== false, 'controller responses should include updated_state');

$userContext = file_get_contents($root . '/includes/pos_user_context.php');
orderMatrixAssert(strpos($userContext, ': 1;') === false, 'pos user context should not fallback to user id 1');

$recovery = file_get_contents($root . '/ajax/pos_write_recovery_status.php');
orderMatrixAssert(strpos($recovery, 'stuck_idempotency') !== false, 'recovery endpoint should expose stuck idempotency');

echo "pos-order-creation-matrix-contract-ok\n";

function orderMatrixAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
