# Phase 18 Stock Level Policy Contracts

Generated: 2026-05-30

Scope: Foodics-like min/reorder/par/max policy management from `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`.

## Implemented

- `InventoryStockLevelService` uses the existing `inventory_item_stock_levels` table.
- No new tables were added.
- The workflow saves one scoped policy row per tenant, branch, store, and item.
- The service validates that the item is stock-tracked and blocks service/non-stock items.
- Quantities are decimal-safe and non-negative.
- Optional ordering guardrails are enforced:
  - reorder cannot be below minimum when both are set;
  - par cannot be below reorder when both are set;
  - maximum cannot be below par when both are set.
- Saving stock levels does not write inventory movements and does not mutate balances.
- Saved policies immediately feed the low-stock and replenishment reports through `InventoryReportsService`.
- Stock-level rows can store optional preferred count and purchase units using existing `item_units`; invalid unit/item combinations are rejected.
- Stock-level rows can store an optional default supplier through `default_supplier_account_id`, pointing at existing supplier accounts in `acc_head`. This gives replenishment and receiving a supplier default without adding a supplier catalog table.
- Pages and CSV export tolerate older `inventory_item_stock_levels` schemas that do not yet have `default_supplier_account_id`: the page still loads, supplier defaults are shown as unavailable, and current CSV exports `0` for that optional column instead of failing.
- `inventory_stock_levels.php` provides an Arabic operator screen for setting minimum, reorder, par, maximum, and safety-stock quantities.
- The stock-level page includes item search by name/barcode and a load-for-edit action on current policy rows, so operators can adjust existing min/reorder/par settings without retyping the store/item context.
- `inventory_stock_levels.php` also lets operators choose preferred count and purchase units. Transfer creation can reuse these preferences as visible unit defaults without adding another preference table.
- `ajax/inventory_stock_level_save.php` is POST-only, CSRF-protected by `inventory_stock_level`, and requires `inventory.edit`.
- Technical admins can download a CSV template, export current stock-level policies, and import up to 500 CSV rows at a time. Normal inventory editors do not see the raw-ID CSV import/export controls, so the day-to-day stock-level screen stays name/barcode driven.
- CSV import calls `InventoryStockLevelService::save()` for each row inside one transaction, so the same item/unit/order validation is reused and no inventory movements or balance mutations are created.
- Category-wide mass update applies the same min/reorder/par/max/safety/active values to all active, stock-tracked, non-service `myitems` rows in one `item_group` category. It reuses `InventoryStockLevelService::save()` per item inside one transaction, intentionally does not mass-assign preferred units, and preserves any existing default supplier instead of spreading one supplier across a category.
- Replenishment suggestions use `preferred_purchase_unit_id` and existing `item_units.u_val` to show rounded purchase-unit quantities and rounded base quantities. When `default_supplier_account_id` is present, the report also exposes the default supplier id/name so buyers can group suggested orders by supplier without a supplier catalog table.
- `ajax/inventory_stock_level_bulk.php` is POST-only, CSRF-protected by `inventory_stock_level`, and requires `inventory.edit`.
- Changing an existing stock-level policy requires manager/accounting approval context (`inventory.approve` or `accounting.view`). New policy rows can still be created by inventory editors, but edits to existing values return `STOCK_LEVEL_APPROVAL_REQUIRED` unless the endpoint supplies approval context.
- Every create/update writes a before/after audit row to the existing
  `recipe_audit_log` table when that table exists. The audit entity type is
  `inventory_stock_level`, with actions `create_inventory_stock_level` and
  `update_inventory_stock_level`. This keeps min/reorder/par changes reviewable
  without adding an inventory-specific audit table or writing stock movements.
