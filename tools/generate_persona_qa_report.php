#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/qa/QaCampaignSupport.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', ['help', 'json', 'run-id::', 'config::', 'no-export']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/generate_persona_qa_report.php [--run-id=ID] [--config=PATH] [--no-export] [--json]\n");
    exit(0);
}

$json = isset($options['json']);
$configPath = isset($options['config']) ? (string) $options['config'] : QaCampaignSupport::localConfigPath();

try {
    $config = QaCampaignSupport::loadConfig($configPath);
} catch (Throwable $exception) {
    emit(['ok' => false, 'error' => $exception->getMessage()], $json);
    exit(2);
}

$runId = (string) ($options['run-id'] ?? ($config['run_id'] ?? ''));
if ($runId === '') {
    emit(['ok' => false, 'error' => 'run_id required'], $json);
    exit(2);
}

$artifactDir = QaCampaignSupport::artifactDir($runId);
$reportDir = $artifactDir . '/report';
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0777, true);
}

$localNonGui = loadJsonFile($artifactDir . '/local/non_gui.json');
$hostedNonGui = loadJsonFile($artifactDir . '/hosted/non_gui.json');
$campaignSummary = loadJsonFile($artifactDir . '/campaign_summary.json');
$syncProof = loadJsonFile($artifactDir . '/sync_proof.json');
$localPw = loadJsonFile($artifactDir . '/local/playwright.json');
$hostedPw = loadJsonFile($artifactDir . '/hosted/playwright.json');

$matrix = buildResultsMatrix($localNonGui, $hostedNonGui, $localPw, $hostedPw);
$roadmap = extractRoadmap($matrix, $artifactDir);

$md = buildReportMarkdown($config, $runId, $matrix, $roadmap, $campaignSummary, $syncProof, $artifactDir);
$mdPath = $reportDir . '/report.md';
file_put_contents($mdPath, $md);

$exports = ['markdown' => $mdPath];
if (!isset($options['no-export'])) {
    $exports = array_merge($exports, exportPandoc($reportDir, $runId, $mdPath));
}

$result = [
    'ok' => true,
    'run_id' => $runId,
    'report_md' => $mdPath,
    'exports' => $exports,
    'matrix' => $matrix,
    'roadmap' => $roadmap,
];

QaCampaignSupport::writeJsonArtifact($runId, 'report/report_meta.json', $result);
emit($result, $json);
exit(0);

/**
 * @return array<string,mixed>|null
 */
function loadJsonFile(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = (string) file_get_contents($path);
    if (preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])\s*$/', $raw, $matches)) {
        $raw = $matches[1];
    }
    $payload = json_decode($raw, true);

    return is_array($payload) ? $payload : null;
}

/**
 * @param array<string,mixed>|null $localNonGui
 * @param array<string,mixed>|null $hostedNonGui
 * @param array<string,mixed>|null $localPw
 * @param array<string,mixed>|null $hostedPw
 * @return array<string,mixed>
 */
function buildResultsMatrix(?array $localNonGui, ?array $hostedNonGui, ?array $localPw, ?array $hostedPw): array
{
    $personas = ['shared', 'cashier', 'waiter', 'manager', 'owner', 'sync_ops'];
    $matrix = [];

    foreach ($personas as $persona) {
        $matrix[$persona] = [
            'local' => [
                'non_gui' => summarizePersonaNonGui($localNonGui, $persona),
                'gui' => summarizePlaywrightPersona($localPw, $persona),
            ],
            'hosted' => [
                'non_gui' => summarizePersonaNonGui($hostedNonGui, $persona),
                'gui' => summarizePlaywrightPersona($hostedPw, $persona),
            ],
        ];
    }

    return $matrix;
}

/**
 * @param array<string,mixed>|null $payload
 * @return array<string,mixed>
 */
