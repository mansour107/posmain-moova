<?php

$root = realpath(__DIR__ . '/../..');
$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');

$shimExpectations = [
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
];

foreach ($shimExpectations as $path => $snippets) {
    $source = file_get_contents($root . '/' . $path);
    posTableEndpointIdempotencyAssert(is_string($source), 'unable to read ' . $path);
    foreach ($snippets as $snippet) {
        posTableEndpointIdempotencyAssert(strpos($source, $snippet) !== false, $path . ' should delegate via ' . $snippet);
    }
}

$controllerExpectations = [
    'saveTable' => 'PosOrderMutationService::SCOPE_TABLE_SAVE',
    'payTable' => 'PosOrderMutationService::SCOPE_TABLE_PAYMENT',
    'splitPayment' => 'PosOrderMutationService::SCOPE_SPLIT_PAYMENT',
];

foreach ($controllerExpectations as $method => $scope) {
    posTableEndpointIdempotencyAssert(strpos($controller, 'function ' . $method) !== false, 'controller should implement ' . $method);
    posTableEndpointIdempotencyAssert(strpos($controller, $scope) !== false, 'controller ' . $method . ' should use ' . $scope);
}

posTableEndpointIdempotencyAssert(strpos($controller, '$idempotencyService = new IdempotencyService()') !== false, 'controller should instantiate IdempotencyService');
posTableEndpointIdempotencyAssert(strpos($controller, 'IDEMPOTENCY_CONFLICT') !== false, 'controller should handle idempotency conflicts');

$directEndpointExpectations = [
    'ajax/delete_order.php' => [
        'scope' => 'PosOrderMutationService::SCOPE_ORDER_CANCEL',
        'complete' => '$idempotencyService->complete($conn, PosOrderMutationService::SCOPE_ORDER_CANCEL',
    ],
    'ajax/clear_table.php' => [
        'scope' => 'PosOrderMutationService::SCOPE_ORDER_CANCEL',
        'complete' => '$idempotencyService->complete($conn, PosOrderMutationService::SCOPE_ORDER_CANCEL',
    ],
    'ajax/clear_table_normal.php' => [
        'scope' => 'PosOrderMutationService::SCOPE_ORDER_CANCEL',
        'complete' => '$idempotencyService->complete($conn, PosOrderMutationService::SCOPE_ORDER_CANCEL',
    ],
    'ajax/update_table_status.php' => [
        'scope' => 'PosOrderMutationService::SCOPE_ORDER_CANCEL',
        'complete' => '$idempotencyService->complete($conn, PosOrderMutationService::SCOPE_ORDER_CANCEL',
    ],
];

foreach ($directEndpointExpectations as $path => $expectation) {
    $source = file_get_contents($root . '/' . $path);
    posTableEndpointIdempotencyAssert(is_string($source), 'unable to read ' . $path);
    posTableEndpointIdempotencyAssert(strpos($source, '$idempotencyService = new IdempotencyService()') !== false, $path . ' should instantiate IdempotencyService');
    posTableEndpointIdempotencyAssert(strpos($source, '$idempotencyService->begin($conn, ' . $expectation['scope']) !== false, $path . ' should begin the expected scope');
    posTableEndpointIdempotencyAssert(strpos($source, $expectation['complete']) !== false, $path . ' should complete the idempotency row after side effects');
}

$jsExpectations = [
    'js/pos_barcode.js' => [
        'createPOSIdempotencyKey',
        'ensureFormIdempotencyKey(form, action)',
        "idempotency_key: createPOSIdempotencyKey('pos.order.cancel')",
    ],
    'includes/pos_content.php' => [
        'createPOSIdempotencyKey',
        'ensureFormIdempotencyKey(form, action)',
        "idempotency_key: createPOSIdempotencyKey('pos.order.cancel')",
    ],
    'js/pos_tables.js' => [
        "getPOSTableIdempotencyKey('pos.table.save')",
        "idempotency_key: getPOSTableIdempotencyKey('pos.order.cancel')",
    ],
    'tables.php' => [
        "'pos.payment.table'",
        'idempotency_key: getPOSTablePageIdempotencyKey(requestScope)',
        "'pos.payment.split'",
        'clearPOSTablePageIdempotencyKey(requestScope)',
    ],
];

foreach ($jsExpectations as $path => $snippets) {
    $source = file_get_contents($root . '/' . $path);
    posTableEndpointIdempotencyAssert(is_string($source), 'unable to read ' . $path);
    foreach ($snippets as $snippet) {
        posTableEndpointIdempotencyAssert(strpos($source, $snippet) !== false, $path . ' missing UI idempotency snippet: ' . $snippet);
    }
}

echo "pos-table-endpoint-idempotency-ok\n";

function posTableEndpointIdempotencyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
