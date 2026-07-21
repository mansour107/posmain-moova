<?php

require_once __DIR__ . '/../../classes/Dashboard/DashboardOverviewService.php';

$service = new DashboardOverviewService();

dashboardUnitAssert(
    DashboardOverviewService::averageOrderValue(100.0, 0) === 0.0,
    'AOV is 0 when order count is 0'
);
dashboardUnitAssert(
    abs(DashboardOverviewService::averageOrderValue(100.0, 4) - 25.0) < 0.0001,
    'AOV divides sales by order count'
);

$kpis = $service->buildKpis([
    'available' => true,
    'today_count' => 2,
    'today_sum' => 50.0,
    'week_sum' => 200.0,
    'month_sum' => 500.0,
    'last_invoice' => 25.0,
]);
dashboardUnitAssert(count($kpis) === 4, 'four today KPIs');
dashboardUnitAssert($kpis[0]['key'] === 'today_sales' && $kpis[0]['formatted'] === '50.00', 'today sales formatted');
dashboardUnitAssert($kpis[1]['key'] === 'today_orders' && $kpis[1]['formatted'] === '2', 'today orders formatted');
dashboardUnitAssert($kpis[2]['key'] === 'today_aov' && $kpis[2]['formatted'] === '25.00', 'today AOV formatted');

$unavailable = $service->buildKpis([
    'available' => false,
    'today_count' => 0,
    'today_sum' => 0.0,
    'week_sum' => 0.0,
    'month_sum' => 0.0,
    'last_invoice' => null,
]);
dashboardUnitAssert(
    $unavailable[0]['formatted'] === DashboardOverviewService::UNAVAILABLE_LABEL,
    'unavailable KPI shows غير متاح'
);

$strip = $service->buildSalesStrip([
    'available' => true,
    'last_invoice' => 12.5,
    'week_sum' => 80.0,
    'month_sum' => 300.0,
]);
dashboardUnitAssert($strip['last_invoice_formatted'] === '12.50', 'sales strip last invoice');
dashboardUnitAssert($strip['reports_url'] === 'cash_flow_report.php?tab=overview', 'sales strip reports url');

$actionsAdmin = $service->filterQuickActions([
    'is_admin' => true,
]);
dashboardUnitAssert(count($actionsAdmin) === 5, 'admin sees five quick actions');

$actionsRestricted = $service->filterQuickActions([
    'menu.edit' => true,
    'reports.view' => false,
    'accounting.view' => false,
]);
dashboardUnitAssert(count($actionsRestricted) === 1, 'restricted role sees only permitted actions');
dashboardUnitAssert($actionsRestricted[0]['url'] === 'add_item.php', 'restricted action is add_item');

$actionsNone = $service->filterQuickActions([]);
dashboardUnitAssert($actionsNone === [], 'no permissions yields no quick actions');

dashboardUnitAssert(
    strpos(DashboardOverviewService::SALES_TYPES_SQL, 'pro_tybe = 9') !== false,
    'dashboard is scoped to POS orders'
);
dashboardUnitAssert(
    strpos(DashboardOverviewService::SALES_TYPES_SQL, 'payment_status') !== false,
    'sales filter requires a completed payment state'
);

echo "dashboard-overview-service-unit-ok\n";

function dashboardUnitAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "dashboard-overview-service-unit-fail: {$message}\n");
        exit(1);
    }
}
