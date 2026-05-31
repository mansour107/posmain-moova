# Current Inventory Schema Findings

Generated: 2026-05-29

This document summarizes schema and data-shape findings that matter for the inventory restructure. It complements `write_path_map.md` and `invoice_type_map.md`.

## Legacy Tables

### `fat_details`

Important columns:

- `pro_tybe`, `fat_tybe`: invoice/operation type IDs.
- `det_store`: store/warehouse pointer, default `1`.
- `item_id`: item being moved.
- `u_val`: unit conversion multiplier used by legacy invoices.
- `qty_in`, `qty_out`: legacy stock movement quantities.
- `cost_price`, `stock_value`, `det_value`, `profit`: cost/value fields mixed into the invoice line.
- `tenant`, `branch`: present but not used by the legacy balance trigger.

Legacy triggers update `myitems.itmqty` after insert/update by summing all active `fat_details` rows for the item. The triggers do not scope by store, tenant, or branch.

### `myitems`

Important columns:

- `itmqty`: current legacy item quantity summary.
- `cost_price`: current catalog/item cost.
- `last_price`: last purchase price.
- `tenant`, `branch`: present but not enough for final inventory truth.
- newer code also uses `item_type` and `track_stock` when available, but not all old write paths enforce them.

Production concern: catalog metadata and inventory state are mixed. A Foodics-like system should treat item master data, stock balances, and movement history as separate concepts.

## Recipe Inventory Tables

`SyncSchemaManager` plans or owns these relevant tables:

| Table | Purpose |
| --- | --- |
| `recipe_headers` / `recipe_lines` | Defines sellable-item recipes/BOMs and ingredient requirements. |
| `recipe_order_line_usage` | Links a sold line to recipe explosion/usage lifecycle. |
| `inventory_movements` | Append-style inventory movement ledger. |
| `inventory_item_balances` | Current per-scope balance cache. |
| `stock_reservations` | Reserved ingredient quantities for order lines. |
| `production_batches` | Batch production of prepared items. |

The recipe schema is much closer to production inventory because it has movement types, source types, idempotency, tenant/branch/store scope, and reservation concepts.

## Unit Model

Legacy invoices use `item_units.u_val` and multiply operator quantity by `u_val` before writing `qty_in` or `qty_out`. Recipe movements store both `unit_id` and `unit_conversion_to_base`.

Proof:

- `db/DB.sql:883` defines `fat_details.u_val`.
- `db/DB.sql:1162` defines `item_units`.
- `db/DB.sql:1165` and `db/DB.sql:1166` define `item_units.unit_id` and `item_units.u_val`.
- `db/DB.sql:1683` defines `myunits`.
- `get/get_iteminfo.php:19` through `get/get_iteminfo.php:33` expose unit metadata and `u_val` to item lookup callers.

Open issue: the final inventory service needs one explicit base-unit rule so purchase units, recipe ingredient units, POS sell units, and count units cannot drift.

## Store And Branch Identity

Legacy:

- `fat_details.det_store` exists but balance trigger ignores it.
- `myitems.itmqty` is global for each item.
- `tenant`/`branch` columns exist on some old tables but are not consistently part of stock calculations.

Recipe ledger:

- balances are unique by `pos_tenant`, `pos_branch`, `store_id`, `item_id`.
- movements are idempotent by `pos_tenant`, `pos_branch`, `store_id`, `idempotency_key`.
- `branch_uuid` is available for sync/cloud correlation.

Proof:

- `classes/Sync/SchemaManager.php:1500` through `classes/Sync/SchemaManager.php:1543` define `recipe_order_line_usage` with scoped idempotency and recipe/order-line evidence.
- `classes/Sync/SchemaManager.php:1549` through `classes/Sync/SchemaManager.php:1594` define `inventory_movements` with tenant, branch, branch UUID, store, item, movement type, source type, quantities, unit conversion, costs, and idempotency.
- `classes/Sync/SchemaManager.php:1600` through `classes/Sync/SchemaManager.php:1618` define `inventory_item_balances` and its unique key for scoped item balances.
- `classes/Sync/SchemaManager.php:1624`, `classes/Sync/SchemaManager.php:1676`, `classes/Sync/SchemaManager.php:1738`, `classes/Sync/SchemaManager.php:1802`, and `classes/Sync/SchemaManager.php:1855` define the stock-level, count, transfer, purchase-order, and purchase-receipt workflow tables that explain why movements are created.
- `classes/Recipe/Repository/InventoryMovementRepository.php:82` through `classes/Recipe/Repository/InventoryMovementRepository.php:100` lookup movements by scoped idempotency key.
- `classes/Recipe/Repository/InventoryBalanceRepository.php:49` through `classes/Recipe/Repository/InventoryBalanceRepository.php:63` lookup balances by tenant/branch/store/item.

