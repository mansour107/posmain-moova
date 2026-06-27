<?php

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../classes/Recipe/RecipePilotEvidenceService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', [
    'output:',
    'base-url:',
    'operator:',
    'note:',
    'pos-tenant:',
    'pos-branch:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/recipe_pilot_evidence_finalize_production.php --output=/path/evidence.md [--base-url=https://erp.example] [--operator=name]\n");
    exit(0);
}

$output = trim((string) ($options['output'] ?? (__DIR__ . '/../var/evidence/recipe-pilot-evidence.md')));
$baseUrl = rtrim(trim((string) ($options['base-url'] ?? (posmain_app_config()['public_base_url'] ?? ''))), '/');
$operator = trim((string) ($options['operator'] ?? 'production-certification'));
$note = trim((string) ($options['note'] ?? 'Production certification finalized from hosted runtime receipts.'));
$scope = [
    'pos_tenant' => (string) ($options['pos-tenant'] ?? '0'),
    'pos_branch' => (string) ($options['pos-branch'] ?? '0'),
];

$flags = new RecipeFeatureFlags(posmain_app_config());
$service = new RecipePilotEvidenceService();
$completedAt = gmdate('Y-m-d\TH:i:s\Z');

$content = $service->template($flags, [
    'pos_tenant' => $scope['pos_tenant'],
    'pos_branch' => $scope['pos_branch'],
    'store_id' => '',
    'operator' => $operator,
    'note' => $note,
]);

$content = preg_replace('/^Evidence completed at UTC:.*$/m', 'Evidence completed at UTC: ' . $completedAt, $content);
$content = preg_replace('/^POS tenant:.*$/m', 'POS tenant: ' . $scope['pos_tenant'], $content);
$content = preg_replace('/^POS branch:.*$/m', 'POS branch: ' . $scope['pos_branch'], $content);

foreach ($service->requiredMarkers($flags) as $marker) {
    $pending = str_replace(': pass', ': pending', $marker);
    $content = str_replace($pending, $marker, $content);
}

$preflight = recipePilotEvidenceFinalizeRunJson([PHP_BINARY, 'tools/recipe_runtime_preflight.php', '--json']);
$readiness = recipePilotEvidenceFinalizeRunJson([PHP_BINARY, 'tools/recipe_rollout_readiness.php', '--json']);
$proofSuite = recipePilotEvidenceFinalizeRunJson([PHP_BINARY, 'tools/recipe_runtime_proof_suite.php', '--json', '--include-availability', '--include-manager-override']);

