<?php

$docPath = __DIR__ . '/../../docs/production/permission_matrix.md';
$doc = file_get_contents($docPath);
if (!is_string($doc) || $doc === '') {
    throw new RuntimeException('permission matrix document is missing or empty');
}

$requiredRoles = [
    'owner/admin',
    'manager',
    'cashier',
    'waiter',
    'kitchen',
    'accountant',
    'inventory manager',
    'branch operator',
    'support/readonly',
];

$requiredPermissions = [
    'pos.open',
    'pos.sell.takeaway',
    'pos.table.open',
    'pos.table.move',
    'pos.table.merge',
    'pos.payment.take',
    'pos.discount.apply',
    'pos.discount.manager_override',
    'pos.cancel.unpaid',
    'pos.void.paid',
    'pos.refund',
    'pos.split',
    'pos.shift.open',
    'pos.shift.close',
    'pos.cashdrawer.count',
    'menu.edit',
    'inventory.edit',
    'inventory.approve',
    'reports.view',
    'accounting.view',
    'users.manage',
    'roles.manage',
    'moova.manage',
    'moova.accept',
    'system.health.view',
    'system.tools.run',
];

foreach ($requiredRoles as $role) {
    phase3PermissionAssertContains($role, $doc, 'missing role ' . $role);
}

foreach ($requiredPermissions as $permission) {
    phase3PermissionAssertContains('`' . $permission . '`', $doc, 'missing permission ' . $permission);
}

foreach (['usr_pwrs', 'ajax/moova_confirm_order.php', 'ajax/moova_change_order.php', 'moova_pos_proxy.php', 'security_audit_log'] as $snippet) {
    phase3PermissionAssertContains($snippet, $doc, 'missing security policy snippet ' . $snippet);
}

require_once __DIR__ . '/../../includes/auth_guard.php';
$map = auth_guard_permission_map();
foreach ($requiredPermissions as $permission) {
    if (!array_key_exists($permission, $map)) {
        throw new RuntimeException('auth_guard_permission_map missing ' . $permission);
    }
}

echo "phase3-permission-matrix-ok\n";

function phase3PermissionAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}
