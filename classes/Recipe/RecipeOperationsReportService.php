<?php

require_once __DIR__ . '/RecipeDecimal.php';

class RecipeOperationsReportService
{
    public function costHistory(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn, 'recipe_cost_snapshots')) {
            return [];
        }

        $conditions = ['1 = 1'];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'rcs', $filters, ['pos_tenant', 'pos_branch']);
        if (($recipeId = $this->positiveInt($filters['recipe_id'] ?? null)) > 0) {
            $conditions[] = 'rcs.recipe_id = ?';
            $params[] = $recipeId;
        }
        if (($itemId = $this->positiveInt($filters['sellable_item_id'] ?? null)) > 0) {
            $conditions[] = 'rcs.sellable_item_id = ?';
            $params[] = $itemId;
        }
        $this->applyDateFilters($conditions, $params, 'rcs.calculated_at', $filters);

        return $this->fetchAll(
            $conn,
            "
SELECT
  rcs.*,
  rh.recipe_name,
  rh.recipe_type,
  item.iname AS sellable_item_name
FROM recipe_cost_snapshots rcs
LEFT JOIN recipe_headers rh ON rh.id = rcs.recipe_id
LEFT JOIN myitems item ON item.id = rcs.sellable_item_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY rcs.calculated_at DESC, rcs.id DESC
LIMIT " . $this->limit($filters['limit'] ?? 500),
            $params
        );
    }

    public function ingredientConsumption(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return [];
        }

        $conditions = [
            "im.movement_type IN ('recipe_consumption', 'production_input', 'waste')",
        ];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'im', $filters);
        $this->applyDateFilters($conditions, $params, 'im.created_at', $filters);
        if (($itemId = $this->positiveInt($filters['ingredient_item_id'] ?? $filters['item_id'] ?? null)) > 0) {
            $conditions[] = 'im.item_id = ?';
            $params[] = $itemId;
        }

        return $this->fetchAll(
            $conn,
            "
SELECT
  im.pos_tenant,
  im.pos_branch,
  im.store_id,
  im.item_id,
  item.iname AS item_name,
  im.movement_type,
  COUNT(*) AS movement_count,
  COALESCE(SUM(im.qty_out), 0) AS qty_consumed,
  COALESCE(SUM(im.total_cost), 0) AS total_cost,
  MIN(im.created_at) AS first_movement_at,
  MAX(im.created_at) AS last_movement_at
FROM inventory_movements im
LEFT JOIN myitems item ON item.id = im.item_id
WHERE " . implode(' AND ', $conditions) . "
GROUP BY im.pos_tenant, im.pos_branch, im.store_id, im.item_id, item.iname, im.movement_type
ORDER BY total_cost DESC, qty_consumed DESC, im.item_id ASC
LIMIT " . $this->limit($filters['limit'] ?? 500),
            $params
        );
    }

    public function recipeCogsByItem(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return [];
        }

        $conditions = [
            "im.movement_type = 'recipe_consumption'",
        ];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'im', $filters);
        $this->applyDateFilters($conditions, $params, 'im.created_at', $filters);
        if (($recipeId = $this->positiveInt($filters['recipe_id'] ?? null)) > 0) {
            $conditions[] = 'im.recipe_id = ?';
            $params[] = $recipeId;
        }
        if (($itemId = $this->positiveInt($filters['sellable_item_id'] ?? null)) > 0) {
            $conditions[] = 'rh.sellable_item_id = ?';
            $params[] = $itemId;
        }

        return $this->fetchAll(
            $conn,
            "
SELECT
  im.pos_tenant,
  im.pos_branch,
  im.store_id,
  im.recipe_id,
  rh.recipe_name,
  rh.sellable_item_id,
  sellable.iname AS sellable_item_name,
  COUNT(DISTINCT im.order_id) AS order_count,
  COUNT(*) AS movement_count,
  COALESCE(SUM(im.qty_out), 0) AS ingredient_qty_out,
  COALESCE(SUM(im.total_cost), 0) AS recipe_cogs,
  MIN(im.created_at) AS first_consumed_at,
  MAX(im.created_at) AS last_consumed_at
FROM inventory_movements im
LEFT JOIN recipe_headers rh ON rh.id = im.recipe_id
LEFT JOIN myitems sellable ON sellable.id = rh.sellable_item_id
WHERE " . implode(' AND ', $conditions) . "
GROUP BY im.pos_tenant, im.pos_branch, im.store_id, im.recipe_id, rh.recipe_name, rh.sellable_item_id, sellable.iname
ORDER BY recipe_cogs DESC, movement_count DESC, im.recipe_id ASC
LIMIT " . $this->limit($filters['limit'] ?? 500),
            $params
        );
    }

    public function productionVariance(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn, 'production_batches')) {
            return [];
        }

        $conditions = ["pb.status = 'committed'"];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'pb', $filters);
        $this->applyDateFilters($conditions, $params, 'pb.committed_at', $filters);
        if (($recipeId = $this->positiveInt($filters['recipe_id'] ?? null)) > 0) {
            $conditions[] = 'pb.recipe_id = ?';
            $params[] = $recipeId;
        }
        if (!empty($filters['variance_only'])) {
            $conditions[] = 'pb.actual_output_qty <> pb.planned_output_qty';
        }

        return $this->fetchAll(
            $conn,
            "
SELECT
  pb.*,
  rh.recipe_name,
  output_item.iname AS output_item_name,
  (pb.actual_output_qty - pb.planned_output_qty) AS variance_qty,
  CASE
    WHEN pb.planned_output_qty = 0 THEN 0
    ELSE ((pb.actual_output_qty - pb.planned_output_qty) / pb.planned_output_qty) * 100
  END AS variance_percent,
  COALESCE(line_totals.input_cost, 0) AS input_cost,
  COALESCE(line_totals.output_cost, 0) AS output_cost
FROM production_batches pb
LEFT JOIN recipe_headers rh ON rh.id = pb.recipe_id
LEFT JOIN myitems output_item ON output_item.id = pb.output_item_id
LEFT JOIN (
  SELECT
    batch_id,
    COALESCE(SUM(CASE WHEN line_type = 'input' THEN total_cost ELSE 0 END), 0) AS input_cost,
    COALESCE(SUM(CASE WHEN line_type = 'output' THEN total_cost ELSE 0 END), 0) AS output_cost
  FROM production_batch_lines
  GROUP BY batch_id
) line_totals ON line_totals.batch_id = pb.id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY ABS(variance_qty) DESC, pb.committed_at DESC, pb.id DESC
LIMIT " . $this->limit($filters['limit'] ?? 500),
            $params
        );
    }

    public function lowStockAffectedItems(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn, 'recipe_availability_cache')) {
            return [];
        }

        $threshold = RecipeDecimal::normalize($filters['low_stock_threshold'] ?? '5');
        $conditions = [
            '(rac.effective_is_available = 0 OR rac.effective_available_qty <= ?)',
        ];
        $params = [$threshold];
        $this->applyScopeFilters($conditions, $params, 'rac', $filters);
        if (($itemId = $this->positiveInt($filters['sellable_item_id'] ?? null)) > 0) {
            $conditions[] = 'rac.sellable_item_id = ?';
            $params[] = $itemId;
        }

        return $this->fetchAll(
            $conn,
            "
SELECT
  rac.*,
  sellable.iname AS sellable_item_name,
  blocking.iname AS blocking_item_name,
  rh.recipe_name
FROM recipe_availability_cache rac
LEFT JOIN myitems sellable ON sellable.id = rac.sellable_item_id
LEFT JOIN myitems blocking ON blocking.id = rac.blocking_item_id
LEFT JOIN recipe_headers rh ON rh.id = rac.recipe_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY rac.effective_is_available ASC, rac.effective_available_qty ASC, rac.updated_at DESC
LIMIT " . $this->limit($filters['limit'] ?? 500),
            $params
        );
    }

    public function cogsJournalReconciliation(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return [];
        }

        $hasJournalTables = $this->tableExists($conn, 'journal_heads') && $this->tableExists($conn, 'journal_entries');
        $conditions = ["im.movement_type = 'recipe_consumption'"];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'im', $filters);
        $this->applyDateFilters($conditions, $params, 'im.created_at', $filters);
        if (($recipeId = $this->positiveInt($filters['recipe_id'] ?? null)) > 0) {
            $conditions[] = 'im.recipe_id = ?';
            $params[] = $recipeId;
        }
        if (($itemId = $this->positiveInt($filters['sellable_item_id'] ?? null)) > 0) {
            $conditions[] = 'rh.sellable_item_id = ?';
            $params[] = $itemId;
        }

        if (!$hasJournalTables) {
            return $this->fetchAll(
                $conn,
                "
SELECT
  im.pos_tenant,
  im.pos_branch,
  im.store_id,
  im.accounting_journal_id,
  im.order_id,
  GROUP_CONCAT(DISTINCT rh.recipe_name ORDER BY rh.recipe_name SEPARATOR ', ') AS recipe_names,
  GROUP_CONCAT(DISTINCT sellable.iname ORDER BY sellable.iname SEPARATOR ', ') AS sellable_item_names,
  COUNT(*) AS movement_count,
  COALESCE(SUM(im.total_cost), 0) AS movement_total,
  0.000000 AS journal_debit_total,
  0.000000 AS journal_credit_total,
  COALESCE(SUM(im.total_cost), 0) AS debit_difference,
  COALESCE(SUM(im.total_cost), 0) AS credit_difference,
  'journal_tables_missing' AS reconciliation_status,
  NULL AS journal_details,
  MAX(im.created_at) AS last_movement_at
FROM inventory_movements im
LEFT JOIN recipe_headers rh ON rh.id = im.recipe_id
LEFT JOIN myitems sellable ON sellable.id = rh.sellable_item_id
WHERE " . implode(' AND ', $conditions) . "
GROUP BY im.pos_tenant, im.pos_branch, im.store_id, im.accounting_journal_id, im.order_id
ORDER BY last_movement_at DESC, im.order_id DESC
LIMIT " . $this->limit($filters['limit'] ?? 500),
                $params
            );
        }

        return $this->fetchAll(
            $conn,
            "
SELECT
  im.pos_tenant,
  im.pos_branch,
  im.store_id,
  im.accounting_journal_id,
  im.order_id,
  GROUP_CONCAT(DISTINCT rh.recipe_name ORDER BY rh.recipe_name SEPARATOR ', ') AS recipe_names,
  GROUP_CONCAT(DISTINCT sellable.iname ORDER BY sellable.iname SEPARATOR ', ') AS sellable_item_names,
  COUNT(*) AS movement_count,
  COALESCE(SUM(im.total_cost), 0) AS movement_total,
  COALESCE(journal_totals.journal_debit_total, 0) AS journal_debit_total,
  COALESCE(journal_totals.journal_credit_total, 0) AS journal_credit_total,
  (COALESCE(journal_totals.journal_debit_total, 0) - COALESCE(SUM(im.total_cost), 0)) AS debit_difference,
  (COALESCE(journal_totals.journal_credit_total, 0) - COALESCE(SUM(im.total_cost), 0)) AS credit_difference,
  CASE
    WHEN im.accounting_journal_id IS NULL OR im.accounting_journal_id = 0 THEN 'missing_journal'
    WHEN journal_totals.journal_id IS NULL THEN 'journal_entries_missing'
    WHEN ABS(COALESCE(journal_totals.journal_debit_total, 0) - COALESCE(SUM(im.total_cost), 0)) > 0.0001 THEN 'mismatch'
    WHEN ABS(COALESCE(journal_totals.journal_credit_total, 0) - COALESCE(SUM(im.total_cost), 0)) > 0.0001 THEN 'mismatch'
    ELSE 'balanced'
  END AS reconciliation_status,
  jh.details AS journal_details,
  MAX(im.created_at) AS last_movement_at
FROM inventory_movements im
LEFT JOIN recipe_headers rh ON rh.id = im.recipe_id
LEFT JOIN myitems sellable ON sellable.id = rh.sellable_item_id
LEFT JOIN journal_heads jh ON jh.id = im.accounting_journal_id
LEFT JOIN (
  SELECT
    journal_id,
    COALESCE(SUM(debit), 0) AS journal_debit_total,
    COALESCE(SUM(credit), 0) AS journal_credit_total
  FROM journal_entries
  GROUP BY journal_id
) journal_totals ON journal_totals.journal_id = im.accounting_journal_id
WHERE " . implode(' AND ', $conditions) . "
GROUP BY
  im.pos_tenant,
  im.pos_branch,
  im.store_id,
  im.accounting_journal_id,
  im.order_id,
  journal_totals.journal_id,
  journal_totals.journal_debit_total,
  journal_totals.journal_credit_total,
  jh.details
ORDER BY reconciliation_status DESC, last_movement_at DESC, im.order_id DESC
LIMIT " . $this->limit($filters['limit'] ?? 500),
            $params
        );
    }

    public function expectedVsActualUsage(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn, 'recipe_order_line_usage') || !$this->tableExists($conn, 'inventory_movements')) {
            return [];
        }

        $conditions = ["u.status IN ('consumed', 'refunded', 'voided', 'wasted')"];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'u', $filters);
        $this->applyDateFilters($conditions, $params, 'COALESCE(u.consumed_at, u.updated_at, u.created_at)', $filters);
        if (($recipeId = $this->positiveInt($filters['recipe_id'] ?? null)) > 0) {
            $conditions[] = 'u.recipe_id = ?';
            $params[] = $recipeId;
        }
        if (($itemId = $this->positiveInt($filters['sellable_item_id'] ?? null)) > 0) {
            $conditions[] = 'u.sellable_item_id = ?';
            $params[] = $itemId;
        }

        $usageRows = $this->fetchAll(
            $conn,
            "
SELECT
  u.*,
  rh.recipe_name,
  sellable.iname AS sellable_item_name
FROM recipe_order_line_usage u
LEFT JOIN recipe_headers rh ON rh.id = u.recipe_id
LEFT JOIN myitems sellable ON sellable.id = u.sellable_item_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY COALESCE(u.consumed_at, u.updated_at, u.created_at) DESC, u.id DESC
LIMIT " . $this->limit($filters['limit'] ?? 500),
            $params
        );
        if (!$usageRows) {
            return [];
        }

        $actualByUsage = $this->actualConsumptionByUsage($conn, array_column($usageRows, 'id'));
        $ingredientIds = [];
        $expectedByUsage = [];
        foreach ($usageRows as $usage) {
            $expectedByUsage[(int) $usage['id']] = $this->expectedRequirementsFromUsage($usage);
            foreach ($expectedByUsage[(int) $usage['id']] as $ingredientId => $_) {
                $ingredientIds[$ingredientId] = $ingredientId;
            }
        }
        foreach ($actualByUsage as $actualRows) {
            foreach ($actualRows as $ingredientId => $_) {
                $ingredientIds[$ingredientId] = $ingredientId;
            }
        }
        $itemNames = $this->itemNames($conn, array_values($ingredientIds));
        $ingredientFilter = $this->positiveInt($filters['ingredient_item_id'] ?? $filters['item_id'] ?? null);

        $rows = [];
        foreach ($usageRows as $usage) {
            $usageId = (int) $usage['id'];
            $expected = $expectedByUsage[$usageId] ?? [];
            $actual = $actualByUsage[$usageId] ?? [];
            $ingredientKeys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
            foreach ($ingredientKeys as $ingredientId) {
                $ingredientId = (int) $ingredientId;
                if ($ingredientFilter > 0 && $ingredientId !== $ingredientFilter) {
                    continue;
                }
                $expectedRow = $expected[$ingredientId] ?? [
                    'expected_qty' => '0.000000',
                    'expected_cost' => '0.000000',
                    'line_types' => [],
                ];
                $actualRow = $actual[$ingredientId] ?? [
                    'actual_qty' => '0.000000',
                    'actual_cost' => '0.000000',
                    'movement_count' => 0,
                ];
                $rows[] = [
                    'pos_tenant' => (int) ($usage['pos_tenant'] ?? 0),
                    'pos_branch' => (int) ($usage['pos_branch'] ?? 0),
                    'store_id' => (int) ($usage['store_id'] ?? 0),
                    'order_id' => (int) ($usage['order_id'] ?? 0),
                    'usage_id' => $usageId,
                    'usage_status' => (string) ($usage['status'] ?? ''),
                    'recipe_id' => (int) ($usage['recipe_id'] ?? 0),
                    'recipe_name' => (string) ($usage['recipe_name'] ?? ''),
                    'sellable_item_id' => (int) ($usage['sellable_item_id'] ?? 0),
                    'sellable_item_name' => (string) ($usage['sellable_item_name'] ?? ''),
                    'ingredient_item_id' => $ingredientId,
                    'ingredient_item_name' => (string) ($itemNames[$ingredientId] ?? ''),
                    'line_types' => implode(', ', array_keys($expectedRow['line_types'] ?? [])),
                    'expected_qty' => RecipeDecimal::normalize($expectedRow['expected_qty'] ?? '0'),
                    'actual_qty' => RecipeDecimal::normalize($actualRow['actual_qty'] ?? '0'),
                    'qty_difference' => RecipeDecimal::subtract($actualRow['actual_qty'] ?? '0', $expectedRow['expected_qty'] ?? '0'),
                    'expected_cost' => RecipeDecimal::normalize($expectedRow['expected_cost'] ?? '0'),
                    'actual_cost' => RecipeDecimal::normalize($actualRow['actual_cost'] ?? '0'),
                    'cost_difference' => RecipeDecimal::subtract($actualRow['actual_cost'] ?? '0', $expectedRow['expected_cost'] ?? '0'),
                    'movement_count' => (int) ($actualRow['movement_count'] ?? 0),
                    'reconciliation_status' => $this->usageReconciliationStatus($expectedRow, $actualRow),
                    'last_movement_at' => $actualRow['last_movement_at'] ?? null,
                ];
            }
        }

        usort($rows, function (array $left, array $right): int {
            $rank = [
                'missing_consumption' => 0,
                'unexpected_consumption' => 1,
                'under_consumed' => 2,
                'over_consumed' => 3,
                'cost_mismatch' => 4,
                'matched' => 5,
            ];

            return ($rank[$left['reconciliation_status']] ?? 9) <=> ($rank[$right['reconciliation_status']] ?? 9)
                ?: ($right['order_id'] <=> $left['order_id'])
                ?: ($left['ingredient_item_id'] <=> $right['ingredient_item_id']);
        });

        return array_slice($rows, 0, $this->limit($filters['limit'] ?? 500));
    }

    public function modifierRevenueCost(mysqli $conn, array $filters = []): array
    {
        if (
            !$this->tableExists($conn, 'recipe_order_line_usage')
            || !$this->tableExists($conn, 'order_line_modifiers')
            || !$this->tableExists($conn, 'modifier_groups')
            || !$this->tableExists($conn, 'modifier_options')
        ) {
            return [];
        }

        $conditions = ["u.status IN ('consumed', 'refunded', 'voided', 'wasted')"];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'u', $filters);
        $this->applyDateFilters($conditions, $params, 'COALESCE(u.consumed_at, u.updated_at, u.created_at)', $filters);
        if (($recipeId = $this->positiveInt($filters['recipe_id'] ?? null)) > 0) {
            $conditions[] = 'u.recipe_id = ?';
            $params[] = $recipeId;
        }
        if (($itemId = $this->positiveInt($filters['sellable_item_id'] ?? null)) > 0) {
            $conditions[] = 'u.sellable_item_id = ?';
            $params[] = $itemId;
        }

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
  u.status AS usage_status,
  u.recipe_id,
  u.sellable_item_id,
  u.explosion_json,
  rh.recipe_name,
  sellable.iname AS sellable_item_name,
  olm.modifier_group_id,
  olm.modifier_option_id,
  COALESCE(mg.name_ar, mg.name_en, '') AS modifier_group_name,
  COALESCE(mo.name_ar, mo.name_en, '') AS modifier_option_name,
  COALESCE(SUM(olm.qty), 0) AS modifier_qty,
  COALESCE(SUM(olm.qty * olm.price_delta), 0) AS modifier_revenue,
  MAX(olm.created_at) AS last_modifier_at
