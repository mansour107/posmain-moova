<?php

require_once __DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php';

$cashFlowPeriod = file_get_contents(__DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php');
cashFlowPeriodContractAssert(strpos($cashFlowPeriod, 'function summary') !== false, 'CashFlowPeriodService should expose summary');
cashFlowPeriodContractAssert(strpos($cashFlowPeriod, 'function sessions') !== false, 'CashFlowPeriodService should expose sessions');
cashFlowPeriodContractAssert(strpos($cashFlowPeriod, 'function movements') !== false, 'CashFlowPeriodService should expose movements');
cashFlowPeriodContractAssert(strpos($cashFlowPeriod, 'function paymentBreakdown') !== false, 'CashFlowPeriodService should expose paymentBreakdown');

$ajax = file_get_contents(__DIR__ . '/../../ajax/cash_flow_report.php');
cashFlowPeriodContractAssert(strpos($ajax, "reports.cash_flow") !== false, 'cash flow ajax should require reports.cash_flow');

$manifest = file_get_contents(__DIR__ . '/../../config/rbac_page_manifest.php');
cashFlowPeriodContractAssert(strpos($manifest, 'cash_flow_report.php') !== false, 'cash flow page should be in rbac manifest');
cashFlowPeriodContractAssert(strpos($manifest, 'reports.cash_flow') !== false, 'cash flow page should use reports.cash_flow');

require_once __DIR__ . '/../../classes/Pos/Service/RestaurantReportContractService.php';
$contract = (new RestaurantReportContractService())->get('shift_z');
foreach ($contract['filters'] as $filter) {
    cashFlowPeriodContractAssert(strpos($cashFlowPeriod, $filter) !== false, "CashFlowPeriodService should support filter {$filter}");
}

$drawer = file_get_contents(__DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php');
cashFlowPeriodContractAssert(strpos($drawer, 'recordUnassignedMovement') !== false, 'DrawerSessionService should record unassigned movements');
cashFlowPeriodContractAssert(strpos($drawer, 'linkMovementToVoucher') !== false, 'DrawerSessionService should link movements to vouchers by id');
cashFlowPeriodContractAssert(strpos($cashFlowPeriod, 'close_variance_rollup') !== false, 'CashFlowPeriodService should expose close variance rollup');
cashFlowPeriodContractAssert(strpos($cashFlowPeriod, 'BusinessDayService') !== false, 'CashFlowPeriodService should use BusinessDayService');

$payment = file_get_contents(__DIR__ . '/../../classes/Pos/Service/PaymentService.php');
cashFlowPeriodContractAssert(strpos($payment, 'recordCashMovementWithFallback') !== false, 'PaymentService should keep cash movement helper');
cashFlowPeriodContractAssert(strpos($payment, 'DRAWER_SESSION_REQUIRED') !== false, 'PaymentService must fail closed without drawer');
cashFlowPeriodContractAssert(strpos($payment, 'recordUnassignedMovement') === false, 'PaymentService must not fall back to unassigned movements');

$settle = file_get_contents(__DIR__ . '/../../do/settle_credit.php');
cashFlowPeriodContractAssert(strpos($settle, 'findOpenSession') === false, 'settle_credit should not gate on findOpenSession');

echo "cash-flow-period-contract-ok\n";

function cashFlowPeriodContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "cash-flow-period-contract-fail: {$message}\n");
        exit(1);
    }
}
