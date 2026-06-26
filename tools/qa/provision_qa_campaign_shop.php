#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/QaCampaignSupport.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', [
    'help',
    'json',
    'run-id::',
    'target::',
    'slug::',
    'branch-uuid::',
    'secret::',
    'cloud-base-url::',
    'local-only',
    'hosted-only',
    'skip-hosted',
    'repair-aliases',
    'teardown',
    'config::',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
Usage: php tools/qa/provision_qa_campaign_shop.php [options]

Provision and seed QA campaign shops for local Docker and/or hosted (Hetzner) environments.
Writes tests/qa/campaign_config.local.json unless --config is set.

Options:
  --run-id=ID           Reuse run id (default: generated)
  --target=local|hosted|both   Which side to provision (default: both)
  --local-only          Alias for --target=local
  --hosted-only         Run on server: provision hosted shop only (via SSH args)
  --skip-hosted         Local provision + seed only
  --slug=SLUG           Hosted-only: shop slug (must start with qa-campaign-)
  --repair-aliases --slug=SLUG
                         Repair hosted router login aliases for an existing QA shop
  --branch-uuid=UUID    Hosted-only: branch UUID
  --secret=SECRET       Hosted-only: shared pairing secret
  --cloud-base-url=URL  Hosted-only: public cloud base URL
  --teardown --slug=SLUG   Deactivate hosted QA shop (hosted-only / remote)
  --config=PATH         Campaign config output path
  --json                JSON summary on stdout

Environment (local):
  POSMAIN_TEST_HTTP_BASE, POSMAIN_TEST_MYSQL_PORT, POSMAIN_DB_NAME

Environment (hosted SSH from laptop):
  POSMAIN_QA_SSH_HOST, POSMAIN_QA_SSH_USER, POSMAIN_QA_REMOTE_APP_PATH
  POSMAIN_QA_HOSTED_BASE_URL

TXT);
    exit(0);
}

$json = isset($options['json']);
$configPath = isset($options['config']) ? (string) $options['config'] : QaCampaignSupport::localConfigPath();

if (isset($options['teardown'])) {
    $slug = trim((string) ($options['slug'] ?? ''));
    if ($slug === '') {
        fwrite(STDERR, "--teardown requires --slug\n");
        exit(1);
    }
    $result = QaCampaignSupport::teardownHostedShopLocal($slug);
    emit($result, $json);
    exit(!empty($result['ok']) ? 0 : 2);
}

if (isset($options['repair-aliases'])) {
    $slug = trim((string) ($options['slug'] ?? ''));
    if ($slug === '') {
        fwrite(STDERR, "--repair-aliases requires --slug\n");
        exit(1);
    }
    try {
        $result = QaCampaignSupport::repairHostedLoginAliasesLocal($slug);
        emit($result, $json);
        exit(!empty($result['ok']) ? 0 : 2);
    } catch (Throwable $exception) {
        emit(['ok' => false, 'error' => $exception->getMessage()], $json);
        exit(2);
    }
}

if (isset($options['hosted-only'])) {
    $input = [
        'slug' => (string) ($options['slug'] ?? ''),
        'branch_uuid' => (string) ($options['branch-uuid'] ?? ''),
        'secret' => (string) ($options['secret'] ?? ''),
        'cloud_base_url' => (string) ($options['cloud-base-url'] ?? ''),
        'run_id' => (string) ($options['run-id'] ?? ''),
    ];
    try {
        $result = QaCampaignSupport::provisionHostedShopLocal($input);
        emit($result, $json);
        exit(!empty($result['ok']) ? 0 : 2);
    } catch (Throwable $exception) {
        emit(['ok' => false, 'error' => $exception->getMessage()], $json);
        exit(2);
    }
}

$target = 'both';
if (isset($options['local-only'])) {
    $target = 'local';
} elseif (isset($options['hosted-only'])) {
    $target = 'hosted';
} elseif (isset($options['target'])) {
    $target = strtolower((string) $options['target']);
}
if (isset($options['skip-hosted'])) {
    $target = 'local';
}

