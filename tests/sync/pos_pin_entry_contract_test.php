<?php

$source = (string) file_get_contents(__DIR__ . '/../../includes/pos_login_screen.php');
$barcodeJs = (string) file_get_contents(__DIR__ . '/../../js/pos_barcode.js');
$barcodeCss = (string) file_get_contents(__DIR__ . '/../../dist/css/pos_barcode.css');

posPinEntryAssert(strpos($source, 'direction: ltr') !== false, 'pin dots should use LTR fill order');
posPinEntryAssert(strpos($source, 'id="pinDots" dir="ltr"') !== false, 'pin dots container should be LTR');
posPinEntryAssert(strpos($source, 'digitFromKeyboardEvent') !== false, 'unlock screen should accept physical keyboard digits');
posPinEntryAssert(strpos($source, 'Numpad') !== false, 'unlock screen should accept numpad keys');
posPinEntryAssert(strpos($source, "body.set('pin', buffer)") !== false, 'unlock screen should submit buffer in entry order');

posPinEntryAssert(strpos($barcodeJs, 'dir="ltr"') !== false, 'manager PIN modal dots should be LTR');
posPinEntryAssert(strpos($barcodeJs, 'digitFromKeyboardEvent') !== false, 'manager PIN modal should accept physical keyboard digits');
posPinEntryAssert(strpos($barcodeCss, 'direction: ltr') !== false, 'manager PIN modal CSS should force LTR dots');

echo "pos-pin-entry-contract-ok\n";

function posPinEntryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
