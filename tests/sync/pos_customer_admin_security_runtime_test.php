<?php

$root = realpath(__DIR__ . '/../..');

$retired = [
    'do/search_customer.php',
    'do/save_customer.php',
    'do/update_customer.php',
];

foreach ($retired as $path) {
    posCustomerAdminRuntimeAssert(!is_file($root . '/' . $path), $path . ' should be removed');
}

$merge = file_get_contents($root . '/do/pos_customer_merge.php');
posCustomerAdminRuntimeAssert(strpos($merge, 'SecurityAuditLogger') !== false, 'merge should audit admin action');
posCustomerAdminRuntimeAssert(strpos($merge, "require_admin_or_permission('customers.manage'") !== false, 'merge should require customers.manage');

$delete = file_get_contents($root . '/do/pos_customer_delete.php');
posCustomerAdminRuntimeAssert(strpos($delete, 'SecurityAuditLogger') !== false, 'delete should audit admin action');
posCustomerAdminRuntimeAssert(strpos($delete, "require_admin_or_permission('customers.manage'") !== false, 'delete should require customers.manage');

$save = file_get_contents($root . '/ajax/pos_customer_save.php');
posCustomerAdminRuntimeAssert(strpos($save, 'PHONE_ALREADY_USED') !== false, 'save endpoint should map PHONE_ALREADY_USED to Arabic');

echo "pos-customer-admin-security-runtime-ok\n";

function posCustomerAdminRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
