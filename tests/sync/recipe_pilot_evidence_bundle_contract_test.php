<?php

$root = dirname(__DIR__, 2);
$toolPath = $root . '/tools/recipe_pilot_evidence_bundle.php';
$tool = recipePilotEvidenceBundleSource($toolPath);
$preflight = recipePilotEvidenceBundleSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$readiness = recipePilotEvidenceBundleSource($root . '/classes/Recipe/RecipeRolloutReadinessService.php');
$doc = recipePilotEvidenceBundleSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($toolPath) . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipePilotEvidenceBundleAssert($helpCode === 0, 'evidence bundle help should exit cleanly');
recipePilotEvidenceBundleAssert(strpos($help, 'draft-only') !== false, 'help should describe draft-only behavior');
recipePilotEvidenceBundleAssert(strpos($help, 'not valid for rollout by itself') !== false, 'help should prevent treating generated evidence as final');
recipePilotEvidenceBundleAssert(strpos($help, 'pass markers, completed-at, browser/operator details, and checklist items remain pending') !== false, 'help should state final evidence remains pending');
recipePilotEvidenceBundleAssert(strpos($help, 'only fixed local commands') !== false, 'help should state the command allow-list model');
recipePilotEvidenceBundleAssert(strpos($help, 'write recipe rows') !== false, 'help should state recipe rows are not written');
recipePilotEvidenceBundleAssert(strpos($help, 'write stock') !== false, 'help should state stock is not written');
recipePilotEvidenceBundleAssert(strpos($help, 'post accounting') !== false, 'help should state accounting is not posted');
recipePilotEvidenceBundleAssert(strpos($help, 'enqueue sync') !== false, 'help should state sync is not enqueued');

recipePilotEvidenceBundleAssert(strpos($tool, "'draft_only' => true") !== false, 'bundle JSON should mark draft_only true');
recipePilotEvidenceBundleAssert(strpos($tool, "'valid_for_rollout' => false") !== false, 'bundle JSON should mark valid_for_rollout false');
recipePilotEvidenceBundleAssert(strpos($tool, "'operator_action_required' => true") !== false, 'bundle JSON should mark operator action required');
recipePilotEvidenceBundleAssert(strpos($tool, 'Recipe Pilot Evidence: pass') === false, 'bundle tool should not stamp the final pass marker');
recipePilotEvidenceBundleAssert(strpos($tool, 'Evidence completed at UTC') === false, 'bundle tool should not stamp a completed-at timestamp');
recipePilotEvidenceBundleAssert(strpos($tool, 'recipe_pilot_evidence_bundle_operator_items_pending') !== false, 'bundle should warn that operator evidence remains pending');
recipePilotEvidenceBundleAssert(strpos($tool, 'tools/recipe_runtime_preflight.php') !== false, 'bundle should run runtime preflight');
recipePilotEvidenceBundleAssert(strpos($tool, 'tools/recipe_pilot_fixture.php') !== false, 'bundle should run pilot fixture verify');
recipePilotEvidenceBundleAssert(strpos($tool, 'tools/recipe_rollout_readiness.php') !== false, 'bundle should run rollout readiness');
recipePilotEvidenceBundleAssert(strpos($tool, 'tools/recipe_runtime_proof_suite.php') !== false, 'bundle should run the proof suite');
recipePilotEvidenceBundleAssert(strpos($tool, 'recipePilotEvidenceBundleScopeArgs') !== false, 'bundle should pass requested POS scope to scoped child commands');
recipePilotEvidenceBundleAssert(strpos($tool, 'recipePilotEvidenceBundleCommandAcceptable') !== false, 'bundle should distinguish expected active-mode pilot-evidence blockers from real command failures');
recipePilotEvidenceBundleAssert(strpos($tool, 'recipe_pilot_evidence_file_not_provided') !== false, 'bundle should tolerate only the expected missing final evidence blocker from active-mode readiness');
recipePilotEvidenceBundleAssert(strpos($tool, "!array_key_exists('force', \$options)") !== false, 'bundle should treat present valueless --force from getopt as enabled');
recipePilotEvidenceBundleAssert(strpos($tool, '$prefix = $label . \': \'') !== false, 'bundle should strip duplicated proof labels from embedded evidence lines');
recipePilotEvidenceBundleAssert(strpos($tool, 'proc_open') !== false, 'bundle should use process execution for fixed PHP commands');
recipePilotEvidenceBundleAssert(strpos($tool, '], $pipes, $root, null)') !== false, 'bundle should inherit runtime environment variables for child commands');

foreach (['shell_exec', 'passthru', 'system('] as $unsafeNeedle) {
    recipePilotEvidenceBundleAssert(strpos($tool, $unsafeNeedle) === false, 'bundle must not execute arbitrary shell commands internally: ' . $unsafeNeedle);
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'RecipeInventoryMovementService', 'RecipeAccountingService', 'RecipeReservationService'] as $writeNeedle) {
    recipePilotEvidenceBundleAssert(strpos($tool, $writeNeedle) === false, 'bundle must not touch runtime write surface: ' . $writeNeedle);
}

recipePilotEvidenceBundleAssert(strpos($preflight, 'tools/recipe_pilot_evidence_bundle.php') !== false, 'runtime preflight should require the evidence bundle tool');
recipePilotEvidenceBundleAssert(strpos($readiness, 'recipe_pilot_evidence_bundle.php --json') !== false, 'readiness should expose evidence bundle command guidance');
recipePilotEvidenceBundleAssert(strpos($readiness, 'not valid for rollout until browser/operator action lines are completed and validated') !== false, 'readiness should label bundle output as non-final');

foreach ([
    'tools/recipe_pilot_evidence_bundle.php --json',
    'draft-only',
    'not valid for rollout by itself',
    'valid for rollout',
    'tools/recipe_runtime_preflight.php --json',
    'tools/recipe_pilot_fixture.php --verify --json',
    'tools/recipe_rollout_readiness.php --json',
    'tools/recipe_runtime_proof_suite.php --json',
    'POSMAIN_DB_PORT',
    'write recipe rows',
    'tools/recipe_pilot_evidence.php --validate',
] as $needle) {
    recipePilotEvidenceBundleAssert(strpos($doc, $needle) !== false, 'rollout doc missing evidence bundle guidance: ' . $needle);
}

echo "recipe-pilot-evidence-bundle-contract-ok\n";

function recipePilotEvidenceBundleSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipePilotEvidenceBundleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
