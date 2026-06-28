<?php

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    throw new RuntimeException('Unable to resolve repository root.');
}

$surfaces = [
    'api/pos/index.php' => [
        'pos_api_dispatch',
        'PosResponse::json',
    ],
    'includes/pos_api_dispatch.php' => [
        'function pos_api_dispatch',
        'orders.takeaway',
        'orders.delivery',
        'orders.payment',
        'orders.edit',
        'orders.table.free',
        'PosOrderAccessPolicy::requireRoutePermission',
    ],
    'do/doadd_invoice.php' => [
        "require_pos_authenticated();",
        "require_csrf('pos_browser');",
        'PosOrderMutationService',
        '$route_takeaway_service',
        '$route_delivery_service',
        'pos_order_api_router_guard_direct_access',
        'INSERT INTO ot_head',
    ],
    'ajax/save_order.php' => [
        'pos_api_dispatch',
        'pos_order_api_router_guard_direct_access',
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
    'ajax/cofe_create_order.php' => [
        'pos_api_dispatch',
        'integrations.cofe.orders',
    ],
    'do/doadd_invoice_waiter.php' => [
        'doadd_invoice.php',
    ],
    'classes/PosOrderService.php' => [
        'class PosOrderService',
        'resolveIncomingItems',
    ],
    'classes/Moova/MoovaNewOrderApplyService.php' => [
        'class MoovaNewOrderApplyService',
        'PosOrderService',
    ],
    'classes/Moova/MoovaChangeOrderApplyService.php' => [
        'class MoovaChangeOrderApplyService',
        'PosOrderService',
    ],
    'ajax/moova_confirm_order.php' => [
        'MoovaLocalIngestService',
        'PosOrderMutationService',
    ],
    'ajax/moova_change_order.php' => [
        'MoovaLocalIngestService',
        'PosOrderMutationService',
    ],
    'includes/pos_supermarket_content.php' => [
        'do/doadd_invoice.php',
    ],
    'pos_supermarket.php' => [
        'do/doadd_invoice.php',
    ],
];

foreach ($surfaces as $relativePath => $snippets) {
    $path = $root . '/' . $relativePath;
    $source = file_get_contents($path);
    orderCreationSurfaceAssert(is_string($source), 'unable to read ' . $relativePath);
    foreach ($snippets as $snippet) {
        orderCreationSurfaceAssert(
            strpos($source, $snippet) !== false,
            $relativePath . ' missing expected snippet: ' . $snippet
        );
    }
}

$cofeSource = file_get_contents($root . '/ajax/cofe_create_order.php');
$dispatchSource = file_get_contents($root . '/includes/pos_api_dispatch.php');
orderCreationSurfaceAssert(
    strpos($cofeSource, 'require_pos_authenticated') === false,
    'cofe_create_order should remain documented as lacking POS session auth'
);
orderCreationSurfaceAssert(
    strpos($dispatchSource, 'PosIntegrationAuth::requireCofeSignature') !== false,
    'pos_api_dispatch should enforce integration signature boundary for cofe'
);

$invoiceSource = file_get_contents($root . '/do/doadd_invoice.php');
orderCreationSurfaceAssert(
    strpos($invoiceSource, 'idempotency') === false,
    'doadd_invoice should remain documented as lacking server idempotency enforcement'
);

$waiterSource = file_get_contents($root . '/do/doadd_invoice_waiter.php');
orderCreationSurfaceAssert(
    strpos($waiterSource, 'require_csrf') === false,
    'doadd_invoice_waiter should remain documented as lacking CSRF'
);

echo "order-creation-write-surface-contract-ok\n";

function orderCreationSurfaceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
