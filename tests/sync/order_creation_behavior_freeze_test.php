<?php

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    throw new RuntimeException('Unable to resolve repository root.');
}

$saveOrder = file_get_contents($root . '/ajax/save_order.php');
$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');
orderCreationFreezeAssert(is_string($saveOrder), 'unable to read ajax/save_order.php');
orderCreationFreezeAssert(
    strpos($saveOrder, 'pos_api_dispatch') !== false,
    'save_order should delegate to pos_api_dispatch'
);
orderCreationFreezeAssert(
    strpos($controller, 'function saveTable') !== false,
    'PosOrderController should own table save behavior'
);
$sideEffects = file_get_contents($root . '/classes/Pos/Service/OrderMutationSideEffectsService.php');
orderCreationFreezeAssert(
    strpos($sideEffects, "\$eventType = \$isUpdate ? 'order.updated' : 'order.saved'") !== false,
    'table save side effects should preserve order.saved vs order.updated outbox semantics'
);
orderCreationFreezeAssert(
    strpos($sideEffects, "'active_order_id' => \$orderStatus === 'completed' ? null : \$orderId") !== false,
    'table save side effects should preserve table.updated active_order_id semantics'
);

$mutationSource = file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php');
orderCreationFreezeAssert(
    strpos($mutationSource, 'error_log(\'POS inventory invoice bridge shadow failed:') !== false,
    'inventory bridge should remain log-and-continue in shadow until SideEffectPolicy live mode'
);
orderCreationFreezeAssert(
    strpos($mutationSource, 'error_log(\'Order event recording skipped:') !== false,
    'order events should remain best-effort until SideEffectPolicy live mode'
);

$pricingValidator = file_get_contents($root . '/classes/Pos/Validation/OrderInputValidator.php');
orderCreationFreezeAssert(
    strpos($pricingValidator, "TableInputValidator::decimal(\$item['price']") !== false,
    'validator currently accepts submitted line prices'
);

$userFallbackEndpoints = [
    'includes/pos_api_dispatch.php' => 'PosRequest',
    'classes/Pos/Service/PosOrderMutationService.php' => 'posmain_resolve_pos_user_id',
    'includes/pos_user_context.php' => 'return $userId > 0 ? $userId : 1',
];

foreach ($userFallbackEndpoints as $path => $needle) {
    $source = file_get_contents($root . '/' . $path);
    orderCreationFreezeAssert(is_string($source), 'unable to read ' . $path);
    orderCreationFreezeAssert(strpos($source, $needle) !== false, $path . ' should document user-id fallback debt: ' . $needle);
}

$invoiceSource = file_get_contents($root . '/do/doadd_invoice.php');
orderCreationFreezeAssert(
    strpos($invoiceSource, 'idempotency') === false,
    'main cashier form should remain documented as lacking server idempotency'
);

$cofeSource = file_get_contents($root . '/ajax/cofe_create_order.php');
$dispatchSource = file_get_contents($root . '/includes/pos_api_dispatch.php');
orderCreationFreezeAssert(
    strpos($cofeSource, 'require_pos_authenticated') === false,
    'cofe endpoint should remain documented as lacking POS session auth'
);
orderCreationFreezeAssert(
    strpos($dispatchSource, 'PosIntegrationAuth::requireCofeSignature') !== false,
    'dispatch should require integration signature for cofe route'
);

$waiterSource = file_get_contents($root . '/do/doadd_invoice_waiter.php');
orderCreationFreezeAssert(
    strpos($waiterSource, 'doadd_invoice.php') !== false,
    'waiter handler should delegate to canonical cashier handler'
);

echo "order-creation-behavior-freeze-ok\n";

function orderCreationFreezeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