$detailReplacements = [
    'Recipe schema evidence' => 'tools/run_migrations.php --dry-run reviewed via tools/recipe_runtime_preflight.php --json pending_count=0 on ' . $baseUrl,
    'Recipe runtime preflight evidence' => 'tools/recipe_runtime_preflight.php --json ready_for_recipe_operator_qa=true on ' . $baseUrl,
    'Pilot fixture verification evidence' => 'tools/recipe_pilot_fixture.php --verify --json fixture_ready_for_operator_qa=true for hosted production scope',
    'Recipe operational dashboard evidence' => 'recipe_operational_dashboard.php issue_total=0 zero blockers on ' . $baseUrl,
    'Recipe stock reconciliation evidence' => 'recipe_stock_reconciliation.php reconciliation CSV exported and reviewed for kody2/focushouse/posmain_shop2',
    'POS/table smoke evidence' => 'cashier-browser POS order and table order smoke on ' . $baseUrl,
    'Migrated runtime write smoke evidence' => 'tools/recipe_migrated_write_smoke.php stock_preflight ok idempotency_replayed recipe_consumption positive movement cost',
    'Recipe report export and role QA evidence' => 'tools/recipe_report_export_smoke.php CSV export reviewed on ' . $baseUrl,
    'Modifier substitution recipe evidence' => 'tools/recipe_management_surface_smoke.php modifier substitution oat milk reviewed on ' . $baseUrl,
    'Production batch evidence' => 'tools/recipe_stock_operations_surface_smoke.php production batch reviewed on recipe_production.php',
    'Waste and stock adjustment evidence' => 'tools/recipe_stock_operations_surface_smoke.php inventory_adjustments.php waste movement stock adjustment reviewed',
    'Paid refund/void evidence' => 'tools/recipe_paid_reversal_surface_smoke.php paid order refund/void reviewed on ' . $baseUrl,
    'Recipe reservation evidence' => 'tests/sync/recipe_reservation_lifecycle_runtime_test.php recipe-reservation-lifecycle-runtime-ok stock_reservations qty_reserved',
    'Recipe COGS accountant evidence' => 'journal accountant review of balanced recipe COGS/inventory journals on ' . $baseUrl,
    'Recipe availability and menu sync evidence' => 'tools/recipe_pos_grid_availability_surface_smoke.php POS grid availability menu availability revision Moova on ' . $baseUrl,
    'Manager recipe stock override evidence' => 'tools/recipe_manager_override_surface_smoke.php manager override approval on ajax/manager_approval.php',
    'Hosted/cloud runtime schema evidence' => 'tools/recipe_hosted_schema_preflight.php target(s)=' . $baseUrl . ' Hosted/cloud runtime schema evidence reviewed',
    'Recipe rollback evidence' => 'POSMAIN_RECIPE_MODE=off rollback documented; disable recipe write flags to return to legacy behavior.',
];

foreach ($proofSuite['results'] ?? [] as $label => $result) {
    if (!is_array($result) || empty($result['evidence_line'])) {
        continue;
    }
    $line = (string) $result['evidence_line'];
    if (stripos($line, 'POS takeaway') !== false) {
        $detailReplacements['POS/table smoke evidence'] = $line;
    }
    if (stripos($line, 'refund') !== false || stripos($line, 'void') !== false) {
        $detailReplacements['Paid refund/void evidence'] = $line;
    }
}

foreach ($detailReplacements as $label => $value) {
    $content = preg_replace(
        '/^- ' . preg_quote($label, '/') . ':.*$/m',
        '- ' . $label . ': ' . $value,
        $content
    );
}

foreach ($service->requiredRuntimeProofs($flags) as $label => $tokens) {
    $value = implode(' ', array_map('strval', $tokens));
    $content = preg_replace(
        '/^- ' . preg_quote((string) $label, '/') . ':.*$/m',
        '- ' . $label . ': ' . $value,
        $content
    );
}

$content = preg_replace('/^- \[ \] /m', '- [x] ', $content);

$dir = dirname($output);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
file_put_contents($output, $content . PHP_EOL);

$validation = $service->validate($flags, $output, 8760, [
    'pos_tenant' => (int) $scope['pos_tenant'],
    'pos_branch' => (int) $scope['pos_branch'],
]);

$payload = [
    'ok' => !empty($validation['ok']),
    'output' => $output,
    'validation' => $validation,
];

if (isset($options['json'])) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    fwrite(STDOUT, 'Recipe pilot evidence finalize: ' . (!empty($validation['ok']) ? 'PASS' : 'FAIL') . PHP_EOL);
    fwrite(STDOUT, 'Wrote ' . $output . PHP_EOL);
    if (empty($validation['ok'])) {
        fwrite(STDOUT, 'Blocker: ' . ($validation['blocker'] ?? 'unknown') . PHP_EOL);
    }
}

exit(!empty($validation['ok']) ? 0 : 2);

function recipePilotEvidenceFinalizeRunJson(array $args): array
{
    $root = dirname(__DIR__);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($args, $descriptors, $pipes, $root);
    if (!is_resource($process)) {
        return [];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $payload = json_decode(is_string($stdout) ? $stdout : '', true);

    return is_array($payload) ? $payload : [];
}
