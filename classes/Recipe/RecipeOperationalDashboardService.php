<?php

require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/../Inventory/NegativeStockSalePolicyService.php';

class RecipeOperationalDashboardService
{
    public function dashboard(mysqli $conn, ?RecipeFeatureFlags $flags = null, array $filters = []): array
    {
        $flags = $flags ?: new RecipeFeatureFlags();
        $limit = $this->limit($filters['limit'] ?? 100);

        $sections = [
            'stale_reservations' => $this->staleReservations($conn, $filters, $limit),
            'negative_balances' => $this->negativeBalances($conn, $filters, $limit),
            'invalid_inventory_movements' => $this->invalidInventoryMovements($conn, $filters, $limit),
            'missing_cost_snapshots' => $this->missingCostSnapshots($conn, $filters, $limit),
            'recipe_setup_issues' => $this->recipeSetupIssues($conn, $filters, $limit),
            'movement_write_gaps' => $this->movementWriteGaps($conn, $filters, $limit),
            'availability_cache_gaps' => $this->availabilityCacheGaps($conn, $filters, $limit),
            'menu_sync_outbox_issues' => $this->menuSyncOutboxIssues($conn, $filters, $limit),
        ];

        $config = $this->configSummary($conn, $flags);
        $summary = $this->summary($sections, $config);
        $summary['recipe_mode'] = $flags->mode();
        $summary['recipe_enabled'] = $flags->isEnabled();

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'config' => $config,
            'summary' => $summary,
            'health' => $this->health($sections, $summary, $config),
            'last_reconciliation' => $this->lastReconciliationSignals($conn, $filters),
            'sections' => $sections,
        ];
    }

    private function configSummary(mysqli $conn, RecipeFeatureFlags $flags): array
    {
        $config = $flags->config();

        return [
            'enabled_configured' => $this->boolValue($config['enabled'] ?? false),
            'enabled_effective' => $flags->isEnabled(),
            'mode' => $flags->mode(),
            'shadow_ledger' => $flags->isShadowLedgerEnabled(),
            'reservations' => $flags->isReservationEnabled(),
            'consumption_configured' => $this->boolValue($config['consumption'] ?? false),
            'accounting_configured' => $this->boolValue($config['accounting'] ?? false),
            'availability_configured' => $this->boolValue($config['availability'] ?? false),
            'availability_effective' => $this->boolValue($config['availability'] ?? false)
                && $flags->isEnabled()
                && in_array($flags->mode(), ['availability_pilot', 'full'], true),
            'moova_sync' => $flags->isMoovaSyncEnabled(),
            'negative_stock_sale_policy' => (new NegativeStockSalePolicyService($flags->appConfig()))->resolve($conn),
            'cost_public_payloads' => $this->boolValue($config['cost_public_payloads'] ?? false),
            'refund_stock_policy' => (string) ($config['refund_stock_policy'] ?? 'waste'),
            'default_reservation_minutes' => (int) ($config['default_reservation_minutes'] ?? 90),
            'production_variance_policy' => $this->productionVariancePolicy($config),
            'pilot_pos_branch' => (string) (($config['pilot'] ?? [])['pos_branch'] ?? ''),
            'pilot_item_count' => count(is_array(($config['pilot'] ?? [])['item_ids'] ?? null) ? ($config['pilot'] ?? [])['item_ids'] : []),
            'pilot_category_count' => count(is_array(($config['pilot'] ?? [])['category_ids'] ?? null) ? ($config['pilot'] ?? [])['category_ids'] : []),
            'php_sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'bcmath_loaded' => function_exists('bcadd'),
        ];
    }

    private function staleReservations(mysqli $conn, array $filters, int $limit): array
    {
        if (!$this->tableExists($conn, 'stock_reservations')) {
            return $this->missingSection('stock_reservations');
        }

        $conditions = [
            "sr.status = 'reserved'",
            'sr.expires_at IS NOT NULL',
            'sr.expires_at < CURRENT_TIMESTAMP',
        ];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'sr', $filters);

        $rows = $this->fetchAll(
            $conn,
            "
SELECT
  sr.id,
  sr.pos_tenant,
  sr.pos_branch,
  sr.store_id,
  sr.order_id,
  sr.fat_detail_id,
  sr.sellable_item_id,
  sellable.iname AS sellable_item_name,
  sr.ingredient_item_id,
  ingredient.iname AS ingredient_item_name,
  sr.qty_reserved,
  sr.expires_at,
  sr.updated_at,
  TIMESTAMPDIFF(MINUTE, sr.expires_at, CURRENT_TIMESTAMP) AS overdue_minutes
FROM stock_reservations sr
LEFT JOIN myitems sellable ON sellable.id = sr.sellable_item_id
LEFT JOIN myitems ingredient ON ingredient.id = sr.ingredient_item_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY sr.expires_at ASC, sr.id ASC
LIMIT " . $limit,
            $params
        );

        return $this->section('stale_reservations', 'Stale reservations', $rows, $this->countRows($conn, 'stock_reservations sr', $conditions, $params));
    }

    private function negativeBalances(mysqli $conn, array $filters, int $limit): array
    {
        if (!$this->tableExists($conn, 'inventory_item_balances')) {
            return $this->missingSection('inventory_item_balances');
        }

        $conditions = ['(b.qty_on_hand < 0 OR b.qty_available < 0 OR b.qty_reserved < 0)'];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'b', $filters);

        $rows = $this->fetchAll(
            $conn,
            "
SELECT
  b.id,
  b.pos_tenant,
  b.pos_branch,
  b.store_id,
  b.item_id,
  item.iname AS item_name,
  b.qty_on_hand,
  b.qty_reserved,
  b.qty_available,
  b.moving_average_cost,
  b.last_movement_id,
  b.updated_at
FROM inventory_item_balances b
LEFT JOIN myitems item ON item.id = b.item_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY b.qty_available ASC, b.qty_on_hand ASC, b.updated_at DESC
LIMIT " . $limit,
            $params
        );

        return $this->section('negative_balances', 'Negative stock balances', $rows, $this->countRows($conn, 'inventory_item_balances b', $conditions, $params));
    }

    private function invalidInventoryMovements(mysqli $conn, array $filters, int $limit): array
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return $this->missingSection('inventory_movements');
        }

        $conditions = [
            "(
                im.qty_in < 0
                OR im.qty_out < 0
                OR im.unit_cost < 0
                OR im.total_cost < 0
                OR im.unit_conversion_to_base <= 0
                OR (im.qty_in > 0 AND im.qty_out > 0)
                OR TRIM(im.idempotency_key) = ''
                OR im.movement_type = ''
                OR im.source_type = ''
            )",
        ];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'im', $filters);

        $rows = $this->fetchAll(
            $conn,
            "
SELECT
  im.id,
  im.movement_uuid,
  im.pos_tenant,
  im.pos_branch,
  im.store_id,
  im.item_id,
  item.iname AS item_name,
  im.movement_type,
  im.source_type,
  im.qty_in,
  im.qty_out,
  im.unit_conversion_to_base,
  im.unit_cost,
  im.total_cost,
  im.idempotency_key,
  TRIM(BOTH ',' FROM CONCAT_WS(',',
    CASE WHEN im.qty_in < 0 THEN 'negative_qty_in' END,
    CASE WHEN im.qty_out < 0 THEN 'negative_qty_out' END,
    CASE WHEN im.unit_cost < 0 THEN 'negative_unit_cost' END,
    CASE WHEN im.total_cost < 0 THEN 'negative_total_cost' END,
    CASE WHEN im.unit_conversion_to_base <= 0 THEN 'invalid_unit_conversion' END,
    CASE WHEN im.qty_in > 0 AND im.qty_out > 0 THEN 'both_qty_in_and_qty_out' END,
    CASE WHEN TRIM(im.idempotency_key) = '' THEN 'blank_idempotency_key' END,
    CASE WHEN im.movement_type = '' THEN 'blank_movement_type' END,
    CASE WHEN im.source_type = '' THEN 'blank_source_type' END
  )) AS issue_type,
  im.created_at
FROM inventory_movements im
LEFT JOIN myitems item ON item.id = im.item_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY im.created_at DESC, im.id DESC
LIMIT " . $limit,
            $params
        );

        return $this->section('invalid_inventory_movements', 'Invalid inventory movements', $rows, $this->countRows($conn, 'inventory_movements im', $conditions, $params));
    }

    private function missingCostSnapshots(mysqli $conn, array $filters, int $limit): array
    {
        if (!$this->tableExists($conn, 'recipe_headers') || !$this->tableExists($conn, 'recipe_cost_snapshots')) {
            return $this->missingSection('recipe_headers/recipe_cost_snapshots');
        }

        $conditions = ["rh.status = 'active'"];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'rh', $filters, ['pos_tenant', 'pos_branch']);
        $conditions[] = 'NOT EXISTS (SELECT 1 FROM recipe_cost_snapshots rcs WHERE rcs.recipe_id = rh.id)';

        $rows = $this->fetchAll(
            $conn,
            "
SELECT
  rh.id AS recipe_id,
  rh.pos_tenant,
  rh.pos_branch,
  rh.sellable_item_id,
  item.iname AS sellable_item_name,
  rh.recipe_name,
  rh.recipe_type,
  rh.version_number,
  rh.approved_at,
  rh.updated_at
FROM recipe_headers rh
LEFT JOIN myitems item ON item.id = rh.sellable_item_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY rh.updated_at DESC, rh.id DESC
LIMIT " . $limit,
            $params
        );

        return $this->section('missing_cost_snapshots', 'Active recipes missing cost snapshots', $rows, $this->countRows($conn, 'recipe_headers rh', $conditions, $params));
    }

    private function recipeSetupIssues(mysqli $conn, array $filters, int $limit): array
    {
        if (!$this->tableExists($conn, 'recipe_headers') || !$this->tableExists($conn, 'recipe_lines')) {
            return $this->missingSection('recipe_headers/recipe_lines');
        }

        $conditions = ["rh.status = 'active'"];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'rh', $filters, ['pos_tenant', 'pos_branch']);
        $where = implode(' AND ', $conditions);

        $rows = $this->fetchAll(
            $conn,
            "
SELECT * FROM (
  SELECT
    rh.id AS recipe_id,
    rh.pos_tenant,
    rh.pos_branch,
    rh.sellable_item_id,
    item.iname AS sellable_item_name,
    rh.recipe_name,
    rh.version_number,
    'invalid_yield_qty' AS issue_type,
    'Yield quantity must be greater than zero.' AS issue_detail,
    NULL AS line_id,
    rh.updated_at
  FROM recipe_headers rh
  LEFT JOIN myitems item ON item.id = rh.sellable_item_id
  WHERE " . $where . " AND rh.yield_qty <= 0

  UNION ALL

  SELECT
    rh.id AS recipe_id,
    rh.pos_tenant,
    rh.pos_branch,
    rh.sellable_item_id,
    item.iname AS sellable_item_name,
    rh.recipe_name,
    rh.version_number,
    'no_required_lines' AS issue_type,
    'Active recipe has no required stock or sub-recipe lines.' AS issue_detail,
    NULL AS line_id,
    rh.updated_at
  FROM recipe_headers rh
  LEFT JOIN myitems item ON item.id = rh.sellable_item_id
  WHERE " . $where . "
    AND NOT EXISTS (
      SELECT 1
      FROM recipe_lines rl
      WHERE rl.recipe_id = rh.id
        AND rl.is_required = 1
        AND rl.line_type <> 'labor_placeholder'
    )

  UNION ALL

  SELECT
    rh.id AS recipe_id,
    rh.pos_tenant,
    rh.pos_branch,
    rh.sellable_item_id,
    item.iname AS sellable_item_name,
    rh.recipe_name,
    rh.version_number,
    'invalid_line_quantity' AS issue_type,
    CONCAT('Line ', rl.id, ' has a non-positive quantity.') AS issue_detail,
    rl.id AS line_id,
    rl.updated_at
  FROM recipe_headers rh
  INNER JOIN recipe_lines rl ON rl.recipe_id = rh.id
  LEFT JOIN myitems item ON item.id = rh.sellable_item_id
  WHERE " . $where . " AND rl.qty_per_yield <= 0

  UNION ALL

  SELECT
    rh.id AS recipe_id,
    rh.pos_tenant,
    rh.pos_branch,
    rh.sellable_item_id,
    item.iname AS sellable_item_name,
    rh.recipe_name,
    rh.version_number,
    'missing_unit_conversion' AS issue_type,
    CONCAT('Line ', rl.id, ' has a non-positive unit conversion.') AS issue_detail,
    rl.id AS line_id,
    rl.updated_at
  FROM recipe_headers rh
  INNER JOIN recipe_lines rl ON rl.recipe_id = rh.id
  LEFT JOIN myitems item ON item.id = rh.sellable_item_id
  WHERE " . $where . " AND rl.unit_conversion_to_base <= 0

  UNION ALL

  SELECT
    rh.id AS recipe_id,
    rh.pos_tenant,
    rh.pos_branch,
    rh.sellable_item_id,
    item.iname AS sellable_item_name,
    rh.recipe_name,
    rh.version_number,
    'missing_ingredient' AS issue_type,
    CONCAT('Line ', rl.id, ' is missing an ingredient item.') AS issue_detail,
    rl.id AS line_id,
    rl.updated_at
  FROM recipe_headers rh
  INNER JOIN recipe_lines rl ON rl.recipe_id = rh.id
  LEFT JOIN myitems item ON item.id = rh.sellable_item_id
  WHERE " . $where . " AND rl.line_type IN ('ingredient','packaging','modifier_ingredient') AND rl.ingredient_item_id IS NULL

  UNION ALL

  SELECT
    rh.id AS recipe_id,
    rh.pos_tenant,
    rh.pos_branch,
    rh.sellable_item_id,
    item.iname AS sellable_item_name,
    rh.recipe_name,
    rh.version_number,
    'missing_sub_recipe' AS issue_type,
    CONCAT('Line ', rl.id, ' is missing a sub-recipe reference.') AS issue_detail,
    rl.id AS line_id,
    rl.updated_at
  FROM recipe_headers rh
  INNER JOIN recipe_lines rl ON rl.recipe_id = rh.id
  LEFT JOIN myitems item ON item.id = rh.sellable_item_id
  WHERE " . $where . " AND rl.line_type = 'sub_recipe' AND rl.sub_recipe_id IS NULL
) issue_rows
ORDER BY updated_at DESC, recipe_id DESC, line_id ASC
LIMIT " . $limit,
            array_merge($params, $params, $params, $params, $params, $params)
        );

        return $this->section('recipe_setup_issues', 'Recipe setup issues', $rows, count($rows));
    }

    private function movementWriteGaps(mysqli $conn, array $filters, int $limit): array
    {
        if (!$this->tableExists($conn, 'recipe_order_line_usage') || !$this->tableExists($conn, 'inventory_movements')) {
            return $this->missingSection('recipe_order_line_usage/inventory_movements');
        }

        $conditions = ["u.status IN ('consumed','refunded','voided','wasted')"];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'u', $filters);

        $rows = $this->fetchAll(
            $conn,
            "
SELECT
  u.id AS usage_id,
  u.pos_tenant,
  u.pos_branch,
  u.store_id,
  u.order_id,
  u.fat_detail_id,
  u.sellable_item_id,
  item.iname AS sellable_item_name,
  u.recipe_id,
  rh.recipe_name,
  u.status AS usage_status,
  u.cost_total,
  u.updated_at,
  COUNT(im.id) AS movement_count
FROM recipe_order_line_usage u
LEFT JOIN inventory_movements im
  ON im.recipe_order_line_usage_id = u.id
 AND im.movement_type = 'recipe_consumption'
LEFT JOIN recipe_headers rh ON rh.id = u.recipe_id
LEFT JOIN myitems item ON item.id = u.sellable_item_id
WHERE " . implode(' AND ', $conditions) . "
GROUP BY
  u.id,
  u.pos_tenant,
  u.pos_branch,
  u.store_id,
  u.order_id,
  u.fat_detail_id,
  u.sellable_item_id,
  item.iname,
  u.recipe_id,
  rh.recipe_name,
  u.status,
  u.cost_total,
  u.updated_at
HAVING movement_count = 0
ORDER BY u.updated_at DESC, u.id DESC
LIMIT " . $limit,
            $params
        );

        return $this->section('movement_write_gaps', 'Consumed usage without inventory movement', $rows, count($rows));
    }

    private function availabilityCacheGaps(mysqli $conn, array $filters, int $limit): array
    {
        if (!$this->tableExists($conn, 'recipe_headers') || !$this->tableExists($conn, 'recipe_availability_cache')) {
            return $this->missingSection('recipe_headers/recipe_availability_cache');
        }

        $conditions = ["rh.status = 'active'"];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'rh', $filters, ['pos_tenant', 'pos_branch']);

        $rows = $this->fetchAll(
            $conn,
            "
SELECT
  rh.id AS recipe_id,
  rh.pos_tenant,
  rh.pos_branch,
  rh.sellable_item_id,
  item.iname AS sellable_item_name,
  rh.recipe_name,
  rh.version_number,
  rh.updated_at AS recipe_updated_at,
  rac.id AS cache_id,
  rac.effective_is_available,
  rac.effective_available_qty,
  rac.availability_revision,
  rac.updated_at AS cache_updated_at,
  CASE
    WHEN rac.id IS NULL THEN 'missing_cache'
    WHEN rac.updated_at < rh.updated_at THEN 'stale_cache'
    ELSE 'ok'
  END AS issue_type
FROM recipe_headers rh
LEFT JOIN recipe_availability_cache rac
  ON rac.pos_tenant = rh.pos_tenant
 AND rac.pos_branch = rh.pos_branch
 AND rac.sellable_item_id = rh.sellable_item_id
 AND (rac.recipe_id = rh.id OR rac.recipe_id IS NULL)
LEFT JOIN myitems item ON item.id = rh.sellable_item_id
WHERE " . implode(' AND ', $conditions) . "
  AND (rac.id IS NULL OR rac.updated_at < rh.updated_at)
ORDER BY rh.updated_at DESC, rh.id DESC
LIMIT " . $limit,
            $params
        );

        return $this->section('availability_cache_gaps', 'Availability cache gaps', $rows, count($rows));
    }

    private function menuSyncOutboxIssues(mysqli $conn, array $filters, int $limit): array
    {
        if (!$this->tableExists($conn, 'sync_outbox')) {
            return $this->missingSection('sync_outbox');
        }

        $conditions = [
            "o.event_type = 'menu.item_availability_changed'",
            "o.status IN ('failed','dead')",
        ];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'o', $filters, ['pos_tenant', 'pos_branch']);

        $rows = $this->fetchAll(
            $conn,
            "
SELECT
  o.id,
  o.branch_uuid,
  o.pos_tenant,
  o.pos_branch,
  o.aggregate_local_id AS item_id,
  item.iname AS item_name,
  o.event_type,
  o.status,
  o.attempts,
  o.last_error,
  o.next_retry_at,
  o.updated_at
FROM sync_outbox o
LEFT JOIN myitems item ON item.id = o.aggregate_local_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY FIELD(o.status, 'dead', 'failed'), o.updated_at DESC, o.id DESC
LIMIT " . $limit,
            $params
        );

        $pendingConditions = [
            "o.event_type = 'menu.item_availability_changed'",
            "o.status IN ('pending','syncing')",
        ];
        $pendingParams = [];
        $this->applyScopeFilters($pendingConditions, $pendingParams, 'o', $filters, ['pos_tenant', 'pos_branch']);

        $section = $this->section('menu_sync_outbox_issues', 'Failed menu availability sync events', $rows, $this->countRows($conn, 'sync_outbox o', $conditions, $params));
        $section['pending_total'] = $this->countRows($conn, 'sync_outbox o', $pendingConditions, $pendingParams);

        return $section;
    }

    private function lastReconciliationSignals(mysqli $conn, array $filters): array
    {
        $signals = [
            'persisted_run_table_exists' => $this->tableExists($conn, 'recipe_reconciliation_runs'),
            'last_recorded_run_at' => null,
            'latest_inventory_movement_at' => null,
            'latest_balance_update_at' => null,
            'inventory_movement_rows' => 0,
            'inventory_balance_rows' => 0,
            'source' => 'runtime_tables',
        ];

        if ($signals['persisted_run_table_exists']) {
            $row = $this->fetchOne($conn, 'SELECT MAX(created_at) AS last_recorded_run_at FROM recipe_reconciliation_runs');
            $signals['last_recorded_run_at'] = $row['last_recorded_run_at'] ?? null;
        }

        if ($this->tableExists($conn, 'inventory_movements')) {
            $conditions = ['1 = 1'];
            $params = [];
            $this->applyScopeFilters($conditions, $params, 'im', $filters);
            $row = $this->fetchOne(
                $conn,
                'SELECT COUNT(*) AS row_count, MAX(im.created_at) AS latest_at FROM inventory_movements im WHERE ' . implode(' AND ', $conditions),
                $params
            );
            $signals['inventory_movement_rows'] = (int) ($row['row_count'] ?? 0);
            $signals['latest_inventory_movement_at'] = $row['latest_at'] ?? null;
        }

        if ($this->tableExists($conn, 'inventory_item_balances')) {
            $conditions = ['1 = 1'];
            $params = [];
            $this->applyScopeFilters($conditions, $params, 'b', $filters);
            $row = $this->fetchOne(
                $conn,
                'SELECT COUNT(*) AS row_count, MAX(b.updated_at) AS latest_at FROM inventory_item_balances b WHERE ' . implode(' AND ', $conditions),
                $params
            );
            $signals['inventory_balance_rows'] = (int) ($row['row_count'] ?? 0);
            $signals['latest_balance_update_at'] = $row['latest_at'] ?? null;
        }

        return $signals;
    }

    private function summary(array $sections, array $config): array
    {
        $summary = [];
        foreach ($sections as $key => $section) {
            $summary[$key] = (int) ($section['total'] ?? 0);
        }
        $summary['pending_menu_sync_outbox'] = (int) (($sections['menu_sync_outbox_issues'] ?? [])['pending_total'] ?? 0);
        $summary['runtime_bcmath_missing'] = !empty($config['bcmath_loaded']) ? 0 : 1;
        $summary['public_cost_payloads_enabled'] = !empty($config['cost_public_payloads']) ? 1 : 0;
        $summary['production_variance_policy_requires_accounting'] = $this->productionVariancePolicyRequiresAccounting($config) ? 1 : 0;
        $summary['active_mode_flag_mismatches'] = count($this->activeModeFlagMismatches($config));
        $summary['stock_policy_mismatches'] = count($this->stockPolicyMismatches($config));
        $summary['issue_total'] = array_sum($summary) - $summary['pending_menu_sync_outbox'];

        return $summary;
    }

    private function health(array $sections, array $summary, array $config): array
    {
        return [
            [
                'key' => 'recipe_mode',
                'label' => 'Recipe mode',
                'status' => !empty($summary['recipe_enabled']) ? 'active' : 'off',
                'severity' => !empty($summary['recipe_enabled']) ? 'info' : 'ok',
                'detail' => 'Mode: ' . (string) ($summary['recipe_mode'] ?? 'off'),
            ],
            [
                'key' => 'runtime_bcmath',
                'label' => 'Current PHP bcmath',
                'status' => !empty($config['bcmath_loaded']) ? 'ok' : 'missing',
                'severity' => !empty($config['bcmath_loaded']) ? 'ok' : (!empty($summary['recipe_enabled']) ? 'danger' : 'warning'),
                'total' => !empty($config['bcmath_loaded']) ? 0 : 1,
                'detail' => !empty($config['bcmath_loaded'])
                    ? 'bcmath is loaded in this PHP runtime.'
                    : 'bcmath is missing in this PHP runtime; active recipe math requires it.',
            ],
            [
                'key' => 'public_cost_payloads',
                'label' => 'Public cost payloads',
                'status' => !empty($summary['public_cost_payloads_enabled']) ? 'enabled' : 'off',
                'severity' => !empty($summary['public_cost_payloads_enabled']) ? 'danger' : 'ok',
                'total' => (int) ($summary['public_cost_payloads_enabled'] ?? 0),
                'detail' => !empty($summary['public_cost_payloads_enabled'])
                    ? 'Recipe cost fields may be exposed to public/customer payloads; rollout readiness requires an explicit override.'
                    : 'Recipe cost fields are not exposed to public/customer payloads by default.',
            ],
            [
                'key' => 'production_variance_policy',
                'label' => 'Production variance policy',
                'status' => !empty($summary['production_variance_policy_requires_accounting'])
                    ? 'requires_accounting'
                    : (string) ($config['production_variance_policy'] ?? 'adjust_unit_cost'),
                'severity' => !empty($summary['production_variance_policy_requires_accounting']) ? 'danger' : 'ok',
                'total' => (int) ($summary['production_variance_policy_requires_accounting'] ?? 0),
                'detail' => !empty($summary['production_variance_policy_requires_accounting'])
                    ? 'post_variance requires recipe accounting in accounting_pilot, availability_pilot, or full mode.'
                    : 'Policy: ' . (string) ($config['production_variance_policy'] ?? 'adjust_unit_cost'),
            ],
            [
                'key' => 'active_mode_flags',
                'label' => 'Active mode flags',
                'status' => !empty($summary['active_mode_flag_mismatches']) ? 'mismatch' : 'ok',
                'severity' => !empty($summary['active_mode_flag_mismatches']) ? 'danger' : 'ok',
                'total' => (int) ($summary['active_mode_flag_mismatches'] ?? 0),
                'detail' => !empty($summary['active_mode_flag_mismatches'])
                    ? implode('; ', $this->activeModeFlagMismatches($config))
                    : 'Recipe mode and runtime flags are consistent.',
            ],
            [
                'key' => 'stock_policy_flags',
                'label' => 'Stock policy flags',
                'status' => !empty($summary['stock_policy_mismatches']) ? 'mismatch' : 'ok',
                'severity' => !empty($summary['stock_policy_mismatches']) ? 'danger' : 'ok',
                'total' => (int) ($summary['stock_policy_mismatches'] ?? 0),
                'detail' => !empty($summary['stock_policy_mismatches'])
                    ? implode('; ', $this->stockPolicyMismatches($config))
                    : 'Recipe stock policy flags are consistent.',
            ],
            $this->healthRow('stale_reservations', 'Stale reservations', $sections, 'warning'),
            $this->healthRow('negative_balances', 'Negative stock balances', $sections, 'danger'),
            $this->healthRow('invalid_inventory_movements', 'Invalid inventory movements', $sections, 'danger'),
            $this->healthRow('missing_cost_snapshots', 'Missing cost snapshots', $sections, 'warning'),
            $this->healthRow('recipe_setup_issues', 'Recipe setup issues', $sections, 'danger'),
            $this->healthRow('movement_write_gaps', 'Movement write gaps', $sections, 'danger'),
            $this->healthRow('availability_cache_gaps', 'Availability cache gaps', $sections, 'warning'),
            $this->healthRow('menu_sync_outbox_issues', 'Failed menu availability sync', $sections, 'danger'),
            [
                'key' => 'pending_menu_sync_outbox',
                'label' => 'Pending menu availability sync',
                'status' => (($sections['menu_sync_outbox_issues']['pending_total'] ?? 0) > 0) ? 'pending' : 'ok',
                'severity' => (($sections['menu_sync_outbox_issues']['pending_total'] ?? 0) > 0) ? 'info' : 'ok',
                'total' => (int) ($sections['menu_sync_outbox_issues']['pending_total'] ?? 0),
                'detail' => 'Pending/syncing menu availability outbox rows.',
            ],
        ];
    }

    private function productionVariancePolicy(array $config): string
    {
        $policy = strtolower(trim((string) ($config['production_variance_policy'] ?? 'adjust_unit_cost')));

        return in_array($policy, ['adjust_unit_cost', 'post_variance'], true) ? $policy : 'adjust_unit_cost';
    }

    private function productionVariancePolicyRequiresAccounting(array $config): bool
    {
        if (($config['production_variance_policy'] ?? 'adjust_unit_cost') !== 'post_variance') {
            return false;
        }
        if (empty($config['consumption_configured'])) {
            return false;
        }
        $mode = (string) ($config['mode'] ?? 'off');
        if (!in_array($mode, ['consume_pilot', 'accounting_pilot', 'availability_pilot', 'full'], true)) {
            return false;
        }

        return empty($config['accounting_configured'])
            || !in_array($mode, ['accounting_pilot', 'availability_pilot', 'full'], true);
    }

    private function activeModeFlagMismatches(array $config): array
    {
        $mode = (string) ($config['mode'] ?? 'off');
        $mismatches = [];

        if ($mode === 'reserve_only' && empty($config['reservations'])) {
            $mismatches[] = 'reserve_only requires reservations';
        }
        if ($mode === 'full' && empty($config['reservations'])) {
            $mismatches[] = 'full requires reservations';
        }
        if (in_array($mode, ['reserve_only', 'consume_pilot', 'accounting_pilot', 'availability_pilot'], true)
            && trim((string) ($config['pilot_pos_branch'] ?? '')) === ''
            && (int) ($config['pilot_item_count'] ?? 0) === 0
            && (int) ($config['pilot_category_count'] ?? 0) === 0
        ) {
            $mismatches[] = $mode . ' requires explicit pilot branch, item, or category scope';
        }
        if (in_array($mode, ['consume_pilot', 'accounting_pilot', 'availability_pilot', 'full'], true)
            && empty($config['consumption_configured'])
        ) {
            $mismatches[] = $mode . ' requires consumption';
        }
        if (in_array($mode, ['accounting_pilot', 'full'], true)
            && empty($config['accounting_configured'])
        ) {
            $mismatches[] = $mode . ' requires accounting';
        }
        if (in_array($mode, ['availability_pilot', 'full'], true)
            && empty($config['availability_configured'])
        ) {
            $mismatches[] = $mode . ' requires availability';
        }

        return $mismatches;
    }

    private function stockPolicyMismatches(array $config): array
    {
        $mode = (string) ($config['mode'] ?? 'off');
        $blocksNegativeStock = ($config['negative_stock_sale_policy'] ?? NegativeStockSalePolicyService::BLOCK) === NegativeStockSalePolicyService::BLOCK;
        $availabilityConfigured = !empty($config['availability_configured']);
        $availabilityEffective = !empty($config['availability_effective']);
        $mismatches = [];

        $policyRelevant = !in_array($mode, ['off', 'schema_only', 'read_only'], true);
        $modeAlreadyRequiresAvailability = in_array($mode, ['availability_pilot', 'full'], true);
        if ($policyRelevant && $blocksNegativeStock && !$availabilityConfigured && !$modeAlreadyRequiresAvailability) {
            $mismatches[] = 'strict stock requires recipe availability';
        }
        if ($policyRelevant && $blocksNegativeStock && $availabilityConfigured && !$availabilityEffective && !$modeAlreadyRequiresAvailability) {
            $mismatches[] = 'strict stock requires effective recipe availability mode';
        }

        return $mismatches;
    }

    private function healthRow(string $key, string $label, array $sections, string $severityWhenOpen): array
    {
        $section = $sections[$key] ?? [];
        $total = (int) ($section['total'] ?? 0);
        $missing = (($section['status'] ?? '') === 'missing_schema');

        return [
            'key' => $key,
            'label' => $label,
            'status' => $missing ? 'missing_schema' : ($total > 0 ? 'needs_attention' : 'ok'),
            'severity' => $missing ? 'muted' : ($total > 0 ? $severityWhenOpen : 'ok'),
            'total' => $total,
            'detail' => $missing ? (string) ($section['message'] ?? 'Schema table missing.') : ($total > 0 ? $total . ' row(s) need attention.' : 'No open issues.'),
        ];
    }

    private function section(string $key, string $label, array $rows, int $total): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $total > 0 ? 'needs_attention' : 'ok',
            'total' => $total,
            'rows' => $rows,
        ];
    }

    private function missingSection(string $tableLabel): array
    {
        return [
            'key' => $tableLabel,
            'label' => $tableLabel,
            'status' => 'missing_schema',
            'total' => 0,
            'rows' => [],
            'message' => 'Required table is not present: ' . $tableLabel,
        ];
    }

    private function applyScopeFilters(array &$conditions, array &$params, string $alias, array $filters, ?array $columns = null): void
    {
        foreach ($columns ?? ['pos_tenant', 'pos_branch', 'store_id'] as $column) {
            if (!isset($filters[$column]) || $filters[$column] === '' || (int) $filters[$column] < 0) {
                continue;
            }
            $conditions[] = $alias . '.' . $this->quoteIdentifier($column) . ' = ?';
            $params[] = (int) $filters[$column];
        }
    }

    private function countRows(mysqli $conn, string $from, array $conditions, array $params): int
    {
        $row = $this->fetchOne($conn, 'SELECT COUNT(*) AS row_count FROM ' . $from . ' WHERE ' . implode(' AND ', $conditions), $params);

        return (int) ($row['row_count'] ?? 0);
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $row = $this->fetchOne(
            $conn,
            "
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?",
            [$table]
        );

        return (int) ($row['table_count'] ?? 0) > 0;
    }

    private function fetchOne(mysqli $conn, string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($conn, $sql, $params);

        return $rows[0] ?? null;
    }

    private function fetchAll(mysqli $conn, string $sql, array $params = []): array
    {
        $stmt = $conn->prepare($sql);
        if ($params) {
            $types = '';
            foreach ($params as $value) {
                $types .= is_int($value) ? 'i' : 's';
            }
            $refs = [];
            foreach ($params as $index => $value) {
                $refs[$index] = $value;
            }
            $bind = [$types];
            foreach ($refs as $index => $_) {
                $bind[] = &$refs[$index];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function limit($value): int
    {
        return max(1, min(500, (int) ($value ?: 100)));
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }

    private function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
