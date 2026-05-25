<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Recipe/RecipeAvailabilityRefreshService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'apply',
    'dry-run',
    'json',
    'help',
    'ingredient-id:',
    'recipe-id:',
    'item-id:',
    'all-active',
    'pos-tenant::',
    'pos-branch::',
    'store-id::',
    'order-type::',
    'channel::',
    'limit::',
]);

if (isset($options['help'])) {
    recipeRefreshAvailabilityUsage();
    exit(0);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $result = (new RecipeAvailabilityRefreshService())->run($conn, recipeRefreshAvailabilityOptions($options));
    $conn->close();

    if (isset($options['json'])) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        recipeRefreshAvailabilityPrintHuman($result);
    }

    exit(0);
} catch (Throwable $exception) {
    $payload = [
        'ok' => false,
        'error' => get_class($exception),
        'message' => $exception->getMessage(),
    ];
    if (isset($options['json'])) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        fwrite(STDERR, 'Failed to refresh recipe availability: ' . $exception->getMessage() . PHP_EOL);
    }
    exit(2);
}

function recipeRefreshAvailabilityUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_refresh_availability.php [--apply] [--json] (--ingredient-id=ID | --recipe-id=ID | --item-id=ID | --all-active) [--pos-tenant=0] [--pos-branch=0] [--store-id=0] [--order-type=takeaway] [--channel=pos] [--limit=500]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Dry-run is the default. Without --apply, the tool only lists recipe availability cache targets and writes nothing.\n");
    fwrite(STDOUT, "Use --ingredient-id after ingredient stock changes to refresh only recipes that depend on that ingredient, including through sub-recipes.\n");
}

function recipeRefreshAvailabilityOptions(array $options): array
{
    return [
        'apply' => isset($options['apply']),
        'ingredient_id' => isset($options['ingredient-id']) ? (int) $options['ingredient-id'] : 0,
        'recipe_id' => isset($options['recipe-id']) ? (int) $options['recipe-id'] : 0,
        'item_id' => isset($options['item-id']) ? (int) $options['item-id'] : 0,
        'all_active' => isset($options['all-active']),
        'pos_tenant' => $options['pos-tenant'] ?? null,
        'pos_branch' => $options['pos-branch'] ?? null,
        'store_id' => isset($options['store-id']) ? (int) $options['store-id'] : 0,
        'order_type' => $options['order-type'] ?? 'takeaway',
        'channel' => $options['channel'] ?? 'pos',
        'limit' => isset($options['limit']) ? (int) $options['limit'] : 500,
    ];
}

function recipeRefreshAvailabilityPrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe availability refresh: ' . (!empty($result['applied']) ? 'APPLIED' : 'DRY RUN') . PHP_EOL);
    fwrite(STDOUT, '- targets: ' . (int) ($result['targets_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- refreshed: ' . (int) ($result['refreshed_count'] ?? 0) . PHP_EOL);
    foreach (($result['targets'] ?? []) as $target) {
        fwrite(STDOUT, '  - recipe ' . (int) $target['recipe_id'] . ' item ' . (int) $target['sellable_item_id'] . ' ' . (string) ($target['recipe_name'] ?? '') . PHP_EOL);
    }
}
