# Phase 14 Historical Migration Contracts

Phase 14 is the cutover preparation layer between the legacy stock model and the ledger model. It does not replace runtime behavior by itself.

## Scope

- Snapshot legacy `myitems.itmqty`, active/deleted `fat_details`, `inventory_movements`, and `inventory_item_balances`.
- Preview idempotent ledger movements that can be safely generated from historical `fat_details`.
- Flag ambiguous legacy rows for human decision instead of guessing.
- Preview, rehearse, and backup-gate a balance rebuild from `inventory_movements` into `inventory_item_balances`.
- Keep the broad migration plan dry-run-only. The `fat_details` backfill and balance rebuild tools have guarded apply paths for reviewed migration work.

## Tools

- `php tools/inventory_migration_plan.php --dry-run`
- `php tools/inventory_backfill_from_fat_details.php --dry-run`
- `php tools/inventory_backfill_from_fat_details.php --rehearse`
- `php tools/inventory_backfill_from_fat_details.php --dry-run --decisions-file=/absolute/path/to/reviewed-decisions.json`
- `php tools/inventory_backfill_from_fat_details.php --apply --backup-file=/absolute/path/to/recent.sql`
- `php tools/inventory_rebuild_balances.php --dry-run`
- `php tools/inventory_rebuild_balances.php --rehearse`
- `php tools/inventory_rebuild_balances.php --apply --backup-file=/absolute/path/to/recent.sql`
- `php tools/inventory_reconciliation_repair.php --dry-run`
- `php tools/inventory_reconciliation_repair.php --rehearse`
- `php tools/inventory_reconciliation_repair.php --apply --backup-file=/absolute/path/to/recent.sql`
- `php tools/inventory_reconciliation_check.php --json`

The broad plan tool requires `--dry-run`. The backfill and balance rebuild tools require exactly one of `--dry-run`, `--rehearse`, or `--apply`. Rehearse executes the same write path inside a rolled-back transaction so operators can prove idempotency and balance behavior without persistent stock changes. Apply requires a readable backup file. Backfill also blocks on ambiguous rows unless reviewed ambiguous row decisions or `--skip-ambiguous` are explicitly supplied for a reviewed batch.

## Safe Legacy Row Rules

A `fat_details` row is considered a safe candidate only when:

- it has a real legacy detail id;
- it has a positive `item_id`;
- it has a positive `det_store`;
- exactly one of `qty_in` or `qty_out` is positive;
- it is not deleted unless explicitly being reviewed with `--include-deleted`;
- its `pro_tybe` maps cleanly to a ledger movement:
  - `4` plus inbound quantity becomes `purchase`;
  - `3` or `9` plus outbound quantity becomes `sale_direct`;
  - `10` plus outbound quantity becomes `adjustment`;
  - `11` plus inbound quantity becomes `refund_reversal`.

Rows with `pro_tybe=14` are always ambiguous because legacy type 14 has been used both for opening balances and invoice offers.

Rows whose item is configured with `track_stock = 0` are reported as skipped with reason `non_stock_item_not_migrated_to_inventory_ledger`. The ledger service would no-op those rows, so the migration plan must not count them as applied inventory movements.

## Reviewed Ambiguous Rows

Reviewed ambiguous rows can be supplied with `--decisions-file=/absolute/path/to/reviewed-decisions.json`. The file must contain a top-level `decisions` array. Each decision must name `fat_detail_id` and either:

- `action: "movement"` with an explicit `movement_type` such as `opening_balance`, `purchase`, `purchase_return`, `sale_direct`, `adjustment`, or `refund_reversal`;
- `action: "skip"` with a reason when the row is proven not to represent inventory stock that should be migrated.

Reviewed movement decisions use a separate idempotency key shape, `migration:fat_details:{id}:reviewed:v1`, and metadata source `phase14_reviewed_fat_details_backfill`. This keeps automatic safe-row migration separate from human-reviewed legacy interpretation.

## Idempotency

Backfill candidates use deterministic keys:

- `idempotency_key`: `migration:fat_details:{id}:v1`
- `source_type`: `fat_details`
- `source_uuid`: `legacy-fat-details:{id}`
- `metadata.source`: `phase14_fat_details_backfill`
- `unit_conversion_to_base`: positive legacy `fat_details.u_val`, while `qty_in` / `qty_out` remain the historical ledger-base quantities already stored on the legacy row.

The preview treats an existing movement with the same tenant, branch, store, and idempotency key as already migrated.

## Signoff Required Before Apply

Any apply run must require:

- database backup evidence;
- clean or intentionally accepted reconciliation;
- reviewed ambiguous `fat_details` rows;
- branch, store, and item-category signoff;
- rollback run on a test database;
- proof that running the migration twice is idempotent or guarded.

The current backfill apply path writes through `InventoryLedgerService`, uses the deterministic migration idempotency key, and updates `inventory_item_balances` as part of the same ledger service behavior.

Use `--rehearse` before `--apply` for each scoped batch. A successful rehearsal should show the expected `rehearsed_count`, should not add persistent `inventory_movements`, and should leave reconciliation blockers unchanged until the real apply is intentionally run with backup evidence.

## Balance Rebuild

The balance rebuild path derives scoped balances from `inventory_movements`, then compares quantity, moving average cost, missing balance rows, and last movement pointers against `inventory_item_balances`.

For negative on-hand rows, derived moving average cost still uses the movement
stock value divided by quantity and is normalized to a non-negative unit cost.
It must not collapse to zero merely because quantity is below zero; otherwise a
rebuild could erase the cost evidence needed for COGS and valuation review. A
zero on-hand row derives zero average cost because there is no quantity base.

Use `php tools/inventory_rebuild_balances.php --rehearse` before any apply. Rehearsal writes through the existing balance repository inside a rolled-back transaction. Apply requires `--backup-file` and should be run only after historical movement migration for the scoped batch is complete.

## Reconciliation Repair

The reconciliation repair tool is intentionally narrow. It only refreshes `myitems.itmqty` compatibility mirror rows when `fat_details`, `inventory_movements`, and `inventory_item_balances` already agree and the only problem is the old mirror quantity. It does not repair non-stock policy problems, deleted legacy detail/ledger-only stock, or real quantity mismatches. Apply requires backup evidence.

## Non-Goals

- No runtime page switches to ledger-only in this phase.
- No deletion of legacy `fat_details` or `myitems.itmqty`.
- No inferred store/tenant/branch mapping for missing legacy data.
- No multiplication or reinterpretation of old quantities during migration; legacy `u_val` is preserved as movement conversion metadata while historical `qty_in` / `qty_out` values remain unchanged.
