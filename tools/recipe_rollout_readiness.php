<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../classes/Recipe/RecipeRolloutReadinessService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'json',
    'help',
    'skip-db',
    'pilot-evidence-file:',
    'max-pilot-evidence-age-hours::',
    'allow-full-mode',
    'allow-cost-public-payloads',
    'pos-tenant::',
    'pos-branch::',
    'store-id::',
    'limit::',
]);

if (isset($options['help'])) {
    recipeRolloutReadinessUsage();
    exit(0);
}

$config = posmain_app_config();
$flags = new RecipeFeatureFlags($config);
$service = new RecipeRolloutReadinessService();

if (isset($options['skip-db'])) {
    $result = [
        'ok' => false,
        'ready_for_recipe_rollout' => false,
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'mode' => $flags->mode(),
        'checks' => [
            'database' => [
                'ok' => false,
                'skipped' => true,
                'blocker' => 'database_check_skipped',
                'message' => 'Database checks were skipped; do not use this result for recipe rollout.',
            ],
        ],
        'dashboard_summary' => [],
        'blockers' => ['database_check_skipped'],
        'warnings' => [],
    ];
} else {
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = posmain_db_connect();
        $result = $service->check($conn, $flags, recipeRolloutReadinessOptions($options));
        $conn->close();
    } catch (Throwable $exception) {
        $result = [
            'ok' => false,
            'ready_for_recipe_rollout' => false,
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => $flags->mode(),
            'checks' => [
                'database' => [
                    'ok' => false,
                    'error' => 'db_connect_failed',
                    'message' => $exception->getMessage(),
                ],
            ],
            'dashboard_summary' => [],
            'blockers' => ['database_unreachable'],
            'warnings' => [],
        ];
    }
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    recipeRolloutReadinessPrintHuman($result);
}

exit(!empty($result['ready_for_recipe_rollout']) ? 0 : 2);

function recipeRolloutReadinessUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_rollout_readiness.php [--json] [--skip-db] [--pilot-evidence-file=/absolute/path/to/recipe-pilot-evidence.md] [--max-pilot-evidence-age-hours=24] [--allow-full-mode] [--allow-cost-public-payloads] [--pos-tenant=0] [--pos-branch=0] [--store-id=0] [--limit=100]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Checks recipe/BOM rollout readiness without applying migrations, changing flags, expiring reservations, requeueing sync, or writing stock/accounting rows.\n");
    fwrite(STDOUT, "Active consumption/accounting/availability modes require a readable pilot evidence file with pass markers.\n");
    fwrite(STDOUT, "Full mode and public cost payloads require explicit override flags so they cannot pass accidentally.\n");
}

function recipeRolloutReadinessOptions(array $options): array
{
    return [
        'pilot_evidence_file' => isset($options['pilot-evidence-file']) ? (string) $options['pilot-evidence-file'] : '',
        'max_pilot_evidence_age_hours' => isset($options['max-pilot-evidence-age-hours']) ? (int) $options['max-pilot-evidence-age-hours'] : 24,
        'allow_full_mode' => isset($options['allow-full-mode']),
        'allow_cost_public_payloads' => isset($options['allow-cost-public-payloads']),
        'pos_tenant' => $options['pos-tenant'] ?? null,
        'pos_branch' => $options['pos-branch'] ?? null,
        'store_id' => $options['store-id'] ?? null,
        'limit' => isset($options['limit']) ? (int) $options['limit'] : 100,
    ];
}

function recipeRolloutReadinessPrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe rollout readiness: ' . (!empty($result['ready_for_recipe_rollout']) ? 'READY' : 'NOT READY') . PHP_EOL);
    fwrite(STDOUT, '- mode: ' . (string) ($result['mode'] ?? 'off') . PHP_EOL);

    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }

    if (!empty($result['warnings'])) {
        fwrite(STDOUT, "- warnings:\n");
        foreach ($result['warnings'] as $warning) {
            fwrite(STDOUT, '  - ' . (string) $warning . PHP_EOL);
        }
    }

    if (!empty($result['dashboard_summary']) && is_array($result['dashboard_summary'])) {
        fwrite(STDOUT, "- dashboard summary:\n");
        foreach ($result['dashboard_summary'] as $key => $value) {
            if (is_scalar($value)) {
                fwrite(STDOUT, '  - ' . (string) $key . ': ' . (string) $value . PHP_EOL);
            }
        }
    }
}
