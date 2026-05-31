<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Inventory/InventoryAdjustmentService.php';
require_once __DIR__ . '/../classes/Inventory/InventoryDecimal.php';
require_once __DIR__ . '/../classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/../classes/Recipe/RecipeFeatureFlags.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'apply',
    'json',
    'help',
    'allow-hosted-staging',
    'run-id::',
    'item-id::',
    'barcode::',
    'qty::',
    'unit-cost::',
    'store-id::',
    'pos-tenant::',
    'pos-branch::',
    'user-id::',
    'reason::',
]);

if (isset($options['help'])) {
    recipeFixtureStockAdjustmentUsage();
    exit(0);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $config = posmain_app_config();
    $conn = posmain_db_connect();
    $toolOptions = recipeFixtureStockAdjustmentOptions($conn, $options);
    $result = recipeFixtureStockAdjustmentRun($conn, $config, $toolOptions);
    $conn->close();
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'applied' => false,
        'error' => get_class($exception),
        'message' => $exception->getMessage(),
        'blockers' => ['recipe_fixture_stock_adjustment_failed'],
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    recipeFixtureStockAdjustmentPrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function recipeFixtureStockAdjustmentUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_fixture_stock_adjustment.php [--json] [--apply] [--allow-hosted-staging] [--run-id=qa-cup-001] [--barcode=RQA-CUP] [--qty=3] [--unit-cost=0.15] [--store-id=27] [--pos-tenant=0] [--pos-branch=0]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Runs a guarded increase stock adjustment for named Recipe QA fixture stock through InventoryAdjustmentService.\n");
    fwrite(STDOUT, "Dry-run is the default. Apply mode writes one idempotent adjustment movement and replays the same source UUID to prove no duplicate adjustment is created.\n");
    fwrite(STDOUT, "The tool refuses production and hosted/router runtimes unless explicitly allowed, refuses non-Recipe-QA items, and never updates inventory_item_balances directly.\n");
}

function recipeFixtureStockAdjustmentOptions(mysqli $conn, array $options): array
{
    $barcode = trim((string) ($options['barcode'] ?? 'RQA-CUP'));
    $itemId = isset($options['item-id']) ? (int) $options['item-id'] : 0;
    $item = $itemId > 0
        ? recipeFixtureStockAdjustmentFetchOne($conn, 'SELECT id, iname, barcode, cost_price, group1 FROM myitems WHERE id = ? AND isdeleted = 0 LIMIT 1', [$itemId])
        : recipeFixtureStockAdjustmentFetchOne($conn, 'SELECT id, iname, barcode, cost_price, group1 FROM myitems WHERE barcode = ? AND isdeleted = 0 LIMIT 1', [$barcode]);
    $settings = recipeFixtureStockAdjustmentFetchOne($conn, 'SELECT def_pos_store FROM settings WHERE isdeleted = 0 ORDER BY id ASC LIMIT 1');
    $user = isset($options['user-id'])
        ? ['id' => (int) $options['user-id']]
        : (recipeFixtureStockAdjustmentFetchOne($conn, "SELECT id FROM users WHERE uname = 'omar' AND isdeleted = 0 LIMIT 1")
            ?: recipeFixtureStockAdjustmentFetchOne($conn, "SELECT id FROM users WHERE isdeleted = 0 ORDER BY id ASC LIMIT 1"));
    $runId = trim((string) ($options['run-id'] ?? ('fixture-adjust-' . gmdate('YmdHis'))));
    $runId = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $runId) ?: ('fixture-adjust-' . gmdate('YmdHis'));

    return [
        'apply' => array_key_exists('apply', $options),
        'allow_hosted_staging' => array_key_exists('allow-hosted-staging', $options),
        'run_id' => $runId,
        'adjustment_uuid' => recipeFixtureStockAdjustmentUuidFromRunId($runId),
        'item' => $item,
        'item_id' => (int) ($item['id'] ?? 0),
        'barcode' => (string) ($item['barcode'] ?? $barcode),
        'qty' => isset($options['qty']) ? (string) $options['qty'] : '3',
        'unit_cost' => isset($options['unit-cost']) ? (string) $options['unit-cost'] : (string) ($item['cost_price'] ?? '0'),
        'store_id' => isset($options['store-id']) ? (int) $options['store-id'] : (int) ($settings['def_pos_store'] ?? 0),
        'pos_tenant' => isset($options['pos-tenant']) ? (int) $options['pos-tenant'] : 0,
        'pos_branch' => isset($options['pos-branch']) ? (int) $options['pos-branch'] : 0,
        'user_id' => (int) ($user['id'] ?? 1),
        'reason' => trim((string) ($options['reason'] ?? ('Recipe QA fixture stock replenish ' . $runId))),
    ];
}

