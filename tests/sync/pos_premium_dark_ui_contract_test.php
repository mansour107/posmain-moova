<?php

$root = dirname(__DIR__, 2);

function posPremiumDarkUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$posBarcode = file_get_contents($root . '/pos_barcode.php');
$content = file_get_contents($root . '/includes/pos_content.php');
$css = file_get_contents($root . '/dist/css/pos_barcode.css');
$js = file_get_contents($root . '/js/pos_barcode.js');
$itemCard = file_get_contents($root . '/includes/pos_item_card.php');
$cartRow = file_get_contents($root . '/includes/pos_cart_row.php');
$lazy = file_get_contents($root . '/ajax/load_items_lazy.php');

foreach ([$posBarcode, $content, $css, $js, $itemCard, $cartRow, $lazy] as $source) {
    posPremiumDarkUiAssert($source !== false, 'Unable to read premium dark POS sources');
}

posPremiumDarkUiAssert(strpos($posBarcode, 'pos-premium-dark') !== false, 'POS page should enable premium dark body class');
posPremiumDarkUiAssert(strpos($posBarcode, 'pos-immersive') !== false, 'POS page should enable immersive layout');
posPremiumDarkUiAssert(strpos($posBarcode, 'pos-corner-menu') !== false, 'POS page should expose corner utility menu');
posPremiumDarkUiAssert(strpos($posBarcode, 'cornerRecentOrdersBtn') !== false, 'Corner menu should expose recent orders trigger');

posPremiumDarkUiAssert(strpos($content, 'pos-order-panel') !== false, 'Order panel shell should exist');
posPremiumDarkUiAssert(strpos($content, 'posClearOrderBtn') !== false, 'Header trash clear button should exist');
posPremiumDarkUiAssert(strpos($content, 'pos-order-type-tabs') !== false, 'Order type tabs should live in order panel');
posPremiumDarkUiAssert(strpos($content, 'pos-catalog-panel') !== false, 'Catalog panel shell should exist');
posPremiumDarkUiAssert(strpos($content, 'placeholder="ابحث عن الصنف..."') !== false, 'Catalog search placeholder should match mockup');
posPremiumDarkUiAssert(strpos($content, 'pos_render_item_card_compact') !== false, 'Initial grid should use compact cards');
posPremiumDarkUiAssert(strpos($content, 'pos_render_cart_row') !== false, 'Edit-mode cart rows should use shared renderer');
posPremiumDarkUiAssert(strpos($content, 'id="itemData"') !== false, 'Cart container should remain #itemData');
posPremiumDarkUiAssert(strpos($content, 'pos-save-order-btn') !== false, 'Save action should remain');
posPremiumDarkUiAssert(strpos($content, 'pos-print-order-btn') !== false, 'Print action should remain');
posPremiumDarkUiAssert(strpos($content, 'pos-pay-order-btn') !== false, 'Pay action should remain');
posPremiumDarkUiAssert(strpos($content, 'pos-clear-btn') !== false, 'Legacy clear button hook should remain for compatibility');

posPremiumDarkUiAssert(strpos($css, 'body.pos-premium-dark') !== false, 'Premium dark theme block should exist in CSS');
posPremiumDarkUiAssert(strpos($css, 'pos-compact-card') !== false, 'Compact product card styles should exist');
posPremiumDarkUiAssert(strpos($css, 'pos-cart-price-display') !== false, 'Mockup cart price display styles should exist');

posPremiumDarkUiAssert(strpos($js, 'pos-cart-price-display') !== false, 'JS cart template should render mockup price display');
posPremiumDarkUiAssert(strpos($js, 'function addItemToOrder') !== false || strpos($js, 'addItemToOrder(') !== false, 'Cart mutation function should remain');

posPremiumDarkUiAssert(strpos($itemCard, 'function pos_render_item_card_compact') !== false, 'Compact card renderer should exist');
posPremiumDarkUiAssert(strpos($itemCard, 'data-item-id') !== false, 'Compact cards should preserve data-item-id');
posPremiumDarkUiAssert(strpos($itemCard, 'item-card itemButton') !== false, 'Compact cards should remain clickable');

posPremiumDarkUiAssert(strpos($lazy, 'pos_render_item_card_compact') !== false, 'Lazy loader should render compact cards');

echo "pos-premium-dark-ui-contract-ok\n";
