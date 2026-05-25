<?php

$root = dirname(__DIR__, 2);
$toolPath = $root . '/tools/recipe_pilot_fixture.php';
$tool = recipePilotFixtureSource($toolPath);
$service = recipePilotFixtureSource($root . '/classes/Recipe/RecipePilotFixtureService.php');
$preflight = recipePilotFixtureSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$doc = recipePilotFixtureSource($root . '/docs/recipe/rollout_readiness.md');
$discovery = recipePilotFixtureSource($root . '/docs/recipe/implementation_discovery.md');

exec('php ' . escapeshellarg($toolPath) . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipePilotFixtureAssert($helpCode === 0, 'pilot fixture help should exit cleanly');
recipePilotFixtureAssert(strpos($help, 'Dry-run is the default') !== false, 'help should state dry-run default');
recipePilotFixtureAssert(strpos($help, '--apply') !== false, 'help should document explicit apply');
recipePilotFixtureAssert(strpos($help, '--verify') !== false, 'help should document read-only verify');
recipePilotFixtureAssert(strpos($help, 'local or staging QA database') !== false, 'help should scope fixture to QA databases');
recipePilotFixtureAssert(strpos($help, 'refuses POSMAIN_ENV=production/prod') !== false, 'help should document production refusal');
recipePilotFixtureAssert(strpos($help, '--allow-hosted-staging') !== false, 'help should document hosted staging override');
recipePilotFixtureAssert(strpos($help, 'read-only fixture completeness check') !== false, 'help should describe verify as read-only');
recipePilotFixtureAssert(strpos($help, 'does not apply migrations') !== false, 'help should state no migrations');
recipePilotFixtureAssert(strpos($help, 'post accounting journals') !== false, 'help should state no accounting');
recipePilotFixtureAssert(strpos($help, 'enqueue sync rows') !== false, 'help should state no sync enqueue');
recipePilotFixtureAssert(strpos($help, 'one draft production batch') !== false, 'help should document selected production batch fixture');

exec('POSMAIN_ENV=production php ' . escapeshellarg($toolPath) . ' --apply --json', $productionLines, $productionCode);
$productionPayload = json_decode(implode("\n", $productionLines), true);
recipePilotFixtureAssert($productionCode === 2, 'pilot fixture apply should fail in production environment before DB connect');
recipePilotFixtureAssert(is_array($productionPayload), 'production refusal should emit JSON');
recipePilotFixtureAssert(in_array('recipe_pilot_fixture_refuses_production_runtime', $productionPayload['blockers'] ?? [], true), 'production refusal should use explicit blocker');

exec('POSMAIN_ROLE=cloud php ' . escapeshellarg($toolPath) . ' --apply --json', $hostedLines, $hostedCode);
$hostedPayload = json_decode(implode("\n", $hostedLines), true);
recipePilotFixtureAssert($hostedCode === 2, 'pilot fixture apply should fail in hosted/cloud shape without explicit staging override');
recipePilotFixtureAssert(is_array($hostedPayload), 'hosted refusal should emit JSON');
recipePilotFixtureAssert(in_array('recipe_pilot_fixture_hosted_staging_requires_explicit_allow', $hostedPayload['blockers'] ?? [], true), 'hosted refusal should use explicit blocker');

recipePilotFixtureAssert(strpos($tool, 'RecipePilotFixtureService') !== false, 'tool should delegate to fixture service');
recipePilotFixtureAssert(strpos($tool, "'apply' => isset(\$options['apply'])") !== false, 'tool should require explicit --apply for writes');
recipePilotFixtureAssert(strpos($tool, "'verify' => isset(\$options['verify'])") !== false, 'tool should parse read-only --verify');
recipePilotFixtureAssert(strpos($tool, '->verify($conn, $toolOptions)') !== false, 'tool should delegate verify mode to service');
recipePilotFixtureAssert(strpos($tool, 'recipePilotFixtureSafetyCheck') !== false, 'tool should run safety check before DB apply');
recipePilotFixtureAssert(strpos($tool, 'recipe_pilot_fixture_refuses_production_runtime') !== false, 'tool should refuse production apply');
recipePilotFixtureAssert(strpos($tool, 'recipe_pilot_fixture_hosted_staging_requires_explicit_allow') !== false, 'tool should require hosted staging override');
recipePilotFixtureAssert(strpos($tool, 'posmain_db_connect') > strpos($tool, 'recipePilotFixtureSafetyCheck'), 'tool should perform safety check before opening DB connection');
recipePilotFixtureAssert(strpos($service, 'recipe_cost_snapshots') !== false, 'service should seed cost snapshots for active fixture recipes');
recipePilotFixtureAssert(strpos($service, 'recipe_availability_cache') !== false, 'service should seed availability cache for menu/Moova QA');
recipePilotFixtureAssert(strpos($service, 'opening_balance') !== false, 'service should create opening balance ledger rows for fixture stock');
recipePilotFixtureAssert(strpos($service, 'fixtureDraftRecipes') !== false, 'service should seed draft recipe for selected recipe editor QA');
recipePilotFixtureAssert(strpos($service, 'ensureDraftProductionBatch') !== false, 'service should seed draft production batch for selected production UI QA');
recipePilotFixtureAssert(strpos($service, 'substitution_remove') !== false, 'service should include modifier substitution remove fixture');
recipePilotFixtureAssert(strpos($service, 'substitution_add') !== false, 'service should include modifier substitution add fixture');
recipePilotFixtureAssert(strpos($service, 'item_modifier_groups') !== false, 'service should link modifier group to fixture sellable item');
recipePilotFixtureAssert(strpos($service, 'POSMAIN_RECIPE_PILOT_ITEM_IDS') !== false, 'service should report pilot item id env suggestion');
recipePilotFixtureAssert(strpos($service, 'RecipeDecimal::multiply') !== false, 'service should use decimal-safe cost multiplication');
recipePilotFixtureAssert(strpos($service, 'RecipeDecimal::compare') !== false, 'service should use decimal-safe quantity checks');
recipePilotFixtureAssert(strpos($service, 'fixture_ready_for_operator_qa') !== false, 'service should expose fixture QA readiness in verify mode');
recipePilotFixtureAssert(strpos($service, 'expected_counts') !== false, 'service should report expected fixture completeness counts');
recipePilotFixtureAssert(strpos($service, 'draft_production_batches') !== false, 'service should verify draft production batch completeness');
recipePilotFixtureAssert(strpos($service, 'read_only') !== false, 'service should mark verify results read-only');
recipePilotFixtureAssert(strpos($service, 'customer orders') !== false, 'service should document no order writes in JSON result');
recipePilotFixtureAssert(strpos($service, 'accounting journals') !== false, 'service should document no accounting writes in JSON result');
recipePilotFixtureAssert(strpos($service, 'sync outbox rows') !== false, 'service should document no sync outbox writes in JSON result');

foreach (['run_migrations.php --apply', 'POSMAIN_RECIPE_MODE=', 'journal_entries', 'sync_outbox', 'ot_head', 'fat_details'] as $forbidden) {
    recipePilotFixtureAssert(strpos($tool, $forbidden) === false, 'tool should not contain live rollout write surface: ' . $forbidden);
}
foreach (['(float)', 'number_format('] as $forbiddenDecimalNeedle) {
    recipePilotFixtureAssert(strpos($service, $forbiddenDecimalNeedle) === false, 'service should not use float math for persisted recipe fixture values: ' . $forbiddenDecimalNeedle);
}

recipePilotFixtureAssert(strpos($preflight, 'recipe_pilot_fixture.php') !== false, 'runtime preflight should require pilot fixture tool presence');
recipePilotFixtureAssert(strpos($doc, 'tools/recipe_pilot_fixture.php --json') !== false, 'rollout doc should document dry-run fixture command');
recipePilotFixtureAssert(strpos($doc, 'tools/recipe_pilot_fixture.php --apply --json') !== false, 'rollout doc should document apply fixture command');
recipePilotFixtureAssert(strpos($doc, 'tools/recipe_pilot_fixture.php --verify --json') !== false, 'rollout doc should document verify fixture command');
recipePilotFixtureAssert(strpos($doc, '--allow-hosted-staging') !== false, 'rollout doc should document hosted staging fixture override');
recipePilotFixtureAssert(strpos($doc, 'POSMAIN_RECIPE_PILOT_ITEM_IDS') !== false, 'rollout doc should mention pilot item id suggestion');
recipePilotFixtureAssert(strpos($discovery, 'guarded pilot fixture tool') !== false, 'discovery doc should record fixture tool status');
recipePilotFixtureAssert(strpos($discovery, '--verify') !== false, 'discovery doc should record read-only fixture verification');

echo "recipe-pilot-fixture-contract-ok\n";

function recipePilotFixtureSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipePilotFixtureAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
