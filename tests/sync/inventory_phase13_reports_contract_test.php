<?php

$root = dirname(__DIR__, 2);
$page = inventoryPhase13Source($root . '/inventory_reports.php');
$dashboardPage = inventoryPhase13Source($root . '/inventory_dashboard.php');
$service = inventoryPhase13Source($root . '/classes/Inventory/InventoryReportsService.php');
$reports = inventoryPhase13Source($root . '/reports.php');
$sidebar = inventoryPhase13Source($root . '/includes/sidebar.php');

inventoryPhase13Assert(strpos($dashboardPage, 'InventoryReportsService') !== false, 'inventory dashboard page should load shared report service');
inventoryPhase13Assert(strpos($dashboardPage, 'require_login()') !== false, 'inventory dashboard page should require login');
inventoryPhase13Assert(strpos($dashboardPage, 'posmain_inventory_dashboard_can_view') !== false, 'inventory dashboard page should enforce report permission');
inventoryPhase13Assert(strpos($dashboardPage, "auth_guard_has_permission('reports.view'") !== false, 'inventory dashboard page should allow report viewers');
inventoryPhase13Assert(strpos($dashboardPage, 'dashboardDetails') !== false, 'inventory dashboard page should load dashboard detail sections');
foreach (['لوحة المخزون', 'يحتاج انتباه', 'اقتراحات الشراء', 'آخر حركات المخزون', 'تأثير توفر القائمة', 'inventory_purchasing.php', 'inventory_counts.php', 'inventory_adjustments.php'] as $needle) {
    inventoryPhase13Assert(strpos($dashboardPage, $needle) !== false, 'inventory dashboard page should include: ' . $needle);
}
inventoryPhase13Assert(strpos($page, 'InventoryReportsService') !== false, 'inventory reports page should load shared report service');
inventoryPhase13Assert(strpos($page, 'require_login()') !== false, 'inventory reports page should require login');
inventoryPhase13Assert(strpos($page, 'posmain_inventory_reports_can_view') !== false, 'inventory reports page should enforce report permission');
inventoryPhase13Assert(strpos($page, "auth_guard_has_permission('reports.view'") !== false, 'inventory reports page should allow report viewers');
inventoryPhase13Assert(strpos($page, "auth_guard_has_permission('inventory.edit'") !== false, 'inventory reports page should allow inventory operators');
inventoryPhase13Assert(strpos($page, 'posmain_inventory_reports_can_view_cost') !== false, 'inventory reports page should separate cost access');
inventoryPhase13Assert(strpos($page, 'أعمدة التكلفة مخفية') !== false, 'inventory reports page should disclose hidden costs');
inventoryPhase13Assert(strpos($page, 'text/csv') !== false && strpos($page, 'posmain_csv_safe_row') !== false, 'inventory reports should export safe CSV');
inventoryPhase13Assert(strpos($page, 'drilldown_url') !== false && strpos($page, 'inventory_report_cell') !== false, 'inventory reports should render drilldown links');

foreach ([
    'inventory_levels',
    'movement_history',
    'low_stock',
    'replenishment_suggestions',
    'purchase_history',
    'supplier_purchase_summary',
    'transfer_history',
    'count_variance',
    'waste_adjustment',
    'production_variance',
    'recipe_consumption',
    'menu_availability',
    'inventory_valuation',
    'cogs_reconciliation',
] as $reportKey) {
    inventoryPhase13Assert(strpos($page, $reportKey) !== false, 'inventory report page missing selector: ' . $reportKey);
    inventoryPhase13Assert(strpos($service, $reportKey) !== false, 'inventory report service missing dispatch key: ' . $reportKey);
}

foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    inventoryPhase13Assert(strpos($page, $writeNeedle) === false, 'inventory report page must remain read-only: ' . $writeNeedle);
    inventoryPhase13Assert(strpos($dashboardPage, $writeNeedle) === false, 'inventory dashboard page must remain read-only: ' . $writeNeedle);
    inventoryPhase13Assert(strpos($service, $writeNeedle) === false, 'inventory report service must remain read-only: ' . $writeNeedle);
}

