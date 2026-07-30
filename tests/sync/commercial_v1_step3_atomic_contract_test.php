<?php

/**
 * Commercial V1 Step 3 contract: version locks + idempotency on money/order writes.
 */

$root = dirname(__DIR__, 2);

function step3ContractAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$mutation = (string) file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php');
$transfer = (string) file_get_contents($root . '/classes/Pos/Service/TableTransferService.php');
$merge = (string) file_get_contents($root . '/classes/Pos/Service/TableMergeService.php');
$controller = (string) file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');
$versionService = (string) file_get_contents($root . '/classes/Pos/Service/OrderMutationVersionService.php');
$syncOutbox = (string) file_get_contents($root . '/classes/Sync/SyncOutboxEventService.php');
$operationalOutbox = (string) file_get_contents($root . '/classes/Sync/OperationalSyncEventService.php');
$drawer = (string) file_get_contents($root . '/classes/Pos/Service/DrawerSessionService.php');
$sideEffects = (string) file_get_contents($root . '/classes/Pos/Service/OrderMutationSideEffectsService.php');
$customerSideEffects = (string) file_get_contents($root . '/classes/Pos/Service/PosCustomerOrderSideEffects.php');
$transactionHelper = (string) file_get_contents($root . '/includes/db_transaction.php');
$fulfillment = (string) file_get_contents($root . '/classes/Pos/Service/OrderFulfillmentService.php');
$deliveryStatusEndpoint = (string) file_get_contents($root . '/ajax/delivery_status_update.php');
$deliveryBoard = (string) file_get_contents($root . '/js/delivery_board.js');
$cashierDeliveryEndpoint = (string) file_get_contents($root . '/ajax/pos_delivery_queue.php');

step3ContractAssert(
    str_contains($versionService, 'STALE_ORDER_VERSION')
    && str_contains($versionService, 'MUTATION_VERSION_REQUIRED')
    && str_contains($versionService, 'GREATEST(1, COALESCE(mutation_version, 0))')
    && str_contains($versionService, 'AND mutation_version = ?'),
    'OrderMutationVersionService must normalize legacy zero versions and reject missing/stale versions'
);

foreach ([
    'payTableOrderInsideTransaction' => 'pay',
    'splitTablePaymentInsideTransaction' => 'split',
    'cancelTableOrderInsideTransaction' => 'cancel',
    'cancelDeliveryOrderInsideTransaction' => 'delivery cancel',
    'reversePaidOrder' => 'refund/void',
] as $needle => $label) {
    step3ContractAssert(str_contains($mutation, $needle), "mutation service must define {$label} path ({$needle})");
}

$payPos = strpos($mutation, 'private function payTableOrderInsideTransaction');
$payLock = strpos($mutation, 'lockAndAssert', $payPos !== false ? $payPos : 0);
step3ContractAssert($payPos !== false && $payLock !== false && $payLock < $payPos + 1200, 'pay must lockAndAssert expected mutation_version');

$splitPos = strpos($mutation, 'private function splitTablePaymentInsideTransaction');
$splitLock = strpos($mutation, 'lockAndAssert', $splitPos !== false ? $splitPos : 0);
step3ContractAssert($splitPos !== false && $splitLock !== false && $splitLock < $splitPos + 1200, 'split must lockAndAssert expected mutation_version');

$cancelPos = strpos($mutation, 'private function cancelTableOrderInsideTransaction');
$cancelLock = strpos($mutation, 'lockAndAssert', $cancelPos !== false ? $cancelPos : 0);
step3ContractAssert($cancelPos !== false && $cancelLock !== false && $cancelLock < $cancelPos + 800, 'cancel must lockAndAssert expected mutation_version');

$refundPos = strpos($mutation, 'public function reversePaidOrder');
$refundLock = strpos($mutation, 'lockAndAssert', $refundPos !== false ? $refundPos : 0);
step3ContractAssert($refundPos !== false && $refundLock !== false && $refundLock < $refundPos + 4000, 'refund/void must lockAndAssert expected mutation_version');
step3ContractAssert(
    $refundLock !== false && strpos($mutation, 'findPostedRefundByIdempotency', $refundPos) < $refundLock,
    'refund idempotent replay must short-circuit before version lock'
);

step3ContractAssert(str_contains($transfer, 'lockAndAssert'), 'table move must assert mutation_version');
step3ContractAssert(str_contains($merge, 'lockAndAssert'), 'table merge must assert mutation_version');
step3ContractAssert(
    str_contains($transfer, 'sort($tableIds, SORT_NUMERIC)')
        && str_contains($merge, 'sort($tableIds, SORT_NUMERIC)')
        && str_contains($merge, 'sort($lockOrderIds, SORT_NUMERIC)'),
    'multi-row table/order mutations must use a canonical numeric lock order'
);
step3ContractAssert(
    str_contains($merge, 'source_mutation_version') && str_contains($merge, 'destination_mutation_version'),
    'table merge must version-lock both orders'
);

