<?php

$root = __DIR__ . '/../..';
$source = file_get_contents($root . '/ajax/process_split_payment.php');
$dispatch = file_get_contents($root . '/includes/pos_api_dispatch.php');
$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');
$sideEffects = file_get_contents($root . '/classes/Pos/Service/OrderMutationSideEffectsService.php');
if ($source === false || $dispatch === false || $controller === false || $sideEffects === false) {
    throw new RuntimeException('Unable to read split-payment routing contract files');
}

splitEndpointRoutingAssert(strpos($source, 'pos_api_dispatch') !== false, 'split endpoint should delegate to pos_api_dispatch');
splitEndpointRoutingAssert(strpos($source, "orders.split-payment") !== false, 'split endpoint should target orders.split-payment');
splitEndpointRoutingAssert(strpos($source, 'pos_api_dispatch_exception_payload') !== false, 'split endpoint should use the central safe dispatch error path');
splitEndpointRoutingAssert(strpos($dispatch, 'return $controller->splitPayment') !== false, 'dispatcher should route split payment to PosOrderController');
splitEndpointRoutingAssert(strpos($controller, '$posMutationService->splitTablePayment') !== false, 'controller should route mutation through splitTablePayment');
splitEndpointRoutingAssert(strpos($controller, "'in_transaction' => true") !== false, 'controller should keep service mutation in its transaction');
splitEndpointRoutingAssert(strpos($controller, "'skip_idempotency' => true") !== false, 'controller should own one idempotency boundary around the delegated service');
splitEndpointRoutingAssert(strpos($controller, '(new OrderMutationSideEffectsService())->recordSplitPayment') !== false, 'controller should delegate order/table/outbox effects to the central side-effect service');
splitEndpointRoutingAssert(strpos($sideEffects, 'SyncOutboxEventService') !== false, 'central side-effect service should own sync outbox recording');
splitEndpointRoutingAssert(strpos($sideEffects, "'order.split_paid'") !== false, 'central side-effect service should preserve the child order split-paid event');
splitEndpointRoutingAssert(strpos($sideEffects, "'active_order_id' => \$activeOrderId") !== false, 'central side-effect service should preserve table active order snapshot value');
splitEndpointRoutingAssert(strpos($controller, "'new_invoice_id' => \$newHeadId") !== false, 'controller should preserve new_invoice_id response key');
splitEndpointRoutingAssert(strpos($controller, "'split_group_id' => \$splitGroupId") !== false, 'controller should preserve split_group_id response key');
splitEndpointRoutingAssert(strpos($controller, 'تم سداد الأصناف المختارة بنجاح') !== false, 'controller should preserve Arabic success message');

$forbiddenSnippets = [
    'INSERT INTO ot_head',
    'UPDATE fat_details',
    'INSERT INTO fat_details',
    'UPDATE ot_head',
    'INSERT INTO order_payments',
    'nextPosProId',
    'recalculateOrderTotals',
    'markTableOccupied',
    'setTableFreeIfNoActiveOrder',
];

foreach ($forbiddenSnippets as $snippet) {
    splitEndpointRoutingAssert(strpos($source, $snippet) === false, 'split endpoint should not keep direct split business snippet: ' . $snippet);
}

echo "process-split-payment-endpoint-routing-ok\n";

function splitEndpointRoutingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
