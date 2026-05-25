<?php

$root = dirname(__DIR__, 2);
$toolPath = $root . '/tools/recipe_hosted_schema_preflight.php';
$tool = recipeHostedSchemaPreflightSource($toolPath);
$preflight = recipeHostedSchemaPreflightSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$doc = recipeHostedSchemaPreflightSource($root . '/docs/recipe/rollout_readiness.md');
$pilotEvidence = recipeHostedSchemaPreflightSource($root . '/classes/Recipe/RecipePilotEvidenceService.php');

exec('php ' . escapeshellarg($toolPath) . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipeHostedSchemaPreflightAssert($helpCode === 0, 'hosted schema preflight help should exit cleanly');
recipeHostedSchemaPreflightAssert(strpos($help, 'hosted or single-domain routed shop databases') !== false, 'help should describe hosted/router purpose');
recipeHostedSchemaPreflightAssert(strpos($help, 'read-only') !== false, 'help should state read-only behavior');
recipeHostedSchemaPreflightAssert(strpos($help, 'does not install router tables') !== false, 'help should state router tables are not installed');

foreach ([
    'RecipeRuntimePreflightService',
    'PosmainShopRouter',
    'posmain_router_enabled',
    'router_shops',
    'connectShopFromRoute',
    'ready_for_hosted_recipe_schema',
    'recipe_hosted_schema_router_query_failed',
    'Hosted/cloud runtime schema evidence',
    'hosted_schema_evidence_line',
] as $needle) {
    recipeHostedSchemaPreflightAssert(strpos($tool, $needle) !== false, 'hosted schema preflight should expose: ' . $needle);
}

foreach ([
    'validateShopConnection',
    '->install(',
    'run_migrations.php --apply',
    '->apply(',
    'INSERT INTO',
    'UPDATE router_shops',
    'DELETE FROM',
    'RecipeInventoryMovementService',
    'RecipeAccountingService',
    'SyncOutboxEventService',
] as $needle) {
    recipeHostedSchemaPreflightAssert(strpos($tool, $needle) === false, 'hosted schema preflight must stay read-only and avoid: ' . $needle);
}

recipeHostedSchemaPreflightAssert(strpos($preflight, 'tools/recipe_hosted_schema_preflight.php') !== false, 'runtime preflight should require hosted schema preflight tool');
recipeHostedSchemaPreflightAssert(strpos($doc, 'tools/recipe_hosted_schema_preflight.php --json') !== false, 'rollout doc should document hosted schema command');
recipeHostedSchemaPreflightAssert(strpos($doc, 'does not install router tables, validate shops, apply migrations') !== false, 'rollout doc should document read-only hosted schema scope');
recipeHostedSchemaPreflightAssert(strpos($doc, 'tests/sync/recipe_hosted_schema_preflight_router_runtime_test.php') !== false, 'rollout doc should document routed shop runtime proof');
recipeHostedSchemaPreflightAssert(strpos($pilotEvidence, 'Hosted/cloud runtime schema evidence') !== false, 'pilot evidence should require hosted schema detail for hosted/router rollout');

echo "recipe-hosted-schema-preflight-contract-ok\n";

function recipeHostedSchemaPreflightSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeHostedSchemaPreflightAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
