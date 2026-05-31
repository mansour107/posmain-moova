<?php

$root = dirname(__DIR__, 2);
$inventoryPage = recipeWasteAdjustmentRetirementSource($root . '/inventory_adjustments.php');
$inventoryEndpoint = recipeWasteAdjustmentRetirementSource($root . '/ajax/inventory_adjustment.php');
$reports = recipeWasteAdjustmentRetirementSource($root . '/reports.php');
$preflight = recipeWasteAdjustmentRetirementSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$operatorSmoke = recipeWasteAdjustmentRetirementSource($root . '/tools/recipe_operator_surface_smoke.php');
$phase9 = recipeWasteAdjustmentRetirementSource($root . '/docs/inventory/phase9_adjustment_contracts.md');

foreach ([
    'recipe_waste.php',
    'classes/Recipe/RecipeWasteAdjustmentService.php',
    'tests/sync/recipe_waste_adjustment_endpoint_runtime_test.php',
] as $removed) {
    recipeWasteAdjustmentRetirementAssert(!is_file($root . '/' . $removed), 'legacy recipe waste surface should be removed: ' . $removed);
}

recipeWasteAdjustmentRetirementAssert(strpos($inventoryPage, 'الهالك والتسويات') !== false, 'Inventory adjustment page should be the Arabic waste/adjustment surface');
recipeWasteAdjustmentRetirementAssert(strpos($inventoryPage, 'ajax/inventory_adjustment.php') !== false, 'Inventory adjustment page should post to the Inventory endpoint');
recipeWasteAdjustmentRetirementAssert(strpos($inventoryPage, 'RecipeWasteAdjustmentService') === false, 'Inventory adjustment page must not use legacy recipe waste service');
recipeWasteAdjustmentRetirementAssert(strpos($inventoryPage, 'INSERT INTO inventory_movements') === false, 'Inventory adjustment page must not write movements directly');
recipeWasteAdjustmentRetirementAssert(strpos($inventoryPage, 'UPDATE inventory_item_balances') === false, 'Inventory adjustment page must not update balances directly');

foreach ([
    'InventoryAdjustmentService.php',
    "require_csrf('inventory_adjustment'",
    "require_permission('inventory.edit'",
    'recordWaste',
    'recordAdjustment',
    'allow_backdate',
    'allow_negative_result',
    'allow_reason_code_approval',
] as $needle) {
    recipeWasteAdjustmentRetirementAssert(strpos($inventoryEndpoint, $needle) !== false, 'Inventory adjustment endpoint should include: ' . $needle);
}
recipeWasteAdjustmentRetirementAssert(strpos($inventoryEndpoint, 'RecipeWasteAdjustmentService') === false, 'Inventory adjustment endpoint must not use legacy recipe waste service');

recipeWasteAdjustmentRetirementAssert(strpos($reports, 'inventory_adjustments.php?from=recipe_reports') !== false, 'reports page should link the Inventory adjustment operator page');
recipeWasteAdjustmentRetirementAssert(strpos($preflight, 'inventory_adjustments.php') !== false, 'runtime preflight should check the Inventory adjustment operator page');
recipeWasteAdjustmentRetirementAssert(strpos($preflight, 'ajax/inventory_adjustment.php') !== false, 'runtime preflight should check the Inventory adjustment endpoint');
recipeWasteAdjustmentRetirementAssert(strpos($preflight, 'recipe_waste.php') === false, 'runtime preflight should not check the removed recipe waste page');
recipeWasteAdjustmentRetirementAssert(strpos($operatorSmoke, 'inventory_adjustments.php') !== false, 'operator smoke should check the Inventory adjustment page');
recipeWasteAdjustmentRetirementAssert(strpos($operatorSmoke, 'recipe_waste.php') === false, 'operator smoke should not check the removed recipe waste page');
recipeWasteAdjustmentRetirementAssert(strpos($phase9, 'recipe_waste.php` and `RecipeWasteAdjustmentService` have been deleted') !== false, 'phase9 docs should record legacy deletion');

echo "recipe-waste-adjustment-retirement-contract-ok\n";

function recipeWasteAdjustmentRetirementSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeWasteAdjustmentRetirementAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
