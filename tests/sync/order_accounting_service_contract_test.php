<?php

require_once __DIR__ . '/../../classes/Pos/Service/OrderAccountingService.php';

$source = file_get_contents(__DIR__ . '/../../classes/Pos/Service/OrderAccountingService.php');
orderAccountingAssert(strpos($source, 'postTablePaymentReceipt') !== false, 'facade should expose table payment receipt posting');
orderAccountingAssert(strpos($source, 'postTableInvoiceFinalization') !== false, 'facade should expose table invoice finalization');
orderAccountingAssert(strpos($source, 'shouldPostSalesRecognitionOnTablePayment') !== false, 'facade should expose sales recognition policy');

$paymentController = file_get_contents(__DIR__ . '/../../classes/Pos/Http/PosOrderController.php');
orderAccountingAssert(strpos($paymentController, 'OrderAccountingService') !== false, 'table payment controller should use accounting facade');

echo "order-accounting-service-contract-ok\n";

function orderAccountingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
