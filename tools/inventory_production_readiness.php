<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$options = getopt('', [
    'json',
    'browser-evidence:',
    'decisions-file:',
    'acceptance-file:',
    'allow-accepted-reconciliation',
    'rebuild-acceptance-file:',
    'allow-accepted-rebuild-differences',
    'accounting-acceptance-file:',
    'allow-accepted-accounting-reconciliation',
    'skip-accounting-gate',
    'help',
]);
if (isset($options['help'])) {
    inventoryProductionReadinessUsage();
    exit(0);
}

$checks = [];
$checks['legacy_retirement'] = inventoryProductionReadinessToolCheck(
    $root,
    'tools/inventory_legacy_retirement_check.php',
    static function (array $payload): bool {
        return !empty($payload['ok']) && !empty($payload['ready_to_delete_legacy_stock_core']);
    }
);
$checks['cutover'] = inventoryProductionReadinessToolCheck(
    $root,
    'tools/inventory_cutover_readiness.php',
    static function (array $payload): bool {
        return !empty($payload['ready_for_cutover'])
            && !empty($payload['ready_for_legacy_retirement'])
            && (string) ($payload['mode'] ?? '') === 'live';
    },
    inventoryProductionReadinessCutoverArgs($options)
);
$checks['operational_health'] = inventoryProductionReadinessToolCheck(
    $root,
    'tools/inventory_operational_health_check.php',
    static function (array $payload): bool {
        return !empty($payload['ok']);
    }
);
$checks['recipe_runtime'] = inventoryProductionReadinessToolCheck(
    $root,
    'tools/recipe_runtime_preflight.php',
    static function (array $payload): bool {
        return !empty($payload['ok']) && !empty($payload['ready_for_recipe_operator_qa']);
    }
);
$checks['browser_operator_qa'] = inventoryProductionReadinessBrowserEvidenceCheck((string) ($options['browser-evidence'] ?? ''));

$blockers = [];
foreach ($checks as $name => $check) {
    if (empty($check['ready'])) {
        $blockers[] = $name . '_not_ready';
    }
    foreach ($check['blockers'] ?? [] as $blocker) {
        $blockers[] = $name . ':' . (string) $blocker;
    }
}
$blockers = array_values(array_unique($blockers));

$result = [
    'production_ready' => empty($blockers),
    'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'scope' => [
        'ledger_and_balance_cutover',
        'legacy_stock_retirement',
        'operational_hardening',
        'recipe_runtime',
        'browser_operator_qa',
    ],
    'checks' => $checks,
    'blockers' => $blockers,
];

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    inventoryProductionReadinessPrint($result);
}

exit(!empty($result['production_ready']) ? 0 : 2);

function inventoryProductionReadinessUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/inventory_production_readiness.php [--browser-evidence=/absolute/path/to/evidence.json] [--decisions-file=/absolute/path/to/reviewed-decisions.json] [--acceptance-file=/absolute/path/to/accepted.json] [--allow-accepted-reconciliation] [--rebuild-acceptance-file=/absolute/path/to/accepted-rebuild.json] [--allow-accepted-rebuild-differences] [--accounting-acceptance-file=/absolute/path/to/accepted-accounting.json] [--allow-accepted-accounting-reconciliation] [--skip-accounting-gate] [--json]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Read-only aggregate gate for the Foodics-level inventory cutover. It combines existing readiness tools and requires explicit in-app browser/operator QA evidence before production readiness can be claimed.\n");
}

function inventoryProductionReadinessToolCheck(string $root, string $script, callable $readyWhen, array $extraArgs = []): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/' . $script);
    foreach ($extraArgs as $arg) {
        $command .= ' ' . escapeshellarg((string) $arg);
    }
    $command .= ' --json 2>&1';
    $outputLines = [];
    $exitCode = 0;
    exec($command, $outputLines, $exitCode);
    $output = trim(implode("\n", $outputLines));
    $payload = json_decode($output, true);

    if (!is_array($payload)) {
        return [
            'ready' => false,
            'command' => 'php ' . $script . ($extraArgs ? ' ' . implode(' ', $extraArgs) : '') . ' --json',
            'exit_code' => $exitCode,
            'blockers' => ['readiness_tool_returned_invalid_json'],
            'raw_output' => $output,
        ];
    }

    $blockers = inventoryProductionReadinessBlockers($payload);
    $ready = $readyWhen($payload);
    if (!$ready && !$blockers) {
        $blockers[] = 'readiness_tool_not_ready';
    }

    return [
        'ready' => $ready,
        'command' => 'php ' . $script . ($extraArgs ? ' ' . implode(' ', $extraArgs) : '') . ' --json',
        'exit_code' => $exitCode,
        'blockers' => $blockers,
        'summary' => inventoryProductionReadinessSummary($payload),
        'payload' => $payload,
    ];
}

