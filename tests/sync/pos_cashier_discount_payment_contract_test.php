<?php

$source = file_get_contents(__DIR__ . '/../../js/pos_barcode.js');
if ($source === false) {
    throw new RuntimeException('Unable to read js/pos_barcode.js');
}

posCashierDiscountPaymentAssert(strpos($source, 'function setDefaultCashPaymentToNet(netAmount)') !== false, 'cashier JS should have one helper that syncs default cash payment to net');
posCashierDiscountPaymentAssert(substr_count($source, 'setDefaultCashPaymentToNet(net)') >= 3, 'total and both discount handlers should sync cash paid to the current net');
posCashierDiscountPaymentAssert(strpos($source, "\$('#modal_paid_cash').val(net.toFixed(2));") !== false, 'cash paid should be written from the after-discount net amount');
posCashierDiscountPaymentAssert(strpos($source, "\$('#modal_paid_bank').val('0.00');") !== false, 'default net payment should keep bank payment cleared');
posCashierDiscountPaymentAssert(strpos($source, 'if ($(\'#pos_split_payment_enabled\').prop(\'checked\'))') !== false, 'selected-item split mode should keep using selected split total instead of whole-order net');

echo "pos-cashier-discount-payment-contract-ok\n";

function posCashierDiscountPaymentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
