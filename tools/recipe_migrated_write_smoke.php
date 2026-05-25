<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../classes/Recipe/DTO/RecipeOrderLineContext.php';
require_once __DIR__ . '/../classes/Recipe/RecipeDecimal.php';
require_once __DIR__ . '/../classes/Recipe/RecipeExplosionService.php';
require_once __DIR__ . '/../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../classes/Recipe/RecipePilotFixtureService.php';

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
    'qty::',
    'price::',
    'store-id::',
    'pos-tenant::',
    'pos-branch::',
]);

if (isset($options['help'])) {
    recipeMigratedWriteSmokeUsage();
    exit(0);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $config = posmain_app_config();
    $conn = posmain_db_connect();
    $toolOptions = recipeMigratedWriteSmokeOptions($conn, $options);
    $result = recipeMigratedWriteSmokeRun($conn, $config, $toolOptions);
    $conn->close();
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'applied' => false,
        'error' => get_class($exception),
        'message' => $exception->getMessage(),
        'blockers' => ['recipe_migrated_write_smoke_failed'],
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    recipeMigratedWriteSmokePrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function recipeMigratedWriteSmokeUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_migrated_write_smoke.php [--json] [--apply] [--allow-hosted-staging] [--run-id=qa-001] [--item-id=987672] [--qty=1] [--price=55] [--store-id=27] [--pos-tenant=0] [--pos-branch=0]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Runs a guarded migrated-runtime write smoke against the real takeaway order service.\n");
    fwrite(STDOUT, "Dry-run is the default. Apply mode creates one named paid QA takeaway order, replays the same idempotency key, and proves recipe usage/movements are not duplicated.\n");
    fwrite(STDOUT, "The tool refuses production, hosted/router runtimes unless explicitly allowed, disabled/non-consumption recipe modes, non-pilot items, and fixture stores that have not been verified.\n");
    fwrite(STDOUT, "It disables sync outbox recording for the smoke request and does not post recipe accounting unless the runtime flags already enable accounting.\n");
}

function recipeMigratedWriteSmokeOptions(mysqli $conn, array $options): array
{
    $defaults = recipeMigratedWriteSmokeDefaults($conn);
    $runId = trim((string) ($options['run-id'] ?? ('local-' . gmdate('YmdHis'))));
    $runId = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $runId) ?: ('local-' . gmdate('YmdHis'));

    return [
        'apply' => isset($options['apply']),
        'allow_hosted_staging' => isset($options['allow-hosted-staging']),
        'run_id' => $runId,
        'item_id' => isset($options['item-id']) ? (int) $options['item-id'] : (int) $defaults['item_id'],
        'qty' => isset($options['qty']) ? (string) $options['qty'] : '1',
        'price' => isset($options['price']) ? (string) $options['price'] : (string) $defaults['price'],
        'store_id' => isset($options['store-id']) ? (int) $options['store-id'] : (int) $defaults['store_id'],
        'customer_id' => (int) $defaults['customer_id'],
        'employee_id' => (int) $defaults['employee_id'],
        'fund_id' => (int) $defaults['fund_id'],
        'user_id' => (int) $defaults['user_id'],
        'pos_tenant' => isset($options['pos-tenant']) ? (int) $options['pos-tenant'] : 0,
        'pos_branch' => isset($options['pos-branch']) ? (int) $options['pos-branch'] : 0,
    ];
}

