<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../classes/Recipe/RecipeEditorItemCostService.php';
require_once __DIR__ . '/../classes/Recipe/RecipeEditorReadService.php';
require_once __DIR__ . '/../classes/Recipe/Repository/RecipeRepository.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['apply', 'dry-run', 'json', 'help', 'limit::']);

if (isset($options['help']) || (!isset($options['apply']) && !isset($options['dry-run']))) {
    recipeBackfillItemCostsUsage();
    exit(isset($options['help']) ? 0 : 1);
}

$dryRun = !isset($options['apply']);
$limit = isset($options['limit']) ? max(0, (int) $options['limit']) : 0;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $result = recipeBackfillItemCostsRun($conn, $dryRun, $limit);
    $conn->close();

    if (isset($options['json'])) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        recipeBackfillItemCostsPrintHuman($result, $dryRun);
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
        fwrite(STDERR, 'Failed to backfill recipe item costs: ' . $exception->getMessage() . PHP_EOL);
    }
    exit(2);
}

function recipeBackfillItemCostsUsage(): void
{
    echo "Recipe item cost backfill — sync myitems.cost_price from live recipe calculation.\n";
    echo "Respects manual_cost_edit=1 (owner overrides are never overwritten).\n\n";
    echo "Usage:\n";
    echo "  php tools/recipe_backfill_item_costs.php --dry-run [--limit=N] [--json]\n";
    echo "  php tools/recipe_backfill_item_costs.php --apply   [--limit=N] [--json]\n\n";
    echo "Options:\n";
    echo "  --dry-run   Report drift only; do not write myitems.cost_price.\n";
    echo "  --apply     Write the recalculated cost for each non-manual active recipe.\n";
    echo "  --limit=N   Process at most N recipes (0 = all).\n";
    echo "  --json      Emit machine-readable JSON.\n";
}