Future migration should use recipe-style scoped identity and demote the old global quantity to a temporary compatibility cache.

## Monitoring And Reporting

Current reporting is split:

- Legacy item and sales reports often read `myitems.itmqty` and/or `fat_details`.
- Recipe reconciliation and operations services read `inventory_movements` and `inventory_item_balances`.
- Opening balance UI now reads recipe balances for ingredients/packaging when available, otherwise legacy item summary.

Production gap: there is no single operator inventory dashboard covering stock-on-hand, reserved, available, below reorder, wastage, transfers, counts, purchasing, and audit history in one consistent language.

## Quantity Read And Monitoring Surfaces

These are the current places where item/ingredient amounts are fetched or shown to operators. They are not all final-state-safe; the point of Phase 0 is to name the current truth each surface uses before changing behavior.

| Surface | Current source | What the operator/system sees | Production concern | Proof |
| --- | --- | --- | --- | --- |
| Item list `myitems.php` | `InventoryStockReadService` decoration when available; otherwise `myitems.itmqty` | Stock quantity cell links to item summary and displays `stock_qty_display` or legacy `itmqty` | In non-live mode the old global quantity is still the list value | `myitems.php:97`, `myitems.php:98`, `classes/Inventory/InventoryStockReadService.php:47`, `classes/Inventory/InventoryStockReadService.php:70` |
| Main dashboard recent items | `InventoryStockReadService::decorateItems()` | Recent item rows show `stock_qty_display` when present | Same source-switching risk as the item list | `elements/main/main_tables.php:190`, `elements/main/main_tables.php:194` |
| Opening balance page `items_start_balance.php` | `inventory_item_balances.qty_on_hand` for ingredients/packaging when table exists; otherwise `myitems.itmqty` | Current quantity/current cost beside editable new quantity/new price | Useful migration bridge, but mixed source by item type | `items_start_balance.php:59`, `items_start_balance.php:143`, `items_start_balance.php:158` |
| Invoice item popup/API `get/get_iteminfo.php` and `InvoiceDetails.php` | `SELECT * FROM myitems` and returned `itmqty` | Store quantity shown in invoice UI and divided by selected unit conversion | Reads legacy global quantity, not scoped available stock | `get/get_iteminfo.php:9`, `classes/InvoiceDetails.php:346`, `classes/InvoiceDetails.php:371` |
| Item movement summary `item_summery.php` | Active `fat_details` totals | Totals compute `SUM(qty_in) - SUM(qty_out)` for the selected legacy details | Legacy history ignores final ledger/reservation state | `item_summery.php:243`, `item_summery.php:251` |
| Stock levels page `inventory_stock_levels.php` | `inventory_item_balances` joined to `inventory_item_stock_levels` | Shows available quantity against min/reorder/par/max levels | Good final-direction surface, but depends on ledger correctness | `inventory_stock_levels.php:72`, `inventory_stock_levels.php:90`, `inventory_stock_levels.php:293` |
| Adjustment page `inventory_adjustments.php` | `inventory_item_balances` preload | Shows available and on-hand before an adjustment | Good operator context; must stay permissioned and ledger-backed | `inventory_adjustments.php:56`, `inventory_adjustments.php:458`, `inventory_adjustments.php:459` |
| Inventory reports service | `inventory_item_balances`, `inventory_movements`, and stock levels | Dashboard totals, low stock, negative stock, reserved quantity, movement history, purchase suggestions | Good final-direction reporting; should replace legacy stock reports after reconciliation | `classes/Inventory/InventoryReportsService.php:46`, `classes/Inventory/InventoryReportsService.php:93`, `classes/Inventory/InventoryReportsService.php:222` |
| Recipe operational dashboard | `stock_reservations` and `inventory_item_balances` | Stale reservations and negative/invalid balances | Good monitoring, but still separate from a unified Arabic operator inventory dashboard | `classes/Recipe/RecipeOperationalDashboardService.php:72`, `classes/Recipe/RecipeOperationalDashboardService.php:102`, `classes/Recipe/RecipeOperationalDashboardService.php:140` |
| Recipe availability service | `InventoryBalanceRepository` / `inventory_item_balances` | Computes makeable quantity from on-hand minus reserved and safety stock | Correct direction for recipe availability; depends on reservation lifecycle consistency | `classes/Recipe/RecipeAvailabilityService.php:347`, `classes/Recipe/RecipeAvailabilityService.php:390` |