function summarizePersonaNonGui(?array $payload, string $persona): array
{
    if ($payload === null) {
        return ['status' => 'missing', 'passed' => 0, 'failed' => 0, 'skipped' => 0];
    }

    $rows = $payload['non_gui'][$persona] ?? [];
    $passed = 0;
    $failed = 0;
    $skipped = 0;
    $failures = [];

    foreach ($rows as $row) {
        if (!empty($row['skipped'])) {
            $skipped++;
        } elseif (!empty($row['ok'])) {
            $passed++;
        } else {
            $failed++;
            $failures[] = [
                'id' => $row['id'] ?? '',
                'output_tail' => $row['output_tail'] ?? '',
            ];
        }
    }

    $status = $failed > 0 ? 'fail' : ($passed > 0 || $skipped > 0 ? 'pass' : 'missing');

    return compact('status', 'passed', 'failed', 'skipped', 'failures');
}

/**
 * @param array<string,mixed>|null $payload
 * @return array<string,mixed>
 */
function summarizePlaywrightPersona(?array $payload, string $persona): array
{
    if ($payload === null) {
        return ['status' => 'missing', 'passed' => 0, 'failed' => 0, 'skipped' => 0];
    }

    $suites = $payload['suites'] ?? [];
    $passed = 0;
    $failed = 0;
    $skipped = 0;
    $failures = [];

    walkPlaywrightSuites($suites, $persona, $passed, $failed, $skipped, $failures);

    $status = $failed > 0 ? 'fail' : ($passed > 0 ? 'pass' : 'missing');

    return [
        'status' => $status,
        'passed' => $passed,
        'failed' => $failed,
        'skipped' => $skipped,
        'failures' => $failures,
    ];
}

/**
 * @param list<mixed> $suites
 * @param list<array<string,string>> $failures
 */
function walkPlaywrightSuites(array $suites, string $persona, int &$passed, int &$failed, int &$skipped, array &$failures, string $prefix = ''): void
{
    foreach ($suites as $suite) {
        if (!is_array($suite)) {
            continue;
        }
        $title = trim($prefix . ' ' . (string) ($suite['title'] ?? ''));
        if (stripos($title, $persona) !== false || stripos((string) ($suite['file'] ?? ''), '/' . $persona . '/') !== false) {
            foreach ($suite['specs'] ?? [] as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                foreach ($spec['tests'] ?? [] as $test) {
                    if (!is_array($test)) {
                        continue;
                    }
                    $results = $test['results'] ?? [];
                    $last = is_array($results) && $results !== [] ? $results[count($results) - 1] : [];
                    $st = (string) ($last['status'] ?? 'unknown');
                    if ($st === 'passed') {
                        $passed++;
                    } elseif ($st === 'skipped') {
                        $skipped++;
                    } else {
                        $failed++;
                        $failures[] = [
                            'title' => (string) ($spec['title'] ?? ''),
                            'error' => (string) ($last['error']['message'] ?? ''),
                        ];
                    }
                }
            }
        }
        if (!empty($suite['suites']) && is_array($suite['suites'])) {
            walkPlaywrightSuites($suite['suites'], $persona, $passed, $failed, $skipped, $failures, $title);
        }
    }
}

/**
 * @param array<string,mixed> $matrix
 * @return array<string,list<string>>
 */
