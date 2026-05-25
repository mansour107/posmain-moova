<?php

$root = dirname(__DIR__, 2);
$toolPath = $root . '/tools/recipe_management_surface_smoke.php';
$tool = recipeManagementSurfaceSmokeSource($toolPath);
$preflight = recipeManagementSurfaceSmokeSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$pilotEvidence = recipeManagementSurfaceSmokeSource($root . '/classes/Recipe/RecipePilotEvidenceService.php');
$doc = recipeManagementSurfaceSmokeSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($toolPath) . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipeManagementSurfaceSmokeAssert($helpCode === 0, 'management surface smoke help should exit cleanly');
recipeManagementSurfaceSmokeAssert(strpos($help, 'authenticated GET smoke') !== false, 'help should describe authenticated GET smoke');
recipeManagementSurfaceSmokeAssert(strpos($help, 'modifier-substitution surface') !== false, 'help should describe modifier-substitution scope');
recipeManagementSurfaceSmokeAssert(strpos($help, 'does not log in') !== false, 'help should state that it does not log in');
recipeManagementSurfaceSmokeAssert(strpos($help, 'does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, or enqueue sync') !== false, 'help should document read-only behavior');
recipeManagementSurfaceSmokeAssert(strpos($help, '--recipe-id=123') !== false, 'help should document selected recipe id input');
recipeManagementSurfaceSmokeAssert(strpos($help, 'curl/Netscape cookie jar') !== false, 'help should document cookie jar input');

foreach ([
    'recipe_manage.php',
    'ajax/recipe_editor_lookup.php',
    'Recipe Draft Management',
    'Save Draft Header',
    'Version History',
    'Cost And Availability Preview',
    'Modifier Behavior',
    'Substitution Group',
    'name="modifier_behavior"',
    'name="substitution_group"',
    'substitution_remove',
    'substitution_add',
] as $needle) {
    recipeManagementSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should check management surface token: ' . $needle);
}

foreach ([
    'recipeManagementSurfaceSmokeNormalizeCookieSource',
    '#HttpOnly_',
    'recipe_id_missing_substitution_controls_not_rendered',
    'sensitive_cost_keys_leaked',
    'costprice',
    'unitcost',
    'totalcost',
    'ingredientcostjson',
    'requires_authenticated_session_cookie',
] as $needle) {
    recipeManagementSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should expose smoke guard: ' . $needle);
}

foreach (['method\' => \'GET\'', 'CURLOPT_HTTPGET'] as $needle) {
    recipeManagementSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should only use GET requests: ' . $needle);
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'RecipeInventoryMovementService', 'RecipeAccountingService', 'SyncOutboxEventService'] as $needle) {
    recipeManagementSurfaceSmokeAssert(strpos($tool, $needle) === false, 'tool must stay read-only and avoid: ' . $needle);
}

recipeManagementSurfaceSmokeAssert(strpos($preflight, 'tools/recipe_management_surface_smoke.php') !== false, 'runtime preflight should require management surface smoke tool');
recipeManagementSurfaceSmokeAssert(strpos($pilotEvidence, 'tools/recipe_management_surface_smoke.php') !== false, 'pilot evidence service should accept management surface smoke evidence command');
recipeManagementSurfaceSmokeAssert(strpos($doc, 'tools/recipe_management_surface_smoke.php') !== false, 'rollout doc should document management surface smoke command');
recipeManagementSurfaceSmokeAssert(strpos($doc, 'modifier-substitution surface evidence') !== false, 'rollout doc should describe modifier-substitution surface evidence');
recipeManagementSurfaceSmokeAssert(strpos($doc, 'It does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, enqueue sync') !== false, 'rollout doc should document read-only scope');
recipeManagementSurfaceSmokeAssert(strpos($doc, 'records a fixture-selection warning') !== false, 'rollout doc should explain no selected recipe warning');

echo "recipe-management-surface-smoke-contract-ok\n";

function recipeManagementSurfaceSmokeSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeManagementSurfaceSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
