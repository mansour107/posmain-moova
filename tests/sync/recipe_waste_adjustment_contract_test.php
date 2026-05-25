<?php

$root = dirname(__DIR__, 2);
$page = recipeWasteAdjustmentSource($root . '/recipe_waste.php');
$service = recipeWasteAdjustmentSource($root . '/classes/Recipe/RecipeWasteAdjustmentService.php');
$movementService = recipeWasteAdjustmentSource($root . '/classes/Recipe/RecipeInventoryMovementService.php');
$accounting = recipeWasteAdjustmentSource($root . '/classes/Recipe/RecipeAccountingService.php');
$runtimeTest = recipeWasteAdjustmentSource($root . '/tests/sync/recipe_waste_adjustment_endpoint_runtime_test.php');
$reports = recipeWasteAdjustmentSource($root . '/reports.php');
$preflight = recipeWasteAdjustmentSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');

recipeWasteAdjustmentAssert(strpos($page, 'require_login()') !== false, 'operator page should require login');
recipeWasteAdjustmentAssert(strpos($page, 'posmain_recipe_waste_can_view') !== false, 'operator page should guard view permission');
recipeWasteAdjustmentAssert(strpos($page, "require_csrf('recipe_waste_adjustment')") !== false, 'operator page should require CSRF for writes');
recipeWasteAdjustmentAssert(strpos($page, 'RecipeWasteAdjustmentService') !== false, 'operator page should delegate writes to shared service');
recipeWasteAdjustmentAssert(strpos($page, 'ajax/recipe_editor_lookup.php') !== false, 'operator page should reuse recipe item lookup');
recipeWasteAdjustmentAssert(strpos($page, 'INSERT INTO inventory_movements') === false, 'operator page must not write inventory directly');
recipeWasteAdjustmentAssert(strpos($page, 'UPDATE inventory_item_balances') === false, 'operator page must not update balances directly');

recipeWasteAdjustmentAssert(strpos($service, 'recordWaste') !== false, 'shared service should expose waste mutation');
recipeWasteAdjustmentAssert(strpos($service, 'recordAdjustment') !== false, 'shared service should expose stock adjustment mutation');
recipeWasteAdjustmentAssert(strpos($service, 'RecipeAuditService') !== false, 'shared service should audit stock-sensitive writes');
recipeWasteAdjustmentAssert(strpos($service, 'assertCanApprove') !== false, 'shared service should require stronger permission for backdated writes');
recipeWasteAdjustmentAssert(strpos($service, 'begin_transaction') !== false, 'shared service should wrap movement/accounting/audit writes transactionally');
recipeWasteAdjustmentAssert(strpos($service, 'idempotency_key') !== false, 'shared service should use deterministic idempotency');

recipeWasteAdjustmentAssert(strpos($movementService, 'recordAdjustment') !== false, 'inventory service should record adjustment movements');
recipeWasteAdjustmentAssert(strpos($movementService, "'movement_type' => 'adjustment'") !== false, 'adjustment movements should use adjustment type');
recipeWasteAdjustmentAssert(strpos($accounting, 'postStockAdjustment') !== false, 'accounting service should post adjustment variance journals');
recipeWasteAdjustmentAssert(strpos($accounting, 'Recipe accounting movement id is missing.') !== false, 'accounting service should fail closed on missing movement ids');
recipeWasteAdjustmentAssert(strpos($accounting, 'Recipe accounting movement type is invalid for this posting.') !== false, 'accounting service should fail closed on wrong movement types');
recipeWasteAdjustmentAssert(strpos($accounting, '/\b(decimal|numeric)\b/') !== false, 'accounting service should require decimal/numeric journal entry columns');
recipeWasteAdjustmentAssert(strpos($runtimeTest, 'recipe_waste.php') !== false, 'runtime test should execute the real waste/adjustment page');
recipeWasteAdjustmentAssert(strpos($runtimeTest, "CREATE DATABASE `{\$db}`") !== false, 'runtime test should use an isolated temporary database');
recipeWasteAdjustmentAssert(strpos($runtimeTest, "DROP DATABASE IF EXISTS `{\$db}`") !== false, 'runtime test should drop the temporary database');
recipeWasteAdjustmentAssert(strpos($runtimeTest, "recipe_waste_adjustment") !== false, 'runtime test should prepare the page CSRF namespace');
recipeWasteAdjustmentAssert(strpos($runtimeTest, 'inventory_movements') !== false, 'runtime test should verify movement rows');
recipeWasteAdjustmentAssert(strpos($runtimeTest, 'inventory_item_balances') !== false, 'runtime test should verify balance rows');
recipeWasteAdjustmentAssert(strpos($runtimeTest, 'recipe_audit_log') !== false, 'runtime test should verify audit rows');
recipeWasteAdjustmentAssert(strpos($runtimeTest, "'POSMAIN_ENABLE_RECIPES' => '1'") !== false, 'runtime test should enable recipe flags only inside the child process');
recipeWasteAdjustmentAssert(strpos($runtimeTest, "'POSMAIN_RECIPE_MODE' => 'shadow'") !== false, 'runtime test should use a write-capable non-accounting recipe mode');
recipeWasteAdjustmentAssert(strpos($runtimeTest, "'POSMAIN_RECIPE_ACCOUNTING' => '0'") !== false, 'runtime test should keep accounting disabled for the isolated smoke');
recipeWasteAdjustmentAssert(strpos($runtimeTest, 'replay should not duplicate movement') !== false, 'runtime test should cover idempotency replay');
recipeWasteAdjustmentAssert(strpos($reports, 'recipe_waste.php') !== false, 'reports page should link the operator page');
recipeWasteAdjustmentAssert(strpos($preflight, 'recipe_waste.php') !== false, 'runtime preflight should check the operator page');

echo "recipe-waste-adjustment-contract-ok\n";

function recipeWasteAdjustmentSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeWasteAdjustmentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
