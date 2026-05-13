<?php

$endpointExpectations = [
    'ajax/save_order.php' => [
        'scope' => 'PosOrderMutationService::SCOPE_TABLE_SAVE',
        'complete' => '$idempotencyService->complete($conn, PosOrderMutationService::SCOPE_TABLE_SAVE',
    ],
    'ajax/process_table_payment.php' => [
        'scope' => 'PosOrderMutationService::SCOPE_TABLE_PAYMENT',
        'complete' => '$idempotencyService->complete($conn, PosOrderMutationService::SCOPE_TABLE_PAYMENT',
    ],
    'ajax/process_split_payment.php' => [
        'scope' => 'PosOrderMutationService::SCOPE_SPLIT_PAYMENT',
        'complete' => '$idempotencyService->complete($conn, PosOrderMutationService::SCOPE_SPLIT_PAYMENT',
    ],
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

foreach ($endpointExpectations as $path => $expectation) {
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    posTableEndpointIdempotencyAssert(is_string($source), 'unable to read ' . $path);
    posTableEndpointIdempotencyAssert(strpos($source, '$idempotencyService = new IdempotencyService()') !== false, $path . ' should instantiate IdempotencyService');
    posTableEndpointIdempotencyAssert(strpos($source, 'resolveKey(') !== false, $path . ' should require an idempotency key');
    posTableEndpointIdempotencyAssert(strpos($source, 'requestHashForPayload(') !== false, $path . ' should hash the canonical request payload');
    posTableEndpointIdempotencyAssert(strpos($source, '$idempotencyService->begin($conn, ' . $expectation['scope']) !== false, $path . ' should begin the expected scope');
    posTableEndpointIdempotencyAssert(strpos($source, "IDEMPOTENCY_CONFLICT") !== false, $path . ' should handle same-key different-payload conflicts');
    posTableEndpointIdempotencyAssert(strpos($source, "=== 'completed'") !== false, $path . ' should replay completed responses');
    posTableEndpointIdempotencyAssert(strpos($source, $expectation['complete']) !== false, $path . ' should complete the idempotency row after side effects');
    posTableEndpointIdempotencyAssert(strpos($source, "'request_id' => \$idempotencyKey") !== false, $path . ' should echo request_id in success response');
}

$jsExpectations = [
    'js/pos_barcode.js' => [
        "createPOSIdempotencyKey",
        "ensureFormIdempotencyKey(form, action)",
        "idempotency_key: createPOSIdempotencyKey('pos.order.cancel')",
    ],
    'includes/pos_content.php' => [
        "createPOSIdempotencyKey",
        "ensureFormIdempotencyKey(form, action)",
        "idempotency_key: createPOSIdempotencyKey('pos.order.cancel')",
    ],
    'js/pos_tables.js' => [
        "getPOSTableIdempotencyKey('pos.table.save')",
        "idempotency_key: getPOSTableIdempotencyKey('pos.order.cancel')",
    ],
    'tables.php' => [
        "'pos.payment.table'",
        "idempotency_key: getPOSTablePageIdempotencyKey(requestScope)",
        "'pos.payment.split'",
        "clearPOSTablePageIdempotencyKey(requestScope)",
    ],
];

foreach ($jsExpectations as $path => $snippets) {
    $source = file_get_contents(__DIR__ . '/../../' . $path);
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