function extractRoadmap(array $matrix, string $artifactDir): array
{
    $now = [];
    $next = [];
    $defer = [];

    foreach ($matrix as $persona => $envs) {
        foreach (['local', 'hosted'] as $env) {
            $ng = $envs[$env]['non_gui'] ?? [];
            if (($ng['failed'] ?? 0) > 0) {
                foreach ($ng['failures'] ?? [] as $failure) {
                    $now[] = sprintf('[%s/%s/non-gui] %s', $persona, $env, $failure['id'] ?? 'unknown');
                }
            }
            $gui = $envs[$env]['gui'] ?? [];
            if (($gui['failed'] ?? 0) > 0) {
                foreach ($gui['failures'] ?? [] as $failure) {
                    $now[] = sprintf('[%s/%s/gui] %s', $persona, $env, $failure['title'] ?? 'unknown');
                }
            }
        }
    }

    $next[] = 'Automate split pay, shift close, refund click-through, and KOT URL in Playwright';
    $next[] = 'Recipe and inventory operator UX polish for owner persona';
    $defer[] = 'Multi-region hosting, enterprise franchise analytics, HR/payroll sidebar';
    $defer[] = 'CRM and clinic modules — out of mid-scale café scope';

    foreach (glob($artifactDir . '/narratives/*/*.md') ?: [] as $narrativeFile) {
        $content = (string) file_get_contents($narrativeFile);
        if (preg_match_all('/\*\*(Now|Next|Defer)\*\*:\s*(.+)$/mu', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $bucket = strtolower($match[1]);
                $line = trim($match[2]);
                if ($bucket === 'now') {
                    $now[] = $line;
                } elseif ($bucket === 'next') {
                    $next[] = $line;
                } else {
                    $defer[] = $line;
                }
            }
        }
    }

    return [
        'now' => array_values(array_unique($now)),
        'next' => array_values(array_unique($next)),
        'defer' => array_values(array_unique($defer)),
    ];
}

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $matrix
 * @param array<string,list<string>> $roadmap
 * @param array<string,mixed>|null $campaignSummary
 * @param array<string,mixed>|null $syncProof
 */
