<?php

require_once __DIR__ . '/../../classes/Pos/Service/RestaurantReportContractService.php';

$service = new RestaurantReportContractService();
$contracts = $service->all();

$expected = [
    'daily_sales',
    'payment_method_breakdown',
    'order_channel_split',
    'open_tables',
    'shift_z',
    'item_performance',
    'category_performance',
    'low_stock',
    'void_cancel_audit',
];

phase4ReportAssert($service->ids() === $expected, 'report ids should be stable and ordered');

foreach ($expected as $id) {
    phase4ReportAssert(isset($contracts[$id]), "{$id} contract missing");
    phase4ReportAssert(!empty($contracts[$id]['title']), "{$id} title missing");
    phase4ReportAssert(!empty($contracts[$id]['sources']), "{$id} sources missing");
    phase4ReportAssert(!empty($contracts[$id]['filters']), "{$id} filters missing");
    phase4ReportAssert(!empty($contracts[$id]['totals']), "{$id} totals missing");
    phase4ReportAssert(!empty($contracts[$id]['invariants']), "{$id} invariants missing");
}

phase4ReportAssert(
    phase4ReportContainsAll($contracts['daily_sales']['sources'], ['ot_head', 'fat_details']),
    'daily_sales should reconcile ot_head and fat_details'
);
phase4ReportAssert(
    in_array('paid_amount + remaining_amount = net_sales for non-void active/completed orders', $contracts['daily_sales']['invariants'], true),
    'daily_sales payment invariant missing'
);
phase4ReportAssert(
    phase4ReportContainsAll($contracts['payment_method_breakdown']['sources'], ['order_payments', 'payment_methods', 'drawer_movements']),
    'payment breakdown should include payment methods and drawer movements'
);
phase4ReportAssert(
    in_array('cash_total must reconcile with drawer_movements.sale_cash minus refund_cash for the same drawer scope', $contracts['payment_method_breakdown']['invariants'], true),
    'payment drawer reconciliation invariant missing'
);
phase4ReportAssert(
    in_array('open tables are derived from active unpaid/partial ot_head rows, not table_case alone', $contracts['open_tables']['invariants'], true),
    'open table active truth invariant missing'
);
phase4ReportAssert(
    in_array('pre_close_expected_cash = opening_cash + sale_cash - refund_cash + paid_in - paid_out - safe_drop', $contracts['shift_z']['invariants'], true),
    in_array('expected_cash = opening_cash + sale_cash - refund_cash + paid_in - paid_out - safe_drop + closing_adjustment', $contracts['shift_z']['invariants'], true),
    'shift_z expected cash invariant missing'
);
phase4ReportAssert(
    in_array('qty_sold uses decimal quantities from fat_details and must not cast to int', $contracts['item_performance']['invariants'], true),
    'item decimal quantity invariant missing'
);
phase4ReportAssert(
    in_array('on_hand_qty uses decimal stock movement sums and must not cast to int', $contracts['low_stock']['invariants'], true),
    'low stock decimal quantity invariant missing'
);
phase4ReportAssert(
    phase4ReportContainsAll($contracts['void_cancel_audit']['sources'], ['order_events', 'manager_approvals', 'security_audit_log']),
    'void/cancel audit should include events, approvals, and audit log'
);

$sources = $service->sourceTables();
foreach ([
    'ot_head',
    'fat_details',
    'order_payments',
    'payment_methods',
    'drawer_sessions',
    'drawer_movements',
    'order_events',
    'manager_approvals',
    'inventory_movements',
] as $source) {
    phase4ReportAssert(in_array($source, $sources, true), "{$source} should be represented in sourceTables");
}

phase4ReportAssert(
    count($service->invariantsFor('shift_z')) >= 3,
    'invariantsFor should return shift_z invariants'
);

phase4ReportExpectException(function () use ($service) {
    $service->get('live_sql_report');
}, 'REPORT_CONTRACT_NOT_FOUND');

echo "phase4-restaurant-report-contract-service-ok\n";

function phase4ReportContainsAll(array $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if (!in_array($needle, $haystack, true)) {
            return false;
        }
    }

    return true;
}

function phase4ReportExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4ReportAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4ReportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
