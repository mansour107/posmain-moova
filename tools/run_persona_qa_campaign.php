#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/qa/QaCampaignSupport.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', [
    'help',
    'json',
    'provision',
    'teardown',
    'local-only',
    'skip-hosted',
    'skip-gui',
    'skip-sync',
    'skip-report',
    'skip-exploration',
    'continue-on-failure',
    'config::',
    'run-id::',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
Usage: php tools/run_persona_qa_campaign.php [options]

Full persona QA campaign orchestrator (local + hosted).

  --provision           Provision QA shops and write campaign config first
  --local-only          Skip hosted SSH steps
  --skip-hosted         Same as --local-only for tests
  --skip-gui            Non-GUI + sync only
  --skip-sync           Skip sync proof scripts
  --skip-report         Do not generate report
  --skip-exploration    Do not scaffold narrative prompt dirs
  --teardown            Drop/deactivate QA shops after run (uses config)
  --continue-on-failure Keep running after step failures
  --config=PATH         Campaign config file
  --run-id=ID           Override run id (must match config if set)
  --json                JSON summary

Phases:
  1. Provision + seed (optional --provision)
  2. Non-GUI persona tests (local + hosted SSH)
  3. Playwright GUI (local + hosted)
  4. Sync proofs
  5. Exploration narrative scaffolding
  6. Report generation (MD/PDF/DOCX)

TXT);
    exit(0);
}

$json = isset($options['json']);
$continue = isset($options['continue-on-failure']);
$configPath = isset($options['config']) ? (string) $options['config'] : QaCampaignSupport::localConfigPath();
$localOnly = isset($options['local-only']) || isset($options['skip-hosted']);

$summary = [
    'ok' => true,
    'started_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'steps' => [],
];

