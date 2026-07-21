<?php

$root = dirname(__DIR__, 2);
$pageManifest = require $root . '/config/rbac_page_manifest.php';
$routeManifest = require $root . '/config/rbac_route_manifest.php';
$boardPage = file_get_contents($root . '/delivery_board.php');
$boardScript = file_get_contents($root . '/js/delivery_board.js');
$deliveryCss = file_get_contents($root . '/css/delivery-operations.css');
$zonesPage = file_get_contents($root . '/delivery_zones.php');

deliveryUiAssert(isset($pageManifest['delivery_management.php']), 'delivery management page must be classified before session bootstrap');
deliveryUiAssert(($pageManifest['delivery_management.php']['any_of'] ?? []) !== [], 'delivery management classification should preserve its multi-permission access model');
foreach (['ajax/delivery_assign.php', 'ajax/delivery_plans.php', 'ajax/delivery_settlements.php', 'ajax/delivery_workers.php'] as $route) {
    deliveryUiAssert(isset($routeManifest[$route]), "missing RBAC route classification for {$route}");
}
deliveryUiAssert(($routeManifest['do/doedit_delivery_zone.php']['csrf'] ?? '') === 'delivery_zones_write', 'delivery zones route must use the same CSRF scope as its form');
deliveryUiAssert(in_array('delivery.assign', $routeManifest['ajax/delivery_zones_list.php']['any_of'] ?? [], true), 'cashiers who can assign delivery must be able to read authoritative zones');

deliveryUiAssert(strpos($boardPage, '<h1>طلبات التوصيل</h1>') !== false, 'delivery board should have an action-indicative Arabic title');
deliveryUiAssert(strpos($boardPage, 'deliveryBoardSearch') !== false && strpos($boardPage, 'deliveryBoardFilters') !== false, 'delivery board should expose search and status filters');
deliveryUiAssert(strpos($boardScript, 'const pageSize = 12') !== false, 'delivery board should paginate dense queues');
deliveryUiAssert(strpos($boardScript, 'delivery-status-filter') !== false, 'delivery board should render a single responsive filtered queue');
deliveryUiAssert(strpos($deliveryCss, 'repeat(auto-fill, minmax(min(100%, 310px), 1fr))') !== false, 'delivery order cards should fit the viewport without six fixed lanes');
deliveryUiAssert(strpos($deliveryCss, 'repeat(6') === false, 'delivery layout must not recreate the overflowing six-column board');

deliveryUiAssert(strpos($zonesPage, 'css/delivery-operations.css') !== false, 'delivery zones should use the premium delivery design system');
deliveryUiAssert(strpos($zonesPage, 'delivery-zone-grid') !== false, 'delivery zones should use responsive premium cards');
deliveryUiAssert(strpos(file_get_contents($root . '/delivery_management.php'), 'بنسبة أيام فترة التسوية') !== false, 'compensation UI should explain weekly/monthly base-pay proration');
foreach (['card-primary', 'card-outline', 'table-striped'] as $legacyClass) {
    deliveryUiAssert(strpos($zonesPage, $legacyClass) === false, "delivery zones should not inherit legacy Kody class {$legacyClass}");
}

echo "delivery-ui-contract-ok\n";

function deliveryUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "delivery_ui_contract_test FAILED: {$message}\n");
        exit(1);
    }
}
