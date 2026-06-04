<?php

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryAccountingReconciliationService.php';
require_once dirname(__DIR__) . '/Recipe/RecipeInventoryDisplayCostService.php';

class InventoryReportsService
{
    private $recipeDisplayCosts;

    public function __construct(?RecipeInventoryDisplayCostService $recipeDisplayCosts = null)
    {
        $this->recipeDisplayCosts = $recipeDisplayCosts ?: new RecipeInventoryDisplayCostService();
    }

    public function report(mysqli $conn, string $report, array $filters = []): array
    {
        switch ($report) {
            case 'movement_history':
                return $this->movementHistory($conn, $filters);
            case 'low_stock':
                return $this->lowStock($conn, $filters);
            case 'replenishment_suggestions':
                return $this->replenishmentSuggestions($conn, $filters);
            case 'purchase_history':
                return $this->purchaseHistory($conn, $filters);
            case 'supplier_purchase_summary':
                return $this->supplierPurchaseSummary($conn, $filters);
            case 'transfer_history':
                return $this->transferHistory($conn, $filters);
            case 'count_variance':
                return $this->countVariance($conn, $filters);
            case 'waste_adjustment':
                return $this->wasteAdjustment($conn, $filters);
            case 'production_variance':
                return $this->productionVariance($conn, $filters);
            case 'recipe_consumption':
                return $this->recipeConsumption($conn, $filters);
            case 'menu_availability':
                return $this->menuAvailability($conn, $filters, false);
            case 'inventory_valuation':
                return $this->inventoryValuation($conn, $filters);
            case 'cogs_reconciliation':
                return $this->cogsReconciliation($conn, $filters);
            case 'inventory_levels':
            default:
                return $this->inventoryLevels($conn, $filters);
        }
    }

    public function dashboard(mysqli $conn, array $filters = []): array
    {
        return [
            'stock_value' => $this->scalar($conn, $this->balanceScopeSql($filters, "COALESCE(SUM(b.qty_on_hand * b.moving_average_cost), 0)"), $this->balanceScopeParams($filters)),
            'item_count' => $this->scalar($conn, $this->balanceScopeSql($filters, 'COUNT(DISTINCT b.item_id)'), $this->balanceScopeParams($filters)),
            'low_stock_count' => $this->dashboardLowStockCount($conn, $filters),
            'negative_count' => $this->scalar($conn, $this->balanceScopeSql($filters, "COALESCE(SUM(CASE WHEN b.qty_on_hand < 0 THEN 1 ELSE 0 END), 0)"), $this->balanceScopeParams($filters)),
            'reserved_qty' => $this->scalar($conn, $this->balanceScopeSql($filters, 'COALESCE(SUM(b.qty_reserved), 0)'), $this->balanceScopeParams($filters)),
            'movements_today' => $this->dashboardMovementCount($conn, $filters, date('Y-m-d') . ' 00:00:00'),
            'waste_7d_cost' => $this->dashboardMovementCost($conn, $filters, ['waste'], date('Y-m-d', strtotime('-7 days')) . ' 00:00:00'),
            'consumption_7d_cost' => $this->dashboardMovementCost($conn, $filters, ['recipe_consumption', 'sale_direct'], date('Y-m-d', strtotime('-7 days')) . ' 00:00:00'),
            'open_counts' => $this->dashboardWorkflowCount($conn, 'inventory_counts', "status IN ('draft','submitted','approved')", $filters),
            'open_transfers' => $this->dashboardWorkflowCount($conn, 'inventory_transfers', "status IN ('draft','submitted','sent','partially_received')", $filters),
            'open_purchase_orders' => $this->dashboardWorkflowCount($conn, 'inventory_purchase_orders', "status IN ('draft','submitted','approved','partially_received')", $filters),
        ];
    }

    public function dashboardDetails(mysqli $conn, array $filters = []): array
    {
        $detailFilters = array_merge($filters, ['limit' => max(1, min(12, (int) ($filters['limit'] ?? 8)))]);

        return [
            'needs_attention' => $this->lowStock($conn, $detailFilters),
            'replenishment_suggestions' => $this->replenishmentSuggestions($conn, $detailFilters),
            'recent_movements' => $this->movementHistory($conn, $detailFilters),
            'menu_availability_impact' => $this->menuAvailabilityImpact($conn, $detailFilters),
        ];
    }

