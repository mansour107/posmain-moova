<?php

require_once __DIR__ . '/../../classes/Pos/Validation/TableInputValidator.php';
require_once __DIR__ . '/../../classes/Pos/Validation/OrderInputValidator.php';
require_once __DIR__ . '/../../classes/Pos/Validation/PaymentInputValidator.php';

$save = OrderInputValidator::validateTableSave([
    'table_id' => '12',
    'order_id' => '',
    'order_date' => '2026-05-13',
    'store_id' => '2',
    'emp_id' => '3',
    'fund_id' => '4',
    'items' => [
        ['id' => '10', 'qty' => '2.5', 'price' => '12.75'],
    ],
    'total' => '31.875',
    'discount' => '1.875',
    'net' => '30.0000',
]);
posInputValidationAssert($save['table_id'] === 12, 'table_id should normalize to int');
posInputValidationAssert($save['order_id'] === 0, 'empty order_id should normalize to 0');
posInputValidationAssert($save['items'][0]['item_id'] === 10, 'item_id should normalize from id');
posInputValidationAssert(abs($save['items'][0]['qty'] - 2.5) < 0.0001, 'qty should normalize to decimal');

$payment = PaymentInputValidator::validateTablePayment([
    'table_id' => '5',
    'paid' => '42.50',
    'payment_method' => 'cash',
]);
posInputValidationAssert($payment['table_id'] === 5, 'payment table_id should normalize');
posInputValidationAssert(abs($payment['paid'] - 42.5) < 0.0001, 'paid should normalize');

$split = PaymentInputValidator::validateSplitPayment([
    'order_id' => 7,
    'table_id' => 5,
    'items' => [['detail_id' => '20', 'qty' => '1.25']],
    'paid_amount' => '18.75',
]);
posInputValidationAssert($split['items'][0]['detail_id'] === 20, 'split detail id should normalize');

posInputValidationExpectInvalid(function () {
    TableInputValidator::positiveInt('1 OR 1=1', 'bad id');
}, 'positiveInt should reject SQL-looking numeric input');

posInputValidationExpectInvalid(function () {
    OrderInputValidator::validateTableSave([
        'table_id' => 1,
        'store_id' => 2,
        'emp_id' => 3,
        'fund_id' => 4,
        'items' => [['id' => 10, 'qty' => 1, 'price' => 5]],
        'total' => '5.00',
        'discount' => '6.00',
        'net' => '0.00',
    ]);
}, 'discount greater than total should be rejected');

posInputValidationExpectInvalid(function () {
    PaymentInputValidator::validateTablePayment([
        'table_id' => 1,
        'paid' => 0,
    ]);
}, 'zero payment should be rejected');

posInputValidationExpectInvalid(function () {
    PaymentInputValidator::validateSplitPayment([
        'order_id' => 7,
        'table_id' => 5,
        'items' => ['20 OR 1=1'],
        'paid_amount' => '18.75',
    ]);
}, 'split item ids should reject SQL-looking input');

posInputValidationExpectInvalid(function () {
    TableInputValidator::tableStatusAction(['action' => 'drop']);
}, 'table status action should be whitelisted');

$response = TableInputValidator::failureResponse(new InvalidArgumentException('bad'));
posInputValidationAssert(($response['code'] ?? '') === 'VALIDATION_FAILED', 'validation failures should have structured code');

echo "pos-input-validation-ok\n";

function posInputValidationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function posInputValidationExpectInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }

    throw new RuntimeException($message);
}
