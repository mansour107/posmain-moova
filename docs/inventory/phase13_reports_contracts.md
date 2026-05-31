# Phase 13 Inventory Reports and Dashboard Contracts

## Scope

Phase 13 adds manager-facing Arabic dashboard and reporting surfaces on top of the new inventory ledger and existing workflow tables. They are read-only and do not add tables.

## Page

- `inventory_dashboard.php`
  - dedicated manager dashboard for daily inventory attention.
  - requires login and the same report/inventory/accounting visibility boundary as inventory reports.
  - shows stock value when cost is allowed, low/negative stock, open counts/transfers/purchase orders, needs-attention items, replenishment suggestions, recent stock movements, and menu availability impact from `recipe_availability_cache`.
  - links operators to purchasing, counts, adjustments, and detailed reports without exposing technical movement ids in the main view.
  - uses branch, store, and item selectors with operator-facing names. The underlying query parameters stay compatible (`pos_branch`, `store_id`, `item_id`), but the normal dashboard workflow no longer asks managers to type raw branch or item ids.
- `inventory_reports.php`
- Requires login.
- Allows users with report, inventory, or accounting visibility.
- Shows Arabic KPI cards and report filters.
- Branch, store, supplier, item, and category filters are operator-facing selectors by name. If an old link carries a selected id that is not in the current option list, the page keeps the filter value with a generic "selected from link" label instead of asking managers to type or interpret raw technical ids.
- The movement-type filter is an operator-facing selector with Arabic labels while preserving the same `movement_type` query parameter values for old links and report service filtering.
- Exports the selected report as sanitized CSV.
- Renders drilldown links to movement history, count detail, transfer detail, purchasing, and production pages where the source row supports it.
- HTML report tables and dashboard recent-movement/menu-availability rows translate technical movement, item type, source, status, order type, and channel tokens into Arabic labels. CSV export keeps the stored raw values so technical reconciliation exports remain stable.
- Dashboard/report filters and dashboard rows use generic unnamed-branch/item/store labels when historical joins are incomplete instead of showing raw database ids in normal manager tables.

## Reports

The shared `InventoryReportsService` supports:

- Inventory Levels
- Movement History
- Low Stock
- Replenishment Suggestions
- Purchase History
- Supplier Purchase Summary
- Transfer History
- Count Variance
- Waste/Adjustment
- Production Variance
- Recipe Consumption
- Menu Availability / Can Make
- Inventory Valuation / Cost History
- COGS Reconciliation

Replenishment Suggestions uses stock-level policy data plus preferred purchase units when present. It still shows the base-unit shortage, and also shows the preferred purchase unit, unit conversion, rounded purchase-unit quantity, rounded base quantity to receive, and estimated cost from that rounded purchase quantity.

Supplier Purchase Summary groups posted/received purchase receipt lines by supplier using existing purchase receipt tables. It shows receipt count, line count, item count, stores used, first/last purchase dates, received/returned/net quantities, and cost metrics when the session may view costs. It is read-only and does not introduce a supplier catalog table.

Menu Availability / Can Make reads the existing `recipe_availability_cache` and shows store, sellable menu item, can-make quantity, available/unavailable status, limiting ingredient, order type, channel, and a stock drilldown. It exposes the same availability truth used by the POS and dashboard without creating another recipe or stock table.

Inventory Valuation / Cost History is accounting-only in the UI. It reads current scoped balances plus each balance row's last inventory movement, showing current quantity, moving-average cost, stock value, and the last cost-bearing movement without writing or rebuilding balances.

## Data Sources

Reports reuse existing tables:

- `inventory_item_balances`
- `inventory_item_stock_levels`
- `inventory_movements`
- `inventory_purchase_receipts`
- `inventory_purchase_receipt_lines`
- `inventory_transfers`
- `inventory_transfer_lines`
- `inventory_counts`
- `inventory_count_lines`
- `production_batches`
- `production_batch_lines`
- `recipe_headers`
- `journal_heads`
- `journal_entries`

## Permissions

Cost-sensitive columns are hidden unless the session has accounting/admin access. General report access alone can view quantities, statuses, dates, and drilldowns without seeing stock value or cost.

## Tests

- `tests/sync/inventory_phase13_reports_contract_test.php`
- `tests/sync/inventory_phase13_reports_service_test.php`

The service test proves dashboard metrics, dashboard detail sections, report dispatch, report rows, supplier purchase grouping, supplier/category/item filters, movement-type filtering, menu availability impact rows, and drilldown URLs against a temporary MySQL database.
