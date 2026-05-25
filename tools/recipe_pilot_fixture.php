<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Recipe/RecipePilotFixtureService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'apply',
    'dry-run',
    'verify',
    'json',
    'help',
    'prefix::',
    'barcode-prefix::',
    'pos-tenant::',
    'pos-branch::',
    'store-id::',
    'allow-hosted-staging',
]);

if (isset($options['help'])) {
    recipePilotFixtureUsage();
    exit(0);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $toolOptions = recipePilotFixtureOptions($options);
    $appConfig = posmain_app_config();
    $safety = recipePilotFixtureSafetyCheck($appConfig, $toolOptions);
    if (empty($safety['ok'])) {
        $result = $safety;
    } else {
        $conn = posmain_db_connect();
        $service = new RecipePilotFixtureService();
        if (!empty($toolOptions['verify']) && empty($toolOptions['apply'])) {
            $result = $service->verify($conn, $toolOptions);
        } else {
            $result = $service->run($conn, $toolOptions);
            if (!empty($toolOptions['apply']) && !empty($toolOptions['verify'])) {
                $result['verification'] = $service->verify($conn, $toolOptions);
                $result['ok'] = !empty($result['ok']) && !empty($result['verification']['ok']);
            }
        }
        $result['runtime_safety'] = recipePilotFixtureSafetySummary($appConfig, $toolOptions);
        $conn->close();
    }
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'applied' => false,
        'error' => get_class($exception),
        'message' => $exception->getMessage(),
        'blockers' => ['recipe_pilot_fixture_failed'],
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    recipePilotFixturePrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function recipePilotFixtureUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_pilot_fixture.php [--json] [--verify] [--apply] [--allow-hosted-staging] [--prefix='Recipe QA'] [--barcode-prefix=RQA] [--pos-tenant=0] [--pos-branch=0] [--store-id=0]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Prepares repeatable recipe pilot QA data on a migrated local/staging runtime.\n");
    fwrite(STDOUT, "Dry-run is the default. Without --apply, the tool only checks schema/conflicts and prints the fixture plan.\n");
    fwrite(STDOUT, "With --verify, the tool performs a read-only fixture completeness check and writes no rows.\n");
    fwrite(STDOUT, "With --apply, it writes only named fixture items, modifier rows, active/draft fixture recipes, one draft production batch, fixture balances, opening-balance ledger rows, cost snapshots, and availability cache rows.\n");
    fwrite(STDOUT, "It does not apply migrations, change feature flags, create customer orders, take payments, post accounting journals, enqueue sync rows, or update router metadata.\n");
    fwrite(STDOUT, "Apply mode refuses POSMAIN_ENV=production/prod or POSMAIN_PRODUCTION_MODE=1, and cloud/router-shaped runtimes require --allow-hosted-staging.\n");
    fwrite(STDOUT, "Run this only against a local or staging QA database, then use the reported pilot item ids for browser/operator smoke evidence.\n");
}

function recipePilotFixtureOptions(array $options): array
{
    return [
        'apply' => isset($options['apply']),
        'verify' => isset($options['verify']),
        'prefix' => $options['prefix'] ?? 'Recipe QA',
        'barcode_prefix' => $options['barcode-prefix'] ?? 'RQA',
        'pos_tenant' => isset($options['pos-tenant']) ? (int) $options['pos-tenant'] : 0,
        'pos_branch' => isset($options['pos-branch']) ? (int) $options['pos-branch'] : 0,
        'store_id' => isset($options['store-id']) ? (int) $options['store-id'] : 0,
        'allow_hosted_staging' => isset($options['allow-hosted-staging']),
    ];
}

function recipePilotFixtureSafetyCheck(array $config, array $options): array
{
    if (empty($options['apply'])) {
        return ['ok' => true];
    }

    $summary = recipePilotFixtureSafetySummary($config, $options);
    $blockers = [];
    if (!empty($summary['production_mode']) || in_array($summary['env'], ['production', 'prod'], true)) {
        $blockers[] = 'recipe_pilot_fixture_refuses_production_runtime';
    }
    if (!empty($summary['hosted_or_router_runtime']) && empty($options['allow_hosted_staging'])) {
        $blockers[] = 'recipe_pilot_fixture_hosted_staging_requires_explicit_allow';
    }

    if ($blockers === []) {
        return ['ok' => true];
    }

    return [
        'ok' => false,
        'applied' => false,
        'dry_run' => false,
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'runtime_safety' => $summary,
        'message' => 'Refusing to apply recipe pilot fixture in an unsafe runtime context.',
        'blockers' => $blockers,
    ];
}

function recipePilotFixtureSafetySummary(array $config, array $options): array
{
    $env = strtolower(trim((string) ($config['env'] ?? 'local')));
    $role = strtolower(trim((string) ($config['role'] ?? 'branch')));
    $routerEnabled = !empty($config['router']['enabled']);
    $hostedOrRouter = in_array($role, ['cloud', 'fake_cloud'], true) || $routerEnabled;

    return [
        'env' => $env,
        'role' => $role,
        'production_mode' => !empty($config['production_mode']),
        'router_enabled' => $routerEnabled,
        'hosted_or_router_runtime' => $hostedOrRouter,
        'allow_hosted_staging' => !empty($options['allow_hosted_staging']),
    ];
}

function recipePilotFixturePrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe pilot fixture: ' . (!empty($result['ok']) ? 'OK' : 'FAILED') . PHP_EOL);
    $mode = !empty($result['read_only']) ? 'VERIFY' : (!empty($result['applied']) ? 'APPLIED' : 'DRY RUN');
    fwrite(STDOUT, '- mode: ' . $mode . PHP_EOL);
    if (!empty($result['message'])) {
        fwrite(STDOUT, '- message: ' . (string) $result['message'] . PHP_EOL);
    }
    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }
    if (!empty($result['fixture']['items']) && is_array($result['fixture']['items'])) {
        fwrite(STDOUT, "- fixture items:\n");
        foreach ($result['fixture']['items'] as $key => $item) {
            fwrite(STDOUT, '  - ' . (string) $key . ': ' . (string) ($item['name'] ?? '') . ' id=' . (string) ($item['id'] ?? 'pending') . PHP_EOL);
        }
    }
    if (!empty($result['pilot_env']) && is_array($result['pilot_env'])) {
        fwrite(STDOUT, "- pilot env suggestions:\n");
        foreach ($result['pilot_env'] as $key => $value) {
            fwrite(STDOUT, '  - ' . (string) $key . '=' . (string) $value . PHP_EOL);
        }
    }
    if (!empty($result['created'])) {
        fwrite(STDOUT, '- created/reconciled rows: ' . count($result['created']) . PHP_EOL);
    }
    if (!empty($result['reused'])) {
        fwrite(STDOUT, '- reused rows: ' . count($result['reused']) . PHP_EOL);
    }
    if (isset($result['fixture_ready_for_operator_qa'])) {
        fwrite(STDOUT, '- fixture ready for operator QA: ' . (!empty($result['fixture_ready_for_operator_qa']) ? 'yes' : 'no') . PHP_EOL);
    }
}