function recipeBackfillItemCostsRun(mysqli $conn, bool $dryRun, int $limit): array
{
    $flags = new RecipeFeatureFlags();
    if (!$flags->isEnabled()) {
        return ['ok' => true, 'mode' => 'recipes_disabled', 'processed' => 0, 'drift' => 0, 'resynced' => 0, 'skipped_manual' => 0, 'items' => []];
    }

    $rows = $conn->query(
        "SELECT id, sellable_item_id, pos_tenant, pos_branch, branch_uuid, costing_method, version_number
         FROM recipe_headers
         WHERE status = 'active'
           AND (effective_from IS NULL OR effective_from <= CURRENT_TIMESTAMP)
           AND (effective_to IS NULL OR effective_to > CURRENT_TIMESTAMP)
         ORDER BY id ASC"
        . ($limit > 0 ? ' LIMIT ' . $limit : '')
    );

    $readService = new RecipeEditorReadService();
    $costService = new RecipeEditorItemCostService();

    $processed = 0;
    $drift = 0;
    $resynced = 0;
    $skippedManual = 0;
    $items = [];

    while ($recipe = $rows->fetch_assoc()) {
        $recipeId = (int) $recipe['id'];
        $processed++;

        $previewContext = [
            'pos_tenant' => (int) ($recipe['pos_tenant'] ?? 0),
            'pos_branch' => (int) ($recipe['pos_branch'] ?? 0),
            'branch_uuid' => $recipe['branch_uuid'] ?? null,
            'store_id' => 0,
            'order_type' => 'takeaway',
            'channel' => 'pos',
            'costing_method' => (string) ($recipe['costing_method'] ?? 'item_cost_price'),
        ];

        // Use the SAME detail source as RecipeEditorItemCostService::applyAutoItemCosts
        // (RecipeEditorReadService::recipeDetail) so the dry-run drift check inspects exactly
        // the items that --apply will write. For variant-based recipes this is the variant
        // items, not the parent sellable item; checking the parent would be a false positive.
        $recipeDetail = $readService->recipeDetail($conn, $recipeId);
        if (!$recipeDetail) {
            $items[] = ['recipe_id' => $recipeId, 'item_id' => 0, 'action' => 'missing_detail', 'stored' => '0', 'calculated' => '0'];
            continue;
        }

        $state = $costService->buildEditorState(
            $conn,
            $recipeDetail,
            $previewContext,
            true
        );

        if (empty($state['items'])) {
            $mainItemId = (int) ($recipe['sellable_item_id'] ?? 0);
            $items[] = ['recipe_id' => $recipeId, 'item_id' => $mainItemId, 'action' => 'no_cost_state', 'stored' => '0', 'calculated' => '0'];
            continue;
        }

        foreach ($state['items'] ?? [] as $rowItemId => $row) {
            $manual = !empty($row['manual_cost_edit']);
            $calculated = (string) ($row['calculated_cost'] ?? '0');
            // stored_cost is the actual myitems.cost_price read from the DB; display_cost
            // already equals calculated_cost for non-manual items, so drift must compare
            // stored_cost against calculated_cost.
            $stored = (string) ($row['stored_cost'] ?? '0');

            if ($manual) {
                $skippedManual++;
                $items[] = ['recipe_id' => $recipeId, 'item_id' => (int) $rowItemId, 'action' => 'skipped_manual', 'stored' => $stored, 'calculated' => $calculated];
                continue;
            }

            $differs = recipeBackfillItemCostsCompare($stored, $calculated);
            if (!$differs) {
                $items[] = ['recipe_id' => $recipeId, 'item_id' => (int) $rowItemId, 'action' => 'in_sync', 'stored' => $stored, 'calculated' => $calculated];
                continue;
            }

            $drift++;
            if ($dryRun) {
                $items[] = ['recipe_id' => $recipeId, 'item_id' => (int) $rowItemId, 'action' => 'drift', 'stored' => $stored, 'calculated' => $calculated];
                continue;
            }

            try {
                $costService->applyAutoItemCosts($conn, $recipeId, $previewContext);
                $resynced++;
                $items[] = ['recipe_id' => $recipeId, 'item_id' => (int) $rowItemId, 'action' => 'resynced', 'stored' => $stored, 'calculated' => $calculated];
            } catch (Throwable $exception) {
                $items[] = ['recipe_id' => $recipeId, 'item_id' => (int) $rowItemId, 'action' => 'error', 'stored' => $stored, 'calculated' => $calculated, 'error' => $exception->getMessage()];
            }
        }
    }

    return [
        'ok' => true,
        'mode' => $dryRun ? 'dry_run' : 'apply',
        'processed' => $processed,
        'drift' => $drift,
        'resynced' => $resynced,
        'skipped_manual' => $skippedManual,
        'items' => $items,
    ];
}

function recipeBackfillItemCostsCompare(string $a, string $b): bool
{
    $a = trim($a);
    $b = trim($b);
    if ($a === '' || $b === '') {
        return $a !== $b;
    }
    $af = round((float) $a, 6);
    $bf = round((float) $b, 6);

    return abs($af - $bf) > 0.000001;
}

function recipeBackfillItemCostsPrintHuman(array $result, bool $dryRun): void
{
    echo ($dryRun ? "Recipe item cost backfill (DRY RUN)\n" : "Recipe item cost backfill (APPLY)\n");
    echo "Mode:            " . ($result['mode'] ?? '') . "\n";
    echo "Recipes seen:    " . (int) ($result['processed'] ?? 0) . "\n";
    echo "Drift detected:  " . (int) ($result['drift'] ?? 0) . "\n";
    echo "Resynced:        " . (int) ($result['resynced'] ?? 0) . "\n";
    echo "Skipped (manual):" . (int) ($result['skipped_manual'] ?? 0) . "\n";

    foreach ($result['items'] ?? [] as $item) {
        $line = sprintf(
            "  recipe=%d item=%d action=%s stored=%s calculated=%s",
            (int) ($item['recipe_id'] ?? 0),
            (int) ($item['item_id'] ?? 0),
            (string) ($item['action'] ?? ''),
            (string) ($item['stored'] ?? ''),
            (string) ($item['calculated'] ?? '')
        );
        if (!empty($item['error'])) {
            $line .= ' error=' . $item['error'];
        }
        echo $line . "\n";
    }
}
