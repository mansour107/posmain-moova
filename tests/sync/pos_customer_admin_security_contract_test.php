<?php

$root = realpath(__DIR__ . '/../..');

$adminEndpoints = [
    'pos_customers.php' => "require_admin_or_permission('customers.manage'",
    'ajax/pos_customers_admin_list.php' => "require_admin_or_permission('customers.manage'",
    'ajax/pos_customers_admin_detail.php' => "require_admin_or_permission('customers.manage'",
    'do/pos_customer_merge.php' => "require_admin_or_permission('customers.manage'",
    'do/pos_customer_delete.php' => "require_admin_or_permission('customers.manage'",
];

foreach ($adminEndpoints as $path => $snippet) {
    $source = file_get_contents($root . '/' . $path);
    posCustomerAdminAssert(is_string($source), 'unable to read ' . $path);
    posCustomerAdminAssert(strpos($source, $snippet) !== false, $path . ' should require customers.manage');
}

$merge = file_get_contents($root . '/do/pos_customer_merge.php');
posCustomerAdminAssert(strpos($merge, "require_csrf('customers_manage')") !== false, 'merge should require customers_manage CSRF');
posCustomerAdminAssert(strpos($merge, 'SecurityAuditLogger') !== false, 'merge should record security audit events');

$delete = file_get_contents($root . '/do/pos_customer_delete.php');
posCustomerAdminAssert(strpos($delete, "require_csrf('customers_manage')") !== false, 'delete should require customers_manage CSRF');

$deliveryJs = file_get_contents($root . '/js/pos_delivery.js');
posCustomerAdminAssert(strpos($deliveryJs, 'ajax/pos_customer_search.php') !== false, 'delivery should use CRM search API');
posCustomerAdminAssert(strpos($deliveryJs, 'ajax/pos_customer_save.php') !== false, 'delivery should use CRM save API');

$auth = file_get_contents($root . '/includes/auth_guard.php');
posCustomerAdminAssert(strpos($auth, "'customers.manage'") !== false, 'auth guard should define customers.manage');

echo "pos-customer-admin-security-contract-ok\n";

function posCustomerAdminAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
