<?php

$root = dirname(__DIR__, 2);
$movementService = recipeInventoryKernelSource(
    $root . '/classes/Recipe/RecipeInventoryMovementService.php'
);
$inventoryFlags = recipeInventoryKernelSource(
    $root . '/classes/Inventory/InventoryFeatureFlags.php'
);

foreach ([
    'recordRecipeConsumptionThroughInventoryLedger',
    'recordReservationDeltaThroughInventoryLedger',
    'recordProductionInputThroughInventoryLedger',
    'recordProductionOutputThroughInventoryLedger',
    '$this->inventoryLedger->recordMovement(',
] as $requiredNeedle) {
    recipeInventoryKernelAssert(
        strpos($movementService, $requiredNeedle) !== false,
        'all active recipe inventory writes must use the shared inventory kernel: ' . $requiredNeedle
    );
}

foreach ([
    '->createMovement(',
    '->putBalance(',
    'INSERT IGNORE INTO inventory_item_balances',
    'FOR UPDATE',
] as $forbiddenNeedle) {
    recipeInventoryKernelAssert(
        strpos($movementService, $forbiddenNeedle) === false,
        'recipe inventory service must not maintain a second balance/movement write path: ' . $forbiddenNeedle
    );
}

foreach ([
    "'shadow'",
    "'reserve_only'",
    "'consume_pilot'",
    "'accounting_pilot'",
    "'availability_pilot'",
    "'full'",
    'legacyRecipeRequiresQuantityTracking',
] as $compatibilityNeedle) {
    recipeInventoryKernelAssert(
        strpos($inventoryFlags, $compatibilityNeedle) !== false,
        'legacy active recipe modes must be adapted to the quantity ledger: ' . $compatibilityNeedle
    );
}

echo "recipe-inventory-kernel-contract-ok\n";

function recipeInventoryKernelSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeInventoryKernelAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
