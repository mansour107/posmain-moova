<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';

$quantityOnly = new InventoryFeatureFlags([
    'inventory' => [
        'ledger_mode' => 'off',
        'quantity_tracking' => true,
        'accounting' => false,
    ],
]);
inventoryQuantitySurfaceAssert(
    $quantityOnly->canWriteQuantityLedger(),
    'quantity-only shops must be allowed to use inventory write surfaces'
);

$disabled = new InventoryFeatureFlags([
    'inventory' => [
        'ledger_mode' => 'off',
        'quantity_tracking' => false,
        'accounting' => false,
    ],
]);
inventoryQuantitySurfaceAssert(
    !$disabled->canWriteQuantityLedger(),
    'inventory-disabled shops must keep inventory write surfaces disabled'
);

$legacyLive = new InventoryFeatureFlags([
    'inventory' => [
        'ledger_mode' => 'live',
    ],
]);
inventoryQuantitySurfaceAssert(
    $legacyLive->canWriteQuantityLedger(),
    'legacy live configuration must retain quantity-write compatibility'
);

foreach ([
    'inventory_purchasing.php',
    'inventory_transfers.php',
    'inventory_transfer_detail.php',
    'inventory_counts.php',
    'inventory_count_detail.php',
    'inventory_adjustments.php',
] as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    inventoryQuantitySurfaceAssert(
        is_string($source) && strpos($source, '->canWriteQuantityLedger()') !== false,
        $relative . ' must gate operator writes with the explicit quantity capability'
    );
    inventoryQuantitySurfaceAssert(
        strpos((string) $source, '->canWriteLedger()') === false,
        $relative . ' must not gate operator writes with the legacy ledger mode'
    );
}

foreach ([
    'ajax/inventory_purchase_receive.php',
    'ajax/inventory_count_common.php',
    'ajax/inventory_adjustment.php',
    'ajax/inventory_transfer_common.php',
] as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    inventoryQuantitySurfaceAssert(
        is_string($source) && strpos($source, 'تفعيل تتبع كمية المخزون') !== false,
        $relative . ' must explain the quantity capability rather than legacy ledger modes'
    );
    inventoryQuantitySurfaceAssert(
        strpos((string) $source, 'تفعيل وضع الجسر أو التشغيل') === false,
        $relative . ' must not tell operators to enable a legacy ledger mode'
    );
}

echo "inventory-quantity-only-operator-surface-contract-ok\n";

function inventoryQuantitySurfaceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