try {
    if (isset($options['provision'])) {
        $provisionArgs = ['--json'];
        if ($localOnly) {
            $provisionArgs[] = '--skip-hosted';
        }
        if (isset($options['run-id'])) {
            $provisionArgs[] = '--run-id=' . (string) $options['run-id'];
        }
        $provision = runPhpScript(QaCampaignSupport::repoRoot() . '/tools/qa/provision_qa_campaign_shop.php', $provisionArgs);
        $summary['steps']['provision'] = $provision;
        if (empty($provision['ok']) && !$continue) {
            throw new RuntimeException('Provision failed');
        }
    }

    $config = QaCampaignSupport::loadConfig($configPath);
    $runId = (string) ($options['run-id'] ?? ($config['run_id'] ?? ''));
    if ($runId === '') {
        throw new RuntimeException('run_id missing from campaign config');
    }

    $artifactDir = QaCampaignSupport::artifactDir($runId);
    $summary['run_id'] = $runId;
    $summary['artifact_dir'] = $artifactDir;
    $summary['config'] = [
        'local_base_url' => $config['local']['base_url'] ?? null,
        'hosted_base_url' => $config['hosted']['base_url'] ?? null,
    ];

    $local = $config['local'] ?? [];
    putenv('POSMAIN_ENV=test');
    putenv('POSMAIN_PRODUCTION_MODE=0');
    putenv('POSMAIN_QA_CAMPAIGN=1');
    putenv('POSMAIN_DB_HOST=' . ($local['mysql_host'] ?? '127.0.0.1'));
    putenv('POSMAIN_DB_PORT=' . (string) ($local['mysql_port'] ?? 3307));
    putenv('POSMAIN_DB_USER=' . ($local['mysql_user'] ?? 'root'));
    putenv('POSMAIN_DB_PASS=' . ($local['mysql_pass'] ?? ''));
    putenv('POSMAIN_DB_NAME=' . ($local['db_name'] ?? 'kody2'));

    $localNonGui = runPhpScript(QaCampaignSupport::repoRoot() . '/tools/run_persona_tests.php', [
        '--all',
        '--non-gui',
        '--json',
        '--continue-on-failure',
    ], [
        'POSMAIN_TEST_HTTP_BASE' => (string) ($local['base_url'] ?? 'http://127.0.0.1:8010'),
    ]);
    $localDir = $artifactDir . '/local';
    if (!is_dir($localDir)) {
        mkdir($localDir, 0777, true);
    }
    file_put_contents($localDir . '/non_gui.json', $localNonGui['stdout'] . PHP_EOL);
    $summary['steps']['local_non_gui'] = $localNonGui;
    if (empty($localNonGui['ok']) && !$continue) {
        throw new RuntimeException('Local non-GUI failed');
    }

    if (!$localOnly) {
        $hostedScript = QaCampaignSupport::repoRoot() . '/tools/qa/run_hosted_non_gui.sh';
        chmod($hostedScript, 0755);
        $hostedNonGui = runShell($hostedScript, ['POSMAIN_QA_CAMPAIGN_CONFIG' => $configPath]);
        $summary['steps']['hosted_non_gui'] = $hostedNonGui;
        if (empty($hostedNonGui['ok']) && !$continue) {
            throw new RuntimeException('Hosted non-GUI failed');
        }
    } else {
        $summary['steps']['hosted_non_gui'] = ['ok' => true, 'skipped' => true];
    }

    if (!isset($options['skip-gui'])) {
        $localGui = runPlaywright($runId, 'local', (string) ($local['base_url'] ?? 'http://127.0.0.1:8010'), $config);
        $summary['steps']['local_playwright'] = $localGui;
        if (empty($localGui['ok']) && !$continue) {
            throw new RuntimeException('Local Playwright failed');
        }

        if (!$localOnly) {
            $hostedUrl = (string) ($config['hosted']['base_url'] ?? '');
            if ($hostedUrl !== '') {
                $hostedGui = runPlaywright($runId, 'hosted', $hostedUrl, $config);
                $summary['steps']['hosted_playwright'] = $hostedGui;
            } else {
                $summary['steps']['hosted_playwright'] = ['ok' => true, 'skipped' => true];
            }
        }
    }

    if (!isset($options['skip-sync'])) {
        $sync = runPhpScript(QaCampaignSupport::repoRoot() . '/tools/qa/sync_campaign_proof.php', [
            '--json',
            '--config=' . $configPath,
            '--run-id=' . $runId,
        ]);
        $summary['steps']['sync_proof'] = $sync;
        if (empty($sync['ok']) && !$continue) {
            throw new RuntimeException('Sync proof failed');
        }

        $worker = runPhpScript(QaCampaignSupport::repoRoot() . '/tools/branch_worker_status.php', [
            '--json',
            '--fail-on-problems',
        ]);
        QaCampaignSupport::writeJsonArtifact($runId, 'branch_worker_status.json', json_decode($worker['stdout'], true) ?: ['raw' => $worker['stdout']]);
        $summary['steps']['branch_worker_status'] = $worker;
    }

    if (!isset($options['skip-exploration'])) {
        $summary['steps']['exploration_scaffold'] = scaffoldExplorationNarratives($runId, $config);
    }

    $campaignSummaryPath = QaCampaignSupport::writeJsonArtifact($runId, 'campaign_summary.json', $summary);

    if (!isset($options['skip-report'])) {
        $report = runPhpScript(QaCampaignSupport::repoRoot() . '/tools/generate_persona_qa_report.php', [
            '--json',
            '--run-id=' . $runId,
            '--config=' . $configPath,
        ]);
        $summary['steps']['report'] = $report;
        if (empty($report['ok']) && !$continue) {
            throw new RuntimeException('Report generation failed');
        }
    }

    if (isset($options['teardown'])) {
        $teardown = QaCampaignSupport::teardown($config);
        $summary['steps']['teardown'] = $teardown;
        if (empty($teardown['ok'])) {
            $summary['ok'] = false;
        }
    }

    $summary['finished_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    file_put_contents($campaignSummaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    emit($summary, $json);
    exit(!empty($summary['ok']) ? 0 : 2);
} catch (Throwable $exception) {
    $summary['ok'] = false;
    $summary['error'] = $exception->getMessage();
    emit($summary, $json);
    exit(2);
}

/**
 * @param list<string> $args
 * @param array<string,string> $env
 * @return array<string,mixed>
 */
function runPhpScript(string $script, array $args = [], array $env = []): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d display_startup_errors=0'
        . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }

    return runShell($cmd, $env);
}

/**
 * @param array<string,string> $env
 * @return array<string,mixed>
 */
function runShell(string $cmd, array $env = []): array
{
    $merged = array_merge($_ENV, $env);
    $prefix = '';
    foreach ($merged as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        $prefix .= $key . '=' . escapeshellarg((string) $value) . ' ';
    }

    $output = [];
    $code = 0;
    exec($prefix . $cmd . ' 2>/dev/null', $output, $code);
    $stdout = implode("\n", $output);
    $payload = json_decode(extractJsonPayload($stdout), true);

    return [
        'ok' => $code === 0,
        'exit_code' => $code,
        'stdout' => $stdout,
        'payload' => is_array($payload) ? $payload : null,
        'output_tail' => QaCampaignSupport::tailLines($output),
    ];
}

/**
 * @return array<string,mixed>
 */