inventoryPhase13Assert(strpos($page, '<select name="store_id"') !== false && strpos($page, 'كل المخازن') !== false, 'inventory reports should use named store selector instead of raw store id input');
inventoryPhase13Assert(strpos($page, '<select name="supplier_account_id"') !== false && strpos($page, 'كل الموردين') !== false, 'inventory reports should use named supplier selector for purchase reports');
inventoryPhase13Assert(strpos($page, '<select name="pos_branch"') !== false && strpos($page, 'كل الفروع') !== false, 'inventory reports should use named branch selector instead of raw branch id input');
inventoryPhase13Assert(strpos($page, '<select name="item_id"') !== false && strpos($page, 'كل الأصناف') !== false, 'inventory reports should use named item selector instead of raw item id input');
inventoryPhase13Assert(strpos($page, 'name="q"') !== false && strpos($page, 'بحث في المخزون') !== false && strpos($page, 'اسم الصنف أو الباركود') !== false, 'inventory reports should expose free-text inventory search');
inventoryPhase13Assert(strpos($page, 'data-inventory-report-live-search') !== false && strpos($page, 'fetch(formUrl(true)') !== false && strpos($page, 'data-inventory-report-body') !== false, 'inventory report search should auto-update the table after typing without a full page refresh');
inventoryPhase13Assert(strpos($page, '<select name="category_id"') !== false && strpos($page, 'كل الفئات') !== false, 'inventory reports should use named category selector instead of raw category id input');
inventoryPhase13Assert(strpos($page, '<select name="movement_type"') !== false && strpos($page, 'كل الحركات') !== false && strpos($page, 'posmain_inventory_report_movement_type_label') !== false, 'inventory reports should use Arabic movement type labels instead of raw movement text input');
foreach (['purchase_return' => 'مرتجع شراء', 'recipe_consumption' => 'استهلاك وصفة', 'inventory_status' => 'posmain_inventory_report_inventory_status_label', 'availability_status' => 'posmain_inventory_report_availability_status_label', 'reconciliation_status' => 'posmain_inventory_report_reconciliation_status_label', 'item_type' => 'posmain_inventory_report_item_type_label', 'source_type' => 'posmain_inventory_report_source_type_label', 'order_type' => 'posmain_inventory_report_order_type_label', 'channel' => 'posmain_inventory_report_channel_label'] as $needle => $labelNeedle) {
    inventoryPhase13Assert(strpos($page, (string) $needle) !== false && strpos($page, (string) $labelNeedle) !== false, 'inventory reports should label technical tokens: ' . $needle);
}
inventoryPhase13Assert(strpos($page, 'فرع #') === false && strpos($page, 'مخزن #') === false && strpos($page, 'مورد #') === false && strpos($page, 'فرع غير مسمى') !== false && strpos($page, "فرع ' .") === false && strpos($page, "CONCAT('فرع '") === false, 'inventory reports should not expose raw id fallback labels in normal filters');
inventoryPhase13Assert(strpos($dashboardPage, "('#' .") === false && strpos($dashboardPage, 'صنف غير مسمى') !== false && strpos($dashboardPage, 'مخزن غير مسمى') !== false && strpos($dashboardPage, 'فرع غير مسمى') !== false && strpos($dashboardPage, "فرع ' .") === false && strpos($dashboardPage, "CONCAT('فرع '") === false, 'inventory dashboard should avoid raw id fallback labels in normal tables');
inventoryPhase13Assert(strpos($page, 'posmain_inventory_report_branches') !== false && strpos($page, 'posmain_inventory_report_stores') !== false && strpos($page, 'posmain_inventory_report_suppliers') !== false && strpos($page, 'posmain_inventory_report_items') !== false && strpos($page, 'posmain_inventory_report_categories') !== false, 'inventory reports should load branch, store, supplier, item, and category selector options');
inventoryPhase13Assert(strpos($dashboardPage, '<select name="pos_branch"') !== false && strpos($dashboardPage, 'كل الفروع') !== false, 'inventory dashboard should use named branch selector instead of raw branch id input');
inventoryPhase13Assert(strpos($dashboardPage, '<select name="item_id"') !== false && strpos($dashboardPage, 'كل الأصناف') !== false, 'inventory dashboard should use named item selector instead of raw item id input');
inventoryPhase13Assert(strpos($dashboardPage, 'posmain_inventory_dashboard_branches') !== false && strpos($dashboardPage, 'posmain_inventory_dashboard_items') !== false, 'inventory dashboard should load branch and item selector options');
inventoryPhase13Assert(strpos($dashboardPage, 'posmain_inventory_dashboard_movement_label') !== false && strpos($dashboardPage, 'مرتجع شراء') !== false, 'inventory dashboard should label recent movement types in Arabic');
inventoryPhase13Assert(strpos($dashboardPage, 'posmain_inventory_dashboard_order_type_label') !== false && strpos($dashboardPage, 'posmain_inventory_dashboard_channel_label') !== false, 'inventory dashboard should label menu availability order/channel tokens in Arabic');
foreach (['movement-type filter is an operator-facing selector', 'dashboard recent-movement/menu-availability rows translate technical movement', 'CSV export keeps the stored raw values', 'generic unnamed-branch/item/store labels'] as $needle) {
    $docs = inventoryPhase13Source($root . '/docs/inventory/phase13_reports_contracts.md');
    inventoryPhase13Assert(strpos($docs, $needle) !== false, 'phase13 docs should describe report label UX: ' . $needle);
}

