<?php

$receiptPath = __DIR__ . '/../../print/receipt.php';
$preparationPath = __DIR__ . '/../../print/preparation.php';
$receipt = file_get_contents($receiptPath);
$preparation = file_get_contents($preparationPath);
if ($receipt === false || $preparation === false) {
    throw new RuntimeException('Unable to read print templates');
}

phase4PrintTemplateAssert(strpos($receipt, "require_once __DIR__ . '/../classes/Pos/Service/OrderPrintPayloadService.php';") !== false, 'receipt should require OrderPrintPayloadService');
phase4PrintTemplateAssert(strpos($receipt, 'buildReceiptPayload($conn, $id)') !== false, 'receipt should build payload by order id');
phase4PrintTemplateAssert(strpos($receipt, '$receipt_lines') !== false, 'receipt should render payload lines');
phase4PrintTemplateAssert(strpos($receipt, '$line_modifiers') !== false, 'receipt should render modifiers');
phase4PrintTemplateAssert(strpos($receipt, '$line_notes') !== false, 'receipt should render line notes');
phase4PrintTemplateAssert(strpos($receipt, '$receipt_totals') !== false, 'receipt should render payload totals');
phase4PrintTemplateAssert(strpos($receipt, 'window.print();') !== false, 'receipt should keep browser print button behavior');
phase4PrintTemplateAssert(strpos($receipt, '@page') !== false, 'receipt should define receipt-sized print page');
phase4PrintTemplateAssert(strpos($receipt, 'size: 72mm 210mm;') !== false, 'receipt print page should use thermal receipt dimensions');
phase4PrintTemplateAssert(strpos($receipt, 'body > *:not(#printed)') !== false, 'receipt print mode should hide non-receipt page chrome');

phase4PrintTemplateAssert(strpos($preparation, "require_once __DIR__ . '/../classes/Pos/Service/OrderPrintPayloadService.php';") !== false, 'preparation should require OrderPrintPayloadService');
phase4PrintTemplateAssert(strpos($preparation, 'buildKotPayloadByOrderId($conn, $order_id)') !== false, 'preparation should build KOT payload by order id');
phase4PrintTemplateAssert(strpos($preparation, 'buildKotPayloadByTableId($conn, $table_id)') !== false, 'preparation should build KOT payload by table id');
phase4PrintTemplateAssert(strpos($preparation, '$print_lines') !== false, 'preparation should render payload lines');
phase4PrintTemplateAssert(strpos($preparation, '($item[\'modifiers\'] ?? [])') !== false, 'preparation should render modifiers');
phase4PrintTemplateAssert(strpos($preparation, '($item[\'notes\'] ?? null)') !== false, 'preparation should render Phase 4 notes');
phase4PrintTemplateAssert(strpos($preparation, "['legacy_notes']") !== false, 'preparation should keep legacy notes fallback');
phase4PrintTemplateAssert(strpos($preparation, 'window.print();') !== false, 'preparation should keep browser auto-print behavior');

echo "phase4-print-template-payload-contract-ok\n";

function phase4PrintTemplateAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