Final-state implication: stock read paths need the same cutover discipline as write paths. A Foodics-like system cannot have POS popups reading `myitems.itmqty`, reports reading `inventory_item_balances`, and item history reading `fat_details` as if all three were the same quantity.

## Inventory Workflow And Control Tables

These tables and services affect inventory operations, monitoring, approvals, and operator workflow. Some of them later create ledger movements, but their own header/line/config writes are not the same as changing on-hand stock.

| Surface | Tables written | Role | Stock effect | Proof |
| --- | --- | --- | --- | --- |
| Stock levels | `inventory_item_stock_levels` | Stores min/reorder/par/max/safety stock, preferred count/purchase units, and optional default supplier | Does not move stock; changes low-stock monitoring and purchase suggestions | `classes/Inventory/InventoryStockLevelService.php:42`, `classes/Inventory/InventoryStockLevelService.php:59`, `ajax/inventory_stock_level_save.php:26` |
| Reason codes | `inventory_reason_codes` | Manages adjustment/transfer/count reason metadata, direction, approval requirement, and active state | Does not move stock by itself; governs whether later operations need approval and how they are classified | `classes/Inventory/InventoryReasonCodeService.php:79`, `classes/Inventory/InventoryReasonCodeService.php:105`, `classes/Inventory/InventoryReasonCodeService.php:135`, `ajax/inventory_reason_code.php:34` |
| Purchase orders and receipts | `inventory_purchase_orders`, `inventory_purchase_order_lines`, `inventory_purchase_receipts`, `inventory_purchase_receipt_lines` | Draft/submitted/approved supplier order workflow plus posted receiving/return evidence | Purchase orders do not increase stock until receiving posts purchase movements; receipt headers/lines explain the movement source and receipt lines link back to movement IDs | `classes/Inventory/InventoryPurchaseOrderService.php:164`, `classes/Inventory/InventoryPurchaseOrderService.php:191`, `classes/Inventory/InventoryPurchaseOrderService.php:214`, `classes/Inventory/InventoryPurchaseReceivingService.php:282`, `classes/Inventory/InventoryPurchaseReceivingService.php:434`, `classes/Inventory/InventoryPurchaseReceivingService.php:462`, `classes/Inventory/InventoryPurchaseReceivingService.php:475` |
| Inventory counts | `inventory_counts`, `inventory_count_lines` | Physical count workflow, snapshots, counted quantity, variance, stale conflict state | Creating/submitting/counting lines does not move stock; closing approved counts posts adjustment movements through the ledger | `classes/Inventory/InventoryCountService.php:394`, `classes/Inventory/InventoryCountService.php:416`, `classes/Inventory/InventoryCountService.php:460`, `classes/Inventory/InventoryCountService.php:512` |
| Transfers | `inventory_transfers`, `inventory_transfer_lines` | Transfer header/line workflow, send/receive/variance state | Draft/submitted lines do not move stock; send/receive/cancel paths post transfer movements through the ledger | `classes/Inventory/InventoryTransferService.php:601`, `classes/Inventory/InventoryTransferService.php:635`, `classes/Inventory/InventoryTransferService.php:650`, `classes/Inventory/InventoryTransferService.php:658` |

Final-state implication: these workflow/config writes should stay separate from direct quantity writes. They are useful and close to the Foodics workflow model, but cutover must prove that only approved/received/closed/send/receive actions post `inventory_movements`, while drafts, reason-code edits, and stock-level edits only change workflow/config state.

## Issues To Address Before Foodics-Level Inventory

- Remove dual truth by choosing one final inventory writer and one final balance source.
- Resolve invoice type `14` and return-type naming conflicts.
- Add real purchase-to-ledger movement writes for purchase invoices.
- Add inventory count workflow with variance approval/posting.
- Add transfers between stores with paired in/out movements.
- Add supplier/purchase receiving status instead of treating all purchase invoice entry as final stock receipt.
- Make `track_stock` and item type rules enforceable at write time.
- Make unit conversion explicit and testable.
- Make stock reservations and availability visible to operators.
- Add permissioned waste/adjustment workflows with reason codes.
- Add UI states for empty data, sync/offline, failed posting, locked/approved counts, and audit drilldown.

## Suggested Test Direction

Future behavior-changing phases should add tests around:

- invoice type direction registry,
- purchase invoice creates `purchase` movement and balance update,
- POS sale creates exactly one legacy/order detail and one expected recipe consumption set,
- refund/void reversal idempotency,
- opening balance does not share type with offer,
- branch/store scoped balances do not leak across stores,
- unit conversion from purchase, recipe, count, and sale paths,
- report pages reading the intended final source.
