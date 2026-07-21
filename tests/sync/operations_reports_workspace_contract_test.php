<?php

$root = dirname(__DIR__, 2);

function operationsWorkspaceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "operations-workspace-contract-fail: {$message}\n");
        exit(1);
    }
}

$workspace = (string) file_get_contents($root . '/cash_flow_report.php');
$workspaceService = (string) file_get_contents($root . '/classes/Pos/Service/CashShiftWorkspaceService.php');
$reportService = (string) file_get_contents($root . '/classes/Pos/Service/OperationsReportService.php');
$cashService = (string) file_get_contents($root . '/classes/Pos/Service/CashFlowPeriodService.php');
$dashboardService = (string) file_get_contents($root . '/classes/Dashboard/DashboardOverviewService.php');
$legacyEntry = (string) file_get_contents($root . '/sales-reports.php');
$sidebar = (string) file_get_contents($root . '/includes/sidebar.php');
$reportsIndex = (string) file_get_contents($root . '/reports.php');
$router = (string) file_get_contents($root . '/classes/Security/PostLoginRouteService.php');
$css = (string) file_get_contents($root . '/css/premium-report-light.css');

foreach (['TAB_OVERVIEW', 'TAB_SHIFTS', 'TAB_ORDERS', 'TAB_PAYMENTS', 'TAB_ITEMS', 'TAB_ATTENTION'] as $constant) {
    operationsWorkspaceAssert(strpos($workspaceService, "public const {$constant}") !== false, "missing {$constant}");
    operationsWorkspaceAssert(strpos($workspace, "CashShiftWorkspaceService::{$constant}") !== false, "workspace does not consume {$constant}");
}

foreach (['إجمالي المبيعات', 'الخصومات', 'المرتجعات', 'صافي المبيعات', 'عدد الطلبات'] as $label) {
    operationsWorkspaceAssert(strpos($workspace, $label) !== false, "daily sales is missing {$label}");
}
foreach (['رصيد البداية', 'المبيعات النقدية', 'نقدية أخرى داخلة', 'النقدية الخارجة', 'الفرق'] as $label) {
    operationsWorkspaceAssert(strpos($workspace, $label) !== false, "shift grid is missing {$label}");
}
foreach (['سجل الطلبات', 'تفاصيل التحصيل', 'أداء الأصناف', 'متابعة الإدارة'] as $section) {
    operationsWorkspaceAssert(strpos($workspace, $section) !== false, "canonical section is missing {$section}");
}

operationsWorkspaceAssert(strpos($workspace, 'OperationsReportService.php') !== false, 'workspace must use the canonical read model');
operationsWorkspaceAssert(strpos($workspace, 'id="cashShiftWorkspace" dir="rtl" lang="ar"') !== false, 'Arabic workspace must declare RTL direction and language');
operationsWorkspaceAssert(strpos($css, 'direction: rtl !important') !== false, 'Arabic workspace and modals must retain RTL under the host theme');
operationsWorkspaceAssert(strpos($reportService, "cn.status = 'posted'") !== false, 'revenue refunds must use posted credit notes');
operationsWorkspaceAssert(strpos($reportService, "payment_status IN ('paid', 'refunded')") !== false, 'sales must use completed payment states');
operationsWorkspaceAssert(strpos($cashService, 'payment_refunds') !== false, 'tender refunds must use payment refund records');
operationsWorkspaceAssert(strpos($cashService, 'cash_reconciliation_diff') !== false, 'cash tender and drawer evidence must reconcile');
operationsWorkspaceAssert(strpos($workspaceService, 'financialSessionForBacklogRow') !== false, 'review backlog must retain ledger-backed shift amounts');
operationsWorkspaceAssert(strpos($cashService, '&& $this->drawerCoversPeriod($conn, $filters)') !== false, 'cash reconciliation must require period coverage');
operationsWorkspaceAssert(strpos($dashboardService, 'new OperationsReportService()') !== false, 'homepage must consume the same report read model');

operationsWorkspaceAssert(strpos($legacyEntry, 'Location: cash_flow_report.php?tab=overview') !== false, 'legacy sales report launcher must redirect');
operationsWorkspaceAssert(strpos($router, "'url' => 'cash_flow_report.php?tab=overview'") !== false, 'report-only login must land in canonical workspace');
operationsWorkspaceAssert(strpos($sidebar, 'href="sales-reports.php"') === false, 'sidebar must not expose the legacy report launcher');
operationsWorkspaceAssert(strpos($sidebar, 'href="items_summery.php"') === false, 'sidebar must not expose duplicate POS item reporting');
operationsWorkspaceAssert(strpos($reportsIndex, 'cash_flow_report.php?tab=overview') !== false, 'general report index must link the canonical workspace');

operationsWorkspaceAssert(strpos($css, '.operations-metrics') !== false, 'premium light metrics styles missing');
operationsWorkspaceAssert(strpos($css, '.operations-attention-grid') !== false, 'exception review styles missing');
operationsWorkspaceAssert(strpos($css, '.operations-focus-bar') !== false, 'focused report navigation styles missing');
operationsWorkspaceAssert(strpos($workspace, 'متابعة الإدارة') !== false, 'management review tab must use plain Arabic');
operationsWorkspaceAssert(strpos($workspaceService, 'FOCUS_TABS') !== false, 'focused report destinations must be normalized centrally');
operationsWorkspaceAssert(strpos($css, '@media (max-width: 768px)') !== false, 'responsive report styling missing');

echo "operations-reports-workspace-contract-ok\n";
