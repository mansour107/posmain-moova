<?php

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    throw new RuntimeException('Unable to resolve repository root.');
}

$dispatch = file_get_contents($root . '/includes/pos_api_dispatch.php');
$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');
$apiPos = file_get_contents($root . '/api/pos/index.php');
$integrationAuth = file_get_contents($root . '/classes/Pos/Security/PosIntegrationAuth.php');
$guard = file_get_contents($root . '/includes/pos_order_api_router_guard.php');

posApiRouterAssert(strpos($dispatch, 'function pos_api_dispatch') !== false, 'dispatch should define pos_api_dispatch');
posApiRouterAssert(strpos($dispatch, 'integrations.cofe.orders') !== false, 'dispatch should register cofe integration route');
posApiRouterAssert(strpos($dispatch, 'orders.takeaway') !== false, 'dispatch should register takeaway route');
posApiRouterAssert(strpos($dispatch, 'orders.payment') !== false, 'dispatch should register payment route');
posApiRouterAssert(strpos($dispatch, 'PosIntegrationAuth::requireCofeSignature') !== false, 'dispatch should verify integration signatures');
posApiRouterAssert(strpos($dispatch, 'require_csrf') !== false, 'dispatch should require browser CSRF');

posApiRouterAssert(strpos($controller, 'function createTakeaway') !== false, 'controller should implement createTakeaway');
posApiRouterAssert(strpos($controller, 'function createDelivery') !== false, 'controller should implement createDelivery');
posApiRouterAssert(strpos($controller, 'function payTable') !== false, 'controller should implement payTable');
posApiRouterAssert(strpos($controller, 'function splitPayment') !== false, 'controller should implement splitPayment');
posApiRouterAssert(strpos($controller, 'function createCofeTableOrder') !== false, 'controller should implement createCofeTableOrder');
posApiRouterAssert(strpos($controller, 'function updateOrder') !== false, 'controller should implement updateOrder');

posApiRouterAssert(strpos($apiPos, 'pos_api_dispatch') !== false, 'api/pos should call dispatch');
posApiRouterAssert(strpos($apiPos, 'NOT_IMPLEMENTED') === false, 'api/pos should not keep 501 stubs');

posApiRouterAssert(strpos($integrationAuth, 'INTEGRATION_DISABLED') !== false, 'integration auth should fail closed without secret');
posApiRouterAssert(strpos($integrationAuth, 'POSMAIN_ALLOW_OPEN_INTEGRATIONS') !== false, 'integration auth should allow explicit dev bypass');

posApiRouterAssert(strpos($guard, 'POSMAIN_ORDER_API_ROUTER_ONLY') !== false, 'router guard should use rollout flag');

$shims = [
    'ajax/save_order.php' => ['pos_api_dispatch', 'orders.table', 'pos_order_api_router_guard_direct_access'],
    'ajax/process_table_payment.php' => ['pos_api_dispatch', 'orders.payment'],
    'ajax/process_split_payment.php' => ['pos_api_dispatch', 'orders.split-payment'],
    'ajax/cofe_create_order.php' => ['pos_api_dispatch', 'integrations.cofe.orders'],
    'do/doadd_invoice.php' => ['pos_order_api_router_guard_direct_access'],
];

foreach ($shims as $relativePath => $snippets) {
    $source = file_get_contents($root . '/' . $relativePath);
    foreach ($snippets as $snippet) {
        posApiRouterAssert(strpos($source, $snippet) !== false, $relativePath . ' should contain ' . $snippet);
    }
}

echo "pos-api-router-contract-ok\n";

function posApiRouterAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
