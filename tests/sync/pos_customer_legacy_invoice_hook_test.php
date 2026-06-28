<?php

$hookPath = __DIR__ . '/../../includes/pos_customer_order_hook.php';
$invoicePath = __DIR__ . '/../../do/doadd_invoice.php';
$paymentPath = __DIR__ . '/../../ajax/process_table_payment.php';
$routePath = __DIR__ . '/../../includes/pos_cashier_table_service_route.php';

foreach ([$hookPath, $invoicePath, $paymentPath, $routePath] as $path) {
    if (!is_readable($path)) {
        throw new RuntimeException('Unable to read ' . $path);
    }
}

$hook = file_get_contents($hookPath);
$invoice = file_get_contents($invoicePath);
$payment = file_get_contents($paymentPath);
$route = file_get_contents($routePath);

posCustomerLegacyHookAssert(strpos($hook, 'PosCustomerOrderSideEffects') !== false, 'hook should use PosCustomerOrderSideEffects');
posCustomerLegacyHookAssert(strpos($hook, 'afterOrderSaved') !== false, 'hook should call afterOrderSaved');
posCustomerLegacyHookAssert(strpos($invoice, 'posmain_apply_crm_order_side_effects') !== false, 'doadd_invoice should call CRM hook before commit');
posCustomerLegacyHookAssert(strpos($payment, 'pos_customer_id') !== false, 'process_table_payment should pass pos_customer_id');
posCustomerLegacyHookAssert(strpos($route, 'pos_customer_id') !== false, 'cashier table route should pass pos_customer_id');

echo "pos-customer-legacy-invoice-hook-ok\n";

function posCustomerLegacyHookAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
