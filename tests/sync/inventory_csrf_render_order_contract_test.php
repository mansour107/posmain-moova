<?php

declare(strict_types=1);

function inventoryCsrfOrderAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "OK: {$message}\n";
}

$root = dirname(__DIR__, 2);
$pages = [
    'inventory_stock_levels.php' => [
        "\$inventoryStockLevelCsrfMeta = csrf_meta_tag('inventory_stock_level', 'inventory-stock-level-csrf');",
        '<?= $inventoryStockLevelCsrfMeta ?>',
    ],
    'inventory_counts.php' => [
        "\$inventoryCountCsrfMeta = csrf_meta_tag('inventory_count', 'inventory-count-csrf');",
        '<?= $inventoryCountCsrfMeta ?>',
    ],
    'inventory_count_detail.php' => [
        "\$inventoryCountCsrfMeta = csrf_meta_tag('inventory_count', 'inventory-count-csrf');",
        '<?= $inventoryCountCsrfMeta ?>',
    ],
    'inventory_purchasing.php' => [
        "\$inventoryReceivingCsrfMeta = csrf_meta_tag('inventory_receiving', 'inventory-receiving-csrf');",
        '<?= $inventoryReceivingCsrfMeta ?>',
    ],
    'inventory_transfers.php' => [
        "\$inventoryTransferCsrfMeta = csrf_meta_tag('inventory_transfer', 'inventory-transfer-csrf');",
        '<?= $inventoryTransferCsrfMeta ?>',
    ],
    'inventory_transfer_detail.php' => [
        "\$inventoryTransferCsrfMeta = csrf_meta_tag('inventory_transfer', 'inventory-transfer-csrf');",
        '<?= $inventoryTransferCsrfMeta ?>',
    ],
    'inventory_adjustments.php' => [
        "\$inventoryAdjustmentCsrfMeta = csrf_meta_tag('inventory_adjustment', 'inventory-adjustment-csrf');",
        '<?= $inventoryAdjustmentCsrfMeta ?>',
    ],
    'inventory_reason_codes.php' => [
        "\$inventoryReasonCodeCsrfMeta = csrf_meta_tag('inventory_reason_code', 'inventory-reason-code-csrf');",
        '<?= $inventoryReasonCodeCsrfMeta ?>',
    ],
];

foreach ($pages as $relativePath => [$mintStatement, $renderStatement]) {
    $source = file_get_contents($root . '/' . $relativePath);
    inventoryCsrfOrderAssert(is_string($source), "{$relativePath} is readable");

    $mintPosition = strpos($source, $mintStatement);
    $headerPosition = strpos($source, "include __DIR__ . '/includes/header.php';");
    $renderPosition = strpos($source, $renderStatement);

    inventoryCsrfOrderAssert($mintPosition !== false, "{$relativePath} mints its write token");
    inventoryCsrfOrderAssert($headerPosition !== false, "{$relativePath} includes the shared header");
    inventoryCsrfOrderAssert(
        $mintPosition < $headerPosition,
        "{$relativePath} mints its write token before the header closes the session"
    );
    inventoryCsrfOrderAssert(
        $renderPosition !== false && $renderPosition > $headerPosition,
        "{$relativePath} renders the persisted token after the header"
    );
}

echo "inventory-csrf-render-order-contract-ok\n";