function recipeMigratedWriteSmokeDefaults(mysqli $conn): array
{
    $settings = recipeMigratedWriteSmokeFetchOne($conn, 'SELECT def_pos_client, def_pos_store, def_pos_employee, def_pos_fund FROM settings WHERE isdeleted = 0 ORDER BY id ASC LIMIT 1');
    $item = recipeMigratedWriteSmokeFetchOne($conn, "SELECT id, price1 FROM myitems WHERE barcode = 'RQA-LATTE' AND isdeleted = 0 LIMIT 1")
        ?: recipeMigratedWriteSmokeFetchOne($conn, "SELECT id, price1 FROM myitems WHERE iname = 'Recipe QA Latte' AND isdeleted = 0 LIMIT 1");
    $user = recipeMigratedWriteSmokeFetchOne($conn, "SELECT id FROM users WHERE uname = 'omar' AND isdeleted = 0 LIMIT 1")
        ?: recipeMigratedWriteSmokeFetchOne($conn, "SELECT id FROM users WHERE isdeleted = 0 ORDER BY id ASC LIMIT 1");
    $customerId = (int) ($settings['def_pos_client'] ?? 0);
    if ($customerId < 1 || !recipeMigratedWriteSmokeAccountExists($conn, $customerId)) {
        $fallbackCustomer = recipeMigratedWriteSmokeFetchOne($conn, "SELECT id FROM acc_head WHERE id = 148 AND isdeleted = 0 LIMIT 1")
            ?: recipeMigratedWriteSmokeFetchOne($conn, "SELECT id FROM acc_head WHERE isdeleted = 0 ORDER BY id DESC LIMIT 1");
        $customerId = (int) ($fallbackCustomer['id'] ?? 0);
    }

    return [
        'item_id' => (int) ($item['id'] ?? 0),
        'price' => (string) ($item['price1'] ?? '55'),
        'store_id' => (int) ($settings['def_pos_store'] ?? 0),
        'customer_id' => $customerId,
        'employee_id' => (int) ($settings['def_pos_employee'] ?? 0),
        'fund_id' => (int) ($settings['def_pos_fund'] ?? 0),
        'user_id' => (int) ($user['id'] ?? 1),
    ];
}

