<?php

$root = dirname(__DIR__, 2);

function posLazyItemsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$posContent = file_get_contents($root . '/includes/pos_content.php');
posLazyItemsAssert(strpos($posContent, '$initialPosItemsLimit = 48') !== false, 'POS should render a small first batch of items');
posLazyItemsAssert(strpos($posContent, 'LIMIT {$initialPosItemsLimit}') !== false, 'Initial POS item query should be limited');
posLazyItemsAssert(strpos($posContent, 'ORDER BY COALESCE(m.salesqty, 0) DESC, m.iname') !== false, 'Initial POS items should prioritize popular items first');
posLazyItemsAssert(strpos($posContent, 'itemsGridLoader') !== false, 'POS should expose a background item-loader status element');

$lazyEndpoint = file_get_contents($root . '/ajax/load_items_lazy.php');
posLazyItemsAssert(strpos($lazyEndpoint, 'pos_render_item_card($item)') !== false, 'Lazy endpoint should return rendered item-card HTML');
posLazyItemsAssert(strpos($lazyEndpoint, 'LIMIT ? OFFSET ?') !== false, 'Lazy endpoint should page through remaining items');
posLazyItemsAssert(strpos($lazyEndpoint, 'ORDER BY COALESCE(m.salesqty, 0) DESC, m.iname') !== false, 'Lazy endpoint should use the same order as initial render');

$posJs = file_get_contents($root . '/js/pos_barcode.js');
foreach (['loadRemainingItems', 'appendLazyItems', 'applyActiveItemFilter', 'ajax/load_items_lazy.php'] as $needle) {
    posLazyItemsAssert(strpos($posJs, $needle) !== false, 'POS JS should include ' . $needle);
}

$itemCard = file_get_contents($root . '/includes/pos_item_card.php');
foreach (['function pos_render_item_card', 'data-item-id', 'data-item-name', 'data-item-price', 'item-details-btn'] as $needle) {
    posLazyItemsAssert(strpos($itemCard, $needle) !== false, 'Shared item-card renderer should include ' . $needle);
}

echo "pos-lazy-items-runtime-ok\n";

