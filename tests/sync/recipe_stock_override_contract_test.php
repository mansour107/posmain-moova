<?php

$root = dirname(__DIR__, 2);
$authGuard = recipeStockOverrideSource($root . '/includes/auth_guard.php');
$permissionMatrix = recipeStockOverrideSource($root . '/docs/production/permission_matrix.md');
$endpoint = recipeStockOverrideSource($root . '/ajax/manager_approval.php');
$runtimeProof = recipeStockOverrideSource($root . '/tests/sync/recipe_manager_override_endpoint_runtime_test.php');
$mutationService = recipeStockOverrideSource($root . '/classes/Pos/Service/PosOrderMutationService.php');
$itemAvailability = recipeStockOverrideSource($root . '/classes/Pos/Service/ItemAvailabilityService.php');
$posContent = recipeStockOverrideSource($root . '/includes/pos_content.php');
$barcodeJs = recipeStockOverrideSource($root . '/js/pos_barcode.js');

recipeStockOverrideAssert(strpos($authGuard, "'pos.recipe_stock_override'") !== false, 'auth guard should define recipe stock override permission');
recipeStockOverrideAssert(strpos($permissionMatrix, '`pos.recipe_stock_override`') !== false, 'permission matrix should document recipe stock override');

recipeStockOverrideAssert(strpos($endpoint, "require_permission('pos.recipe_stock_override'") !== false, 'manager approval endpoint should require override permission');
recipeStockOverrideAssert(strpos($endpoint, "'recipe.stock_override'") !== false, 'manager approval endpoint should create recipe stock override approvals');
recipeStockOverrideAssert(strpos($endpoint, '$service->requestApproval') !== false, 'manager approval endpoint should request approval');
recipeStockOverrideAssert(strpos($endpoint, '$service->decide') !== false, 'manager approval endpoint should approve with the current manager');
recipeStockOverrideAssert(strpos($runtimeProof, 'ajax/manager_approval.php') !== false, 'runtime proof should execute the manager approval endpoint');
recipeStockOverrideAssert(strpos($runtimeProof, 'recipe-manager-override-endpoint-runtime-ok') !== false, 'runtime proof should emit the manager override success marker');
recipeStockOverrideAssert(strpos($runtimeProof, 'CSRF_INVALID') !== false, 'runtime proof should cover CSRF denial');
recipeStockOverrideAssert(strpos($runtimeProof, 'PERMISSION_DENIED') !== false, 'runtime proof should cover permission denial');

recipeStockOverrideAssert(strpos($itemAvailability, 'availability_requires_manager_override') !== false, 'availability service should expose manager override requirement');
recipeStockOverrideAssert(strpos($itemAvailability, 'allowNegativeStockWithApproval') !== false, 'availability service should honor negative-stock approval setting');
recipeStockOverrideAssert(strpos($itemAvailability, 'isStrictStockEnabled') !== false, 'availability service should keep strict stock as a hard block');

recipeStockOverrideAssert(strpos($mutationService, "'recipe.stock_override'") !== false, 'mutation service should verify recipe stock override approval');
recipeStockOverrideAssert(strpos($mutationService, 'recordRecipeStockOverrideAudit') !== false, 'mutation service should audit approved stock overrides');
recipeStockOverrideAssert(strpos($mutationService, 'RecipeAuditService') !== false, 'mutation service should use recipe audit service for overrides');
recipeStockOverrideAssert(strpos($mutationService, "'itmmanagerapproval'") !== false, 'mutation service should read posted line approval ids');

recipeStockOverrideAssert(strpos($posContent, 'POSMAIN_CAN_RECIPE_STOCK_OVERRIDE') !== false, 'POS page should expose current user override capability');
recipeStockOverrideAssert(strpos($barcodeJs, 'ajax/manager_approval.php') !== false, 'POS JS should call manager approval endpoint');
recipeStockOverrideAssert(strpos($barcodeJs, 'requestRecipeStockOverride') !== false, 'POS JS should centralize recipe stock override prompt');
recipeStockOverrideAssert(strpos($barcodeJs, 'name="itmmanagerapproval[]"') !== false, 'POS JS should post manager approval id with line item');

echo "recipe-stock-override-contract-ok\n";

function recipeStockOverrideSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeStockOverrideAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
