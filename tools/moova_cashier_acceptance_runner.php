<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This acceptance runner must be run from the command line.\n");
    exit(2);
}

$options = moovaAcceptanceParseArgs($argv);
if (!empty($options['help'])) {
    moovaAcceptanceUsage();
    exit(0);
}

$report = moovaAcceptanceRun($options);
if (!empty($options['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    moovaAcceptanceHuman($report);
}

exit(empty($report['ok']) ? 1 : 0);

function moovaAcceptanceUsage(): void
{
    echo "Usage: php tools/moova_cashier_acceptance_runner.php --output=/absolute/path/to/moova-cashier-acceptance.md [--json]\n";
    echo "       [--pos-url=http://127.0.0.1:8010/index.php] [--moova-url=http://127.0.0.1:3001]\n";
    echo "       [--branch-uuid=UUID] [--operator=name] [--backup-file=/absolute/path/to/backup.sql]\n";
    echo "       [--skip-live-topology]\n";
    echo "\n";
    echo "Runs live local topology checks plus the two-mock-server Moova drop/recovery smoke and writes local/mock-backed acceptance evidence.\n";
}

function moovaAcceptanceRun(array $options): array
{
    $topology = !empty($options['skip_live_topology'])
        ? ['ok' => true, 'skipped' => true, 'summary' => 'live topology skipped by operator option']
        : moovaAcceptanceRunJsonCommand(moovaAcceptanceTopologyCommand($options));
    $smoke = moovaAcceptanceRunJsonCommand([
        PHP_BINARY,
        __DIR__ . '/moova_reachability_smoke.php',
        '--self-test',
    ]);

    $markers = moovaAcceptanceMarkers($smoke['json'] ?? []);
    $markersOk = !in_array(false, $markers, true);
    $ok = !empty($topology['ok']) && !empty($smoke['ok']) && $markersOk;
    $evidence = moovaAcceptanceEvidenceText($options, $topology, $smoke, $markers, $ok);
    $output = (string) ($options['output'] ?? '');
    $write = null;
    if ($output !== '') {
        $write = moovaAcceptanceWriteFile($output, $evidence);
        if (empty($write['ok'])) {
            $ok = false;
        }
    }

    return [
        'ok' => $ok,
        'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'evidence_type' => 'local_mock_backed_acceptance',
        'output' => $output !== '' ? $output : null,
        'write' => $write,
        'markers' => $markers,
        'topology' => [
            'ok' => !empty($topology['ok']),
            'skipped' => !empty($topology['skipped']),
            'exit_code' => $topology['exit_code'] ?? null,
        ],
        'smoke' => [
            'ok' => !empty($smoke['ok']),
            'exit_code' => $smoke['exit_code'] ?? null,
            'step_count' => count($smoke['json']['steps'] ?? []),
        ],
        'notes' => [
            'This is local/mock-backed evidence for the go-live readiness file.',
            'It does not replace final real-shop hosted cashier acceptance.',
        ],
    ];
}

function moovaAcceptanceParseArgs(array $argv): array
{
    $options = [
        'output' => '',
        'pos_url' => getenv('POSMAIN_LOCAL_POS_URL') ?: 'http://127.0.0.1:8010/index.php',
        'moova_url' => getenv('POSMAIN_LOCAL_MOOVA_URL') ?: 'http://127.0.0.1:3001',
        'branch_uuid' => getenv('POSMAIN_BRANCH_UUID') ?: '',
        'operator' => getenv('USER') ?: '',
        'backup_file' => '',
        'skip_live_topology' => false,
        'json' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }
        if ($arg === '--skip-live-topology') {
            $options['skip_live_topology'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        foreach ([
            '--output=' => 'output',
            '--pos-url=' => 'pos_url',
            '--moova-url=' => 'moova_url',
            '--branch-uuid=' => 'branch_uuid',
            '--operator=' => 'operator',
            '--backup-file=' => 'backup_file',
        ] as $prefix => $key) {
            if (str_starts_with($arg, $prefix)) {
                $options[$key] = substr($arg, strlen($prefix));
                continue 2;
            }
        }
        throw new InvalidArgumentException('Unknown option: ' . $arg);
    }

    return $options;
}

function moovaAcceptanceTopologyCommand(array $options): array
{
    return [
        PHP_BINARY,
        __DIR__ . '/moova_local_topology_check.php',
        '--fail-on-down',
        '--pos-url=' . (string) $options['pos_url'],
        '--moova-url=' . (string) $options['moova_url'],
    ];
}

function moovaAcceptanceRunJsonCommand(array $parts): array
{
    $cmd = implode(' ', array_map('escapeshellarg', $parts));
    exec($cmd, $lines, $code);
    $output = implode("\n", $lines);
    $json = json_decode($output, true);

    return [
        'ok' => $code === 0 && is_array($json) && !empty($json['ok']),
        'exit_code' => $code,
        'json' => is_array($json) ? $json : null,
        'output' => $output,
    ];
}

function moovaAcceptanceMarkers(array $smoke): array
{
    $steps = [];
    foreach (($smoke['steps'] ?? []) as $step) {
        if (is_array($step) && isset($step['name'])) {
            $steps[(string) $step['name']] = !empty($step['passed']);
        }
    }

    return [
        'queued_new_order' => !empty($steps['queued_new_order']),
        'queued_edit_order' => !empty($steps['queued_edit_order']),
        'queued_cancel_order' => !empty($steps['queued_cancel_order']),
        'pos_drop_recovery' => !empty($steps['pos_drop']) && !empty($steps['pos_recovery']),
        'moova_drop_recovery' => !empty($steps['moova_drop']) && !empty($steps['moova_recovery']),
    ];
}

function moovaAcceptanceEvidenceText(array $options, array $topology, array $smoke, array $markers, bool $ok): string
{
    $lines = [
        '# POSMAIN Moova Cashier Acceptance Evidence',
        '',
        'generated_by=moova_cashier_acceptance_runner',
        'evidence_type=local_mock_backed_acceptance',
        'overall=' . ($ok ? 'pass' : 'fail'),
        'generated_at_utc=' . gmdate('Y-m-d\TH:i:s\Z'),
        'branch_uuid=' . (string) ($options['branch_uuid'] ?? ''),
        'operator=' . (string) ($options['operator'] ?? ''),
        'pos_url=' . (string) ($options['pos_url'] ?? ''),
        'moova_url=' . (string) ($options['moova_url'] ?? ''),
        'backup_file=' . (string) ($options['backup_file'] ?? ''),
        'live_topology=' . (!empty($topology['skipped']) ? 'skipped' : (!empty($topology['ok']) ? 'pass' : 'fail')),
        'mock_two_server_smoke=' . (!empty($smoke['ok']) ? 'pass' : 'fail'),
        '',
        '## Required Result Markers',
        '',
        'queued_new_order=' . (!empty($markers['queued_new_order']) ? 'pass' : 'fail'),
        'queued_edit_order=' . (!empty($markers['queued_edit_order']) ? 'pass' : 'fail'),
        'queued_cancel_order=' . (!empty($markers['queued_cancel_order']) ? 'pass' : 'fail'),
        'pos_drop_recovery=' . (!empty($markers['pos_drop_recovery']) ? 'pass' : 'fail'),
        'moova_drop_recovery=' . (!empty($markers['moova_drop_recovery']) ? 'pass' : 'fail'),
        '',
        '## Notes',
        '',
        '- This file is generated from local topology checks and a two-mock-server Moova/POS drop-recovery smoke.',
        '- It is useful for local go-live readiness rehearsal, but final real-shop hosted cashier acceptance should still be retained separately.',
    ];

    return implode("\n", $lines) . "\n";
}

function moovaAcceptanceWriteFile(string $path, string $contents): array
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        return [
            'ok' => false,
            'error' => 'output_directory_missing',
            'path' => $path,
        ];
    }

    $bytes = file_put_contents($path, $contents);
    if ($bytes === false) {
        return [
            'ok' => false,
            'error' => 'write_failed',
            'path' => $path,
        ];
    }

    return [
        'ok' => true,
        'path' => $path,
        'bytes' => $bytes,
    ];
}

function moovaAcceptanceHuman(array $report): void
{
    echo 'Moova acceptance runner: ' . (!empty($report['ok']) ? 'pass' : 'fail') . PHP_EOL;
    foreach (($report['markers'] ?? []) as $name => $passed) {
        echo '- ' . $name . ': ' . (!empty($passed) ? 'pass' : 'fail') . PHP_EOL;
    }
    if (!empty($report['output'])) {
        echo 'Evidence: ' . $report['output'] . PHP_EOL;
    }
}
