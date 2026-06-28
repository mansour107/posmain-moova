<?php

$root = realpath(__DIR__ . '/../..');
$source = file_get_contents($root . '/ajax/process_table_payment.php');
$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');

if ($source === false) {
    throw new RuntimeException('Unable to read process_table_payment.php');
}

processTablePaymentRoutingAssert(strpos($source, 'pos_api_dispatch') !== false, 'endpoint should delegate to pos_api_dispatch');
processTablePaymentRoutingAssert(strpos($source, 'orders.payment') !== false, 'endpoint should target orders.payment route');
processTablePaymentRoutingAssert(strpos($controller, '$posMutationService->payTableOrder') !== false, 'controller should route payment mutation through PosOrderMutationService');
processTablePaymentRoutingAssert(strpos($controller, "'pos_customer_id'") !== false, 'controller should pass pos_customer_id for CRM rollup');
processTablePaymentRoutingAssert(strpos($controller, '$accountingPostingService->postTablePaymentReceipt') !== false, 'controller should route receipt posting through OrderAccountingService');
processTablePaymentRoutingAssert(strpos($controller, 'SyncOutboxEventService') !== false, 'controller should preserve sync outbox recording');
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
