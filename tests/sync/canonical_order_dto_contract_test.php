<?php

require_once __DIR__ . '/../../classes/Pos/DTO/OrderCreateRequest.php';

$table = OrderCreateRequest::fromTableSavePayload([
    'table_id' => 3,
    'items' => [['id' => 10, 'qty' => 2, 'price' => 15]],
    'total' => 30,
], 7);
canonicalDtoAssert($table->tableId === 3, 'table save dto should parse table id');
canonicalDtoAssert(count($table->lines) === 1, 'table save dto should parse lines');
canonicalDtoAssert($table->total === '30.00', 'table save dto must keep total as an exact decimal string');
canonicalDtoAssert($table->lines[0]->qty === '2.000000', 'line quantity must remain an exact decimal string');
canonicalDtoAssert($table->lines[0]->price === '15.000000', 'line price must remain an exact decimal string');

$takeaway = OrderCreateRequest::fromTakeawayPayload(['items' => []], 1);
canonicalDtoAssert(($takeaway->raw['channel'] ?? '') === 'takeaway', 'takeaway dto should set channel');

$delivery = OrderCreateRequest::fromDeliveryPayload(['items' => []], 1);
canonicalDtoAssert(($delivery->raw['channel'] ?? '') === 'delivery', 'delivery dto should set channel');

$payment = OrderCreateRequest::fromTablePaymentPayload(['order_id' => 42, 'amount' => 100], 2);
canonicalDtoAssert($payment->orderId === 42, 'payment dto should parse order id');
canonicalDtoAssert($payment->net === '100.00', 'payment dto must keep amount as an exact decimal string');

$split = OrderCreateRequest::fromSplitPaymentPayload(['order_id' => 5, 'lines' => [['id' => 1, 'qty' => 1]]], 2);
canonicalDtoAssert(count($split->lines) === 1, 'split payment dto should parse split lines');

$cofe = OrderCreateRequest::fromIntegrationPayload(['idempotencyKey' => 'abc'], 0, 'cofe');
canonicalDtoAssert($cofe->idempotencyKey === 'abc', 'integration dto should parse idempotency key');

$withCustomer = OrderCreateRequest::fromTableSavePayload(['pos_customer_id' => 55, 'items' => []], 1);
canonicalDtoAssert($withCustomer->posCustomerId === 55, 'table save dto should parse pos customer id');
canonicalDtoAssert(($withCustomer->toTableSaveArray()['pos_customer_id'] ?? null) === 55, 'table save array should include pos customer id');

$floatRejected = false;
try {
    OrderCreateRequest::fromTableSavePayload(['total' => 0.1, 'items' => []], 1);
} catch (InvalidArgumentException $exception) {
    $floatRejected = $exception->getMessage() === 'FINANCIAL_DECIMAL_STRING_REQUIRED';
}
canonicalDtoAssert($floatRejected, 'canonical order DTO must reject PHP floats at the financial boundary');

echo "canonical-order-dto-contract-ok\n";

function canonicalDtoAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
