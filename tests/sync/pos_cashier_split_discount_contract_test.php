<?php

$root = __DIR__ . '/../..';
$invoice = file_get_contents($root . '/do/doadd_invoice.php');
$posJs = file_get_contents($root . '/js/pos_barcode.js');

if ($invoice === false || $posJs === false) {
    throw new RuntimeException('Unable to read POS split discount contract files');
}

posCashierSplitDiscountAssert(strpos($posJs, 'function currentOrderDiscountRate()') !== false, 'cashier JS should calculate the current invoice discount rate');
posCashierSplitDiscountAssert(strpos($posJs, 'const discountedAmount = Math.max(0, subtotal * (1 - discountRate));') !== false, 'selected split rows should use discounted item amounts');
posCashierSplitDiscountAssert(strpos($posJs, 'function refreshSplitPaymentLineAmounts()') !== false, 'split payment panel should refresh selected-row prices when discount changes');
posCashierSplitDiscountAssert(strpos($posJs, 'refreshSplitPaymentLineAmounts();') !== false, 'split mode should refresh its line amounts from the current discount');

$distributePosition = strpos($invoice, '$discountedTotals = posmainInvoiceDistributeHeaderDiscountAcrossDetails(');
$splitPosition = strpos($invoice, '$mutationService->splitTablePayment');
posCashierSplitDiscountAssert($distributePosition !== false, 'invoice handler should distribute header discount across detail rows');
posCashierSplitDiscountAssert(strpos($invoice, 'fat_disc = 0') !== false, 'distributed split discount should clear the header discount to avoid double-discounting');
posCashierSplitDiscountAssert($splitPosition !== false && $distributePosition < $splitPosition, 'discount distribution must run before splitTablePayment reads detail values');
posCashierSplitDiscountAssert(strpos($invoice, '$newLineDiscount = (float) ($detail[\'discount\'] ?? 0) + ($lineDiscount / $qtyBasis);') !== false, 'detail discount should be applied per item quantity');

echo "pos-cashier-split-discount-contract-ok\n";

function posCashierSplitDiscountAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