function recipeFixtureStockAdjustmentRun(mysqli $conn, array $config, array $options): array
{
    $recipeFlags = new RecipeFeatureFlags($config);
    $inventoryFlags = new InventoryFeatureFlags($config);
    $safety = recipeFixtureStockAdjustmentSafety($config, $recipeFlags, $inventoryFlags, $options);
    $before = recipeFixtureStockAdjustmentBalance($conn, $options);
    $result = [
        'ok' => false,
        'applied' => false,
        'dry_run' => empty($options['apply']),
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'mode' => $recipeFlags->mode(),
        'recipe_mode' => $recipeFlags->mode(),
        'inventory_mode' => $inventoryFlags->mode(),
        'run_id' => $options['run_id'],
        'adjustment_uuid' => $options['adjustment_uuid'],
        'scope' => [
            'pos_tenant' => $options['pos_tenant'],
            'pos_branch' => $options['pos_branch'],
            'store_id' => $options['store_id'],
            'item_id' => $options['item_id'],
            'barcode' => $options['barcode'],
        ],
        'runtime_safety' => $safety['summary'],
        'before' => $before,
        'would_write_on_apply' => [
            'one inventory_movements adjustment row through InventoryAdjustmentService',
            'one inventory_item_balances increase for the selected Recipe QA fixture item',
            'optional inventory accounting journals only when inventory accounting flags/accounts are enabled',
        ],
        'does_not_write' => [
            'feature flags',
            'recipe definitions',
            'orders/payments',
            'sync outbox rows',
            'router metadata',
        ],
        'blockers' => $safety['blockers'],
        'warnings' => $safety['warnings'],
    ];

    if ($safety['blockers'] !== []) {
        return $result;
    }

    if (empty($options['apply'])) {
        $result['ok'] = true;
        return $result;
    }

    $input = [
        'pos_tenant' => $options['pos_tenant'],
        'pos_branch' => $options['pos_branch'],
        'store_id' => $options['store_id'],
        'item_id' => $options['item_id'],
        'direction' => 'increase',
        'qty' => InventoryDecimal::normalize($options['qty']),
        'unit_cost' => InventoryDecimal::normalize($options['unit_cost']),
        'operation_uuid' => $options['adjustment_uuid'],
        'reason' => $options['reason'],
    ];
    $context = [
        'user_id' => $options['user_id'],
        'pos_tenant' => $options['pos_tenant'],
        'pos_branch' => $options['pos_branch'],
        'allow_reason_code_approval' => true,
    ];
    $service = new InventoryAdjustmentService($inventoryFlags);
    $first = $service->recordAdjustment($conn, $input, $context);
    $replay = $service->recordAdjustment($conn, $input, $context);
    $after = recipeFixtureStockAdjustmentBalance($conn, $options);
    $proof = recipeFixtureStockAdjustmentProof($conn, $options, $first, $replay, $before, $after);

    $result['ok'] = $proof['ok'];
    $result['applied'] = true;
    $result['dry_run'] = false;
    $result['first_response'] = $first;
    $result['replay_response'] = $replay;
    $result['after'] = $after;
    $result['proof'] = $proof;
    $result['blockers'] = $proof['blockers'];

    return $result;
}

