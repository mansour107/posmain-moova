<?php

require_once dirname(__DIR__, 2) . '/includes/csv_export.php';

recipeCsvExportGuardAssert(posmain_csv_safe_cell('=cmd|calc') === "'=cmd|calc", 'equals formula should be exported as text');
recipeCsvExportGuardAssert(posmain_csv_safe_cell('+SUM(A1:A2)') === "'+SUM(A1:A2)", 'plus formula should be exported as text');
recipeCsvExportGuardAssert(posmain_csv_safe_cell('@HYPERLINK("x")') === "'@HYPERLINK(\"x\")", 'at formula should be exported as text');
recipeCsvExportGuardAssert(posmain_csv_safe_cell('-HYPERLINK("x")') === "'-HYPERLINK(\"x\")", 'minus non-numeric formula should be exported as text');
recipeCsvExportGuardAssert(posmain_csv_safe_cell('-12.500000') === '-12.500000', 'negative numeric quantity should remain numeric text');
recipeCsvExportGuardAssert(posmain_csv_safe_cell("  =SUM(A1:A2)") === "'  =SUM(A1:A2)", 'leading-space formula should be exported as text');
recipeCsvExportGuardAssert(posmain_csv_safe_cell("\t=SUM(A1:A2)") === "'\t=SUM(A1:A2)", 'tab-prefixed formula should be exported as text');
recipeCsvExportGuardAssert(posmain_csv_safe_cell('ordinary item') === 'ordinary item', 'ordinary text should remain unchanged');

$row = posmain_csv_safe_row(['Burger', '=1+1', '-3.000000']);
recipeCsvExportGuardAssert($row === ['Burger', "'=1+1", '-3.000000'], 'CSV row sanitizer should sanitize only risky cells');

echo "recipe-csv-export-guard-ok\n";

function recipeCsvExportGuardAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
