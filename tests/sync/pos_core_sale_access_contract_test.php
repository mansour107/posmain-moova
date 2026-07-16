<?php

require_once __DIR__ . '/../../includes/auth_guard.php';
require_once __DIR__ . '/../../classes/Pos/Security/PosOrderAccessPolicy.php';
require_once __DIR__ . '/../../classes/Security/RolePermissionSyncService.php';

function posCoreSaleAccessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$coreSaleRoutes = [
    'orders.takeaway',
    'orders.delivery',
    'orders.payment',
    'orders.split-payment',
    'orders.edit',
];

foreach ($coreSaleRoutes as $route) {
    posCoreSaleAccessAssert(
        PosOrderAccessPolicy::permissionForRoute($route) === 'pos.open',
        $route . ' must be included automatically with POS access'
    );
}

posCoreSaleAccessAssert(
    PosOrderAccessPolicy::permissionForRoute('orders.table') === 'pos.table.open',
    'opening table orders must retain its table-specific permission'
);
posCoreSaleAccessAssert(
    PosOrderAccessPolicy::permissionForRoute('orders.refund') === 'pos.refund',
    'refunds must remain separately protected'
);
posCoreSaleAccessAssert(
    PosOrderAccessPolicy::permissionForRoute('integrations.cofe.orders') === 'moova.accept',
    'integration orders must retain their integration permission'
);

$knownPermissions = auth_guard_permission_map();
$policyRoutes = [
    'orders.table',
    'orders.takeaway',
    'orders.delivery',
    'orders.payment',
    'orders.split-payment',
    'orders.refund',
    'orders.edit',
    'orders.table.free',
    'integrations.cofe.orders',
];

foreach ($policyRoutes as $route) {
    $permission = PosOrderAccessPolicy::permissionForRoute($route);
    posCoreSaleAccessAssert(
        array_key_exists($permission, $knownPermissions),
        $route . ' must not reference an unknown permission: ' . $permission
    );
}

$manifest = require __DIR__ . '/../../config/rbac_route_manifest.php';
foreach (['do/doadd_invoice.php', 'do/doedit_invoice.php', 'ajax/process_table_payment.php', 'ajax/process_split_payment.php'] as $endpoint) {
    posCoreSaleAccessAssert(
        ($manifest[$endpoint]['permission'] ?? null) === 'pos.open',
        $endpoint . ' compatibility guard must match the core POS access policy'
    );
}

$teamHubPosPermissions = RolePermissionSyncService::permissionGroups()['POS'] ?? [];
foreach (['pos.sell.takeaway', 'pos.payment.take', 'pos.split'] as $redundantPermission) {
    posCoreSaleAccessAssert(
        !in_array($redundantPermission, $teamHubPosPermissions, true),
        $redundantPermission . ' must not be offered as a separate Team Hub switch'
    );
}

foreach (['print/receipt.php', 'print/receipt_waiter.php', 'print/preparation.php'] as $printPath) {
    $anyOf = $manifest[$printPath]['any_of'] ?? [];
    posCoreSaleAccessAssert(
        is_array($anyOf) && in_array('pos.open', $anyOf, true),
        $printPath . ' must allow every POS operator'
    );
    posCoreSaleAccessAssert(
        in_array('pos.reprint', $anyOf, true),
        $printPath . ' must preserve standalone reprint access'
    );
}

fwrite(STDOUT, "OK pos_core_sale_access_contract_test\n");
exit(0);
