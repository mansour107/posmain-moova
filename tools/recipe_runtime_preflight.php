<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../classes/Recipe/RecipeRuntimePreflightService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'json',
    'help',
]);

if (isset($options['help'])) {
    recipeRuntimePreflightUsage();
    exit(0);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $result = (new RecipeRuntimePreflightService())->check($conn, new RecipeFeatureFlags(posmain_app_config()));
    $conn->close();
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'ready_for_recipe_operator_qa' => false,
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'mode' => 'unknown',
        'checks' => [
            'database' => [
                'ok' => false,
                'error' => 'db_connect_failed',
                'message' => $exception->getMessage(),
            ],
        ],
        'blockers' => ['recipe_runtime_database_unreachable'],
        'warnings' => [],
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    recipeRuntimePreflightPrintHuman($result);
}

exit(!empty($result['ready_for_recipe_operator_qa']) ? 0 : 2);

function recipeRuntimePreflightUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_runtime_preflight.php [--json]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Checks whether the current runtime is prepared for recipe browser/operator QA.\n");
    fwrite(STDOUT, "This command is read-only: it does not apply migrations, change flags, expire reservations, refresh availability, write recipe rows, write stock, or post accounting.\n");
}

function recipeRuntimePreflightPrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe runtime preflight: ' . (!empty($result['ready_for_recipe_operator_qa']) ? 'READY' : 'NOT READY') . PHP_EOL);
    fwrite(STDOUT, '- mode: ' . (string) ($result['mode'] ?? 'unknown') . PHP_EOL);

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

    $schema = $result['checks']['schema'] ?? [];
    if (is_array($schema)) {
        fwrite(STDOUT, '- pending schema changes: ' . (int) ($schema['pending_count'] ?? 0) . PHP_EOL);
        fwrite(STDOUT, '- missing recipe tables: ' . count($schema['missing_recipe_tables'] ?? []) . PHP_EOL);
    }
}