FROM recipe_order_line_usage u
INNER JOIN order_line_modifiers olm ON olm.order_id = u.order_id AND olm.detail_id = u.fat_detail_id
LEFT JOIN recipe_headers rh ON rh.id = u.recipe_id
LEFT JOIN myitems sellable ON sellable.id = u.sellable_item_id
LEFT JOIN modifier_groups mg ON mg.id = olm.modifier_group_id
LEFT JOIN modifier_options mo ON mo.id = olm.modifier_option_id
WHERE " . implode(' AND ', $conditions) . "
GROUP BY
  u.id,
  u.pos_tenant,
  u.pos_branch,
  u.store_id,
  u.order_id,
  u.fat_detail_id,
  u.status,
  u.recipe_id,
  u.sellable_item_id,
  u.explosion_json,
  rh.recipe_name,
  sellable.iname,
  olm.modifier_group_id,
  olm.modifier_option_id,
  mg.name_ar,
  mg.name_en,
  mo.name_ar,
  mo.name_en
ORDER BY modifier_revenue DESC, u.order_id DESC, olm.modifier_option_id ASC
LIMIT " . $this->limit($filters['limit'] ?? 500),
            $params
        );
        if (!$rows) {
            return [];
        }

        $ingredientIds = [];
        $costsByUsage = [];
        foreach ($rows as $row) {
            $usageId = (int) ($row['usage_id'] ?? 0);
            if (!isset($costsByUsage[$usageId])) {
                $costsByUsage[$usageId] = $this->modifierIngredientCostsFromExplosion($row['explosion_json'] ?? '');
            }
            foreach (($costsByUsage[$usageId] ?? []) as $costRow) {
                foreach (($costRow['ingredient_item_ids'] ?? []) as $ingredientId => $_) {
                    $ingredientIds[$ingredientId] = (int) $ingredientId;
                }
            }
        }
        $itemNames = $this->itemNames($conn, array_values($ingredientIds));
        $modifierFilter = $this->positiveInt($filters['modifier_option_id'] ?? null);
        $ingredientFilter = $this->positiveInt($filters['ingredient_item_id'] ?? $filters['item_id'] ?? null);

        $reportRows = [];
        foreach ($rows as $row) {
            $optionId = (int) ($row['modifier_option_id'] ?? 0);
            if ($modifierFilter > 0 && $optionId !== $modifierFilter) {
                continue;
            }
            $usageId = (int) ($row['usage_id'] ?? 0);
            $costRow = $costsByUsage[$usageId][$optionId] ?? [
                'ingredient_qty' => '0.000000',
                'ingredient_cost' => '0.000000',
                'ingredient_item_ids' => [],
            ];
            if ($ingredientFilter > 0 && !isset($costRow['ingredient_item_ids'][$ingredientFilter])) {
                continue;
            }
            $ingredientNames = [];
            foreach (array_keys($costRow['ingredient_item_ids'] ?? []) as $ingredientId) {
                $ingredientNames[] = $itemNames[(int) $ingredientId] ?? (string) $ingredientId;
            }
            $revenue = RecipeDecimal::normalize($row['modifier_revenue'] ?? '0');
            $cost = RecipeDecimal::normalize($costRow['ingredient_cost'] ?? '0');
            $margin = RecipeDecimal::subtract($revenue, $cost);
            $reportRows[] = [
                'pos_tenant' => (int) ($row['pos_tenant'] ?? 0),
                'pos_branch' => (int) ($row['pos_branch'] ?? 0),
                'store_id' => (int) ($row['store_id'] ?? 0),
                'order_id' => (int) ($row['order_id'] ?? 0),
                'usage_id' => $usageId,
                'usage_status' => (string) ($row['usage_status'] ?? ''),
                'recipe_id' => (int) ($row['recipe_id'] ?? 0),
                'recipe_name' => (string) ($row['recipe_name'] ?? ''),
                'sellable_item_id' => (int) ($row['sellable_item_id'] ?? 0),
                'sellable_item_name' => (string) ($row['sellable_item_name'] ?? ''),
                'modifier_group_id' => (int) ($row['modifier_group_id'] ?? 0),
                'modifier_group_name' => (string) ($row['modifier_group_name'] ?? ''),
                'modifier_option_id' => $optionId,
                'modifier_option_name' => (string) ($row['modifier_option_name'] ?? ''),
                'modifier_qty' => RecipeDecimal::normalize($row['modifier_qty'] ?? '0'),
                'modifier_revenue' => $revenue,
                'modifier_ingredient_qty' => RecipeDecimal::normalize($costRow['ingredient_qty'] ?? '0'),
                'modifier_ingredient_cost' => $cost,
                'modifier_margin' => $margin,
                'modifier_margin_percent' => $this->marginPercent($margin, $revenue),
                'ingredient_item_names' => implode(', ', $ingredientNames),
                'reconciliation_status' => RecipeDecimal::isPositive($cost) ? 'costed' : 'no_modifier_recipe_cost',
                'last_modifier_at' => $row['last_modifier_at'] ?? null,
            ];
        }

        usort($reportRows, function (array $left, array $right): int {
            return RecipeDecimal::compare($right['modifier_revenue'], $left['modifier_revenue'])
                ?: ($right['order_id'] <=> $left['order_id'])
                ?: ($left['modifier_option_id'] <=> $right['modifier_option_id']);
        });

        return array_slice($reportRows, 0, $this->limit($filters['limit'] ?? 500));
    }

    public function report(mysqli $conn, string $report, array $filters = []): array
    {
        switch ($report) {
            case 'cost_history':
                return $this->costHistory($conn, $filters);
            case 'ingredient_consumption':
                return $this->ingredientConsumption($conn, $filters);
            case 'recipe_cogs':
                return $this->recipeCogsByItem($conn, $filters);
            case 'production_variance':
                return $this->productionVariance($conn, $filters);
            case 'low_stock_impact':
                return $this->lowStockAffectedItems($conn, $filters);
            case 'cogs_reconciliation':
                return $this->cogsJournalReconciliation($conn, $filters);
            case 'expected_vs_actual_usage':
                return $this->expectedVsActualUsage($conn, $filters);
            case 'modifier_revenue_cost':
                return $this->modifierRevenueCost($conn, $filters);
        }

        throw new InvalidArgumentException('Unsupported recipe operations report.');
    }

    private function expectedRequirementsFromUsage(array $usage): array
    {
        $data = json_decode((string) ($usage['explosion_json'] ?? ''), true);
        if (!is_array($data)) {
            return [];
        }

        $expected = [];
        foreach (($data['requirements'] ?? []) as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            $ingredientId = (int) ($requirement['ingredient_item_id'] ?? 0);
            if ($ingredientId <= 0) {
                continue;
            }
            if (!isset($expected[$ingredientId])) {
                $expected[$ingredientId] = [
                    'expected_qty' => '0.000000',
                    'expected_cost' => '0.000000',
                    'line_types' => [],
                ];
            }
            $expected[$ingredientId]['expected_qty'] = RecipeDecimal::add(
                $expected[$ingredientId]['expected_qty'],
                $requirement['required_qty_base'] ?? '0'
            );
            $expected[$ingredientId]['expected_cost'] = RecipeDecimal::add(
                $expected[$ingredientId]['expected_cost'],
                $requirement['total_cost'] ?? '0'
            );
            $lineType = (string) ($requirement['line_type'] ?? 'ingredient');
            $expected[$ingredientId]['line_types'][$lineType] = true;
        }

        return $expected;
    }

    private function modifierIngredientCostsFromExplosion($explosionJson): array
    {
        $data = json_decode((string) $explosionJson, true);
        if (!is_array($data)) {
            return [];
        }

        $costs = [];
        foreach (($data['requirements'] ?? []) as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            $optionId = (int) ($requirement['modifier_option_id'] ?? 0);
            $ingredientId = (int) ($requirement['ingredient_item_id'] ?? 0);
            if ($optionId <= 0 || $ingredientId <= 0) {
                continue;
            }
            if (!isset($costs[$optionId])) {
                $costs[$optionId] = [
                    'ingredient_qty' => '0.000000',
                    'ingredient_cost' => '0.000000',
                    'ingredient_item_ids' => [],
                ];
            }
            $costs[$optionId]['ingredient_qty'] = RecipeDecimal::add(
                $costs[$optionId]['ingredient_qty'],
                $requirement['required_qty_base'] ?? '0'
            );
            $costs[$optionId]['ingredient_cost'] = RecipeDecimal::add(
                $costs[$optionId]['ingredient_cost'],
                $requirement['total_cost'] ?? '0'
            );
            $costs[$optionId]['ingredient_item_ids'][$ingredientId] = true;
        }

        return $costs;
    }

    private function marginPercent(string $margin, string $revenue): string
    {
        if (!RecipeDecimal::isPositive($revenue)) {
            return '0.000000';
        }

        return RecipeDecimal::multiply(RecipeDecimal::divide($margin, $revenue), '100');
    }

    private function actualConsumptionByUsage(mysqli $conn, array $usageIds): array
    {
        $usageIds = array_values(array_unique(array_filter(array_map('intval', $usageIds), static function (int $id): bool {
            return $id > 0;
        })));
        if (!$usageIds) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($usageIds), '?'));
        $rows = $this->fetchAll(
            $conn,
            "
SELECT
  recipe_order_line_usage_id,
  item_id,
  COUNT(*) AS movement_count,
  COALESCE(SUM(qty_out), 0) AS actual_qty,
  COALESCE(SUM(total_cost), 0) AS actual_cost,
  MAX(created_at) AS last_movement_at
FROM inventory_movements
WHERE movement_type = 'recipe_consumption'
  AND recipe_order_line_usage_id IN (" . $placeholders . ")
GROUP BY recipe_order_line_usage_id, item_id",
            $usageIds
        );

        $grouped = [];
        foreach ($rows as $row) {
            $usageId = (int) ($row['recipe_order_line_usage_id'] ?? 0);
            $itemId = (int) ($row['item_id'] ?? 0);
            if ($usageId <= 0 || $itemId <= 0) {
                continue;
            }
            $grouped[$usageId][$itemId] = [
                'actual_qty' => RecipeDecimal::normalize($row['actual_qty'] ?? '0'),
                'actual_cost' => RecipeDecimal::normalize($row['actual_cost'] ?? '0'),
                'movement_count' => (int) ($row['movement_count'] ?? 0),
                'last_movement_at' => $row['last_movement_at'] ?? null,
            ];
        }

        return $grouped;
    }

    private function itemNames(mysqli $conn, array $itemIds): array
    {
        if (!$this->tableExists($conn, 'myitems')) {
            return [];
        }
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static function (int $id): bool {
            return $id > 0;
        })));
        if (!$itemIds) {
            return [];
        }

        $rows = $this->fetchAll(
            $conn,
            'SELECT id, iname FROM myitems WHERE id IN (' . implode(', ', array_fill(0, count($itemIds), '?')) . ')',
            $itemIds
        );
        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['id']] = (string) ($row['iname'] ?? '');
        }

        return $names;
    }

    private function usageReconciliationStatus(array $expected, array $actual): string
    {
        $expectedQty = $expected['expected_qty'] ?? '0';
        $actualQty = $actual['actual_qty'] ?? '0';
        $expectedCost = $expected['expected_cost'] ?? '0';
        $actualCost = $actual['actual_cost'] ?? '0';
        $expectedPositive = RecipeDecimal::isPositive($expectedQty);
        $actualPositive = RecipeDecimal::isPositive($actualQty);

        if ($expectedPositive && !$actualPositive) {
            return 'missing_consumption';
        }
        if (!$expectedPositive && $actualPositive) {
            return 'unexpected_consumption';
        }
        $qtyComparison = RecipeDecimal::compare($actualQty, $expectedQty);
        if ($qtyComparison < 0) {
            return 'under_consumed';
        }
        if ($qtyComparison > 0) {
            return 'over_consumed';
        }
        if (RecipeDecimal::compare($actualCost, $expectedCost) !== 0) {
            return 'cost_mismatch';
        }

        return 'matched';
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

    private function applyDateFilters(array &$conditions, array &$params, string $column, array $filters): void
    {
        $from = $this->normalizeDate($filters['date_from'] ?? null);
        if ($from !== null) {
            $conditions[] = $column . ' >= ?';
            $params[] = $from . ' 00:00:00';
        }

        $to = $this->normalizeDate($filters['date_to'] ?? null);
        if ($to !== null) {
            $conditions[] = $column . ' <= ?';
            $params[] = $to . ' 23:59:59';
        }
    }

    private function positiveInt($value): int
    {
        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }

    private function normalizeDate($value): ?string
    {
        $text = trim((string) $value);
        if ($text === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            return null;
        }

        return $text;
    }

    private function limit($value): int
    {
        return max(1, min(5000, (int) ($value ?: 500)));
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

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
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
}