function recipeFixtureStockAdjustmentSafety(array $config, RecipeFeatureFlags $recipeFlags, InventoryFeatureFlags $inventoryFlags, array $options): array
{
    $summary = recipeFixtureStockAdjustmentSafetySummary($config, $options);
    $blockers = [];
    $warnings = [];
    if (!empty($summary['production_mode']) || in_array($summary['env'], ['production', 'prod'], true)) {
        $blockers[] = 'recipe_fixture_stock_adjustment_refuses_production_runtime';
    }
    if (!empty($summary['hosted_or_router_runtime']) && empty($options['allow_hosted_staging'])) {
        $blockers[] = 'recipe_fixture_stock_adjustment_hosted_staging_requires_explicit_allow';
    }
    if (!$recipeFlags->isEnabled() || in_array($recipeFlags->mode(), ['schema_only', 'read_only'], true)) {
        $blockers[] = 'recipe_fixture_stock_adjustment_requires_writable_recipe_mode';
    }
    if (!$inventoryFlags->canWriteLedger()) {
        $blockers[] = 'recipe_fixture_stock_adjustment_requires_writable_inventory_ledger';
    }
    if ($options['store_id'] < 1 || $options['item_id'] < 1 || $options['user_id'] < 1) {
        $blockers[] = 'recipe_fixture_stock_adjustment_missing_defaults';
    }
    $name = (string) ($options['item']['iname'] ?? '');
    $barcode = (string) ($options['item']['barcode'] ?? '');
    if (strpos($name, 'Recipe QA') !== 0 || strpos($barcode, 'RQA-') !== 0) {
        $blockers[] = 'recipe_fixture_stock_adjustment_refuses_non_fixture_item';
    }
    if (!InventoryDecimal::isPositive($options['qty'])) {
        $blockers[] = 'recipe_fixture_stock_adjustment_qty_must_be_positive';
    }
    if (InventoryDecimal::compare($options['unit_cost'], '0') < 0) {
        $blockers[] = 'recipe_fixture_stock_adjustment_unit_cost_must_not_be_negative';
    }

    return [
        'summary' => $summary,
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

function recipeFixtureStockAdjustmentSafetySummary(array $config, array $options): array
{
    $env = strtolower(trim((string) ($config['env'] ?? 'local')));
    $role = strtolower(trim((string) ($config['role'] ?? 'branch')));
    $routerEnabled = !empty($config['router']['enabled']);

    return [
        'env' => $env,
        'role' => $role,
        'production_mode' => !empty($config['production_mode']),
        'router_enabled' => $routerEnabled,
        'hosted_or_router_runtime' => in_array($role, ['cloud', 'fake_cloud'], true) || $routerEnabled,
        'allow_hosted_staging' => !empty($options['allow_hosted_staging']),
        'inventory_ledger_mode' => strtolower(trim((string) ($config['inventory']['ledger_mode'] ?? 'off'))),
    ];
}

function recipeFixtureStockAdjustmentBalance(mysqli $conn, array $options): array
{
    $row = recipeFixtureStockAdjustmentFetchOne($conn, '
SELECT b.item_id, i.iname, i.barcode, b.store_id, b.qty_on_hand, b.qty_reserved, b.qty_available, b.last_movement_id
FROM inventory_item_balances b
LEFT JOIN myitems i ON i.id = b.item_id
WHERE b.pos_tenant = ?
  AND b.pos_branch = ?
  AND b.store_id = ?
  AND b.item_id = ?
LIMIT 1', [
        $options['pos_tenant'],
        $options['pos_branch'],
        $options['store_id'],
        $options['item_id'],
    ]);

    return [
        'item_id' => (int) ($row['item_id'] ?? $options['item_id']),
        'name' => (string) ($row['iname'] ?? ($options['item']['iname'] ?? '')),
        'barcode' => (string) ($row['barcode'] ?? $options['barcode']),
        'store_id' => $options['store_id'],
        'qty_on_hand' => (string) ($row['qty_on_hand'] ?? '0.000000'),
        'qty_reserved' => (string) ($row['qty_reserved'] ?? '0.000000'),
        'qty_available' => (string) ($row['qty_available'] ?? '0.000000'),
        'last_movement_id' => isset($row['last_movement_id']) ? (int) $row['last_movement_id'] : null,
    ];
}

function recipeFixtureStockAdjustmentProof(mysqli $conn, array $options, array $first, array $replay, array $before, array $after): array
{
    $blockers = [];
    $movementIds = recipeFixtureStockAdjustmentMovementIds($first, $replay);
    if (count($movementIds) !== 1) {
        $blockers[] = 'recipe_fixture_stock_adjustment_expected_one_idempotent_movement';
    }
    if (empty($replay['idempotent_replay'])) {
        $blockers[] = 'recipe_fixture_stock_adjustment_replay_not_idempotent';
    }
    $movement = $movementIds
        ? recipeFixtureStockAdjustmentFetchOne($conn, 'SELECT id, movement_type, source_uuid, qty_in, qty_out, total_cost FROM inventory_movements WHERE id = ? LIMIT 1', [$movementIds[0]])
        : null;
    if (!$movement || (string) ($movement['movement_type'] ?? '') !== 'adjustment') {
        $blockers[] = 'recipe_fixture_stock_adjustment_missing_adjustment_movement';
    }
    if ($movement && InventoryDecimal::compare($movement['qty_in'] ?? '0', $options['qty']) !== 0) {
        $blockers[] = 'recipe_fixture_stock_adjustment_unexpected_qty_in';
    }
    $expectedAvailable = !empty($first['idempotent_replay'])
        ? InventoryDecimal::normalize($before['qty_available'])
        : InventoryDecimal::add($before['qty_available'], $options['qty']);
    if (InventoryDecimal::compare($after['qty_available'], $expectedAvailable) !== 0) {
        $blockers[] = 'recipe_fixture_stock_adjustment_balance_not_increased';
    }

    return [
        'ok' => $blockers === [],
        'blockers' => array_values(array_unique($blockers)),
        'movement_ids' => $movementIds,
        'movement_row' => $movement,
        'idempotency_replayed' => empty($blockers) && !empty($replay['idempotent_replay']),
        'cleanup_guidance' => 'This adjustment intentionally replenishes named local/staging Recipe QA fixture stock. Preserve it as pilot evidence or reverse through the normal stock adjustment flow; do not delete rows directly.',
    ];
}

function recipeFixtureStockAdjustmentMovementIds(array $first, array $replay): array
{
    $ids = [];
    foreach ([$first, $replay] as $response) {
        if (!empty($response['movement_ids']) && is_array($response['movement_ids'])) {
            foreach ($response['movement_ids'] as $movementId) {
                $ids[] = (int) $movementId;
            }
        }
        if (!empty($response['movement_id'])) {
            $ids[] = (int) $response['movement_id'];
        }
    }

    return array_values(array_unique(array_filter($ids, static fn ($movementId) => $movementId > 0)));
}

function recipeFixtureStockAdjustmentUuidFromRunId(string $runId): string
{
    $hex = substr(hash('sha256', 'recipe-fixture-stock-adjustment:' . $runId), 0, 32);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
}

function recipeFixtureStockAdjustmentFetchOne(mysqli $conn, string $sql, array $params = []): ?array
{
    $rows = recipeFixtureStockAdjustmentFetchAll($conn, $sql, $params);

    return $rows[0] ?? null;
}

function recipeFixtureStockAdjustmentFetchAll(mysqli $conn, string $sql, array $params = []): array
{
    if (!$params) {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function recipeFixtureStockAdjustmentPrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe fixture stock adjustment: ' . (!empty($result['ok']) ? 'OK' : 'FAILED') . PHP_EOL);
    fwrite(STDOUT, '- mode: ' . (string) ($result['mode'] ?? 'unknown') . PHP_EOL);
    fwrite(STDOUT, '- run id: ' . (string) ($result['run_id'] ?? '') . PHP_EOL);
    fwrite(STDOUT, '- apply: ' . (!empty($result['applied']) ? 'yes' : 'no') . PHP_EOL);
    if (!empty($result['before']) && is_array($result['before'])) {
        fwrite(STDOUT, '- before available: ' . (string) ($result['before']['qty_available'] ?? '') . PHP_EOL);
    }
    if (!empty($result['after']) && is_array($result['after'])) {
        fwrite(STDOUT, '- after available: ' . (string) ($result['after']['qty_available'] ?? '') . PHP_EOL);
    }
    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }
}
