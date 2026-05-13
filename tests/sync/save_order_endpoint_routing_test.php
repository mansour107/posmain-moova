<?php

$sourcePath = __DIR__ . '/../../ajax/save_order.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read ajax/save_order.php');
}

saveOrderRoutingAssert(strpos($source, "require_once('../classes/Pos/Service/PosOrderMutationService.php')") !== false, 'save_order should require PosOrderMutationService');
saveOrderRoutingAssert(strpos($source, '$posMutationService = new PosOrderMutationService()') !== false, 'save_order should instantiate PosOrderMutationService');
saveOrderRoutingAssert(strpos($source, '$posMutationService->saveTableOrder') !== false, 'save_order should route mutation through saveTableOrder');
saveOrderRoutingAssert(strpos($source, "'in_transaction' => true") !== false, 'save_order should keep service call inside endpoint transaction for sync outbox');
saveOrderRoutingAssert(strpos($source, 'SyncOutboxEventService') !== false, 'save_order should preserve sync outbox recording');
saveOrderRoutingAssert(strpos($source, "'event_type' => \$isUpdate ? 'order.updated' : 'order.saved'") !== false, 'save_order should preserve order saved/updated event selection');
saveOrderRoutingAssert(strpos($source, "'active_order_id' => \$orderStatus === 'completed' ? null : \$orderId") !== false, 'save_order should preserve active_order_id semantics');
saveOrderRoutingAssert(strpos($source, "'order_id' => \$orderId") !== false, 'save_order should preserve order_id response key');
saveOrderRoutingAssert(strpos($source, 'تم حفظ الطلب بنجاح') !== false, 'save_order should preserve Arabic success message');

$forbiddenSnippets = [
    'INSERT INTO ot_head',
    'UPDATE ot_head',
    'INSERT INTO fat_details',
    'UPDATE fat_details SET isdeleted',
    'nextPosProId',
    'recalculateOrderTotals',
    'markTableOccupied',
    'setTableFreeIfNoActiveOrder',
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