    private function inventoryLevels(mysqli $conn, array $filters): array
    {
        if (!$this->tableExists($conn, 'inventory_item_balances')) {
            return [];
        }

        $conditions = ['1 = 1'];
        $params = [];
        $this->applyBalanceFilters($conn, $conditions, $params, 'b', 'item', $filters);

        $rows = $this->fetchAll($conn, "
SELECT
  b.pos_tenant,
  b.pos_branch,
  b.store_id,
  store.aname AS store_name,
  b.item_id,
  item.iname AS item_name,
  item.barcode,
  item.item_type,
  item.group1 AS category_id,
  b.qty_on_hand,
  b.qty_reserved,
  b.qty_available,
  b.moving_average_cost,
  (b.qty_on_hand * b.moving_average_cost) AS stock_value,
  COALESCE(levels.minimum_level, 0) AS minimum_level,
  COALESCE(levels.reorder_level, 0) AS reorder_level,
  COALESCE(levels.par_level, 0) AS par_level,
  CASE
    WHEN b.qty_on_hand < 0 THEN 'negative'
    WHEN COALESCE(levels.reorder_level, 0) > 0 AND b.qty_available <= levels.reorder_level THEN 'reorder'
    WHEN COALESCE(levels.minimum_level, 0) > 0 AND b.qty_available <= levels.minimum_level THEN 'low'
    ELSE 'ok'
  END AS inventory_status,
  b.last_movement_id,
  last_movement.created_at AS last_movement_at,
  CONCAT('inventory_dashboard.php?item_id=', b.item_id, '&store_id=', b.store_id) AS drilldown_url
FROM inventory_item_balances b
LEFT JOIN myitems item ON item.id = b.item_id
LEFT JOIN acc_head store ON store.id = b.store_id
LEFT JOIN inventory_item_stock_levels levels
  ON levels.pos_tenant = b.pos_tenant
 AND levels.pos_branch = b.pos_branch
 AND levels.store_id = b.store_id
 AND levels.item_id = b.item_id
 AND COALESCE(levels.is_active, 1) = 1
LEFT JOIN inventory_movements last_movement ON last_movement.id = b.last_movement_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY inventory_status DESC, item.iname ASC, b.item_id ASC
LIMIT " . $this->limit($filters['limit'] ?? 500), $params);

        return $this->recipeDisplayCosts->enrichBalanceReportRows($conn, $rows, $filters);
    }

    private function movementHistory(mysqli $conn, array $filters): array
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return [];
        }

        $conditions = ['1 = 1'];
        $params = [];
        $this->applyMovementFilters($conn, $conditions, $params, 'im', 'item', $filters);
        $this->applyDateFilters($conditions, $params, 'im.created_at', $filters);
        if (($movementType = $this->textFilter($filters['movement_type'] ?? '')) !== '') {
            $conditions[] = 'im.movement_type = ?';
            $params[] = $movementType;
        }

        $rows = $this->fetchAll($conn, "
SELECT
  im.id,
  im.created_at,
  im.pos_tenant,
  im.pos_branch,
  im.store_id,
  store.aname AS store_name,
  im.item_id,
  item.iname AS item_name,
  item.barcode,
  item.group1 AS category_id,
  im.movement_type,
  im.source_type,
  im.source_id,
  im.order_id,
  im.recipe_id,
  rh.recipe_name,
  im.production_batch_id,
  im.qty_in,
  im.qty_out,
  im.unit_cost,
  im.total_cost,
  im.accounting_journal_id,
  im.idempotency_key
FROM inventory_movements im
LEFT JOIN myitems item ON item.id = im.item_id
LEFT JOIN acc_head store ON store.id = im.store_id
LEFT JOIN recipe_headers rh ON rh.id = im.recipe_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY im.created_at DESC, im.id DESC
LIMIT " . $this->limit($filters['limit'] ?? 500), $params);

        return $this->withMovementDrilldown($rows);
    }

    private function lowStock(mysqli $conn, array $filters): array
    {
        $filters['only_low_stock'] = true;
        return $this->stockLevelReport($conn, $filters, false);
    }

    private function replenishmentSuggestions(mysqli $conn, array $filters): array
    {
        return $this->stockLevelReport($conn, $filters, true);
    }