function recipeMigratedWriteSmokeRun(mysqli $conn, array $config, array $options): array
{
    $flags = new RecipeFeatureFlags($config);
    $request = recipeMigratedWriteSmokeRequest($options);
    $safety = recipeMigratedWriteSmokeSafety($config, $flags, $options, $conn);
    $before = recipeMigratedWriteSmokeSnapshot($conn, $options);

    $result = [
        'ok' => false,
        'applied' => false,
        'dry_run' => empty($options['apply']),
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'mode' => $flags->mode(),
        'run_id' => $options['run_id'],
        'idempotency_key' => $request['idempotency_key'],
        'scope' => [
            'pos_tenant' => $options['pos_tenant'],
            'pos_branch' => $options['pos_branch'],
            'store_id' => $options['store_id'],
            'item_id' => $options['item_id'],
        ],
        'runtime_safety' => $safety['summary'],
        'fixture_verification' => $safety['fixture_verification'] ?? null,
        'stock_preflight' => $safety['stock_preflight'] ?? null,
        'before' => $before,
        'would_write_on_apply' => [
            'one ot_head paid takeaway QA order plus receipt/journal rows from the existing service',
            'one fat_details sellable line for the QA item',
            'recipe_order_line_usage rows produced by RecipeOrderLifecycleService',
            'inventory_movements recipe_consumption rows and inventory_item_balances changes for the selected store',
            'one pos_request_keys completed idempotency row',
        ],
        'does_not_write' => [
            'feature flags',
            'recipe definitions',
            'router metadata',
            'sync outbox rows for this smoke request',
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

    $service = new PosOrderMutationService();
    $context = [
        'user_id' => $options['user_id'],
        'tenant' => $options['pos_tenant'],
        'branch' => $options['pos_branch'],
        'pos_tenant' => $options['pos_tenant'],
        'pos_branch' => $options['pos_branch'],
        'store_id' => $options['store_id'],
        'event_source' => 'recipe_migrated_write_smoke',
        'record_outbox' => false,
        'config' => $config,
    ];

    $created = $service->createTakeawayOrder($conn, $request, $context);
    $replayed = $service->createTakeawayOrder($conn, $request, $context);
    $orderId = (int) ($created['data']['order_id'] ?? 0);
    $after = recipeMigratedWriteSmokeSnapshot($conn, $options, $orderId);
    $proof = recipeMigratedWriteSmokeProof($conn, $options, $request, $created, $replayed, $before, $after, $orderId);

    $result['applied'] = true;
    $result['dry_run'] = false;
    $result['created_response'] = $created;
    $result['replay_response'] = $replayed;
    $result['after'] = $after;
    $result['proof'] = $proof;
    $result['ok'] = $proof['ok'];
    $result['blockers'] = $proof['blockers'];

    return $result;
}

function recipeMigratedWriteSmokeSafety(array $config, RecipeFeatureFlags $flags, array $options, mysqli $conn): array
{
    $summary = recipeMigratedWriteSmokeSafetySummary($config, $options);
    $blockers = [];
    $warnings = [];

    if (!empty($summary['production_mode']) || in_array($summary['env'], ['production', 'prod'], true)) {
        $blockers[] = 'recipe_migrated_write_smoke_refuses_production_runtime';
    }
    if (!empty($summary['hosted_or_router_runtime']) && empty($options['allow_hosted_staging'])) {
        $blockers[] = 'recipe_migrated_write_smoke_hosted_staging_requires_explicit_allow';
    }
    if ($options['store_id'] < 1 || $options['customer_id'] < 1 || $options['employee_id'] < 1 || $options['fund_id'] < 1 || $options['user_id'] < 1) {
        $blockers[] = 'recipe_migrated_write_smoke_missing_pos_defaults';
    }
    foreach ([
        'customer' => $options['customer_id'],
        'fund' => $options['fund_id'],
        'service_sales_account_91' => 91,
    ] as $accountLabel => $accountId) {
        if ((int) $accountId < 1 || !recipeMigratedWriteSmokeAccountExists($conn, (int) $accountId)) {
            $blockers[] = 'recipe_migrated_write_smoke_missing_account_' . $accountLabel;
        }
    }
    if ($options['item_id'] < 1) {
        $blockers[] = 'recipe_migrated_write_smoke_missing_fixture_latte_item';
    }
    if (!$flags->isEnabled() || !$flags->isConsumptionEnabledForItem(new RecipeScope($options['pos_tenant'], $options['pos_branch'], $options['store_id']), $options['item_id'])) {
        $blockers[] = 'recipe_migrated_write_smoke_requires_consumption_pilot_flags';
    }

    $fixtureOptions = [
        'verify' => true,
        'prefix' => 'Recipe QA',
        'barcode_prefix' => 'RQA',
        'pos_tenant' => $options['pos_tenant'],
        'pos_branch' => $options['pos_branch'],
        'store_id' => $options['store_id'],
        'allow_hosted_staging' => !empty($options['allow_hosted_staging']),
    ];
    $fixture = (new RecipePilotFixtureService())->verify($conn, $fixtureOptions);
    if (empty($fixture['ok'])) {
        $blockers[] = 'recipe_migrated_write_smoke_fixture_not_verified_for_store';
        $warnings[] = 'Run: php tools/recipe_pilot_fixture.php --apply --verify --store-id=' . $options['store_id'] . ' --json';
    }

    $stockPreflight = null;
    if ($blockers === []) {
        $stockPreflight = recipeMigratedWriteSmokeStockPreflight($conn, $flags, $options);
        if (empty($stockPreflight['ok'])) {
            $blockers[] = 'recipe_migrated_write_smoke_insufficient_fixture_stock';
        }
    }

    $existing = recipeMigratedWriteSmokeFetchOne($conn, 'SELECT COUNT(*) AS c FROM pos_request_keys WHERE scope = ? AND idempotency_key = ?', [
        PosOrderMutationService::SCOPE_TAKEAWAY_CREATE,
        recipeMigratedWriteSmokeIdempotencyKey($options),
    ]);
    if ((int) ($existing['c'] ?? 0) > 0) {
        if (!empty($options['apply'])) {
            $blockers[] = 'recipe_migrated_write_smoke_run_id_already_used';
        } else {
            $warnings[] = 'This run-id already has a completed or pending pos_request_keys row; choose a fresh --run-id before --apply.';
        }
    }

    return [
        'summary' => $summary,
        'fixture_verification' => $fixture,
        'stock_preflight' => $stockPreflight,
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

function recipeMigratedWriteSmokeStockPreflight(mysqli $conn, RecipeFeatureFlags $flags, array $options): array
{
    $context = new RecipeOrderLineContext([
        'pos_tenant' => $options['pos_tenant'],
        'pos_branch' => $options['pos_branch'],
        'store_id' => $options['store_id'],
        'sellable_item_id' => $options['item_id'],
        'quantity' => $options['qty'],
        'order_type' => 'takeaway',
        'channel' => 'pos',
        'requested_at' => date('Y-m-d H:i:s'),
    ]);
    $explosion = (new RecipeExplosionService($flags))->explodeOrderLine($conn, $context);
    if (!$explosion->hasRecipe) {
        return [
            'ok' => false,
            'has_recipe' => false,
            'fallback_mode' => $explosion->fallbackMode,
            'requirements' => [],
            'shortages' => [
                [
                    'item_id' => $options['item_id'],
                    'reason' => 'no_active_recipe_for_fixture_item',
                ],
            ],
        ];
    }

    $requirements = [];
    foreach ($explosion->requirements as $requirement) {
        if (!$requirement->isRequired || $requirement->ingredientItemId < 1) {
            continue;
        }
        $itemId = (int) $requirement->ingredientItemId;
        $current = $requirements[$itemId]['required_qty_base'] ?? '0.000000';
        $requirements[$itemId] = [
            'item_id' => $itemId,
            'required_qty_base' => RecipeDecimal::add($current, $requirement->requiredQtyBase),
        ];
    }

    $rows = [];
    $shortages = [];
    foreach ($requirements as $itemId => $requirement) {
        $balance = recipeMigratedWriteSmokeFetchOne($conn, '
SELECT b.item_id, i.iname, b.store_id, b.qty_on_hand, b.qty_reserved, b.qty_available
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
            $itemId,
        ]);
        $available = $balance
            ? RecipeDecimal::normalize($balance['qty_available'] ?? RecipeDecimal::subtract((string) ($balance['qty_on_hand'] ?? '0'), (string) ($balance['qty_reserved'] ?? '0')))
            : '0.000000';
        $required = RecipeDecimal::normalize($requirement['required_qty_base']);
        $row = [
            'item_id' => (int) $itemId,
            'name' => (string) ($balance['iname'] ?? ''),
            'store_id' => $options['store_id'],
            'required_qty_base' => $required,
            'qty_available' => $available,
            'qty_on_hand' => (string) ($balance['qty_on_hand'] ?? '0.000000'),
            'qty_reserved' => (string) ($balance['qty_reserved'] ?? '0.000000'),
            'enough_stock' => RecipeDecimal::compare($available, $required) >= 0,
        ];
        $rows[] = $row;
        if (empty($row['enough_stock'])) {
            $shortages[] = $row;
        }
    }

    return [
        'ok' => $shortages === [],
        'has_recipe' => true,
        'recipe_id' => $explosion->recipeId,
        'recipe_version' => $explosion->recipeVersion,
        'requirements' => $rows,
        'shortages' => $shortages,
    ];
}

function recipeMigratedWriteSmokeSafetySummary(array $config, array $options): array
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
    ];
}

function recipeMigratedWriteSmokeRequest(array $options): array
{
    $qty = (float) $options['qty'];
    $price = (float) $options['price'];
    $total = round($qty * $price, 2);

    return [
        'store_id' => $options['store_id'],
        'idempotency_key' => recipeMigratedWriteSmokeIdempotencyKey($options),
        'pro_serial' => 'RQA-WRITE-' . $options['run_id'],
        'pro_date' => date('Y-m-d'),
        'accural_date' => date('Y-m-d'),
        'acc2_id' => $options['customer_id'],
        'emp_id' => $options['employee_id'],
        'headtotal' => $total,
        'headdisc' => 0,
        'headplus' => 0,
        'headnet' => $total,
        'fund_id' => $options['fund_id'],
        'payment_fund_id' => $options['fund_id'],
        'paid' => $total,
        'paid_cash' => $total,
        'paid_bank' => 0,
        'info' => 'Recipe QA migrated write smoke ' . $options['run_id'],
        'itmname' => [$options['item_id']],
        'itmqty' => [$qty],
        'itmprice' => [$price],
        'itmdisc' => [0],
        'u_val' => [1],
    ];
}

function recipeMigratedWriteSmokeIdempotencyKey(array $options): string
{
    return 'recipe-migrated-write-smoke:' . $options['run_id'];
}

function recipeMigratedWriteSmokeSnapshot(mysqli $conn, array $options, int $orderId = 0): array
{
    $whereOrder = $orderId > 0 ? ' AND order_id = ' . (int) $orderId : '';
    $usage = recipeMigratedWriteSmokeFetchOne($conn, 'SELECT COUNT(*) AS c FROM recipe_order_line_usage WHERE pos_tenant = ? AND pos_branch = ? AND store_id = ?' . $whereOrder, [
        $options['pos_tenant'],
        $options['pos_branch'],
        $options['store_id'],
    ]);
    $movements = recipeMigratedWriteSmokeFetchOne($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(qty_out), 0) AS qty_out FROM inventory_movements WHERE pos_tenant = ? AND pos_branch = ? AND store_id = ? AND movement_type = 'recipe_consumption'" . $whereOrder, [
        $options['pos_tenant'],
        $options['pos_branch'],
        $options['store_id'],
    ]);
    $orders = recipeMigratedWriteSmokeFetchOne($conn, "SELECT COUNT(*) AS c FROM ot_head WHERE info LIKE ? AND pro_tybe = 9", [
        '%Recipe QA migrated write smoke ' . $options['run_id'] . '%',
    ]);

    return [
        'qa_orders_for_run' => (int) ($orders['c'] ?? 0),
        'recipe_usage_rows' => (int) ($usage['c'] ?? 0),
        'recipe_consumption_movements' => (int) ($movements['c'] ?? 0),
        'recipe_consumption_qty_out_sum' => (string) ($movements['qty_out'] ?? '0.000000'),
        'balances' => recipeMigratedWriteSmokeBalances($conn, $options),
    ];
}