$runId = trim((string) ($options['run-id'] ?? ''));
if ($runId === '') {
    $runId = QaCampaignSupport::generateRunId();
}

$summary = [
    'ok' => true,
    'run_id' => $runId,
    'target' => $target,
    'steps' => [],
];

try {
    $config = QaCampaignSupport::buildFreshConfig($runId);

    if ($target === 'local' || $target === 'both') {
        $local = $config['local'] ?? [];
        $mysqlOk = QaCampaignSupport::mysqlProbe(
            (string) ($local['mysql_host'] ?? '127.0.0.1'),
            (int) ($local['mysql_port'] ?? 3307),
            (string) ($local['mysql_user'] ?? 'root'),
            (string) ($local['mysql_pass'] ?? '')
        );
        $summary['steps']['local_mysql_probe'] = ['ok' => $mysqlOk];
        if (!$mysqlOk) {
            throw new RuntimeException('Local MySQL not reachable on port ' . ($local['mysql_port'] ?? 3307));
        }

        $seed = QaCampaignSupport::seedLocalDemo($config);
        $summary['steps']['local_seed'] = $seed;
        if (empty($seed['ok'])) {
            throw new RuntimeException('Local demo seed failed');
        }

        $httpOk = QaCampaignSupport::httpProbe((string) ($local['base_url'] ?? ''));
        $summary['steps']['local_http_probe'] = ['ok' => $httpOk, 'url' => $local['base_url'] ?? ''];
    }

    if ($target === 'hosted' || $target === 'both') {
        $hosted = QaCampaignSupport::provisionHostedShop($config);
        $summary['steps']['hosted_provision'] = $hosted;
        if (!empty($hosted['ok']) && is_array($hosted['payload'] ?? null)) {
            $payload = $hosted['payload'];
            $config['hosted']['shop_id'] = $payload['shop_id'] ?? ($config['hosted']['shop_id'] ?? null);
            $config['hosted']['db_name'] = $payload['db_name'] ?? ($config['hosted']['db_name'] ?? null);
            $config['hosted']['login_aliases'] = $payload['login_aliases'] ?? QaCampaignSupport::hostedLoginAliases((string) ($config['hosted']['shop_slug'] ?? ''));
        }
        if (empty($hosted['ok']) && empty($hosted['skipped'])) {
            $summary['ok'] = false;
            $summary['warning'] = 'Hosted provision failed; continuing with local-only config';
        }
    }

    if (($target === 'local' || $target === 'both') && !empty($config['sync']['pair_local_to_hosted'])) {
        $pair = QaCampaignSupport::pairLocalBranchToHosted($config);
        $summary['steps']['local_pairing'] = $pair;
    }

    QaCampaignSupport::saveConfig($config, $configPath);
    $summary['config_path'] = $configPath;
    $summary['artifact_dir'] = QaCampaignSupport::artifactDir($runId);
    QaCampaignSupport::writeJsonArtifact($runId, 'provision_summary.json', $summary);

    emit($summary, $json);
    exit(!empty($summary['ok']) ? 0 : 2);
} catch (Throwable $exception) {
    $summary['ok'] = false;
    $summary['error'] = $exception->getMessage();
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

    $status = !empty($payload['ok']) ? 'OK' : 'FAIL';
    fwrite(STDOUT, 'QA campaign provision: ' . $status . PHP_EOL);
    if (!empty($payload['run_id'])) {
        fwrite(STDOUT, 'Run ID: ' . $payload['run_id'] . PHP_EOL);
    }
    if (!empty($payload['config_path'])) {
        fwrite(STDOUT, 'Config: ' . $payload['config_path'] . PHP_EOL);
    }
    if (!empty($payload['error'])) {
        fwrite(STDERR, 'Error: ' . $payload['error'] . PHP_EOL);
    }
}
