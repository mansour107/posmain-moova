<?php

$root = realpath(__DIR__ . '/../..');
$source = file_get_contents($root . '/ajax/save_order.php');
$dispatch = file_get_contents($root . '/includes/pos_api_dispatch.php');
$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');

if ($source === false) {
    throw new RuntimeException('Unable to read ajax/save_order.php');
}

saveOrderRoutingAssert(strpos($source, 'pos_api_dispatch') !== false, 'save_order should delegate to pos_api_dispatch');
saveOrderRoutingAssert(strpos($dispatch, 'orders.table') !== false, 'dispatch should route table saves');
saveOrderRoutingAssert(strpos($controller, 'function saveTable') !== false, 'controller should own saveTable');
saveOrderRoutingAssert(strpos($source, 'PosOrderMutationService') === false, 'save_order should not keep inline mutation wiring');

$sideEffects = file_get_contents($root . '/classes/Pos/Service/OrderMutationSideEffectsService.php');
saveOrderRoutingAssert(strpos($sideEffects, "\$eventType = \$isUpdate ? 'order.updated' : 'order.saved'") !== false, 'table save side effects should preserve order saved/updated event selection');
saveOrderRoutingAssert(strpos($sideEffects, "'active_order_id' => \$orderStatus === 'completed' ? null : \$orderId") !== false, 'table save side effects should preserve active_order_id semantics');

$forbiddenSnippets = [
    'INSERT INTO ot_head',
    'UPDATE ot_head',
    'INSERT INTO fat_details',
    'UPDATE fat_details SET isdeleted',
    'nextPosProId',
    'recalculateOrderTotals',
    'markTableOccupied',
    'setTableFreeIfNoActiveOrder',
    '$posMutationService = new PosOrderMutationService()',
];

foreach ($forbiddenSnippets as $snippet) {
    saveOrderRoutingAssert(strpos($source, $snippet) === false, 'save_order should not keep direct table-save business snippet: ' . $snippet);
}

echo "save-order-endpoint-routing-ok\n";

function saveOrderRoutingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