function recipeMigratedWriteSmokeBalances(mysqli $conn, array $options): array
{
    $rows = recipeMigratedWriteSmokeFetchAll($conn, "
SELECT b.item_id, i.iname, b.store_id, b.qty_on_hand, b.qty_reserved, b.qty_available
FROM inventory_item_balances b
JOIN myitems i ON i.id = b.item_id
WHERE b.pos_tenant = ?
  AND b.pos_branch = ?
  AND b.store_id = ?
  AND i.iname LIKE 'Recipe QA%'
ORDER BY b.item_id", [
        $options['pos_tenant'],
        $options['pos_branch'],
        $options['store_id'],
    ]);

    return array_map(static function (array $row): array {
        return [
            'item_id' => (int) $row['item_id'],
            'name' => (string) $row['iname'],
            'store_id' => (int) $row['store_id'],
            'qty_on_hand' => (string) $row['qty_on_hand'],
            'qty_reserved' => (string) $row['qty_reserved'],
            'qty_available' => (string) $row['qty_available'],
        ];
    }, $rows);
}

function recipeMigratedWriteSmokeProof(mysqli $conn, array $options, array $request, array $created, array $replayed, array $before, array $after, int $orderId): array
{
    $blockers = [];
    if (empty($created['success']) || $orderId < 1) {
        $blockers[] = 'recipe_migrated_write_smoke_order_not_created';
    }
    if (empty($replayed['idempotency_replayed']) || (int) ($replayed['data']['order_id'] ?? 0) !== $orderId) {
        $blockers[] = 'recipe_migrated_write_smoke_replay_did_not_return_same_order';
    }

    $orderRows = recipeMigratedWriteSmokeFetchOne($conn, 'SELECT COUNT(*) AS c FROM ot_head WHERE id = ? AND payment_status = ? AND order_status = ?', [$orderId, 'paid', 'completed']);
    if ((int) ($orderRows['c'] ?? 0) !== 1) {
        $blockers[] = 'recipe_migrated_write_smoke_order_not_paid_completed';
    }

    $usages = recipeMigratedWriteSmokeFetchAll($conn, 'SELECT id, status, sellable_item_id, order_qty, recipe_id, explosion_json, cost_total FROM recipe_order_line_usage WHERE order_id = ? ORDER BY id', [$orderId]);
    if (count($usages) !== 1 || (string) ($usages[0]['status'] ?? '') !== 'consumed') {
        $blockers[] = 'recipe_migrated_write_smoke_expected_one_consumed_usage';
    }
    if (count($usages) === 1 && (float) ($usages[0]['cost_total'] ?? 0) <= 0) {
        $blockers[] = 'recipe_migrated_write_smoke_expected_positive_usage_cost';
    }

    $movements = recipeMigratedWriteSmokeFetchAll($conn, "SELECT id, item_id, qty_out, total_cost, idempotency_key, recipe_order_line_usage_id FROM inventory_movements WHERE order_id = ? AND movement_type = 'recipe_consumption' ORDER BY item_id, id", [$orderId]);
    if (count($movements) < 2) {
        $blockers[] = 'recipe_migrated_write_smoke_expected_ingredient_and_packaging_movements';
    }

    $distinctKeys = [];
    foreach ($movements as $movement) {
        $key = (string) ($movement['idempotency_key'] ?? '');
        if ($key === '') {
            $blockers[] = 'recipe_migrated_write_smoke_blank_movement_idempotency_key';
        }
        if ((float) ($movement['total_cost'] ?? 0) <= 0) {
            $blockers[] = 'recipe_migrated_write_smoke_expected_positive_movement_cost';
        }
        $distinctKeys[$key] = true;
    }
    if (count($distinctKeys) !== count($movements)) {
        $blockers[] = 'recipe_migrated_write_smoke_duplicate_movement_idempotency_keys';
    }

    if ((int) ($after['qa_orders_for_run'] ?? 0) !== (int) ($before['qa_orders_for_run'] ?? 0) + 1) {
        $blockers[] = 'recipe_migrated_write_smoke_replay_created_duplicate_order';
    }

    $requestKeyRows = recipeMigratedWriteSmokeFetchOne($conn, 'SELECT COUNT(*) AS c FROM pos_request_keys WHERE scope = ? AND idempotency_key = ? AND status = ?', [
        PosOrderMutationService::SCOPE_TAKEAWAY_CREATE,
        $request['idempotency_key'],
        'completed',
    ]);
    if ((int) ($requestKeyRows['c'] ?? 0) !== 1) {
        $blockers[] = 'recipe_migrated_write_smoke_missing_completed_request_key';
    }

    return [
        'ok' => $blockers === [],
        'blockers' => array_values(array_unique($blockers)),
        'order_id' => $orderId,
        'usage_rows' => $usages,
        'movement_rows' => $movements,
        'idempotency_replayed' => !empty($replayed['idempotency_replayed']),
        'reversal_needed' => true,
        'cleanup_guidance' => 'This smoke intentionally creates a paid QA order and recipe consumption ledger rows. Preserve it as pilot evidence or reverse through the normal paid reversal flow; do not delete rows directly.',
    ];
}