    private function stockLevelReport(mysqli $conn, array $filters, bool $suggestions): array
    {
        if (!$this->tableExists($conn, 'inventory_item_balances')) {
            return [];
        }

        $conditions = ['1 = 1'];
        $params = [];
        $this->applyBalanceFilters($conn, $conditions, $params, 'b', 'item', $filters);
        $conditions[] = "(COALESCE(levels.reorder_level, 0) > 0 OR COALESCE(levels.minimum_level, 0) > 0 OR COALESCE(levels.par_level, 0) > 0)";
        $conditions[] = "(b.qty_available <= COALESCE(NULLIF(levels.reorder_level, 0), levels.minimum_level) OR b.qty_on_hand < 0" . ($suggestions ? " OR b.qty_available < COALESCE(levels.par_level, 0)" : "") . ")";
        $hasDefaultSupplier = $this->columnExists($conn, 'inventory_item_stock_levels', 'default_supplier_account_id');
        $defaultSupplierSelect = $hasDefaultSupplier
            ? "levels.default_supplier_account_id,\n  supplier.aname AS default_supplier_name,"
            : "NULL AS default_supplier_account_id,\n  NULL AS default_supplier_name,";
        $defaultSupplierJoin = $hasDefaultSupplier
            ? "\nLEFT JOIN acc_head supplier\n  ON supplier.id = levels.default_supplier_account_id\n AND COALESCE(supplier.isdeleted, 0) = 0"
            : '';

        $rows = $this->fetchAll($conn, "
SELECT
  b.pos_tenant,
  b.pos_branch,
  b.store_id,
  store.aname AS store_name,
  b.item_id,
  item.iname AS item_name,
  item.barcode,
  item.group1 AS category_id,
  b.qty_on_hand,
  b.qty_reserved,
  b.qty_available,
  COALESCE(levels.minimum_level, 0) AS minimum_level,
  COALESCE(levels.reorder_level, 0) AS reorder_level,
  COALESCE(levels.par_level, 0) AS par_level,
  GREATEST(COALESCE(levels.par_level, 0) - b.qty_available, 0) AS suggested_qty,
  levels.preferred_purchase_unit_id,
  purchase_unit.uname AS preferred_purchase_unit_name,
  COALESCE(purchase_item_unit.u_val, 1) AS preferred_purchase_unit_conversion,
  " . $defaultSupplierSelect . "
  CASE
    WHEN COALESCE(levels.preferred_purchase_unit_id, 0) > 0 AND COALESCE(purchase_item_unit.u_val, 0) > 0
      THEN CEIL(GREATEST(COALESCE(levels.par_level, 0) - b.qty_available, 0) / purchase_item_unit.u_val)
    ELSE GREATEST(COALESCE(levels.par_level, 0) - b.qty_available, 0)
  END AS suggested_purchase_qty,
  CASE
    WHEN COALESCE(levels.preferred_purchase_unit_id, 0) > 0 AND COALESCE(purchase_item_unit.u_val, 0) > 0
      THEN CEIL(GREATEST(COALESCE(levels.par_level, 0) - b.qty_available, 0) / purchase_item_unit.u_val) * purchase_item_unit.u_val
    ELSE GREATEST(COALESCE(levels.par_level, 0) - b.qty_available, 0)
  END AS suggested_purchase_base_qty,
  b.moving_average_cost,
  (CASE
    WHEN COALESCE(levels.preferred_purchase_unit_id, 0) > 0 AND COALESCE(purchase_item_unit.u_val, 0) > 0
      THEN CEIL(GREATEST(COALESCE(levels.par_level, 0) - b.qty_available, 0) / purchase_item_unit.u_val) * purchase_item_unit.u_val
    ELSE GREATEST(COALESCE(levels.par_level, 0) - b.qty_available, 0)
  END * b.moving_average_cost) AS estimated_purchase_cost,
  CONCAT('inventory_dashboard.php?item_id=', b.item_id, '&store_id=', b.store_id) AS drilldown_url
FROM inventory_item_balances b
LEFT JOIN myitems item ON item.id = b.item_id
LEFT JOIN acc_head store ON store.id = b.store_id
LEFT JOIN inventory_item_stock_levels levels
  ON levels.pos_tenant = b.pos_tenant
 AND levels.pos_branch = b.pos_branch
 AND levels.store_id = b.store_id
 AND levels.item_id = b.item_id
 AND COALESCE(levels.is_active, 1) = 1
LEFT JOIN item_units purchase_item_unit
  ON purchase_item_unit.item_id = b.item_id
 AND purchase_item_unit.unit_id = levels.preferred_purchase_unit_id
 AND COALESCE(purchase_item_unit.isdeleted, 0) = 0
LEFT JOIN myunits purchase_unit
  ON purchase_unit.id = levels.preferred_purchase_unit_id
 AND COALESCE(purchase_unit.isdeleted, 0) = 0
" . $defaultSupplierJoin . "
WHERE " . implode(' AND ', $conditions) . "
ORDER BY suggested_qty DESC, b.qty_available ASC, item.iname ASC
LIMIT " . $this->limit($filters['limit'] ?? 500), $params);

        return $this->recipeDisplayCosts->enrichBalanceReportRows($conn, $rows, $filters);
    }

    private function purchaseHistory(mysqli $conn, array $filters): array
    {
        if (!$this->tableExists($conn, 'inventory_purchase_receipts')) {
            return [];
        }

        $conditions = ['1 = 1'];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'r', $filters, ['pos_tenant', 'pos_branch']);
        $this->applyStoreFilter($conditions, $params, 'r.destination_store_id', $filters);
        $this->applySupplierFilter($conditions, $params, 'r.supplier_account_id', $filters);
        $this->applyDateFilters($conditions, $params, 'COALESCE(r.posted_at, r.received_at, r.created_at)', $filters);
        if (($itemId = $this->positiveInt($filters['item_id'] ?? null)) > 0) {
            $conditions[] = 'l.item_id = ?';
            $params[] = $itemId;
        }
        if (($categoryId = $this->positiveInt($filters['category_id'] ?? null)) > 0 && $this->columnExists($conn, 'myitems', 'group1')) {
            $conditions[] = 'item.group1 = ?';
            $params[] = $categoryId;
        }

        return $this->fetchAll($conn, "
SELECT
  r.id AS receipt_id,
  r.status,
  COALESCE(r.posted_at, r.received_at, r.created_at) AS document_at,
  r.supplier_invoice_no,
  supplier.aname AS supplier_name,
  r.destination_store_id AS store_id,
  store.aname AS store_name,
  COUNT(l.id) AS line_count,
  GROUP_CONCAT(DISTINCT item.iname ORDER BY item.iname SEPARATOR ', ') AS item_names,
  COALESCE(SUM(l.received_qty), 0) AS received_qty,
  COALESCE(SUM(l.returned_qty), 0) AS returned_qty,
  COALESCE(SUM(l.total_cost), 0) AS total_cost,
  CONCAT('inventory_purchasing.php?receipt_id=', r.id) AS drilldown_url
FROM inventory_purchase_receipts r
LEFT JOIN inventory_purchase_receipt_lines l ON l.purchase_receipt_id = r.id
LEFT JOIN myitems item ON item.id = l.item_id
LEFT JOIN acc_head supplier ON supplier.id = r.supplier_account_id
LEFT JOIN acc_head store ON store.id = r.destination_store_id
WHERE " . implode(' AND ', $conditions) . "
GROUP BY r.id, r.status, r.posted_at, r.received_at, r.created_at, r.supplier_invoice_no, supplier.aname, r.destination_store_id, store.aname
ORDER BY document_at DESC, r.id DESC
LIMIT " . $this->limit($filters['limit'] ?? 500), $params);
    }

    private function supplierPurchaseSummary(mysqli $conn, array $filters): array
    {
        if (!$this->tableExists($conn, 'inventory_purchase_receipts')) {
            return [];
        }

        $conditions = ['1 = 1'];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'r', $filters, ['pos_tenant', 'pos_branch']);
        $this->applyStoreFilter($conditions, $params, 'r.destination_store_id', $filters);
        $this->applySupplierFilter($conditions, $params, 'r.supplier_account_id', $filters);
        $this->applyDateFilters($conditions, $params, 'COALESCE(r.posted_at, r.received_at, r.created_at)', $filters);
        if (($itemId = $this->positiveInt($filters['item_id'] ?? null)) > 0) {
            $conditions[] = 'l.item_id = ?';
            $params[] = $itemId;
        }
        if (($categoryId = $this->positiveInt($filters['category_id'] ?? null)) > 0 && $this->columnExists($conn, 'myitems', 'group1')) {
            $conditions[] = 'item.group1 = ?';
            $params[] = $categoryId;
        }

        return $this->fetchAll($conn, "
SELECT
  r.pos_tenant,
  r.pos_branch,
  r.supplier_account_id,
  COALESCE(supplier.aname, 'بدون مورد') AS supplier_name,
  COUNT(DISTINCT r.id) AS receipt_count,
  COUNT(l.id) AS line_count,
  COUNT(DISTINCT l.item_id) AS item_count,
  GROUP_CONCAT(DISTINCT store.aname ORDER BY store.aname SEPARATOR ', ') AS store_names,
  MIN(COALESCE(r.posted_at, r.received_at, r.created_at)) AS first_purchase_at,
  MAX(COALESCE(r.posted_at, r.received_at, r.created_at)) AS last_purchase_at,
  COALESCE(SUM(l.received_qty), 0) AS received_qty,
  COALESCE(SUM(l.returned_qty), 0) AS returned_qty,
  COALESCE(SUM(l.received_qty - l.returned_qty), 0) AS net_received_qty,
  COALESCE(SUM(l.total_cost), 0) AS total_cost,
  CASE
    WHEN COALESCE(SUM(l.received_qty - l.returned_qty), 0) = 0 THEN 0
    ELSE COALESCE(SUM(l.total_cost), 0) / SUM(l.received_qty - l.returned_qty)
  END AS avg_unit_cost,
  CONCAT('inventory_reports.php?report=purchase_history&supplier_account_id=', COALESCE(r.supplier_account_id, 0)) AS drilldown_url
FROM inventory_purchase_receipts r
LEFT JOIN inventory_purchase_receipt_lines l ON l.purchase_receipt_id = r.id
LEFT JOIN myitems item ON item.id = l.item_id
LEFT JOIN acc_head supplier ON supplier.id = r.supplier_account_id
LEFT JOIN acc_head store ON store.id = r.destination_store_id
WHERE " . implode(' AND ', $conditions) . "
GROUP BY r.pos_tenant, r.pos_branch, r.supplier_account_id, supplier.aname
ORDER BY total_cost DESC, last_purchase_at DESC, supplier_name ASC
LIMIT " . $this->limit($filters['limit'] ?? 500), $params);
    }

    private function transferHistory(mysqli $conn, array $filters): array
    {
        if (!$this->tableExists($conn, 'inventory_transfers')) {
            return [];
        }

        $conditions = ['1 = 1'];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 't', $filters, ['pos_tenant', 'pos_branch']);
        if (($storeId = $this->positiveInt($filters['store_id'] ?? null)) > 0) {
            $conditions[] = '(t.source_store_id = ? OR t.destination_store_id = ?)';
            $params[] = $storeId;
            $params[] = $storeId;
        }
        $this->applyDateFilters($conditions, $params, 't.created_at', $filters);

        return $this->fetchAll($conn, "
SELECT
  t.id AS transfer_id,
  t.status,
  t.created_at,
  t.sent_at,
  t.received_at,
  source_store.aname AS source_store_name,
  destination_store.aname AS destination_store_name,
  COUNT(l.id) AS line_count,
  GROUP_CONCAT(DISTINCT item.iname ORDER BY item.iname SEPARATOR ', ') AS item_names,
  COALESCE(SUM(l.requested_qty), 0) AS requested_qty,
  COALESCE(SUM(l.sent_qty), 0) AS sent_qty,
  COALESCE(SUM(l.received_qty), 0) AS received_qty,
  COALESCE(SUM(l.variance_qty), 0) AS variance_qty,
  COALESCE(SUM(l.sent_qty * l.unit_cost), 0) AS total_cost,
  CONCAT('inventory_transfer_detail.php?id=', t.id) AS drilldown_url
FROM inventory_transfers t
LEFT JOIN inventory_transfer_lines l ON l.transfer_id = t.id
LEFT JOIN myitems item ON item.id = l.item_id
LEFT JOIN acc_head source_store ON source_store.id = t.source_store_id
LEFT JOIN acc_head destination_store ON destination_store.id = t.destination_store_id
WHERE " . implode(' AND ', $conditions) . "
GROUP BY t.id, t.status, t.created_at, t.sent_at, t.received_at, source_store.aname, destination_store.aname
ORDER BY t.created_at DESC, t.id DESC
LIMIT " . $this->limit($filters['limit'] ?? 500), $params);
    }

    private function countVariance(mysqli $conn, array $filters): array
    {
        if (!$this->tableExists($conn, 'inventory_counts')) {
            return [];
        }

        $conditions = ['1 = 1'];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'c', $filters, ['pos_tenant', 'pos_branch']);
        $this->applyStoreFilter($conditions, $params, 'c.store_id', $filters);
        $this->applyDateFilters($conditions, $params, 'COALESCE(c.closed_at, c.created_at)', $filters);
        if (($itemId = $this->positiveInt($filters['item_id'] ?? null)) > 0) {
            $conditions[] = 'l.item_id = ?';
            $params[] = $itemId;
        }

        return $this->fetchAll($conn, "
SELECT
  c.id AS count_id,
  c.status,
  c.count_type,
  COALESCE(c.closed_at, c.created_at) AS document_at,
  c.store_id,
  store.aname AS store_name,
  l.item_id,
  item.iname AS item_name,
  item.group1 AS category_id,
  l.snapshot_qty,
  l.counted_qty,
  l.variance_qty,
  l.variance_percent,
  l.variance_cost,
  l.stale_count_conflict,
  CONCAT('inventory_count_detail.php?id=', c.id) AS drilldown_url
FROM inventory_counts c
INNER JOIN inventory_count_lines l ON l.count_id = c.id
LEFT JOIN myitems item ON item.id = l.item_id
LEFT JOIN acc_head store ON store.id = c.store_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY ABS(l.variance_cost) DESC, ABS(l.variance_qty) DESC, document_at DESC
LIMIT " . $this->limit($filters['limit'] ?? 500), $params);
    }

    private function wasteAdjustment(mysqli $conn, array $filters): array
    {
        $filters['movement_type_set'] = ['waste', 'adjustment'];
        return $this->typedMovementSummary($conn, $filters);
    }

    private function recipeConsumption(mysqli $conn, array $filters): array
    {
        $filters['movement_type_set'] = ['recipe_consumption'];
        return $this->typedMovementSummary($conn, $filters);
    }

    private function typedMovementSummary(mysqli $conn, array $filters): array
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return [];
        }

