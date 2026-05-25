<?php

$root = dirname(__DIR__, 2);
$movement = recipeInventoryConcurrencySource($root . '/classes/Recipe/RecipeInventoryMovementService.php');
$movementRepo = recipeInventoryConcurrencySource($root . '/classes/Recipe/Repository/InventoryMovementRepository.php');
$reservationRepo = recipeInventoryConcurrencySource($root . '/classes/Recipe/Repository/StockReservationRepository.php');
$production = recipeInventoryConcurrencySource($root . '/classes/Recipe/ProductionBatchService.php');
$retry = recipeInventoryConcurrencySource($root . '/classes/Recipe/RecipeTransactionRetryService.php');
$expireTool = recipeInventoryConcurrencySource($root . '/tools/recipe_expire_reservations.php');

exec('php ' . escapeshellarg($root . '/tools/recipe_expire_reservations.php') . ' --help', $expireHelpLines, $expireHelpCode);
$expireHelp = implode("\n", $expireHelpLines);

recipeInventoryConcurrencyAssert(
    strpos($movement, 'INSERT IGNORE INTO inventory_item_balances') !== false,
    'recipe inventory movement service should create missing balances without overwriting existing rows'
);
recipeInventoryConcurrencyAssert(
    strpos($movement, 'ORDER BY item_id ASC') !== false && strpos($movement, 'FOR UPDATE') !== false,
    'recipe inventory movement service should lock multi-ingredient balances in deterministic order'
);
recipeInventoryConcurrencyAssert(
    strpos($movement, 'lockRequirementBalances') !== false,
    'recipe inventory movement service should pre-lock requirement balances'
);
recipeInventoryConcurrencyAssert(
    strpos($movementRepo, 'assertValidMovement') !== false,
    'inventory movement repository should validate ledger rows before insert'
);
foreach (['movement_type is invalid', 'source_type is invalid', 'idempotency key is required', 'cannot be negative', 'unit conversion must be positive', 'cannot have both qty_in and qty_out positive'] as $needle) {
    recipeInventoryConcurrencyAssert(strpos($movementRepo, $needle) !== false, 'movement repository missing invariant guard: ' . $needle);
}
foreach (['recipe_consumption', 'production_input', 'production_output', 'refund_reversal', 'opening_balance', 'recipe_order_line_usage', 'purchase_invoice', 'sync_event'] as $enumNeedle) {
    recipeInventoryConcurrencyAssert(strpos($movementRepo, $enumNeedle) !== false, 'movement repository missing schema enum allowlist value: ' . $enumNeedle);
}
foreach (['Stock reservation status is invalid', 'Stock reservation qty_reserved must be positive', 'Stock reservation idempotency key is required', 'order_id', 'must be positive'] as $needle) {
    recipeInventoryConcurrencyAssert(strpos($reservationRepo, $needle) !== false, 'stock reservation repository missing invariant guard: ' . $needle);
}
foreach (['reserved', 'consumed', 'released', 'expired'] as $statusNeedle) {
    recipeInventoryConcurrencyAssert(strpos($reservationRepo, $statusNeedle) !== false, 'stock reservation repository missing status allowlist value: ' . $statusNeedle);
}
recipeInventoryConcurrencyAssert(
    strpos($reservationRepo, "AND status = 'reserved'\nORDER BY id\nFOR UPDATE") !== false,
    'active reservation lookup should lock reserved rows before write-side status transitions'
);
recipeInventoryConcurrencyAssert($expireHelpCode === 0, 'reservation expiry tool help should exit cleanly');
recipeInventoryConcurrencyAssert(strpos($expireHelp, 'Dry-run is the default') !== false, 'reservation expiry help should make dry-run default explicit');
recipeInventoryConcurrencyAssert(strpos($expireHelp, '--apply') !== false, 'reservation expiry help should document apply flag');
recipeInventoryConcurrencyAssert(strpos($expireHelp, '--json') !== false, 'reservation expiry help should document json output');
recipeInventoryConcurrencyAssert(strpos($expireTool, 'StockReservationRepository') !== false, 'reservation expiry dry-run should inspect reservations through repository');
recipeInventoryConcurrencyAssert(strpos($expireTool, 'RecipeReservationService') !== false, 'reservation expiry apply should delegate to reservation service');
recipeInventoryConcurrencyAssert(strpos($expireTool, '$conn->rollback()') !== false, 'reservation expiry dry-run should rollback its inspection transaction');
recipeInventoryConcurrencyAssert(strpos($expireTool, 'Use either --apply or --dry-run, not both.') !== false, 'reservation expiry tool should reject ambiguous apply/dry-run flags');
foreach (['run_migrations.php --apply', 'shell_exec', 'passthru', 'system('] as $unsafeNeedle) {
    recipeInventoryConcurrencyAssert(strpos($expireTool, $unsafeNeedle) === false, 'reservation expiry tool must not use unsafe operation: ' . $unsafeNeedle);
}
recipeInventoryConcurrencyAssert(
    strpos($production, 'RecipeTransactionRetryService') !== false,
    'production batch commit should use the recipe transaction retry helper'
);
foreach (['1205', '1213', 'deadlock', 'lock wait timeout', 'try restarting transaction'] as $needle) {
    recipeInventoryConcurrencyAssert(strpos($retry, $needle) !== false, 'retry helper missing retryable signal: ' . $needle);
}

echo "recipe-inventory-concurrency-contract-ok\n";

function recipeInventoryConcurrencySource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeInventoryConcurrencyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
