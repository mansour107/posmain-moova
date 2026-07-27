<?php

$root = dirname(__DIR__, 2);
$pages = [
    'sales-by-day.php', 'sales-by-week.php', 'sales-by-month.php', 'sales-by-hour.php',
    'sales-by-group.php', 'items_summery.php', 'top_products_report.php',
];
foreach ($pages as $page) {
    $source = file_get_contents($root . '/' . $page);
    legacyRefundContractAssert($source !== false, "{$page} must be readable");
    legacyRefundContractAssert(str_contains($source, 'LegacySalesReportService'), "{$page} must use the refund-aware legacy adapter");
}
$operations = file_get_contents($root . '/operations_summary.php');
$closedItems = file_get_contents($root . '/print/closed_session_items.php');
legacyRefundContractAssert($operations !== false && str_contains($operations, 'stateForOrder'), 'legacy operation rows must show partial/full reversal state');
legacyRefundContractAssert(str_contains($operations, 'originalSaleEvidencePredicate'), 'legacy operation rows must retain historically hidden reversed originals');
legacyRefundContractAssert(str_contains($operations, 'صافي المبيعات بعد الاسترداد'), 'legacy operation totals must clearly identify the net amount after refunds');
legacyRefundContractAssert(str_contains($operations, 'refundedProfitForOrder'), 'legacy operation profit must be reduced by posted refund lines');
legacyRefundContractAssert(str_contains($operations, 'صافي الربح بعد الاسترداد'), 'legacy operation totals must clearly identify refund-adjusted profit');
legacyRefundContractAssert(str_contains($operations, 'مسترد بالكامل'), 'legacy operation rows must use clear full-refund wording');
legacyRefundContractAssert(str_contains($operations, 'طلب كاشير محفوظ — الاسترداد من شاشة الكاشير'), 'legacy operation rows must not offer destructive POS deletion');
legacyRefundContractAssert($closedItems !== false && str_contains($closedItems, 'cn.drawer_session_id'), 'closed shift items must attribute refunded lines to the refunding shift');
$service = file_get_contents($root . '/classes/Financial/LegacySalesReportService.php');
legacyRefundContractAssert($service !== false, 'legacy adapter must be readable');
legacyRefundContractAssert(str_contains($service, "cn.status = 'posted'"), 'legacy reports must use posted credit notes');
legacyRefundContractAssert(str_contains($service, 'business_day'), 'legacy reports must attribute refunds by refund business day');
legacyRefundContractAssert(str_contains($service, 'credit_note_lines'), 'legacy item reports must reverse credited quantities and values');
legacyRefundContractAssert(str_contains($service, 'refund_profit'), 'legacy product profitability must reverse refunded profit');

echo "refund-legacy-reports-contract-ok\n";

function legacyRefundContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "refund-legacy-reports-contract-fail: {$message}\n");
        exit(1);
    }
}
