<?php

$endpointExpectations = [
    'ajax/save_order.php' => [
        "require_once('../classes/Pos/Validation/OrderInputValidator.php')",
        'OrderInputValidator::validateTableSave($data)',
        'VALIDATION_FAILED',
    ],
    'ajax/process_table_payment.php' => [
        "require_once('../classes/Pos/Validation/PaymentInputValidator.php')",
        'PaymentInputValidator::validateTablePayment($_POST)',
        'TableInputValidator::failureResponse($e)',
    ],
    'ajax/process_split_payment.php' => [
        "require_once('../classes/Pos/Validation/PaymentInputValidator.php')",
        'PaymentInputValidator::validateSplitPayment($data)',
        'TableInputValidator::failureResponse($e)',
    ],
    'ajax/delete_order.php' => [
        "require_once('../classes/Pos/Validation/TableInputValidator.php')",
        'TableInputValidator::positiveInt',
        'TableInputValidator::reason',
    ],
    'ajax/clear_table.php' => [
        "require_once('../classes/Pos/Validation/TableInputValidator.php')",
        'TableInputValidator::positiveInt',
        'TableInputValidator::reason',
    ],
    'ajax/clear_table_normal.php' => [
        "require_once('../classes/Pos/Validation/TableInputValidator.php')",
        'TableInputValidator::positiveInt',
        'TableInputValidator::reason',
    ],
    'ajax/update_table_status.php' => [
        "require_once('../classes/Pos/Validation/TableInputValidator.php')",
        'TableInputValidator::tableStatusAction',
        'TableInputValidator::reason',
    ],
];

foreach ($endpointExpectations as $path => $snippets) {
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    posEndpointValidationAssert(is_string($source), 'unable to read ' . $path);
    foreach ($snippets as $snippet) {
        posEndpointValidationAssert(strpos($source, $snippet) !== false, $path . ' missing validation snippet: ' . $snippet);
    }
}

$saveSource = file_get_contents(__DIR__ . '/../../ajax/save_order.php');
posEndpointValidationAssert(
    strpos($saveSource, 'OrderInputValidator::validateTableSave($data)') < strpos($saveSource, '$idempotencyService = new IdempotencyService()'),
    'save_order should validate before beginning idempotency or mutations'
);

$splitSource = file_get_contents(__DIR__ . '/../../ajax/process_split_payment.php');
posEndpointValidationAssert(
    strpos($splitSource, 'PaymentInputValidator::validateSplitPayment($data)') < strpos($splitSource, '$idempotencyService = new IdempotencyService()'),
    'split payment should validate before beginning idempotency or mutations'
);

echo "pos-endpoint-validation-contract-ok\n";

function posEndpointValidationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
