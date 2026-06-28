<?php

$root = realpath(__DIR__ . '/../..');
$invoice = file_get_contents($root . '/do/doadd_invoice.php');
$cashierRoute = file_get_contents($root . '/includes/pos_cashier_table_service_route.php');

legacyCashierAssert(strpos($invoice, 'posmain_should_route_cashier_table_save') !== false, 'doadd_invoice should gate cashier table save routing');
legacyCashierAssert(strpos($invoice, 'posmain_route_cashier_table_save') !== false, 'doadd_invoice should call cashier table save route');
legacyCashierAssert(strpos($cashierRoute, 'PosOrderController') !== false, 'cashier table route should use PosOrderController');
legacyCashierAssert(strpos($cashierRoute, 'posmain_order_router') === false, 'cashier table route should not depend on router flags');

echo "legacy-cashier-migration-contract-ok\n";

function legacyCashierAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
