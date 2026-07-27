<?php

$root = dirname(__DIR__, 2);
$report = file_get_contents($root . '/cash_flow_report.php');
$operations = file_get_contents($root . '/classes/Pos/Service/OperationsReportService.php');
$readModel = file_get_contents($root . '/classes/Financial/RefundReversalReadService.php');

refundAdminContractAssert($report !== false, 'cash-flow report must be readable');
refundAdminContractAssert($operations !== false, 'operations report service must be readable');
refundAdminContractAssert($readModel !== false, 'refund read model must be readable');

foreach ([
    'data-testid="refund-reversal-drilldown"',
    '$operationsReports->refunds(',
    'original_order_id',
    'drawer_session_id',
    'manager_approval_id',
    'cumulative_refunded_amount',
    'remaining_refundable_amount',
    'reversal_status',
    'pending_external_amount',
] as $needle) {
    refundAdminContractAssert(str_contains($report, $needle), "admin drill-down missing {$needle}");
}
refundAdminContractAssert(str_contains($report, 'مسترد بالكامل'), 'admin drill-down must use clear full-refund wording');
refundAdminContractAssert(str_contains($report, 'مسترد جزئياً'), 'admin drill-down must use clear partial-refund wording');
refundAdminContractAssert(str_contains($report, 'print/receipt.php?id='), 'admin drill-down must link to the active preserved sale receipt');
refundAdminContractAssert(!str_contains($report, 'check_orders.php?id='), 'admin drill-down must not link to the retired order checker');

refundAdminContractAssert(str_contains($operations, 'RefundReversalReadService'), 'operations reports must use the canonical refund read model');
refundAdminContractAssert(str_contains($operations, 'stateForOrder'), 'order and refund rows must expose cumulative reversal state');
refundAdminContractAssert(str_contains($readModel, "cn.status = 'posted'"), 'only posted credit notes may reduce revenue');
refundAdminContractAssert(str_contains($readModel, 'COALESCE(cn.business_day, DATE(cn.created_at))'), 'refunds must be attributed to their own business day');

echo "refund-admin-drilldown-contract-ok\n";

function refundAdminContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "refund-admin-drilldown-contract-fail: {$message}\n");
        exit(1);
    }
}
