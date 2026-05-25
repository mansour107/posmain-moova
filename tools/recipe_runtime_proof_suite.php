<?php

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../classes/Recipe/RecipePilotEvidenceService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'json',
    'help',
    'list',
    'all',
    'include-availability',
    'include-manager-override',
    'include-moova-sync',
    'continue-on-failure',
]);

if (isset($options['help'])) {
    recipeRuntimeProofSuiteUsage();
    exit(0);
}

$root = dirname(__DIR__);
$flags = new RecipeFeatureFlags(posmain_app_config());
$service = new RecipePilotEvidenceService();
$proofs = $service->requiredRuntimeProofs($flags);

if (
    isset($options['all'])
    || isset($options['include-availability'])
    || isset($options['include-manager-override'])
    || isset($options['include-moova-sync'])
) {
    if (isset($options['all'])) {
        $proofs = recipeRuntimeProofSuiteMergeProofs($proofs, $service->requiredRuntimeProofs(recipeRuntimeProofSuiteFlags([
            'mode' => 'reserve_only',
            'reservations' => true,
            'consumption' => false,
        ])));
    }
    $proofs = recipeRuntimeProofSuiteMergeProofs($proofs, $service->requiredRuntimeProofs(recipeRuntimeProofSuiteFlags([
        'availability' => true,
        'allow_negative_stock_with_approval' => isset($options['all']) || isset($options['include-manager-override']),
        'moova_sync' => isset($options['all']) || isset($options['include-moova-sync']),
    ])));
}

if (isset($options['list'])) {
    $result = [
        'ok' => true,
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'mode' => $flags->mode(),
        'proofs' => $proofs,
    ];
} else {
    $result = recipeRuntimeProofSuiteRun($root, $flags, $proofs, isset($options['continue-on-failure']));
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    recipeRuntimeProofSuitePrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function recipeRuntimeProofSuiteUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_runtime_proof_suite.php [--json] [--list] [--all] [--include-availability] [--include-manager-override] [--include-moova-sync] [--continue-on-failure]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Runs the fixed isolated recipe runtime proof scripts required by pilot evidence.\n");
    fwrite(STDOUT, "The suite emits paste-ready evidence lines for tools/recipe_pilot_evidence.php templates.\n");
    fwrite(STDOUT, "It does not accept arbitrary commands, apply migrations, change flags, use live orders, post accounting, or enqueue sync.\n");
    fwrite(STDOUT, "Each proof script creates and drops its own temporary test database and keeps recipe behavior feature-scoped.\n");
}

function recipeRuntimeProofSuiteFlags(array $recipeOverrides): RecipeFeatureFlags
{
    return new RecipeFeatureFlags([
        'recipe' => array_replace_recursive([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'consumption' => true,
            'availability' => false,
            'moova_sync' => false,
        ], $recipeOverrides),
    ]);
}

function recipeRuntimeProofSuiteMergeProofs(array $base, array $extra): array
{
    foreach ($extra as $label => $proof) {
        $base[$label] = $proof;
    }

    return $base;
}

function recipeRuntimeProofSuiteRun(string $root, RecipeFeatureFlags $flags, array $proofs, bool $continueOnFailure): array
{
    $results = [];
    $blockers = [];
    $evidenceLines = [];

    foreach ($proofs as $label => $tokens) {
        $proof = recipeRuntimeProofSuiteRunOne($root, (string) $label, $tokens);
        $results[(string) $label] = $proof;
        if (!empty($proof['ok'])) {
            $evidenceLines[] = (string) $proof['evidence_line'];
            continue;
        }

        $blockers[] = 'recipe_runtime_proof_failed_' . recipeRuntimeProofSuiteSlug((string) $label);
        if (!$continueOnFailure) {
            break;
        }
    }

    return [
        'ok' => $blockers === [],
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'mode' => $flags->mode(),
        'proof_count' => count($proofs),
        'passed_count' => count($evidenceLines),
        'results' => $results,
        'evidence_lines' => $evidenceLines,
        'blockers' => $blockers,
    ];
}

function recipeRuntimeProofSuiteRunOne(string $root, string $label, array $tokens): array
{
    $script = trim((string) ($tokens[0] ?? ''));
    $successMarker = trim((string) ($tokens[1] ?? ''));
    if ($script === '' || $successMarker === '') {
        return [
            'ok' => false,
            'label' => $label,
            'error' => 'invalid_proof_definition',
            'message' => 'Proof definition is missing script or success marker.',
        ];
    }

    if (!preg_match('/^tests\/sync\/[A-Za-z0-9_\/.-]+\.php$/', $script)) {
        return [
            'ok' => false,
            'label' => $label,
            'script' => $script,
            'error' => 'invalid_proof_script_path',
            'message' => 'Proof script path is outside the fixed tests/sync allow-list.',
        ];
    }

    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $script);
    if (!is_file($path)) {
        return [
            'ok' => false,
            'label' => $label,
            'script' => $script,
            'error' => 'proof_script_missing',
            'message' => 'Proof script file is missing.',
        ];
    }

    $command = [PHP_BINARY, $script];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root, $_ENV);
    if (!is_resource($process)) {
        return [
            'ok' => false,
            'label' => $label,
            'script' => $script,
            'error' => 'proof_process_start_failed',
            'message' => 'Unable to start proof process.',
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $stdout = is_string($stdout) ? trim($stdout) : '';
    $stderr = is_string($stderr) ? trim($stderr) : '';
    $ok = $exitCode === 0 && strpos($stdout, $successMarker) !== false;

    return [
        'ok' => $ok,
        'label' => $label,
        'script' => $script,
        'command' => 'php ' . $script,
        'success_marker' => $successMarker,
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'evidence_line' => $ok ? $label . ': php ' . $script . ' -> ' . $successMarker : '',
    ];
}

function recipeRuntimeProofSuiteSlug(string $label): string
{
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $label) ?: '');
    $slug = trim($slug, '_');

    return $slug !== '' ? $slug : 'unknown';
}

function recipeRuntimeProofSuitePrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe runtime proof suite: ' . (!empty($result['ok']) ? 'PASS' : 'FAIL') . PHP_EOL);
    fwrite(STDOUT, '- mode: ' . (string) ($result['mode'] ?? 'unknown') . PHP_EOL);

    if (!empty($result['proofs']) && is_array($result['proofs'])) {
        fwrite(STDOUT, "- proofs:\n");
        foreach ($result['proofs'] as $label => $tokens) {
            fwrite(STDOUT, '  - ' . (string) $label . ': php ' . (string) ($tokens[0] ?? '') . PHP_EOL);
        }
        return;
    }

    fwrite(STDOUT, '- passed: ' . (int) ($result['passed_count'] ?? 0) . '/' . (int) ($result['proof_count'] ?? 0) . PHP_EOL);
    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }
    if (!empty($result['evidence_lines'])) {
        fwrite(STDOUT, "- evidence lines:\n");
        foreach ($result['evidence_lines'] as $line) {
            fwrite(STDOUT, '  - ' . (string) $line . PHP_EOL);
        }
    }
}
