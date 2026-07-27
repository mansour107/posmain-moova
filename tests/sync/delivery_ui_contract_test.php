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
deliveryUiAssert(($routeManifest['ajax/pos_delivery_queue.php']['permission'] ?? '') === 'pos.open', 'cashier delivery queue must be available to POS operators');
deliveryUiAssert(($routeManifest['ajax/pos_delivery_queue.php']['csrf'] ?? '') === 'pos_browser', 'cashier delivery transitions must use POS browser CSRF');
deliveryUiAssert(($routeManifest['ajax/delivery_pending_count.php']['permission'] ?? '') === 'pos.open', 'active delivery reminder must be visible to cashiers');
deliveryUiAssert(($routeManifest['do/doedit_delivery_zone.php']['csrf'] ?? '') === 'delivery_zones_write', 'delivery zones route must use the same CSRF scope as its form');
deliveryUiAssert(in_array('delivery.assign', $routeManifest['ajax/delivery_zones_list.php']['any_of'] ?? [], true), 'cashiers who can assign delivery must be able to read authoritative zones');

deliveryUiAssert(strpos($boardPage, '<h1>طلبات التوصيل</h1>') !== false, 'delivery board should have an action-indicative Arabic title');
deliveryUiAssert(strpos($boardPage, 'deliveryBoardSearch') !== false && strpos($boardPage, 'deliveryBoardFilters') !== false, 'delivery board should expose search and status filters');
deliveryUiAssert(strpos($boardScript, 'const pageSize = 12') !== false, 'delivery board should paginate dense queues');
deliveryUiAssert(strpos($boardScript, 'delivery-status-filter') !== false, 'delivery board should render a single responsive filtered queue');
deliveryUiAssert(strpos($deliveryCss, 'repeat(auto-fill, minmax(min(100%, 310px), 1fr))') !== false, 'delivery order cards should fit the viewport without six fixed lanes');
deliveryUiAssert(strpos($deliveryCss, 'repeat(6') === false, 'delivery layout must not recreate the overflowing six-column board');
$posCss = file_get_contents($root . '/dist/css/pos_barcode.css');
$posContent = file_get_contents($root . '/includes/pos_content.php');
$cashierQueueEndpoint = file_get_contents($root . '/ajax/pos_delivery_queue.php');
deliveryUiAssert(strpos($posContent, 'pos-delivery-queue__summary') !== false && strpos($posContent, 'posDeliveryOrderList') !== false, 'cashier should use one compact queue instead of large status boxes');
deliveryUiAssert(strpos($posCss, '--bs-offcanvas-width: min(440px, 100vw)') !== false, 'cashier delivery queue must stay compact on desktop');
deliveryUiAssert(strpos($posCss, '.pos-delivery-order-list') !== false && strpos($posCss, '.pos-delivery-order__action') !== false, 'cashier queue needs dense rows with direct actions');
deliveryUiAssert(strpos($cashierQueueEndpoint, "require_permission('pos.open'") !== false, 'cashier queue endpoint must require POS access');
deliveryUiAssert(strpos($cashierQueueEndpoint, "require_csrf('pos_browser'") !== false, 'cashier delivery writes must validate POS CSRF');
deliveryUiAssert(strpos($cashierQueueEndpoint, 'posmain_cashier_delivery_order_in_scope') !== false, 'cashier actions must be constrained to the active tenant and branch');
deliveryUiAssert(strpos($cashierQueueEndpoint, 'preflightSyncIdentity') !== false && strpos($cashierQueueEndpoint, 'begin_transaction') !== false, 'cashier dispatch must preflight sync identity and update assignment atomically');

deliveryUiAssert(strpos($zonesPage, 'css/delivery-operations.css') !== false, 'delivery zones should use the premium delivery design system');
deliveryUiAssert(strpos($zonesPage, 'delivery-zone-grid') !== false, 'delivery zones should use responsive premium cards');
deliveryUiAssert(strpos(file_get_contents($root . '/delivery_management.php'), 'بنسبة أيام فترة التسوية') !== false, 'compensation UI should explain weekly/monthly base-pay proration');
$deliveryManagementPage = file_get_contents($root . '/delivery_management.php');
$deliveryManagementScript = file_get_contents($root . '/js/delivery_management.js');
deliveryUiAssert(strpos($deliveryManagementPage, 'name="base_amount"') !== false && strpos($deliveryManagementPage, 'disabled') !== false, 'base amount should start disabled when no base period is selected');
deliveryUiAssert(strpos($deliveryManagementScript, 'syncBaseAmountField') !== false && strpos($deliveryManagementScript, "amount.value = '0'") !== false, 'base amount should be cleared when the base period is none');
deliveryUiAssert(strpos($deliveryManagementPage, 'مستحقات العامل') !== false, 'worker table should label the worker dues directly');
deliveryUiAssert(strpos($deliveryManagementScript, 'function workerDues(worker)') !== false, 'worker rows should render worker dues');
deliveryUiAssert(strpos($deliveryManagementScript, 'workerDues(w)') !== false && strpos($deliveryManagementScript, 'workerBalance(w)') === false, 'worker rows must show dues instead of an accounting net balance');
deliveryUiAssert(strpos($deliveryManagementScript, "money(workerEarnings)+' ج.م") !== false, 'worker dues should include open delivery earnings and tips');
deliveryUiAssert(strpos($deliveryManagementScript, "disabled=false;render()") === false, 'settlement preview must preserve the selected worker before finalization');
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
