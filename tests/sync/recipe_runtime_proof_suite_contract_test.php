<?php

$root = dirname(__DIR__, 2);
$toolPath = $root . '/tools/recipe_runtime_proof_suite.php';
$tool = recipeRuntimeProofSuiteSource($toolPath);
$preflight = recipeRuntimeProofSuiteSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$pilotEvidence = recipeRuntimeProofSuiteSource($root . '/classes/Recipe/RecipePilotEvidenceService.php');
$doc = recipeRuntimeProofSuiteSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($toolPath) . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);
recipeRuntimeProofSuiteAssert($helpCode === 0, 'proof suite help should exit cleanly');
recipeRuntimeProofSuiteAssert(strpos($help, '--include-availability') !== false, 'proof suite help should document availability option');
recipeRuntimeProofSuiteAssert(strpos($help, '--include-manager-override') !== false, 'proof suite help should document manager override option');
recipeRuntimeProofSuiteAssert(strpos($help, '--include-moova-sync') !== false, 'proof suite help should document Moova sync option');
recipeRuntimeProofSuiteAssert(strpos($help, 'does not accept arbitrary commands') !== false, 'proof suite help should state fixed command list');
recipeRuntimeProofSuiteAssert(strpos($help, 'temporary test database') !== false, 'proof suite help should describe isolated temporary DB behavior');

exec('php ' . escapeshellarg($toolPath) . ' --list --all --json', $jsonLines, $jsonCode);
$payload = json_decode(implode("\n", $jsonLines), true);
recipeRuntimeProofSuiteAssert($jsonCode === 0, 'proof suite list should exit cleanly');
recipeRuntimeProofSuiteAssert(is_array($payload), 'proof suite list should emit JSON');
foreach ([
    'Recipe reservation lifecycle runtime proof',
    'Isolated cashier browser fixture smoke proof',
    'Modifier substitution management endpoint runtime proof',
    'Production endpoint runtime proof',
    'Waste and stock adjustment endpoint runtime proof',
    'Paid refund/void endpoint runtime proof',
    'POS grid availability endpoint runtime proof',
    'Manager recipe stock override endpoint runtime proof',
    'Moova menu sync payload endpoint runtime proof',
    'Moova/Cofe replay runtime proof',
    'Legacy Cofe endpoint runtime proof',
] as $label) {
    recipeRuntimeProofSuiteAssert(isset($payload['proofs'][$label]), 'proof suite should list proof: ' . $label);
}

foreach ([
    'recipe_reservation_lifecycle_runtime_test.php',
    'recipe_cashier_browser_fixture_smoke_test.php',
    'recipe_modifier_substitution_management_endpoint_runtime_test.php',
    'recipe_production_endpoint_runtime_test.php',
    'recipe_waste_adjustment_endpoint_runtime_test.php',
    'recipe_paid_reversal_endpoint_runtime_test.php',
    'recipe_pos_grid_availability_endpoint_runtime_test.php',
    'recipe_manager_override_endpoint_runtime_test.php',
    'recipe_moova_menu_sync_payload_endpoint_runtime_test.php',
    'recipe_moova_replay_runtime_test.php',
    'recipe_cofe_create_order_endpoint_runtime_test.php',
] as $script) {
    recipeRuntimeProofSuiteAssert(strpos($tool, $script) !== false || strpos($pilotEvidence, $script) !== false, 'proof suite stack should know proof script: ' . $script);
}

recipeRuntimeProofSuiteAssert(strpos($tool, 'proc_open') !== false, 'proof suite should run fixed child PHP processes');
recipeRuntimeProofSuiteAssert(strpos($tool, 'PHP_BINARY') !== false, 'proof suite should use current PHP binary');
recipeRuntimeProofSuiteAssert(strpos($tool, 'preg_match') !== false && strpos($tool, 'tests\\/sync\\/') !== false, 'proof suite should restrict proof script paths');
recipeRuntimeProofSuiteAssert(strpos($tool, 'posmain_db_connect') === false, 'proof suite tool should not connect to the runtime DB itself');
recipeRuntimeProofSuiteAssert(strpos($tool, 'db_bootstrap') === false, 'proof suite tool should not load DB bootstrap');
foreach (['shell_exec', 'passthru', 'system(', 'run_migrations.php --apply', 'RecipeInventoryMovementService', 'RecipeAccountingService'] as $forbidden) {
    recipeRuntimeProofSuiteAssert(strpos($tool, $forbidden) === false, 'proof suite should not contain unsafe/live write surface: ' . $forbidden);
}

recipeRuntimeProofSuiteAssert(strpos($preflight, 'recipe_runtime_proof_suite.php') !== false, 'runtime preflight should require proof suite tool presence');
recipeRuntimeProofSuiteAssert(strpos($doc, 'tools/recipe_runtime_proof_suite.php --json') !== false, 'rollout doc should document proof suite JSON command');
recipeRuntimeProofSuiteAssert(strpos($doc, 'tools/recipe_runtime_proof_suite.php --all') !== false, 'rollout doc should document all-proof evidence command');
recipeRuntimeProofSuiteAssert(strpos($doc, 'does not accept arbitrary commands') !== false, 'rollout doc should describe fixed proof suite command list');

echo "recipe-runtime-proof-suite-contract-ok\n";

function recipeRuntimeProofSuiteSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeRuntimeProofSuiteAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
