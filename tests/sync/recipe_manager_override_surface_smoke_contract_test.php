<?php

$root = dirname(__DIR__, 2);
$toolPath = $root . '/tools/recipe_manager_override_surface_smoke.php';
$tool = recipeManagerOverrideSurfaceSmokeSource($toolPath);
$preflight = recipeManagerOverrideSurfaceSmokeSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$pilotEvidence = recipeManagerOverrideSurfaceSmokeSource($root . '/classes/Recipe/RecipePilotEvidenceService.php');
$doc = recipeManagerOverrideSurfaceSmokeSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($toolPath) . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipeManagerOverrideSurfaceSmokeAssert($helpCode === 0, 'manager override surface smoke help should exit cleanly');
recipeManagerOverrideSurfaceSmokeAssert(strpos($help, 'read-only authenticated GET smoke') !== false, 'help should describe authenticated GET smoke');
recipeManagerOverrideSurfaceSmokeAssert(strpos($help, 'manager recipe stock override POS surface') !== false, 'help should describe manager override scope');
recipeManagerOverrideSurfaceSmokeAssert(strpos($help, 'does not log in') !== false, 'help should state that it does not log in');
recipeManagerOverrideSurfaceSmokeAssert(strpos($help, 'curl/Netscape cookie jar') !== false, 'help should document cookie jar input');
recipeManagerOverrideSurfaceSmokeAssert(strpos($help, 'manager approval endpoint method guard') !== false, 'help should document method-guard check');
recipeManagerOverrideSurfaceSmokeAssert(strpos($help, 'POS barcode gate') !== false, 'help should document cashier unlock warning');
recipeManagerOverrideSurfaceSmokeAssert(strpos($help, 'does not click buttons') !== false, 'help should distinguish browser QA limits');
recipeManagerOverrideSurfaceSmokeAssert(strpos($help, 'request approvals') !== false, 'help should state that it does not request approvals');

foreach ([
    'pos_barcode.php',
    'js/pos_barcode.js',
    'ajax/get_category_items.php',
    'ajax/manager_approval.php',
    'POSMAIN_CAN_RECIPE_STOCK_OVERRIDE',
    'requestRecipeStockOverride',
    'approve_recipe_stock_override',
    'data-requires-manager-override',
    'data-override-allowed',
    'data-override-permission',
    'itmmanagerapproval[]',
    'availability_requires_manager_override',
    'availability_override_permission',
    'METHOD_NOT_ALLOWED',
    'pos_barcode_gate_requires_operator_unlock',
    'category_id_not_provided_payload_shape_not_observed',
    'no_manager_override_item_in_category',
    'method_guard_payload_missing',
] as $needle) {
    recipeManagerOverrideSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should check manager override surface: ' . $needle);
}

foreach ([
    'recipeManagerOverrideSurfaceSmokeLoginDetected',
    'recipeManagerOverrideSurfaceSmokePosBarcodeGateDetected',
    'recipeManagerOverrideSurfaceSmokeAccessDenied',
    'recipeManagerOverrideSurfaceSmokeFatalText',
    'recipeManagerOverrideSurfaceSmokeNormalizeCookieSource',
    '#HttpOnly_',
    'requires_authenticated_session_cookie',
    'override_availability_fields_missing',
    'expected_override_js_missing',
    'expected_override_bootstrap_missing',
] as $needle) {
    recipeManagerOverrideSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should expose smoke guard: ' . $needle);
}

foreach ([
    "'method' => 'GET'",
    'CURLOPT_HTTPGET',
] as $needle) {
    recipeManagerOverrideSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should only use GET requests: ' . $needle);
}

foreach (['CURLOPT_POST', 'INSERT INTO', 'UPDATE ', 'DELETE FROM', 'RecipeInventoryMovementService', 'RecipeAccountingService', 'SyncOutboxEventService', 'requestApproval(', 'decide('] as $needle) {
    recipeManagerOverrideSurfaceSmokeAssert(strpos($tool, $needle) === false, 'tool must stay read-only and avoid: ' . $needle);
}

recipeManagerOverrideSurfaceSmokeAssert(strpos($preflight, 'tools/recipe_manager_override_surface_smoke.php') !== false, 'runtime preflight should require manager override smoke tool');
recipeManagerOverrideSurfaceSmokeAssert(strpos($pilotEvidence, 'tools/recipe_manager_override_surface_smoke.php') !== false, 'pilot evidence should accept manager override surface smoke command details');
recipeManagerOverrideSurfaceSmokeAssert(strpos($doc, 'tools/recipe_manager_override_surface_smoke.php') !== false, 'rollout doc should document manager override smoke command');
recipeManagerOverrideSurfaceSmokeAssert(strpos($doc, 'manager recipe stock override POS surface') !== false, 'rollout doc should describe manager override smoke scope');
recipeManagerOverrideSurfaceSmokeAssert(strpos($doc, 'operator-unlock warning') !== false, 'rollout doc should describe POS barcode gate warning');
recipeManagerOverrideSurfaceSmokeAssert(strpos($doc, 'click buttons, approve prompts, issue mutations') !== false, 'rollout doc should document non-mutating manager override scope');

echo "recipe-manager-override-surface-smoke-contract-ok\n";

function recipeManagerOverrideSurfaceSmokeSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeManagerOverrideSurfaceSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