function recipeMigratedWriteSmokeFetchOne(mysqli $conn, string $sql, array $params = []): ?array
{
    $rows = recipeMigratedWriteSmokeFetchAll($conn, $sql, $params);

    return $rows[0] ?? null;
}

function recipeMigratedWriteSmokeFetchAll(mysqli $conn, string $sql, array $params = []): array
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

function recipeMigratedWriteSmokeAccountExists(mysqli $conn, int $accountId): bool
{
    if ($accountId < 1) {
        return false;
    }

    $row = recipeMigratedWriteSmokeFetchOne($conn, 'SELECT id FROM acc_head WHERE id = ? AND isdeleted = 0 LIMIT 1', [$accountId]);

    return $row !== null;
}

function recipeMigratedWriteSmokePrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe migrated write smoke: ' . (!empty($result['ok']) ? 'OK' : 'FAILED') . PHP_EOL);
    fwrite(STDOUT, '- mode: ' . (string) ($result['mode'] ?? 'unknown') . PHP_EOL);
    fwrite(STDOUT, '- run id: ' . (string) ($result['run_id'] ?? '') . PHP_EOL);
    fwrite(STDOUT, '- apply: ' . (!empty($result['applied']) ? 'yes' : 'no') . PHP_EOL);
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
    if (!empty($result['proof']['order_id'])) {
        fwrite(STDOUT, '- order id: ' . (string) $result['proof']['order_id'] . PHP_EOL);
        fwrite(STDOUT, '- movement rows: ' . count($result['proof']['movement_rows'] ?? []) . PHP_EOL);
    }
}
