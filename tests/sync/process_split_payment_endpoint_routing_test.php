<?php

$sourcePath = __DIR__ . '/../../ajax/process_split_payment.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read ajax/process_split_payment.php');
}

splitEndpointRoutingAssert(strpos($source, "require_once('../classes/Pos/Service/PosOrderMutationService.php')") !== false, 'split endpoint should require PosOrderMutationService');
splitEndpointRoutingAssert(strpos($source, '$posMutationService = new PosOrderMutationService()') !== false, 'split endpoint should instantiate PosOrderMutationService');
splitEndpointRoutingAssert(strpos($source, '$posMutationService->splitTablePayment') !== false, 'split endpoint should route mutation through splitTablePayment');
splitEndpointRoutingAssert(strpos($source, "'in_transaction' => true") !== false, 'split endpoint should keep service call inside endpoint transaction for sync outbox');
splitEndpointRoutingAssert(strpos($source, 'SyncOutboxEventService') !== false, 'split endpoint should preserve sync outbox recording');
splitEndpointRoutingAssert(strpos($source, "'event_type' => 'order.split_paid'") !== false, 'split endpoint should preserve child order outbox event');
splitEndpointRoutingAssert(strpos($source, "'active_order_id' => \$activeTableOrderId") !== false, 'split endpoint should preserve table active order snapshot value');
splitEndpointRoutingAssert(strpos($source, "'new_invoice_id' => \$new_head_id") !== false, 'split endpoint should preserve new_invoice_id response key');
splitEndpointRoutingAssert(strpos($source, "'split_group_id' => \$split_group_id") !== false, 'split endpoint should preserve split_group_id response key');
splitEndpointRoutingAssert(strpos($source, 'تم سداد الأصناف المختارة بنجاح') !== false, 'split endpoint should preserve Arabic success message');

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
