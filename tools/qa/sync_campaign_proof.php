#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/QaCampaignSupport.php';
require_once dirname(__DIR__, 2) . '/includes/db_bootstrap.php';
require_once dirname(__DIR__, 2) . '/classes/Sync/BranchCatalogPushService.php';
require_once dirname(__DIR__, 2) . '/classes/Sync/BranchIdentity.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', ['help', 'json', 'config::', 'run-id::', 'skip-mock', 'skip-bidirectional']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/qa/sync_campaign_proof.php [--config=PATH] [--run-id=ID] [--json]\n");
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

$runId = (string) ($options['run-id'] ?? ($config['run_id'] ?? 'qa-run'));
$local = $config['local'] ?? [];
$hosted = $config['hosted'] ?? [];

putenv('POSMAIN_ENV=test');
putenv('POSMAIN_PRODUCTION_MODE=0');
putenv('POSMAIN_DB_HOST=' . ($local['mysql_host'] ?? '127.0.0.1'));
putenv('POSMAIN_DB_PORT=' . (string) ($local['mysql_port'] ?? 3307));
putenv('POSMAIN_DB_USER=' . ($local['mysql_user'] ?? 'root'));
putenv('POSMAIN_DB_PASS=' . ($local['mysql_pass'] ?? ''));
putenv('POSMAIN_DB_NAME=' . ($local['db_name'] ?? 'kody2'));

$summary = [
    'ok' => true,
    'run_id' => $runId,
    'scenarios' => [],
];

try {
    if (!isset($options['skip-mock'])) {
        $mockCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(QaCampaignSupport::repoRoot() . '/tools/e2e_mock_online_offline_sync.php');
        $mockOut = [];
        $mockCode = 0;
        exec($mockCmd . ' 2>&1', $mockOut, $mockCode);
        $summary['scenarios']['mock_online_offline'] = [
            'ok' => $mockCode === 0,
            'exit_code' => $mockCode,
            'output_tail' => QaCampaignSupport::tailLines($mockOut),
        ];
        if ($mockCode !== 0) {
            $summary['ok'] = false;
        }
    }

    $cloudUrl = rtrim((string) ($hosted['base_url'] ?? ''), '/');
    $branchUuid = (string) ($local['branch_uuid'] ?? '');
    $secret = (string) ($local['branch_secret'] ?? '');

    if ($cloudUrl !== '' && $branchUuid !== '' && $secret !== '') {
        $conn = posmain_db_connect();
        $appConfig = posmain_app_config();
        $appConfig['role'] = 'branch';
        $appConfig['branch']['uuid'] = $branchUuid;
        $appConfig['branch']['cloud_base_url'] = $cloudUrl;
        $appConfig['sync']['branch_secret'] = $secret;
        $appConfig['sync']['branch_sync_enabled'] = true;
        $appConfig['sync']['menu_sync_enabled'] = true;
        $appConfig['sync']['operational_sync_enabled'] = true;
        $appConfig['sync']['outbox_enabled'] = true;

        (new SyncBranchIdentity())->ensure($conn, $appConfig);

        $localItems = (int) ($conn->query("SELECT COUNT(*) AS c FROM myitems WHERE iname LIKE 'P6-DEMO%'")->fetch_assoc()['c'] ?? 0);

        $push = (new BranchCatalogPushService())->pushToHosted($conn, $appConfig, [
            'drain_outbox' => true,
            'max_batches' => 50,
            'batch_size' => 25,
        ]);

        $summary['scenarios']['catalog_push_local_to_hosted'] = [
            'ok' => (int) ($push['pending_outbox'] ?? 1) === 0,
            'local_demo_items' => $localItems,
            'push' => [
                'pending_outbox' => $push['pending_outbox'] ?? null,
                'cloud_base_url' => $push['cloud_base_url'] ?? $cloudUrl,
                'dispatch' => $push['dispatch'] ?? null,
            ],
        ];

        if ((int) ($push['pending_outbox'] ?? 1) !== 0) {
            $summary['ok'] = false;
        }

        $conn->close();
    } else {
        $summary['scenarios']['catalog_push_local_to_hosted'] = [
            'ok' => true,
            'skipped' => true,
            'message' => 'Missing cloud URL or pairing metadata',
        ];
    }

    $bidirCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(QaCampaignSupport::repoRoot() . '/tools/e2e_bidirectional_operational_sync.php');
    $bidirOut = [];
    $bidirCode = 0;
    if (!isset($options['skip-bidirectional'])) {
        exec($bidirCmd . ' 2>&1', $bidirOut, $bidirCode);
    } else {
        $bidirCode = 0;
        $bidirOut = ['skipped by --skip-bidirectional'];
    }
    $summary['scenarios']['bidirectional_clone_schema'] = [
        'ok' => $bidirCode === 0,
        'exit_code' => $bidirCode,
        'output_tail' => QaCampaignSupport::tailLines($bidirOut),
    ];
    if ($bidirCode !== 0) {
        $summary['ok'] = false;
    }

    QaCampaignSupport::writeJsonArtifact($runId, 'sync_proof.json', $summary);
    emit($summary, $json);
    exit(!empty($summary['ok']) ? 0 : 2);
} catch (Throwable $exception) {
    $summary['ok'] = false;
    $summary['error'] = $exception->getMessage();
    QaCampaignSupport::writeJsonArtifact($runId, 'sync_proof.json', $summary);
    emit($summary, $json);
    exit(2);
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

    fwrite(STDOUT, 'Sync campaign proof: ' . (!empty($payload['ok']) ? 'OK' : 'FAIL') . PHP_EOL);
    foreach ($payload['scenarios'] ?? [] as $name => $row) {
        $label = !empty($row['skipped']) ? 'SKIP' : (!empty($row['ok']) ? 'OK' : 'FAIL');
        fwrite(STDOUT, sprintf("  [%s] %s\n", $label, $name));
    }
}