inventoryPhase13Assert(strpos($page, "if (\$canViewCost)") !== false && strpos($page, "'inventory_valuation'") !== false, 'inventory valuation report should be cost-gated in the report selector');

foreach (['inventoryLevels', 'movementHistory', 'lowStock', 'replenishmentSuggestions', 'purchaseHistory', 'supplierPurchaseSummary', 'transferHistory', 'countVariance', 'wasteAdjustment', 'productionVariance', 'recipeConsumption', 'menuAvailability', 'inventoryValuation', 'cogsReconciliation', 'dashboard', 'dashboardDetails', 'menuAvailabilityImpact'] as $method) {
    inventoryPhase13Assert(strpos($service, 'function ' . $method) !== false, 'inventory report service should expose: ' . $method);
}
foreach (['moving_average_cost', 'stock_value', 'total_cost', 'variance_cost', 'estimated_purchase_cost', 'journal_debit_total', 'current_stock_value', 'last_unit_cost', 'last_total_cost'] as $costColumn) {
    inventoryPhase13Assert(strpos($page, "unset(\$selected[\$costColumn])") !== false || strpos($page, "'" . $costColumn . "'") !== false, 'inventory reports should define cost column gate for: ' . $costColumn);
}

inventoryPhase13Assert(strpos($reports, 'inventory_dashboard.php') !== false && strpos($reports, 'لوحة المخزون') !== false, 'reports index should link Arabic inventory dashboard');
inventoryPhase13Assert(strpos($reports, 'inventory_reports.php') !== false && strpos($reports, 'تقارير المخزون') !== false, 'reports index should link Arabic inventory reports');
inventoryPhase13Assert(strpos($sidebar, 'inventory_dashboard.php') !== false && strpos($sidebar, 'لوحة المخزون') !== false, 'sidebar should link Arabic inventory dashboard');
inventoryPhase13Assert(strpos($sidebar, 'inventory_reports.php') !== false && strpos($sidebar, 'تقارير المخزون') !== false, 'sidebar should link Arabic inventory reports');

echo "inventory-phase13-reports-contract-ok\n";

function inventoryPhase13Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase13Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
