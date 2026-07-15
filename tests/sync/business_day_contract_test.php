<?php

require_once __DIR__ . '/../../classes/Pos/Service/RestaurantReportContractService.php';

$service = file_get_contents(__DIR__ . '/../../classes/Pos/Service/BusinessDayService.php');
businessDayContractAssert(strpos($service, 'function currentBusinessDayForBranch') !== false, 'currentBusinessDayForBranch required');
businessDayContractAssert(strpos($service, 'function windowBounds') !== false, 'windowBounds required');
businessDayContractAssert(strpos($service, 'function setCutoffHourForBranch') !== false, 'setCutoffHourForBranch required');
businessDayContractAssert(strpos($service, 'function timestampBusinessDayExpression') !== false, 'timestampBusinessDayExpression required');

$helper = file_get_contents(__DIR__ . '/../../includes/business_day.php');
businessDayContractAssert(strpos($helper, 'function posmain_business_day_context') !== false, 'page helper required');
businessDayContractAssert(strpos($helper, 'function posmain_current_business_day') !== false, 'current day helper required');
businessDayContractAssert(strpos($helper, 'function posmain_business_day_resolve_scope') !== false, 'scope resolver required');

$drawer = file_get_contents(__DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php');
businessDayContractAssert(strpos($drawer, 'BusinessDayService') !== false, 'drawer open should use BusinessDayService');
businessDayContractAssert(strpos($drawer, 'business_day') !== false, 'drawer open should stamp business_day');

$schema = file_get_contents(__DIR__ . '/../../classes/Sync/SchemaManager.php');
businessDayContractAssert(strpos($schema, 'business_day DATE') !== false, 'schema should define drawer_sessions.business_day');
businessDayContractAssert(strpos($schema, 'idx_drawer_sessions_business_day') !== false, 'schema should index business_day');

$posBarcode = file_get_contents(__DIR__ . '/../../pos_barcode.php');
businessDayContractAssert(strpos($posBarcode, "strtotime('-4 hours')") === false, 'pos_barcode must not use -4h hack');
businessDayContractAssert(strpos($posBarcode, 'posmain_current_business_day') !== false, 'pos_barcode should use business day helper');

$posSupermarket = file_get_contents(__DIR__ . '/../../pos_supermarket.php');
businessDayContractAssert(strpos($posSupermarket, "strtotime('-4 hours')") === false, 'pos_supermarket must not use -4h hack');
businessDayContractAssert(strpos($posSupermarket, 'posmain_current_business_day') !== false, 'pos_supermarket should use business day helper');

$posClothes = file_get_contents(__DIR__ . '/../../pos_clothes.php');
businessDayContractAssert(strpos($posClothes, "strtotime('-4 hours')") === false, 'pos_clothes must not use -4h hack');

$right0 = file_get_contents(__DIR__ . '/../../elements/pos/right0.php');
businessDayContractAssert(strpos($right0, "strtotime('-4 hours')") === false, 'right0 must not use -4h hack');

$shiftReport = file_get_contents(__DIR__ . '/../../classes/ShiftReport.php');
businessDayContractAssert(strpos($shiftReport, 'BusinessDayService') !== false, 'ShiftReport should use BusinessDayService');
businessDayContractAssert(strpos($shiftReport, 'resolveBusinessDay') !== false, 'ShiftReport should resolve business day');

$recon = file_get_contents(__DIR__ . '/../../classes/Pos/Service/ShiftDrawerReconciliationService.php');
businessDayContractAssert(strpos($recon, 'BusinessDayService') !== false, 'reconciliation should use BusinessDayService');
businessDayContractAssert(strpos($recon, 'windowBounds') !== false, 'reconciliation should use business-day window');

$cashFlow = file_get_contents(__DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php');
businessDayContractAssert(strpos($cashFlow, 'windowBounds') !== false, 'cash flow payments should use windowBounds');
businessDayContractAssert(strpos($cashFlow, 'COALESCE(ds.business_day') !== false, 'cash flow sessions should prefer persisted business_day');

$cashFlowPage = file_get_contents(__DIR__ . '/../../cash_flow_report.php');
businessDayContractAssert(strpos($cashFlowPage, 'posmain_business_day_context') !== false, 'cash flow page should use business day context');
businessDayContractAssert(strpos($cashFlowPage, "['current_business_day']") !== false, 'cash flow presets should use business day');

$closeShift = file_get_contents(__DIR__ . '/../../do_close_shift_z.php');
businessDayContractAssert(strpos($closeShift, 'BusinessDayService') !== false, 'close shift should use BusinessDayService');
businessDayContractAssert(strpos($closeShift, 'sessionBusinessDay') !== false, 'close shift should prefer session business day');

$shiftSessionService = file_get_contents(__DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php');
businessDayContractAssert(strpos($shiftSessionService, 'function buildShiftReportContext') !== false, 'shift report context helper required');
businessDayContractAssert(strpos($shiftSessionService, 'posmain_current_business_day') !== false, 'shift report context should default to business day');

$zReport = file_get_contents(__DIR__ . '/../../z_report.php');
businessDayContractAssert(strpos($zReport, 'buildShiftReportContext') !== false, 'z_report should default to business day via shared context');

$preview = file_get_contents(__DIR__ . '/../../do/get_shift_preview.php');
businessDayContractAssert(strpos($preview, 'buildShiftReportContext') !== false, 'shift preview should default to business day via shared context');

$ops = file_get_contents(__DIR__ . '/../../operations_summary.php');
businessDayContractAssert(strpos($ops, 'posmain_business_day_context') !== false, 'operations_summary should use business day');

$salesDay = file_get_contents(__DIR__ . '/../../sales-by-day.php');
businessDayContractAssert(strpos($salesDay, 'posmain_business_day_context') !== false, 'sales-by-day should use business day');

$salesHour = file_get_contents(__DIR__ . '/../../sales-by-hour.php');
businessDayContractAssert(strpos($salesHour, 'DATE_SUB(crtime') !== false, 'sales-by-hour should filter by business-day crtime');

$cutoffEndpoint = file_get_contents(__DIR__ . '/../../do/do_set_business_day_cutoff.php');
businessDayContractAssert(strpos($cutoffEndpoint, 'setCutoffHourForBranch') !== false, 'cutoff endpoint should persist setting');

$routeManifest = file_get_contents(__DIR__ . '/../../config/rbac_route_manifest.php');
businessDayContractAssert(strpos($routeManifest, 'do/do_set_business_day_cutoff.php') !== false, 'cutoff route should be in rbac manifest');

$closedSessions = file_get_contents(__DIR__ . '/../../closed_sessions.php');
businessDayContractAssert(strpos($cashFlowPage, 'businessDayCutoffForm') !== false, 'cash workspace settings should expose cutoff UI');
businessDayContractAssert(
    strpos($closedSessions, "'tab' => 'shifts'") !== false
        && strpos($closedSessions, "'status' => 'needs_review'") !== false
        && strpos($closedSessions, "'scope' => 'backlog'") !== false,
    'closed_sessions should redirect to the unified backlog'
);

$contracts = new RestaurantReportContractService();
$daily = $contracts->get('daily_sales');
$payment = $contracts->get('payment_method_breakdown');
$shiftZ = $contracts->get('shift_z');
businessDayContractAssert(
    in_array(
        'date_from/date_to filter ot_head.pro_date as branch business day (stamped via business_day_cutoff_hour)',
        $daily['invariants'],
        true
    ),
    'daily_sales contract should require business-day filtering'
);
businessDayContractAssert(
    count(array_filter($payment['invariants'], static function ($item) {
        return strpos((string) $item, 'business_day_cutoff_hour') !== false;
    })) > 0,
    'payment breakdown contract should mention business day'
);
businessDayContractAssert(
    in_array(
        'drawer session business_day = DATE(DATE_SUB(opened_at, INTERVAL branch business_day_cutoff_hour HOUR))',
        $shiftZ['invariants'],
        true
    ),
    'shift_z contract should keep business-day invariant'
);

echo "business-day-contract-ok\n";

function businessDayContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "business-day-contract-fail: {$message}\n");
        exit(1);
    }
}
