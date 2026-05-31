# Phase 2 Disabled Inventory Workflow Schema

Generated: 2026-05-29

Scope: Phase 2 of `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`.

This phase adds missing workflow tables through `classes/Sync/SchemaManager.php` only. At the Phase 2 boundary, it does not wire the new tables into POS, purchase, transfer, count, waste, recipe, sync, or report runtime behavior.

Later phase contracts are allowed to wire these tables through approved Inventory module services and pages. The Phase 2 regression test keeps this distinction explicit: unexpected references outside those later-phase inventory surfaces should still fail.

## Reuse Decision

Existing `ot_head` / `fat_details` rows can represent posted invoice lines and current legacy stock math, but they cannot safely represent:

- physical count drafts, submitted counts, approvals, snapshot quantities, count conflicts, or variance cost;
- transfer draft/send/receive/partial receive/variance lifecycle;
- purchase order approval and partial receiving before actual stock receipt;
- reusable reason codes for waste, count variance, transfer variance, purchase return, and manual adjustment;
- branch/store/item min, reorder, par, max, and safety stock levels.
- item/store preferred count unit, preferred purchase unit, and default supplier policy.

For that reason, Phase 2 adds workflow document tables. `inventory_store_settings` is not added in this phase because default store configuration already exists through `myoptions`, `settings`, users, and stock-account rows; the current plan only allows that table if a later workflow proves it is needed.

## Added Planned Tables

- `inventory_item_stock_levels`
- `inventory_reason_codes`
- `inventory_counts`
- `inventory_count_lines`
- `inventory_transfers`
- `inventory_transfer_lines`
- `inventory_purchase_orders`
- `inventory_purchase_order_lines`
- `inventory_purchase_receipts`
- `inventory_purchase_receipt_lines`

## Existing Ledger Tables Verified

The schema manager continues to plan:

- `inventory_movements`
- `inventory_item_balances`
- `stock_reservations`
- `recipe_order_line_usage`
- `production_batches`
- `recipe_availability_cache`

Phase 2 also adds missing additive indexes for later query paths:

- `inventory_movements.idx_inventory_movement_type_time (movement_type, created_at)`
- `stock_reservations.idx_stock_reservation_order_line (order_id, order_line_uuid)`

`inventory_movements.movement_type` now includes `purchase_return` so supplier returns are distinguishable from manual adjustments in the ledger, reports, and accounting review paths.

The existing unique keys for inventory idempotency and balances remain intact. The current code uses store-scoped idempotency lookups, so changing that uniqueness shape is deferred until a data audit proves no existing store-scoped keys would collide.

## Regression Guardrails

At the Phase 2 boundary, this deliberately does not:

- add runtime calls to the new workflow tables;
- replace `fat_details` or `myitems.itmqty`;
- enable strict stock, reservations, accounting, availability, sync, or public cost payloads;
- add `inventory_store_settings` without a proven workflow need;
- introduce destructive SQL.

The migration statements are additive and disabled by default: table creation and index creation only.
