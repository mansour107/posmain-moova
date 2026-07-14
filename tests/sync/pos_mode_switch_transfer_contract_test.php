<?php
declare(strict_types=1);

/**
 * Contract: POS mode tabs transfer unsaved cart items instead of silently clearing them.
 */

function posModeSwitchTransferAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'OK: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__, 2);
$js = file_get_contents($root . '/js/pos_barcode.js');
$css = file_get_contents($root . '/dist/css/pos_barcode.css');
$deliveryJs = file_get_contents($root . '/js/pos_delivery.js');

posModeSwitchTransferAssert(is_string($js) && $js !== '', 'pos_barcode.js readable');
posModeSwitchTransferAssert(is_string($css) && $css !== '', 'pos_barcode.css readable');
posModeSwitchTransferAssert(is_string($deliveryJs) && $deliveryJs !== '', 'pos_delivery.js readable');

posModeSwitchTransferAssert(
    strpos($js, 'preparePosOrderContextForModeSwitch') !== false,
    'mode switch should prepare context with optional cart keep'
);
posModeSwitchTransferAssert(
    strpos($js, 'showModeTransferToast') !== false,
    'mode switch should show transfer toast when cart is kept'
);
posModeSwitchTransferAssert(
    strpos($js, 'requestPosOrderModeSwitch') !== false,
    'mode tabs should route through transfer-aware switch helper'
);
posModeSwitchTransferAssert(
    strpos($js, 'bindEmptyTableToCurrentCart') !== false,
    'empty table selection should keep transferred cart items'
);
posModeSwitchTransferAssert(
    strpos($js, 'showDenyButton') !== false
        && strpos($js, 'بدء طلب جديد بدون نقل') !== false,
    'saved-order mode switch should use SweetAlert deny button for discard'
);
posModeSwitchTransferAssert(
    strpos($css, '.pos-mode-transfer-toast') !== false,
    'transfer toast styles should exist'
);
posModeSwitchTransferAssert(
    strpos($css, 'pos-cart-transfer-flash') !== false,
    'cart flash animation styles should exist'
);
posModeSwitchTransferAssert(
    strpos($deliveryJs, '__posModeSwitchSilent') !== false,
    'delivery modal dismiss should keep cart silently on revert'
);

echo "pos_mode_switch_transfer_contract_test.php PASS\n";
