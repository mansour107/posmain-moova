<?php

$root = realpath(__DIR__ . '/../..');
$saveOrder = file_get_contents($root . '/ajax/save_order.php');

posOrderControllerAssert(strpos($saveOrder, 'pos_api_dispatch') !== false, 'save_order should delegate to pos_api_dispatch');
posOrderControllerAssert(strpos($saveOrder, 'PosOrderController') === false, 'save_order should not call controller directly');

$cashierRoute = file_get_contents($root . '/includes/pos_cashier_table_service_route.php');
posOrderControllerAssert(strpos($cashierRoute, 'PosOrderController') !== false, 'cashier table route should use controller');

$cofe = file_get_contents($root . '/ajax/cofe_create_order.php');
posOrderControllerAssert(strpos($cofe, 'pos_api_dispatch') !== false, 'cofe endpoint should delegate to dispatch');
posOrderControllerAssert(strpos($cofe, 'INSERT INTO ot_head') === false, 'cofe endpoint should not keep inline order SQL');

$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');
posOrderControllerAssert(strpos($controller, 'createTakeaway') !== false, 'controller should own takeaway create');
posOrderControllerAssert(strpos($controller, 'payTable') !== false, 'controller should own table payment');
posOrderControllerAssert(strpos($controller, 'pos_customer_id') !== false, 'table save path should support pos_customer_id');

echo "pos-order-controller-contract-ok\n";

function posOrderControllerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