function runPlaywright(string $runId, string $envName, string $baseUrl, array $config = []): array
{
    $artifactDir = QaCampaignSupport::artifactDir($runId) . '/' . $envName;
    if (!is_dir($artifactDir)) {
        mkdir($artifactDir, 0777, true);
    }

    $jsonReport = $artifactDir . '/playwright.json';
    $htmlReport = $artifactDir . '/playwright-report';
    $root = QaCampaignSupport::repoRoot();

    $envPrefix = 'POSMAIN_TEST_HTTP_BASE=' . escapeshellarg($baseUrl)
        . ' POSMAIN_QA_RUN_ID=' . escapeshellarg($runId)
        . ' POSMAIN_QA_ENV=' . escapeshellarg($envName)
        . ' POSMAIN_E2E_DEMO_PASSWORD=P6demo123!';

    if ($envName === 'hosted') {
        $slug = (string) ($config['hosted']['shop_slug'] ?? '');
        if ($slug !== '') {
            $envPrefix .= ' POSMAIN_E2E_USER_ADMIN=' . escapeshellarg('p6_admin@' . $slug)
                . ' POSMAIN_E2E_USER_MANAGER=' . escapeshellarg('p6_manager@' . $slug)
                . ' POSMAIN_E2E_USER_CASHIER=' . escapeshellarg('p6_cashier@' . $slug)
                . ' POSMAIN_E2E_USER_WAITER=' . escapeshellarg('p6_waiter@' . $slug);
        }
    }

    $cmd = 'cd ' . escapeshellarg($root)
        . ' && ' . $envPrefix
        . ' npx playwright test 2>&1';

    $output = [];
    $code = 0;
    exec($cmd, $output, $code);

    return [
        'ok' => $code === 0,
        'exit_code' => $code,
        'base_url' => $baseUrl,
        'json_report' => is_file($jsonReport) ? $jsonReport : null,
        'html_report' => is_dir($htmlReport) ? $htmlReport : null,
        'output_tail' => QaCampaignSupport::tailLines($output),
    ];
}

/**
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function scaffoldExplorationNarratives(string $runId, array $config): array
{
    $personas = ['shared', 'cashier', 'waiter', 'manager', 'owner', 'sync_ops'];
    $envs = ['local', 'hosted'];
    $promptsPath = QaCampaignSupport::repoRoot() . '/tests/qa/persona_exploration_prompts.md';
    $prompts = is_file($promptsPath) ? file_get_contents($promptsPath) : '';

    $created = [];
    foreach ($personas as $persona) {
        foreach ($envs as $env) {
            $dir = QaCampaignSupport::artifactDir($runId) . '/narratives/' . $persona;
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $path = $dir . '/' . $env . '.md';
            if (!is_file($path)) {
                $baseUrl = $env === 'local'
                    ? (string) ($config['local']['base_url'] ?? '')
                    : (string) ($config['hosted']['base_url'] ?? '');
                $content = <<<MD
# {$persona} — {$env}

> Scaffold for Phase 3 AI exploration. Replace this file with persona narrative after guided session.

- Environment URL: {$baseUrl}
- Prompts: tests/qa/persona_exploration_prompts.md
- Evidence: var/qa/{$runId}/{$env}/

## Arabic narrative (persona voice)

### ما الذي اختبرته

### ما أعجبني

### ما أزعجني

### أخطاء / مشاكوك فيها

### مقارنة بفودكس (كافيه متوسط)

### أولوياتي لهذا الدور

## English summary

### What I tested

### What worked well

### Pain points

### Suspected bugs (with log/screenshot refs)

### Foodics comparison (mid-scale café)

### Priority for this role

MD;
                file_put_contents($path, $content);
            }
            $created[] = $path;
        }
    }

    return ['ok' => true, 'files' => $created, 'prompts_bytes' => strlen($prompts)];
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

function extractJsonPayload(string $stdout): string
{
    $trimmed = trim($stdout);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        return $trimmed;
    }

    if (preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])\s*$/', $stdout, $matches)) {
        return $matches[1];
    }

    return $stdout;
}

/**
 * @param array<string,mixed> $payload
 */
function emit(array $payload, bool $json): void
{
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    fwrite(STDOUT, 'Persona QA campaign: ' . (!empty($payload['ok']) ? 'OK' : 'FAIL') . PHP_EOL);
    if (!empty($payload['run_id'])) {
        fwrite(STDOUT, 'Run ID: ' . $payload['run_id'] . PHP_EOL);
    }
    if (!empty($payload['artifact_dir'])) {
        fwrite(STDOUT, 'Artifacts: ' . $payload['artifact_dir'] . PHP_EOL);
    }
    if (!empty($payload['error'])) {
        fwrite(STDERR, 'Error: ' . $payload['error'] . PHP_EOL);
    }
}
