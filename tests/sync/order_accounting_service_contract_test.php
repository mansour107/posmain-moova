<?php

require_once __DIR__ . '/../../classes/Pos/Service/OrderAccountingService.php';

$source = file_get_contents(__DIR__ . '/../../classes/Pos/Service/OrderAccountingService.php');
orderAccountingAssert(strpos($source, 'postTablePaymentReceipt') !== false, 'facade should expose table payment receipt posting');
orderAccountingAssert(strpos($source, 'shouldPostSalesRecognitionOnTablePayment') !== false, 'facade should expose sales recognition policy');

$paymentEndpoint = file_get_contents(__DIR__ . '/../../ajax/process_table_payment.php');
orderAccountingAssert(strpos($paymentEndpoint, 'OrderAccountingService') !== false, 'table payment endpoint should use accounting facade');

echo "order-accounting-service-contract-ok\n";

function orderAccountingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
