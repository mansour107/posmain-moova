<?php

$root = realpath(__DIR__ . '/../..');
$source = file_get_contents($root . '/ajax/process_table_payment.php');
$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');
$sideEffects = file_get_contents($root . '/classes/Pos/Service/OrderMutationSideEffectsService.php');

if ($source === false || $controller === false || $sideEffects === false) {
    throw new RuntimeException('Unable to read table-payment routing contract files');
}

processTablePaymentRoutingAssert(strpos($source, 'pos_api_dispatch') !== false, 'endpoint should delegate to pos_api_dispatch');
processTablePaymentRoutingAssert(strpos($source, 'orders.payment') !== false, 'endpoint should target orders.payment route');
processTablePaymentRoutingAssert(strpos($controller, '$posMutationService->payTableOrder') !== false, 'controller should route payment mutation through PosOrderMutationService');
processTablePaymentRoutingAssert(strpos($controller, "'pos_customer_id'") !== false, 'controller should pass pos_customer_id for CRM rollup');
processTablePaymentRoutingAssert(strpos($controller, '$accountingPostingService->postTablePaymentReceipt') !== false, 'controller should route receipt posting through OrderAccountingService');
processTablePaymentRoutingAssert(strpos($controller, '(new OrderMutationSideEffectsService())->recordTablePayment') !== false, 'controller should centralize table order/outbox side effects');
processTablePaymentRoutingAssert(strpos($sideEffects, 'SyncOutboxEventService') !== false, 'central side-effect service should preserve sync outbox recording');
processTablePaymentRoutingAssert(strpos($sideEffects, "'order.payment_recorded'") !== false, 'central side-effect service should preserve the payment lifecycle event');
processTablePaymentRoutingAssert(strpos($sideEffects, "'active_order_id' => \$activeOrderId") !== false, 'central side-effect service should preserve the active table order snapshot');
processTablePaymentRoutingAssert(strpos($controller, "'receipt_id' => \$receiptId") !== false, 'controller should preserve receipt_id response key');
processTablePaymentRoutingAssert(strpos($controller, "'invoice_id' => \$orderId") !== false, 'controller should preserve invoice_id response key');
processTablePaymentRoutingAssert(strpos($controller, 'تم السداد بالكامل') !== false, 'controller should preserve full-payment Arabic message');
processTablePaymentRoutingAssert(strpos($controller, 'تم تسجيل دفعة جزئية') !== false, 'controller should preserve partial-payment Arabic message');

$forbiddenSnippets = [
    'INSERT INTO journal_heads',
    'INSERT INTO journal_entries',
    'INSERT INTO ot_head (',
    '->nextJournalId(',
];

foreach ($forbiddenSnippets as $snippet) {
    processTablePaymentRoutingAssert(strpos($source, $snippet) === false, 'endpoint shim should not keep direct accounting snippet: ' . $snippet);
}

echo "process-table-payment-endpoint-routing-ok\n";

function processTablePaymentRoutingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