function inventoryProductionReadinessCutoverArgs(array $options): array
{
    $args = [];
    foreach ([
        'decisions-file',
        'acceptance-file',
        'rebuild-acceptance-file',
        'accounting-acceptance-file',
    ] as $name) {
        $value = trim((string) ($options[$name] ?? ''));
        if ($value !== '') {
            $args[] = '--' . $name . '=' . $value;
        }
    }
    foreach ([
        'allow-accepted-reconciliation',
        'allow-accepted-rebuild-differences',
        'allow-accepted-accounting-reconciliation',
        'skip-accounting-gate',
    ] as $name) {
        if (isset($options[$name])) {
            $args[] = '--' . $name;
        }
    }

    return $args;
}

function inventoryProductionReadinessBlockers(array $payload): array
{
    $blockers = [];
    foreach (['blockers', 'legacy_retirement_blockers'] as $key) {
        foreach (($payload[$key] ?? []) as $blocker) {
            $blockers[] = (string) $blocker;
        }
    }

    if (!$blockers && !empty($payload['error'])) {
        $blockers[] = 'readiness_tool_error:' . (string) $payload['error'];
    }
    foreach (($payload['warnings'] ?? []) as $warning) {
        if ((string) $warning === 'inventory_ledger_mode_not_live_yet') {
            $blockers[] = 'inventory_ledger_mode_not_live_yet';
        }
    }

    return array_values(array_unique($blockers));
}

function inventoryProductionReadinessSummary(array $payload): array
{
    $summary = [];
    foreach ([
        'ok',
        'ready_for_cutover',
        'ready_for_legacy_retirement',
        'ready_to_delete_legacy_stock_core',
        'ready_for_recipe_operator_qa',
        'mode',
        'phase',
    ] as $key) {
        if (array_key_exists($key, $payload)) {
            $summary[$key] = $payload[$key];
        }
    }

    if (isset($payload['summary']) && is_array($payload['summary'])) {
        $summary['summary'] = $payload['summary'];
    }

    return $summary;
}

function inventoryProductionReadinessBrowserEvidenceCheck(string $path): array
{
    if ($path === '') {
        return [
            'ready' => false,
            'evidence_file' => null,
            'blockers' => ['browser_operator_qa_evidence_missing'],
            'summary' => [
                'required' => true,
                'expected_source' => 'Codex in-app browser smoke evidence',
            ],
        ];
    }

    if (!is_file($path) || !is_readable($path)) {
        return [
            'ready' => false,
            'evidence_file' => $path,
            'blockers' => ['browser_operator_qa_evidence_unreadable'],
        ];
    }

    $payload = json_decode((string) file_get_contents($path), true);
    if (!is_array($payload)) {
        return [
            'ready' => false,
            'evidence_file' => $path,
            'blockers' => ['browser_operator_qa_evidence_invalid_json'],
        ];
    }

    $ready = !empty($payload['ok']) && !empty($payload['inventory_operator_qa_passed']);
    return [
        'ready' => $ready,
        'evidence_file' => $path,
        'blockers' => $ready ? [] : ['browser_operator_qa_evidence_not_passing'],
        'summary' => [
            'ok' => !empty($payload['ok']),
            'inventory_operator_qa_passed' => !empty($payload['inventory_operator_qa_passed']),
            'checked_at_utc' => $payload['checked_at_utc'] ?? null,
            'pages' => $payload['pages'] ?? [],
        ],
        'payload' => $payload,
    ];
}

function inventoryProductionReadinessPrint(array $result): void
{
    fwrite(STDOUT, 'Inventory production readiness: ' . (!empty($result['production_ready']) ? 'READY' : 'BLOCKED') . PHP_EOL);
    foreach ($result['checks'] as $name => $check) {
        fwrite(STDOUT, '- ' . $name . ': ' . (!empty($check['ready']) ? 'READY' : 'BLOCKED') . PHP_EOL);
    }
    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }
}
