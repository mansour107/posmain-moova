<?php

$sourcePath = __DIR__ . '/../../ajax/process_table_payment.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read process_table_payment.php');
}

processTablePaymentRoutingAssert(strpos($source, "require_once('../classes/Pos/Service/PosOrderMutationService.php')") !== false, 'endpoint should require PosOrderMutationService');
processTablePaymentRoutingAssert(strpos($source, "require_once('../classes/Pos/Service/AccountingPostingService.php')") !== false, 'endpoint should require AccountingPostingService');
processTablePaymentRoutingAssert(strpos($source, '$posMutationService->payTableOrder') !== false, 'endpoint should route payment mutation through PosOrderMutationService');
processTablePaymentRoutingAssert(strpos($source, '$accountingPostingService->postTablePaymentReceipt') !== false, 'endpoint should route receipt and journal posting through AccountingPostingService');
processTablePaymentRoutingAssert(strpos($source, 'SyncOutboxEventService') !== false, 'endpoint should preserve sync outbox recording');
processTablePaymentRoutingAssert(strpos($source, "'receipt_id' => \$receipt_id") !== false, 'endpoint should preserve receipt_id response key');
processTablePaymentRoutingAssert(strpos($source, "'invoice_id' => \$order_id") !== false, 'endpoint should preserve invoice_id response key');
processTablePaymentRoutingAssert(strpos($source, "تم السداد بالكامل") !== false, 'endpoint should preserve full-payment Arabic message');
processTablePaymentRoutingAssert(strpos($source, "تم تسجيل دفعة جزئية") !== false, 'endpoint should preserve partial-payment Arabic message');

$forbiddenSnippets = [
    'INSERT INTO journal_heads',
    'INSERT INTO journal_entries',
    'INSERT INTO ot_head (',
    '->nextJournalId(',
];

foreach ($forbiddenSnippets as $snippet) {
    processTablePaymentRoutingAssert(strpos($source, $snippet) === false, 'endpoint should not keep direct accounting snippet: ' . $snippet);
}

echo "process-table-payment-endpoint-routing-ok\n";

function processTablePaymentRoutingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