        $types = $filters['movement_type_set'] ?? [];
        $conditions = ['1 = 1'];
        $params = [];
        if ($types) {
            $conditions[] = 'im.movement_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            foreach ($types as $type) {
                $params[] = (string) $type;
            }
        }
        $this->applyMovementFilters($conn, $conditions, $params, 'im', 'item', $filters);
        $this->applyDateFilters($conditions, $params, 'im.created_at', $filters);

        return $this->fetchAll($conn, "
SELECT
  im.pos_tenant,
  im.pos_branch,
  im.store_id,
  store.aname AS store_name,
  im.item_id,
  item.iname AS item_name,
  item.group1 AS category_id,
  im.movement_type,
  im.recipe_id,
  rh.recipe_name,
  COUNT(*) AS movement_count,
  COALESCE(SUM(im.qty_in), 0) AS qty_in,
  COALESCE(SUM(im.qty_out), 0) AS qty_out,
  COALESCE(SUM(im.total_cost), 0) AS total_cost,
  MIN(im.created_at) AS first_movement_at,
  MAX(im.created_at) AS last_movement_at,
  CONCAT('inventory_dashboard.php?item_id=', im.item_id, '&store_id=', im.store_id, '&movement_type=', im.movement_type) AS drilldown_url
FROM inventory_movements im
LEFT JOIN myitems item ON item.id = im.item_id
LEFT JOIN acc_head store ON store.id = im.store_id
LEFT JOIN recipe_headers rh ON rh.id = im.recipe_id
WHERE " . implode(' AND ', $conditions) . "
GROUP BY im.pos_tenant, im.pos_branch, im.store_id, store.aname, im.item_id, item.iname, item.group1, im.movement_type, im.recipe_id, rh.recipe_name
ORDER BY total_cost DESC, qty_out DESC, movement_count DESC
LIMIT " . $this->limit($filters['limit'] ?? 500), $params);
    }

    private function productionVariance(mysqli $conn, array $filters): array
    {
        if (!$this->tableExists($conn, 'production_batches')) {
            return [];
        }

        $conditions = ["pb.status = 'committed'"];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'pb', $filters, ['pos_tenant', 'pos_branch']);
        $this->applyStoreFilter($conditions, $params, 'pb.store_id', $filters);
        $this->applyDateFilters($conditions, $params, 'pb.committed_at', $filters);

        return $this->fetchAll($conn, "
SELECT
  pb.id AS batch_id,
  pb.committed_at,
  pb.store_id,
  store.aname AS store_name,
  pb.recipe_id,
  rh.recipe_name,
  pb.output_item_id,
  item.iname AS output_item_name,
  pb.planned_output_qty,
  pb.actual_output_qty,
  (pb.actual_output_qty - pb.planned_output_qty) AS variance_qty,
  CASE WHEN pb.planned_output_qty = 0 THEN 0 ELSE ((pb.actual_output_qty - pb.planned_output_qty) / pb.planned_output_qty) * 100 END AS variance_percent,
  pb.variance_reason,
  COALESCE(line_costs.input_cost, 0) AS input_cost,
  COALESCE(line_costs.output_cost, 0) AS output_cost,
  CONCAT('recipe_production.php?batch_id=', pb.id) AS drilldown_url
FROM production_batches pb
LEFT JOIN recipe_headers rh ON rh.id = pb.recipe_id
LEFT JOIN myitems item ON item.id = pb.output_item_id
LEFT JOIN acc_head store ON store.id = pb.store_id
LEFT JOIN (
  SELECT
    batch_id,
    COALESCE(SUM(CASE WHEN line_type = 'input' THEN total_cost ELSE 0 END), 0) AS input_cost,
    COALESCE(SUM(CASE WHEN line_type = 'output' THEN total_cost ELSE 0 END), 0) AS output_cost
  FROM production_batch_lines
  GROUP BY batch_id
) line_costs ON line_costs.batch_id = pb.id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY ABS(variance_qty) DESC, pb.committed_at DESC, pb.id DESC
LIMIT " . $this->limit($filters['limit'] ?? 500), $params);
    }

    private function cogsReconciliation(mysqli $conn, array $filters): array
    {
        $review = (new InventoryAccountingReconciliationService())->review($conn, [
            'pos_tenant' => $filters['pos_tenant'] ?? -1,
            'pos_branch' => $filters['pos_branch'] ?? -1,
            'store_id' => $filters['store_id'] ?? -1,
            'limit' => $filters['limit'] ?? 500,
        ]);

        return $review['rows'] ?? [];
    }

    private function inventoryValuation(mysqli $conn, array $filters): array
    {
        if (!$this->tableExists($conn, 'inventory_item_balances')) {
            return [];
        }

        $conditions = ['1 = 1'];
        $params = [];
        $this->applyBalanceFilters($conn, $conditions, $params, 'b', 'item', $filters);

        $rows = $this->fetchAll($conn, "
SELECT
  b.pos_tenant,
  b.pos_branch,
  b.store_id,
  store.aname AS store_name,
  b.item_id,
  item.iname AS item_name,
  item.barcode,
  item.group1 AS category_id,
  b.qty_on_hand,
  b.qty_reserved,
  b.qty_available,
  b.moving_average_cost,
  (b.qty_on_hand * b.moving_average_cost) AS current_stock_value,
  b.last_movement_id,
  last_movement.movement_type AS last_movement_type,
  last_movement.unit_cost AS last_unit_cost,
  last_movement.total_cost AS last_total_cost,
  last_movement.created_at AS last_cost_movement_at,
  CONCAT('inventory_dashboard.php?item_id=', b.item_id, '&store_id=', b.store_id) AS drilldown_url
FROM inventory_item_balances b
LEFT JOIN myitems item ON item.id = b.item_id
LEFT JOIN acc_head store ON store.id = b.store_id
LEFT JOIN inventory_movements last_movement ON last_movement.id = b.last_movement_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY current_stock_value DESC, b.qty_on_hand DESC, item.iname ASC
LIMIT " . $this->limit($filters['limit'] ?? 500), $params);

        return $this->recipeDisplayCosts->enrichBalanceReportRows($conn, $rows, $filters);
    }

    private function menuAvailabilityImpact(mysqli $conn, array $filters): array
    {
        return $this->menuAvailability($conn, $filters, true);
    }

    private function menuAvailability(mysqli $conn, array $filters, bool $onlyUnavailable): array
    {
        if (!$this->tableExists($conn, 'recipe_availability_cache')) {
            return [];
        }

        $conditions = $onlyUnavailable ? ['rac.effective_is_available = 0'] : ['1 = 1'];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'rac', $filters, ['pos_tenant', 'pos_branch']);
        $this->applyStoreFilter($conditions, $params, 'rac.store_id', $filters);
        if (($itemId = $this->positiveInt($filters['item_id'] ?? null)) > 0) {
            $conditions[] = '(rac.sellable_item_id = ? OR rac.blocking_item_id = ?)';
            $params[] = $itemId;
            $params[] = $itemId;
        }
        if (($categoryId = $this->positiveInt($filters['category_id'] ?? null)) > 0 && $this->columnExists($conn, 'myitems', 'group1')) {
            $conditions[] = 'sellable.group1 = ?';
            $params[] = $categoryId;
        }

        return $this->fetchAll($conn, "
SELECT
  rac.pos_tenant,
  rac.pos_branch,
  rac.store_id,
  store.aname AS store_name,
  rac.sellable_item_id,
  sellable.iname AS sellable_item_name,
  sellable.group1 AS category_id,
  rac.recipe_id,
  rac.blocking_item_id,
  blocker.iname AS blocking_item_name,
  rac.computed_available_qty,
  rac.effective_available_qty,
  rac.effective_is_available,
  CASE WHEN rac.effective_is_available = 1 THEN 'available' ELSE 'unavailable' END AS availability_status,
  rac.unavailable_reason,
  rac.order_type,
  rac.channel,
  rac.calculated_at AS updated_at,
  CONCAT('inventory_reports.php?report=inventory_levels&item_id=', COALESCE(rac.blocking_item_id, rac.sellable_item_id), '&store_id=', rac.store_id) AS drilldown_url
FROM recipe_availability_cache rac
LEFT JOIN myitems sellable ON sellable.id = rac.sellable_item_id
LEFT JOIN myitems blocker ON blocker.id = rac.blocking_item_id
LEFT JOIN acc_head store ON store.id = rac.store_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY rac.effective_is_available ASC, rac.effective_available_qty ASC, rac.calculated_at DESC, sellable.iname ASC
LIMIT " . $this->limit($filters['limit'] ?? ($onlyUnavailable ? 8 : 500)), $params);
    }

    private function applyBalanceFilters(mysqli $conn, array &$conditions, array &$params, string $balanceAlias, string $itemAlias, array $filters): void
    {
        $this->applyScopeFilters($conditions, $params, $balanceAlias, $filters, ['pos_tenant', 'pos_branch']);
        $this->applyStoreFilter($conditions, $params, $balanceAlias . '.store_id', $filters);
        $this->applyItemFilters($conn, $conditions, $params, $balanceAlias . '.item_id', $itemAlias, $filters);
    }

    private function applyMovementFilters(mysqli $conn, array &$conditions, array &$params, string $movementAlias, string $itemAlias, array $filters): void
    {
        $this->applyScopeFilters($conditions, $params, $movementAlias, $filters, ['pos_tenant', 'pos_branch']);
        $this->applyStoreFilter($conditions, $params, $movementAlias . '.store_id', $filters);
        $this->applyItemFilters($conn, $conditions, $params, $movementAlias . '.item_id', $itemAlias, $filters);
    }

    private function applyItemFilters(mysqli $conn, array &$conditions, array &$params, string $itemIdColumn, string $itemAlias, array $filters): void
    {
        if (($itemId = $this->positiveInt($filters['item_id'] ?? null)) > 0) {
            $conditions[] = $itemIdColumn . ' = ?';
            $params[] = $itemId;
        }
        if (($categoryId = $this->positiveInt($filters['category_id'] ?? null)) > 0 && $this->columnExists($conn, 'myitems', 'group1')) {
            $conditions[] = $itemAlias . '.group1 = ?';
            $params[] = $categoryId;
        }
        if (($search = $this->searchFilter($filters['q'] ?? '')) !== '') {
            $like = '%' . addcslashes($search, "\\%_") . '%';
            $conditions[] = '(' . $itemAlias . ".iname LIKE ? ESCAPE '\\\\' OR " . $itemAlias . ".barcode LIKE ? ESCAPE '\\\\' OR CAST(" . $itemIdColumn . ' AS CHAR) = ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $search;
        }
    }

    private function applyScopeFilters(array &$conditions, array &$params, string $alias, array $filters, array $columns): void
    {
        foreach ($columns as $column) {
            if (isset($filters[$column]) && (int) $filters[$column] >= 0) {
                $conditions[] = $alias . '.' . $column . ' = ?';
                $params[] = (int) $filters[$column];
            }
        }
    }

    private function applyStoreFilter(array &$conditions, array &$params, string $column, array $filters): void
    {
        if (($storeId = $this->positiveInt($filters['store_id'] ?? null)) > 0) {
            $conditions[] = $column . ' = ?';
            $params[] = $storeId;
        }
    }

    private function applySupplierFilter(array &$conditions, array &$params, string $column, array $filters): void
    {
        if (($supplierId = $this->positiveInt($filters['supplier_account_id'] ?? null)) > 0) {
            $conditions[] = $column . ' = ?';
            $params[] = $supplierId;
        }
    }

    private function applyDateFilters(array &$conditions, array &$params, string $column, array $filters): void
    {
        $from = $this->dateFilter($filters['date_from'] ?? '');
        $to = $this->dateFilter($filters['date_to'] ?? '');
        if ($from !== '') {
            $conditions[] = $column . ' >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $conditions[] = $column . ' <= ?';
            $params[] = $to . ' 23:59:59';
        }
    }

    private function withMovementDrilldown(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['drilldown_url'] = $this->movementDrilldownUrl($row);
        }
        unset($row);

        return $rows;
    }

    private function movementDrilldownUrl(array $row): string
    {
        $sourceType = (string) ($row['source_type'] ?? '');
        $sourceId = (int) ($row['source_id'] ?? 0);
        if ($sourceType === 'inventory_count' && $sourceId > 0) {
            return 'inventory_count_detail.php?id=' . $sourceId;
        }
        if ($sourceType === 'inventory_transfer' && $sourceId > 0) {
            return 'inventory_transfer_detail.php?id=' . $sourceId;
        }
        if ($sourceType === 'production_batch' && (int) ($row['production_batch_id'] ?? 0) > 0) {
            return 'recipe_production.php?batch_id=' . (int) $row['production_batch_id'];
        }
        if (in_array($sourceType, ['purchase_receipt', 'purchase_order', 'purchase_invoice'], true)) {
            return 'inventory_purchasing.php';
        }
        if ((int) ($row['order_id'] ?? 0) > 0) {
            return 'sales.php?id=' . (int) $row['order_id'];
        }

        return '';
    }

    private function balanceScopeSql(array $filters, string $select): string
    {
        $conditions = ['1 = 1'];
        if (isset($filters['pos_tenant']) && (int) $filters['pos_tenant'] >= 0) {
            $conditions[] = 'b.pos_tenant = ?';
        }
        if (isset($filters['pos_branch']) && (int) $filters['pos_branch'] >= 0) {
            $conditions[] = 'b.pos_branch = ?';
        }
        if (($storeId = $this->positiveInt($filters['store_id'] ?? null)) > 0) {
            $conditions[] = 'b.store_id = ?';
        }

        return "SELECT {$select} AS value FROM inventory_item_balances b WHERE " . implode(' AND ', $conditions);
    }

    private function balanceScopeParams(array $filters): array
    {
        $params = [];
        if (isset($filters['pos_tenant']) && (int) $filters['pos_tenant'] >= 0) {
            $params[] = (int) $filters['pos_tenant'];
        }
        if (isset($filters['pos_branch']) && (int) $filters['pos_branch'] >= 0) {
            $params[] = (int) $filters['pos_branch'];
        }
        if (($storeId = $this->positiveInt($filters['store_id'] ?? null)) > 0) {
            $params[] = $storeId;
        }

        return $params;
    }

    private function dashboardLowStockCount(mysqli $conn, array $filters): string
    {
        if (!$this->tableExists($conn, 'inventory_item_stock_levels')) {
            return '0';
        }

        $conditions = ['1 = 1'];
        $params = [];
        $this->applyScopeFilters($conditions, $params, 'b', $filters, ['pos_tenant', 'pos_branch']);
        $this->applyStoreFilter($conditions, $params, 'b.store_id', $filters);

        return $this->scalar($conn, "
SELECT COUNT(*) AS value
FROM inventory_item_balances b
INNER JOIN inventory_item_stock_levels levels
  ON levels.pos_tenant = b.pos_tenant
 AND levels.pos_branch = b.pos_branch
 AND levels.store_id = b.store_id
 AND levels.item_id = b.item_id
 AND COALESCE(levels.is_active, 1) = 1
WHERE " . implode(' AND ', $conditions) . "
  AND (b.qty_available <= COALESCE(NULLIF(levels.reorder_level, 0), levels.minimum_level) OR b.qty_on_hand < 0)", $params);
    }

    private function dashboardMovementCount(mysqli $conn, array $filters, string $from): string
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return '0';
        }
        $conditions = ['im.created_at >= ?'];
        $params = [$from];
        $this->applyScopeFilters($conditions, $params, 'im', $filters, ['pos_tenant', 'pos_branch']);
        $this->applyStoreFilter($conditions, $params, 'im.store_id', $filters);

        return $this->scalar($conn, 'SELECT COUNT(*) AS value FROM inventory_movements im WHERE ' . implode(' AND ', $conditions), $params);
    }

    private function dashboardMovementCost(mysqli $conn, array $filters, array $types, string $from): string
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return '0';
        }
        $conditions = ['im.created_at >= ?', 'im.movement_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')'];
        $params = array_merge([$from], $types);
        $this->applyScopeFilters($conditions, $params, 'im', $filters, ['pos_tenant', 'pos_branch']);
        $this->applyStoreFilter($conditions, $params, 'im.store_id', $filters);

        return $this->scalar($conn, 'SELECT COALESCE(SUM(im.total_cost), 0) AS value FROM inventory_movements im WHERE ' . implode(' AND ', $conditions), $params);
    }

    private function dashboardWorkflowCount(mysqli $conn, string $table, string $statusCondition, array $filters): string
    {
        if (!$this->tableExists($conn, $table)) {
            return '0';
        }
        $conditions = [$statusCondition];
        $params = [];
        $alias = 'w';
        $this->applyScopeFilters($conditions, $params, $alias, $filters, ['pos_tenant', 'pos_branch']);
        if (($storeId = $this->positiveInt($filters['store_id'] ?? null)) > 0) {
            $storeColumn = $table === 'inventory_transfers' ? 'source_store_id' : ($table === 'inventory_purchase_orders' ? 'destination_store_id' : 'store_id');
            if ($table === 'inventory_transfers') {
                $conditions[] = '(w.source_store_id = ? OR w.destination_store_id = ?)';
                $params[] = $storeId;
                $params[] = $storeId;
            } else {
                $conditions[] = 'w.' . $storeColumn . ' = ?';
                $params[] = $storeId;
            }
        }

        return $this->scalar($conn, 'SELECT COUNT(*) AS value FROM `' . $table . '` w WHERE ' . implode(' AND ', $conditions), $params);
    }

    private function scalar(mysqli $conn, string $sql, array $params = []): string
    {
        $row = $this->fetchOne($conn, $sql, $params);

        return (string) ($row['value'] ?? '0');
    }

    private function fetchOne(mysqli $conn, string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($conn, $sql, $params);

        return $rows[0] ?? null;
    }

    private function fetchAll(mysqli $conn, string $sql, array $params = []): array
    {
        $stmt = $conn->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function bindParams(mysqli_stmt $stmt, array $params): void
    {
        if (!$params) {
            return;
        }

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

    private function limit($value): int
    {
        return max(1, min(5000, (int) $value));
    }

    private function positiveInt($value): int
    {
        return (int) $value > 0 ? (int) $value : 0;
    }

    private function textFilter($value): string
    {
        return preg_replace('/[^a-zA-Z0-9_:-]/', '', strtolower(trim((string) $value)));
    }

    private function searchFilter($value): string
    {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '');
        if ($text === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, 120, 'UTF-8') : substr($text, 0, 120);
    }

    private function dateFilter($value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['table_count'] ?? 0) > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['column_count'] ?? 0) > 0;
    }
}
