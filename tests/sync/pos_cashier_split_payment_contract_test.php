<?php

$root = __DIR__ . '/../..';
$invoice = file_get_contents($root . '/do/doadd_invoice.php');
$posContent = file_get_contents($root . '/includes/pos_content.php');
$posJs = file_get_contents($root . '/js/pos_barcode.js');

if ($invoice === false || $posContent === false || $posJs === false) {
    throw new RuntimeException('Unable to read POS split-payment contract files');
}

posCashierSplitAssert(strpos($posContent, 'pos_split_payment_enabled') !== false, 'payment modal should expose selected-item split toggle');
posCashierSplitAssert(strpos($posContent, "submitPOS('split_cash')") !== false, 'payment modal should submit selected-item payment action');
posCashierSplitAssert(strpos($posContent, 'pos-split-pay-confirm-btn" onclick="submitPOS(\'split_cash\');" style="display: none;"') !== false, 'selected-item payment button should start hidden until split mode is active');
posCashierSplitAssert(strpos($posContent, "action === 'cash' && \$('#pos_split_payment_enabled').prop('checked')") !== false, 'inline submit override should route active split mode away from full payment');
posCashierSplitAssert(strpos($posJs, 'window.POSMainPrepareSplitPaymentFields') !== false, 'cashier JS should prepare selected-item split payload');
posCashierSplitAssert(strpos($posJs, 'pos_split_payment_payload') !== false, 'cashier JS should submit selected row payload');
posCashierSplitAssert(strpos($posJs, 'سداد الأصناف المحددة يستخدم طريقة دفع واحدة') !== false, 'cashier JS should guard single-method split payments');
posCashierSplitAssert(strpos($posJs, 'function updateSplitPaymentButtons()') !== false, 'cashier JS should hide full-payment action while selected-item mode is active');
posCashierSplitAssert(strpos($posJs, "action === 'cash' && \$('#pos_split_payment_enabled').prop('checked')") !== false, 'cashier JS should route active split mode away from full payment');
posCashierSplitAssert(strpos($invoice, '$is_split_line_payment = ($submit === \'split_cash\')') !== false, 'invoice handler should recognize split_cash action');
posCashierSplitAssert(strpos($invoice, 'posmainInvoiceDecodeSplitRows') !== false, 'invoice handler should decode selected row payload');
posCashierSplitAssert(strpos($invoice, '$mutationService->splitTablePayment') !== false, 'invoice handler should route selected item payment through splitTablePayment');
posCashierSplitAssert(strpos($invoice, "'event_source' => 'pos_cashier_split_payment'") !== false, 'invoice handler should tag split cashier events');
posCashierSplitAssert(strpos($invoice, '$split_receipt_order_id') !== false, 'invoice handler should redirect printing to the split child invoice');

echo "pos-cashier-split-payment-contract-ok\n";

function posCashierSplitAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
