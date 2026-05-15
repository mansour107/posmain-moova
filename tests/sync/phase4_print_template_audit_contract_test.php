<?php

$receipt = file_get_contents(__DIR__ . '/../../print/receipt.php');
$preparation = file_get_contents(__DIR__ . '/../../print/preparation.php');
if ($receipt === false || $preparation === false) {
    throw new RuntimeException('Unable to read print templates');
}

phase4PrintAuditContractAssert(strpos($receipt, "require_once __DIR__ . '/../classes/Pos/Service/BrowserPrintAuditService.php';") !== false, 'receipt should require BrowserPrintAuditService');
phase4PrintAuditContractAssert(strpos($receipt, "recordRenderedPrint(\n                \$conn,\n                'receipt'") !== false, 'receipt should record receipt print audit');
phase4PrintAuditContractAssert(strpos($receipt, "'source' => 'print_receipt_page'") !== false, 'receipt audit source expected');
phase4PrintAuditContractAssert(strpos($receipt, "catch (Throwable \$printAuditError)") !== false, 'receipt audit should be best-effort');

phase4PrintAuditContractAssert(strpos($preparation, "require_once __DIR__ . '/../classes/Pos/Service/BrowserPrintAuditService.php';") !== false, 'preparation should require BrowserPrintAuditService');
phase4PrintAuditContractAssert(strpos($preparation, "recordRenderedPrint(\n            \$conn,\n            'kot'") !== false, 'preparation should record kot print audit');
phase4PrintAuditContractAssert(strpos($preparation, "'source' => 'print_preparation_page'") !== false, 'preparation audit source expected');
phase4PrintAuditContractAssert(strpos($preparation, "catch (Throwable \$printAuditError)") !== false, 'preparation audit should be best-effort');
phase4PrintAuditContractAssert(strpos($preparation, 'window.print();') !== false, 'preparation should keep browser print');
phase4PrintAuditContractAssert(strpos($receipt, 'window.print();') !== false, 'receipt should keep browser print');

echo "phase4-print-template-audit-contract-ok\n";

function phase4PrintAuditContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
