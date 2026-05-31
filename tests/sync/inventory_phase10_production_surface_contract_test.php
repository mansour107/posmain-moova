<?php

$root = dirname(__DIR__, 2);
$page = inventoryPhase10ProductionSurfaceSource($root . '/recipe_production.php');
$service = inventoryPhase10ProductionSurfaceSource($root . '/classes/Recipe/ProductionBatchService.php');
$movementService = inventoryPhase10ProductionSurfaceSource($root . '/classes/Recipe/RecipeInventoryMovementService.php');
$explosionService = inventoryPhase10ProductionSurfaceSource($root . '/classes/Recipe/RecipeExplosionService.php');
$lifecycleTest = inventoryPhase10ProductionSurfaceSource($root . '/tests/recipe/RecipeOrderLifecycleServiceTest.php');
$smoke = inventoryPhase10ProductionSurfaceSource($root . '/tools/recipe_stock_operations_surface_smoke.php');

foreach ([
    'تشغيل الإنتاج',
    'مسودة إنتاج جديدة',
    'الوصفة / الصنف الناتج',
    'recipeProductionRecipeSearch',
    'recipeProductionRecipeSelect',
    'recipeProductionRecipeFilterNote',
    'نتائج مطابقة',
    'دفعات الإنتاج',
    'معاينة المدخلات',
    'الرصيد المتاح = الموجود - المحجوز',
    'حركات الإنتاج المثبتة',
    'تأكيد الإنتاج',
    'إلغاء المسودة',
    'name="action" value="create_draft"',
    'name="action" value="commit"',
    'name="action" value="cancel"',
    'name="variance_reason"',
] as $needle) {
    inventoryPhase10ProductionSurfaceAssert(strpos($page, $needle) !== false, 'production page should expose operator control: ' . $needle);
}

$docs = inventoryPhase10ProductionSurfaceSource($root . '/docs/inventory/phase10_production_contracts.md');
foreach ([
    'recipe/output-item search field',
    'stable form contract',
] as $needle) {
    inventoryPhase10ProductionSurfaceAssert(strpos($docs, $needle) !== false, 'phase10 docs should describe production search UX: ' . $needle);
}

foreach ([
    'available_qty',
    'short_qty',
    'has_shortage',
    'qty_on_hand',
    'qty_reserved',
    'item_name',
    'InventoryBalanceRepository',
    'RecipeDecimal::subtract($qtyOnHand, $qtyReserved)',
] as $needle) {
    inventoryPhase10ProductionSurfaceAssert(strpos($service, $needle) !== false, 'production preview should include balance evidence: ' . $needle);
}

foreach ([
    'تشغيل الإنتاج',
    'مسودة إنتاج جديدة',
    'معاينة المدخلات',
    'حركات الإنتاج المثبتة',
    'المتاح',
    'العجز',
] as $needle) {
    inventoryPhase10ProductionSurfaceAssert(strpos($smoke, $needle) !== false, 'stock operations smoke should follow production UI token: ' . $needle);
}

foreach ([
    'recordProductionInput',
    'recordProductionOutput',
    'updateCommitted',
] as $needle) {
    inventoryPhase10ProductionSurfaceAssert(strpos($service, $needle) !== false, 'production commit should keep shared ledger movement path: ' . $needle);
}

foreach ([
    'InventoryLedgerService',
    'canWriteLedger',
    'recordProductionInputThroughInventoryLedger',
    'recordProductionOutputThroughInventoryLedger',
] as $needle) {
    inventoryPhase10ProductionSurfaceAssert(strpos($movementService, $needle) !== false, 'recipe production movement service should delegate to shared ledger: ' . $needle);
}

foreach ([
    'batch_prepared',
    'preparedStockRequirement',
    "'line_type' => 'prepared_stock'",
    "'ingredient_item_id' => (int) \$recipe['sellable_item_id']",
] as $needle) {
    inventoryPhase10ProductionSurfaceAssert(strpos($explosionService, $needle) !== false, 'batch-prepared sale should explode to prepared stock: ' . $needle);
}

foreach ([
    'testBatchPreparedPaidOrderConsumesPreparedStockNotRawInputs',
    "'3.000000', \$preparedBalance['qty_on_hand']",
    "'100.000000', \$rawBalance['qty_on_hand']",
    "'prepared_stock', \$explosion['requirements'][0]['line_type']",
    "\$setup['sellable_item_id'], (int) \$movements[0]['item_id']",
] as $needle) {
    inventoryPhase10ProductionSurfaceAssert(strpos($lifecycleTest, $needle) !== false, 'prepared item sale proof should consume prepared stock only: ' . $needle);
}

$ledgerRequest = inventoryPhase10ProductionSurfaceSource($root . '/classes/Inventory/DTO/InventoryMovementRequest.php');
foreach ([
    'movementGroupUuid',
    'movement_group_uuid',
] as $needle) {
    inventoryPhase10ProductionSurfaceAssert(strpos($ledgerRequest, $needle) !== false, 'shared ledger request should preserve production movement grouping: ' . $needle);
}

echo "inventory-phase10-production-surface-contract-ok\n";

function inventoryPhase10ProductionSurfaceSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase10ProductionSurfaceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
