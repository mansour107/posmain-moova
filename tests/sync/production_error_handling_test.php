<?php

$connectSource = productionErrorRead('includes/connect.php');
productionErrorAssertContains('function posmain_exception_payload(', $connectSource, 'connect should define a central safe payload helper');
productionErrorAssertContains('function posmain_browser_exception_response(', $connectSource, 'connect should define a safe browser response helper');
productionErrorAssertContains('function posmain_handle_uncaught_exception(', $connectSource, 'connect should define an uncaught exception handler');
productionErrorAssertContains('production_guard_is_production()', $connectSource, 'safe messages should respect production mode');
productionErrorAssertContains("\$payload['error_reference'] = \$reference", $connectSource, 'payloads should include a support error reference');
productionErrorAssertContains('set_exception_handler(function ($e) {', $connectSource, 'connect should install the central exception handler');
productionErrorAssertContains("posmain_handle_uncaught_exception(\$e, 'db_connect')", $connectSource, 'DB connect failures should use the central handler in production');
productionErrorAssertNotContains('die("Connection failed: " . $e->getMessage())', $connectSource, 'DB connect failures should not raw-die with exception text');

$validatorSource = productionErrorRead('classes/Pos/Validation/TableInputValidator.php');
productionErrorAssertContains('posmain_exception_payload(', $validatorSource, 'table endpoint failures should use central safe payloads');

$dispatchRoutes = [
    'ajax/save_order.php' => 'orders.table',
    'ajax/process_table_payment.php' => 'orders.payment',
    'ajax/process_split_payment.php' => 'orders.split-payment',
];

foreach ($dispatchRoutes as $path => $route) {
    $source = productionErrorRead($path);
    productionErrorAssertContains('pos_api_dispatch_exception_payload(', $source, $path . ' should use central safe dispatch error payloads');
    productionErrorAssertContains("'" . $route . "'", $source, $path . ' should tag errors with the canonical API route');
}

$apiRouterSource = productionErrorRead('api/pos/index.php');
productionErrorAssertContains('pos_api_dispatch_exception_payload(', $apiRouterSource, 'canonical POS API should use the safe dispatch error payload');
productionErrorAssertContains('PosResponse::json(', $apiRouterSource, 'canonical POS API should emit the normalized safe payload');

$jsonRoutes = [
    'ajax/delete_order.php' => 'delete_order',
    'ajax/update_table_status.php' => 'update_table_status',
    'ajax/search_items.php' => 'search_items',
    'ajax/load_items_lazy.php' => 'load_items_lazy',
    'ajax/moova_save_integration.php' => 'moova_integration_save',
    'ajax/moova_disconnect_integration.php' => 'moova_integration_disconnect',
];

foreach ($jsonRoutes as $path => $context) {
    $source = productionErrorRead($path);
    productionErrorAssertContains('posmain_exception_payload(', $source, $path . ' should use central safe JSON error payloads');
    productionErrorAssertContains("'" . $context . "'", $source, $path . ' should tag errors with a logging context');
}

$invoiceSource = productionErrorRead('do/doadd_invoice.php');
productionErrorAssertContains('posmain_browser_exception_response(', $invoiceSource, 'invoice form errors should use central safe browser response');
productionErrorAssertContains("'invoice_takeaway_route'", $invoiceSource, 'takeaway route errors should have a context');
productionErrorAssertContains("'invoice_transaction'", $invoiceSource, 'invoice transaction errors should have a context');
productionErrorAssertNotContains("die('حدث خطأ أثناء معالجة الفاتورة: ' . \$e->getMessage());", $invoiceSource, 'invoice errors should not expose raw exception text');

$adminRoutes = [
    'do/doadd_user.php' => 'تعذر رفع صورة المستخدم',
    'do/doedit_user.php' => 'حدث خطأ أثناء تحديث المستخدم',
    'do/doedit_settings.php' => 'حدث خطأ أثناء تحديث الإعدادات',
];

foreach ($adminRoutes as $path => $safeMessage) {
    $source = productionErrorRead($path);
    productionErrorAssertContains('posmain_safe_exception_message(', $source, $path . ' should use safe browser/admin error messages');
    productionErrorAssertContains($safeMessage, $source, $path . ' should include an Arabic-friendly fallback message');
}

echo "production-error-handling-ok\n";

function productionErrorRead(string $path): string
{
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function productionErrorAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function productionErrorAssertNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message);
    }
}
