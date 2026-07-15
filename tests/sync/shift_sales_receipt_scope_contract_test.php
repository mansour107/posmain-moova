<?php

/**
 * Close-shift preview and "طباعة مبيعاتي" must share the same drawer shift window.
 * Day-only ot_head queries create false sales that do not appear in the close modal.
 */

function shiftSalesReceiptScopeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$shiftReceipt = file_get_contents(__DIR__ . '/../../print/shift_sales_receipt.php');
$dailyReceipt = file_get_contents(__DIR__ . '/../../print/daily_sales_receipt.php');
$preview = file_get_contents(__DIR__ . '/../../do/get_shift_preview.php');
$zReport = file_get_contents(__DIR__ . '/../../z_report.php');
$shiftReportPage = file_get_contents(__DIR__ . '/../../shift_sales_report.php');
$shiftService = file_get_contents(__DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php');
$shiftReport = file_get_contents(__DIR__ . '/../../classes/ShiftReport.php');

foreach ([$shiftReceipt, $dailyReceipt, $preview, $zReport, $shiftReportPage, $shiftService, $shiftReport] as $source) {
    shiftSalesReceiptScopeAssert($source !== false, 'source readable');
}

shiftSalesReceiptScopeAssert(
    strpos($shiftService, 'function buildShiftReportContext') !== false,
    'ShiftSessionService should expose shared report context builder'
);

foreach ([
    'shift_sales_receipt' => $shiftReceipt,
    'daily_sales_receipt' => $dailyReceipt,
    'get_shift_preview' => $preview,
    'z_report' => $zReport,
    'shift_sales_report' => $shiftReportPage,
] as $label => $source) {
    shiftSalesReceiptScopeAssert(
        strpos($source, 'buildShiftReportContext') !== false,
        "{$label} should use shared buildShiftReportContext"
    );
    shiftSalesReceiptScopeAssert(
        strpos($source, 'new ShiftReport') !== false,
        "{$label} should use ShiftReport"
    );
    shiftSalesReceiptScopeAssert(
        strpos($source, "DATE(pro_date) = '\$today'") === false
            && strpos($source, 'DATE(pro_date) = "$today"') === false,
        "{$label} must not use raw day-only ot_head sales queries"
    );
}

shiftSalesReceiptScopeAssert(
    strpos($shiftReport, "appendShiftWindow(\$query, \$params, 'oh.crtime')") === false,
    'getItemsBreakdown must not pass oh.crtime as table alias'
);
shiftSalesReceiptScopeAssert(
    strpos($shiftReport, "appendShiftWindow(\$query, \$params, 'oh')") !== false,
    'getItemsBreakdown should append shift window with oh alias'
);

echo "shift-sales-receipt-scope-contract-ok\n";