step3ContractAssert(
    str_contains($controller, 'mutation_version')
    && str_contains($controller, 'payTableOrder')
    && str_contains($controller, 'splitTablePayment'),
    'controller payment/split paths must forward mutation_version'
);
step3ContractAssert(
    str_contains($controller, '$autoCreatedMutationVersion = !empty($resolvedPaymentOrder[\'created\'])')
        && str_contains($controller, "'created' => false")
        && str_contains($controller, "'created' => true"),
    'pay-without-save may inherit only its transaction-local created version; existing orders must still supply an expected version'
);
step3ContractAssert(
    str_contains($controller, 'skip_idempotency') && str_contains($mutation, 'SCOPE_TABLE_PAYMENT'),
    'payment must remain idempotent at controller/service boundary'
);

$clear = (string) file_get_contents($root . '/ajax/clear_table.php');
$delete = (string) file_get_contents($root . '/ajax/delete_order.php');
$tableStatus = (string) file_get_contents($root . '/ajax/update_table_status.php');
$move = (string) file_get_contents($root . '/ajax/move_table_order.php');
$mergeAjax = (string) file_get_contents($root . '/ajax/merge_table_orders.php');
step3ContractAssert(str_contains($clear, 'skip_idempotency') && str_contains($clear, 'mutation_version'), 'clear_table must pass version + skip nested idempotency');
step3ContractAssert(str_contains($delete, 'skip_idempotency') && str_contains($delete, 'mutation_version'), 'delete_order must pass version + skip nested idempotency');
step3ContractAssert(
    !str_contains($clear, 'recordOrderSnapshot(')
        && !str_contains($clear, 'recordTableSnapshot(')
        && !str_contains($delete, 'recordOrderSnapshot(')
        && !str_contains($delete, 'recordTableSnapshot(')
        && str_contains($tableStatus, 'recordRequiredOrderSnapshot')
        && str_contains($tableStatus, 'recordRequiredTableSnapshot'),
    'cancel wrappers must not duplicate service-owned outbox snapshots; table-only status writes must remain fail-closed'
);
step3ContractAssert(str_contains($move, 'mutation_version'), 'move endpoint must forward mutation_version');
step3ContractAssert(str_contains($mergeAjax, 'source_mutation_version'), 'merge endpoint must forward source/destination versions');

step3ContractAssert(
    str_contains($syncOutbox, 'recordRequiredOrderSnapshot')
        && str_contains($syncOutbox, 'recordRequiredTableSnapshot')
        && str_contains($mutation, 'recordRequiredOrderSnapshot')
        && str_contains($sideEffects, 'recordRequiredOrderSnapshot'),
    'order/table mutations must fail closed when the durable outbox cannot be recorded'
);
step3ContractAssert(
    str_contains($operationalOutbox, 'recordRequiredDrawerMovementSnapshot')
        && str_contains($drawer, 'recordRequiredDrawerMovementSnapshot'),
    'drawer writes must fail closed when their outbox record cannot be recorded'
);
step3ContractAssert(
    str_contains($customerSideEffects, 'MYSQLI_SERVER_STATUS_IN_TRANS')
        && str_contains($customerSideEffects, 'ownsTransaction($conn, $options)'),
    'customer side effects must never begin/commit over an active money transaction'
);
step3ContractAssert(
    str_contains($transactionHelper, 'posmain_tx_connection_in_transaction')
        && str_contains($transactionHelper, 'MYSQLI_SERVER_STATUS_IN_TRANS')
        && str_contains($transactionHelper, 'posmain_tx_connection_in_transaction($conn)'),
    'shared transaction helper must detect and preserve an already-active database transaction'
);
step3ContractAssert(
    str_contains($fulfillment, 'require_mutation_version')
        && str_contains($fulfillment, 'bumpAndGet')
        && str_contains($fulfillment, 'recordRequiredOrderSnapshot')
        && str_contains($fulfillment, 'posmain_tx_begin_if_needed'),
    'delivery transitions must use versioning, nested-safe transactions, and required atomic outbox capture'
);
step3ContractAssert(
    str_contains($deliveryStatusEndpoint, "'require_mutation_version' => true")
        && str_contains($deliveryStatusEndpoint, "'require_outbox' => true")
        && str_contains($deliveryBoard, 'mutation_version')
        && str_contains($deliveryBoard, 'idempotency_key')
        && str_contains($cashierDeliveryEndpoint, "'require_mutation_version' => true")
        && str_contains($cashierDeliveryEndpoint, "'require_outbox' => true"),
    'both delivery browser surfaces must provide concurrency identity and require atomic outbox capture'
);

echo "commercial-v1-step3-atomic-contract-ok\n";