function buildReportMarkdown(
    array $config,
    string $runId,
    array $matrix,
    array $roadmap,
    ?array $campaignSummary,
    ?array $syncProof,
    string $artifactDir
): string {
    $created = (string) ($config['created_at_utc'] ?? gmdate('Y-m-d\TH:i:s\Z'));
    $commit = (string) ($config['git_commit'] ?? '');
    $localUrl = (string) ($config['local']['base_url'] ?? '');
    $hostedUrl = (string) ($config['hosted']['base_url'] ?? '');

    $lines = [];
    $lines[] = '# POSMAIN Persona QA Campaign Report';
    $lines[] = '';
    $lines[] = '**Run ID:** `' . $runId . '`  ';
    $lines[] = '**Created (UTC):** ' . $created . '  ';
    $lines[] = '**Git commit:** `' . $commit . '`  ';
    $lines[] = '';
    $lines[] = '## Part A — Executive Summary (English)';
    $lines[] = '';
    $lines[] = '| Environment | Base URL | Shop slug |';
    $lines[] = '|-------------|----------|-----------|';
    $lines[] = '| Local | ' . $localUrl . ' | `' . ($config['local']['shop_slug'] ?? '') . '` |';
    $lines[] = '| Hosted | ' . ($hostedUrl !== '' ? $hostedUrl : '(skipped)') . ' | `' . ($config['hosted']['shop_slug'] ?? '') . '` |';
    $lines[] = '';
    $lines[] = '### Automated results matrix';
    $lines[] = '';
    $lines[] = '| Persona | Local non-GUI | Local GUI | Hosted non-GUI | Hosted GUI |';
    $lines[] = '|---------|---------------|-----------|----------------|------------|';

    foreach ($matrix as $persona => $envs) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | %s |',
            $persona,
            statusLabel($envs['local']['non_gui'] ?? []),
            statusLabel($envs['local']['gui'] ?? []),
            statusLabel($envs['hosted']['non_gui'] ?? []),
            statusLabel($envs['hosted']['gui'] ?? [])
        );
    }

    $lines[] = '';
    $lines[] = '### Critical findings (P0/P1)';
    $lines[] = '';
    foreach ($roadmap['now'] as $item) {
        $lines[] = '- ' . $item;
    }
    if ($roadmap['now'] === []) {
        $lines[] = '- None recorded from automated suite (review narratives for UX gaps).';
    }

    $lines[] = '';
    $lines[] = '### Roadmap';
    $lines[] = '';
    $lines[] = '#### Add now (mid-scale café must-haves)';
    foreach ($roadmap['now'] as $item) {
        $lines[] = '- ' . $item;
    }
    $lines[] = '';
    $lines[] = '#### Next quarter';
    foreach ($roadmap['next'] as $item) {
        $lines[] = '- ' . $item;
    }
    $lines[] = '';
    $lines[] = '#### Defer (big-system only)';
    foreach ($roadmap['defer'] as $item) {
        $lines[] = '- ' . $item;
    }

    $lines[] = '';
    $lines[] = '## Part B — Arabic persona chapters';
    $lines[] = '';
    $lines[] = '<div dir="rtl">';
    $lines[] = '';

    foreach (['shared', 'cashier', 'waiter', 'manager', 'owner', 'sync_ops'] as $persona) {
        $lines[] = '### ' . $persona;
        $lines[] = '';
        foreach (['local', 'hosted'] as $env) {
            $path = $artifactDir . '/narratives/' . $persona . '/' . $env . '.md';
            if (is_file($path)) {
                $lines[] = file_get_contents($path);
                $lines[] = '';
                $lines[] = '---';
                $lines[] = '';
            }
        }
    }

    $lines[] = '</div>';
    $lines[] = '';
    $lines[] = '## Part C — Appendices';
    $lines[] = '';
    $lines[] = '### Sync proof';
    $lines[] = '';
    $lines[] = '```json';
    $lines[] = json_encode($syncProof, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $lines[] = '```';
    $lines[] = '';
    $lines[] = '### Campaign summary';
    $lines[] = '';
    $lines[] = '```json';
    $lines[] = json_encode($campaignSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $lines[] = '```';

    return implode("\n", $lines) . "\n";
}

/**
 * @param array<string,mixed> $row
 */
function statusLabel(array $row): string
{
    $status = (string) ($row['status'] ?? 'missing');
    $p = (int) ($row['passed'] ?? 0);
    $f = (int) ($row['failed'] ?? 0);
    $s = (int) ($row['skipped'] ?? 0);

    return sprintf('%s (%d/%d/%d)', strtoupper($status), $p, $f, $s);
}

/**
 * @return array<string,string|null>
 */
function exportPandoc(string $reportDir, string $runId, string $mdPath): array
{
    $safeId = QaCampaignSupport::sanitizeRunId($runId);
    $pdfPath = $reportDir . '/POSMAIN_QA_Report_' . $safeId . '.pdf';
    $docxPath = $reportDir . '/POSMAIN_QA_Report_' . $safeId . '.docx';

    $exports = ['pdf' => null, 'docx' => null];

    $pandoc = trim((string) shell_exec('command -v pandoc 2>/dev/null') ?: '');
    if ($pandoc === '') {
        $exports['pandoc'] = 'not_installed';

        return $exports;
    }

    $fromFormat = 'markdown-yaml_metadata_block';

    $pdfCmd = escapeshellarg($pandoc) . ' -f ' . escapeshellarg($fromFormat)
        . ' ' . escapeshellarg($mdPath)
        . ' -o ' . escapeshellarg($pdfPath)
        . ' --pdf-engine=xelatex 2>&1';
    $pdfOut = [];
    $pdfCode = 0;
    exec($pdfCmd, $pdfOut, $pdfCode);
    if ($pdfCode === 0 && is_file($pdfPath)) {
        $exports['pdf'] = $pdfPath;
    } else {
        $exports['pdf_error'] = QaCampaignSupport::tailLines($pdfOut);
    }

    $docxCmd = escapeshellarg($pandoc) . ' -f ' . escapeshellarg($fromFormat)
        . ' ' . escapeshellarg($mdPath)
        . ' -o ' . escapeshellarg($docxPath) . ' 2>&1';
    $docxOut = [];
    $docxCode = 0;
    exec($docxCmd, $docxOut, $docxCode);
    if ($docxCode === 0 && is_file($docxPath)) {
        $exports['docx'] = $docxPath;
    } else {
        $exports['docx_error'] = QaCampaignSupport::tailLines($docxOut);
    }

    return $exports;
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

    fwrite(STDOUT, 'Report: ' . ($payload['report_md'] ?? '') . PHP_EOL);
    foreach ($payload['exports'] ?? [] as $format => $path) {
        if (is_string($path) && $path !== '') {
            fwrite(STDOUT, '  ' . $format . ': ' . $path . PHP_EOL);
        }
    }
}
