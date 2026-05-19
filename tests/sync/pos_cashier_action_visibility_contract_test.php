<?php

$root = dirname(__DIR__, 2);
$content = file_get_contents($root . '/includes/pos_content.php');
$assets = file_get_contents($root . '/includes/pos_assets.php');
$css = file_get_contents($root . '/dist/css/pos_barcode.css');

if ($content === false || $assets === false || $css === false) {
    throw new RuntimeException('Unable to read POS cashier layout sources');
}

posCashierActionVisibilityAssert(strpos($content, 'pos-order-items-section') !== false, 'order item section should have a dedicated layout hook');
posCashierActionVisibilityAssert(strpos($content, 'pos-order-items-card') !== false, 'order item card should have a dedicated layout hook');
posCashierActionVisibilityAssert(strpos($content, 'pos-payment-summary-card') !== false, 'payment/action summary should have a dedicated layout hook');
posCashierActionVisibilityAssert(strpos($content, 'pos-save-order-btn') !== false, 'save action should remain on the cashier page');
posCashierActionVisibilityAssert(strpos($content, 'pos-pay-order-btn') !== false, 'pay action should remain on the cashier page');
posCashierActionVisibilityAssert(strpos($content, 'pos-clear-btn') !== false, 'cancel/clear action should remain on the cashier page');
posCashierActionVisibilityAssert(strpos($content, 'name="itmname[]"') !== false, 'item identity fields should remain submitted');
posCashierActionVisibilityAssert(strpos($content, 'name="itmqty[]"') !== false, 'item quantity fields should remain submitted');
posCashierActionVisibilityAssert(strpos($content, 'name="itmprice[]"') !== false, 'item price fields should remain submitted');
posCashierActionVisibilityAssert(strpos($content, 'name="itmval[]"') !== false, 'item subtotal fields should remain submitted');

posCashierActionVisibilityAssert(strpos($content, 'pos-payment-modal-dialog') !== false, 'payment modal should have a compact viewport layout hook');
posCashierActionVisibilityAssert(strpos($content, 'pos-payment-modal-body') !== false, 'payment modal body should have a scrollable layout hook');
posCashierActionVisibilityAssert(strpos($content, 'pos-payment-grid') !== false, 'payment modal sections should have a compact grid hook');
posCashierActionVisibilityAssert(strpos($content, 'pos-pay-confirm-btn') !== false, 'payment confirmation action should remain in the modal footer');
posCashierActionVisibilityAssert(strpos($assets, 'filemtime(__DIR__ . \'/../dist/css/pos_barcode.css\')') !== false, 'POS barcode stylesheet should have a file version for cashier browser refreshes');
posCashierActionVisibilityAssert(strpos($assets, 'dist/css/pos_barcode.css?v=') !== false, 'POS barcode stylesheet link should include the version query');

foreach ([
    '.pos-order-column > .card > .card-body' => 'order column body should be height-managed',
    '.pos-order-items-section' => 'order items section should shrink before actions disappear',
    '.pos-order-items-card #itemData' => 'item list should scroll inside its own area',
    '.pos-payment-summary-card' => 'payment/action summary should have sticky styling',
    '#paymentModal .modal-content' => 'payment modal content should be viewport-limited',
    '#paymentModal .pos-payment-modal-body' => 'payment modal body should scroll independently',
    '#paymentModal .modal-footer' => 'payment modal footer should stay visible',
] as $needle => $message) {
    posCashierActionVisibilityAssert(strpos($css, $needle) !== false, $message);
}

foreach ([
    'max-height: calc(100vh - 96px)' => 'order column should be limited to the first viewport',
    'min-height: 0' => 'nested flex areas should be allowed to shrink',
    'overflow-y: auto !important' => 'order items should keep their own scroll surface',
    'position: sticky' => 'important action areas should remain pinned in view',
    '@media (min-width: 576px) and (max-width: 991.98px)' => 'tablet action area should be scoped away from phone width',
    'position: fixed' => 'tablet action area should remain visible while browsing items',
    'bottom: 0' => 'sticky action areas should pin to the bottom edge',
    'z-index: 1030' => 'fixed action area should stay above the cashier content',
    'max-height: calc(100vh - 1rem)' => 'payment modal should fit inside the viewport',
    'overflow-y: auto' => 'payment modal body should scroll without hiding footer actions',
] as $needle => $message) {
    posCashierActionVisibilityAssert(strpos($css, $needle) !== false, $message);
}

echo "pos-cashier-action-visibility-contract-ok\n";

function posCashierActionVisibilityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
