<?php

$root = realpath(__DIR__ . '/../..');

$schema = file_get_contents($root . '/classes/Sync/SchemaManager.php');
foreach ([
    'pos_customers',
    'pos_customer_phones',
    'pos_customer_addresses',
    'pos_customer_id',
] as $needle) {
    posCustomerCrmAssert(strpos($schema, $needle) !== false, 'SchemaManager should define customer CRM: ' . $needle);
}

foreach ([
    'classes/Pos/Service/PosCustomerPhoneService.php',
    'classes/Pos/Service/PosCustomerService.php',
    'classes/Pos/Service/PosCustomerOrderLinkService.php',
    'classes/Pos/Service/PosCustomerAnalyticsService.php',
] as $servicePath) {
    posCustomerCrmAssert(is_file($root . '/' . $servicePath), 'missing service file: ' . $servicePath);
}

$bootstrap = file_get_contents($root . '/includes/pos_customer_bootstrap.php');
posCustomerCrmAssert(strpos($bootstrap, 'applyPosCustomerSchema') !== false, 'bootstrap should use scoped CRM schema apply');
posCustomerCrmAssert(strpos($bootstrap, '->apply($conn)') === false, 'bootstrap should not call full SyncSchemaManager::apply');
posCustomerCrmAssert(strpos($bootstrap, 'pendingPosCustomerStatements') !== false, 'runtime customer readiness should inspect only customer schema');
posCustomerCrmAssert(strpos($bootstrap, 'SyncSchemaReadinessGuard') === false, 'unrelated pending migrations must not block customer saving');

$customerSave = file_get_contents($root . '/ajax/pos_customer_save.php');
posCustomerCrmAssert(strpos($customerSave, 'POS_CUSTOMER_SCHEMA_MIGRATIONS_PENDING') !== false, 'customer save should expose a scoped actionable schema error');
$deliveryUi = file_get_contents($root . '/js/pos_delivery.js');
posCustomerCrmAssert(strpos($deliveryUi, 'JSON.parse(xhr.responseText)') !== false, 'delivery customer save should parse JSON failures instead of displaying raw response bodies');

$migration = file_get_contents($root . '/update/013_pos_customers.sql');
posCustomerCrmAssert(strpos($migration, 'CREATE TABLE IF NOT EXISTS pos_customers') !== false, 'migration should create pos_customers');

$defaults = file_get_contents($root . '/includes/pos_default_accounts.php');
posCustomerCrmAssert(strpos($defaults, '$clientId = (int) ($defaults[\'client_id\'] ?? 0);') !== false, 'invoice accounts should prefer default client over posted acc2');

$mutation = file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php');
posCustomerCrmAssert(strpos($mutation, 'PosCustomerOrderSideEffects') !== false, 'mutation service should use CRM side effects');
posCustomerCrmAssert(strpos($mutation, 'PosCustomerOrderLinkService') !== false, 'mutation service should use order link service');

$content = file_get_contents($root . '/includes/pos_content.php');
posCustomerCrmAssert(strpos($content, 'posCustomerStrip') !== false, 'cashier UI should include customer strip');
posCustomerCrmAssert(strpos($content, 'pos_customer.js') !== false, 'cashier UI should load pos_customer.js');
posCustomerCrmAssert(strpos($content, 'type="hidden" name="acc2_id"') !== false, 'acc2 dropdown should be hidden default client input');

$barcodeJs = file_get_contents($root . '/js/pos_barcode.js');
posCustomerCrmAssert(strpos($barcodeJs, 'get_pos_options.php?type=customers') === false, 'barcode POS should not lazy-load accounting customers');

$invoice = file_get_contents($root . '/do/doadd_invoice.php');
posCustomerCrmAssert(strpos($invoice, 'pos_customer_id') !== false, 'invoice route should pass pos_customer_id to service paths');

$merge = file_get_contents($root . '/do/pos_customer_merge.php');
posCustomerCrmAssert(strpos($merge, 'mergeCustomers') !== false, 'merge endpoint should call mergeCustomers');

$migrationService = file_get_contents($root . '/classes/Pos/Service/PosCustomerMigrationService.php');
posCustomerCrmAssert(strpos($migrationService, 'backfillOrderFulfillmentCustomers') !== false, 'migration service should backfill fulfillment customers');

$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');
posCustomerCrmAssert(strpos($controller, 'pos_customer_id') !== false, 'table save controller should forward pos_customer_id');

$dto = file_get_contents($root . '/classes/Pos/DTO/OrderCreateRequest.php');
posCustomerCrmAssert(strpos($dto, 'posCustomerId') !== false, 'order DTO should include posCustomerId');

echo "pos-customer-crm-contract-ok\n";

function posCustomerCrmAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
