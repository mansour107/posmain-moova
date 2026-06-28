<?php

$root = realpath(__DIR__ . '/../..');
$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');
$dispatch = file_get_contents($root . '/includes/pos_api_dispatch.php');

$endpointExpectations = [
    'ajax/save_order.php' => [
        'pos_api_dispatch',
        'orders.table',
    ],
    'ajax/process_table_payment.php' => [
        'pos_api_dispatch',
        'orders.payment',
    ],
    'ajax/process_split_payment.php' => [
        'pos_api_dispatch',
        'orders.split-payment',
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
    $source = file_get_contents($root . '/' . $path);
    posEndpointValidationAssert(is_string($source), 'unable to read ' . $path);
    foreach ($snippets as $snippet) {
        posEndpointValidationAssert(strpos($source, $snippet) !== false, $path . ' missing validation snippet: ' . $snippet);
    }
}

posEndpointValidationAssert(
    strpos($controller, 'PaymentInputValidator::validateTablePayment') !== false,
    'controller should validate table payments'
);
posEndpointValidationAssert(
    strpos($controller, 'PaymentInputValidator::validateSplitPayment') !== false,
    'controller should validate split payments'
);
posEndpointValidationAssert(
    strpos($dispatch, 'PosOrderAccessPolicy::requireRoutePermission') !== false,
    'dispatch should enforce route permissions'
);

echo "pos-endpoint-validation-contract-ok\n";

function posEndpointValidationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
