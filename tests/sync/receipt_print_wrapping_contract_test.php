<?php

$templates = [
    'receipt' => __DIR__ . '/../../print/receipt.php',
    'receipt_waiter' => __DIR__ . '/../../print/receipt_waiter.php',
    'daily_sales_receipt' => __DIR__ . '/../../print/daily_sales_receipt.php',
    'shift_sales_receipt' => __DIR__ . '/../../print/shift_sales_receipt.php',
    'preparation' => __DIR__ . '/../../print/preparation.php',
    'closed_session_items' => __DIR__ . '/../../print/closed_session_items.php',
];

foreach ($templates as $name => $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException("Unable to read template: {$name}");
    }

    receiptPrintWrapAssert(strpos($source, 'receipt-item-name-cell') !== false, "{$name} should mark the item-name cell for wrapping");
    receiptPrintWrapAssert(
        strpos($source, 'table-layout: fixed') !== false || strpos($source, 'table-layout: fixed !important') !== false,
        "{$name} should use fixed table layout to preserve receipt width"
    );
    receiptPrintWrapAssert(strpos($source, 'overflow-wrap: anywhere') !== false, "{$name} should allow long item names to wrap");
}

$shiftSource = file_get_contents($templates['shift_sales_receipt']);
receiptPrintWrapAssert($shiftSource !== false, 'shift sales receipt source should load');
receiptPrintWrapAssert(strpos($shiftSource, 'text-truncate') === false, 'shift sales receipt should no longer truncate item names');

echo "receipt-print-wrapping-contract-ok\n";

function receiptPrintWrapAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
