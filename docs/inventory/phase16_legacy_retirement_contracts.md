# Phase 16 Legacy Retirement Contracts

Phase 16 retires old inventory stock behavior only after proof. In the current rollout, destructive removal is deliberately blocked because historical migration and business signoff are not complete.

## What Is Guarded Now

- The old item opening-balance menu link is hidden when `POSMAIN_INVENTORY_LEDGER_MODE=live`.
- Direct visits to `items_start_balance.php` redirect to the new inventory adjustment surface in live mode.
- `save_start_balance.php` rejects legacy opening-balance writes in live mode.
- `InventoryLedgerService` remains the only accepted owner of the `myitems.itmqty` compatibility mirror.
- Non-live legacy opening-balance compatibility refreshes go through `InventoryLegacyMirrorService`, not through direct page SQL.
- The detached `fat_details` insert/delete helpers and global `myitems.itmqty`
  reindex helper now return stable 410 retired-endpoint responses and no longer
  connect to the database, write stock, delete stock history, or trigger a
  global compatibility rebuild.
- Known unsafe legacy stock helpers remain explicit retirement blockers while
  they still expose stock write behavior. After this pass, the known standalone
  endpoint stock helpers return retired-endpoint responses; the clothes POS form
  is rerouted to the general invoice/POS handler instead of its specialized
  writer.
- Retired helper files remain only as compatibility responses for stale clients;
  supported screens now route to inventory adjustments, reconciliation, or the
  general POS/invoice handler.

## What Is Not Removed Yet

- Existing databases may still contain old `fat_details` stock triggers until migration signoff and guarded trigger retirement apply.
- `save_start_balance.php` still calls legacy refresh logic for non-live modes, but the direct mirror SQL is isolated inside the inventory compatibility service.
- Legacy invoice/order detail rows remain because invoices and receipts still depend on `fat_details`.
- Reconciliation remains available for admin diagnostics.

## Readiness Tool

Run:

`php tools/inventory_legacy_retirement_check.php --json`

The tool is read-only. It reports proven controls and pending retirement items. It should not return ready until legacy triggers and direct legacy stock writes have been intentionally removed or isolated.
It scans multi-line `myitems.itmqty` updates and reports retired helpers as
`legacy_stock_endpoint_retired:*` proven controls when their source contains
only the retired endpoint responder.
The JSON can still list `legacy_stock_endpoint_live_guarded:*` controls for any
future legacy file that is only live-blocked, while
`legacy_stock_endpoint_retired:*` means the source no longer exposes the stock
write behavior.

For the trigger-removal step itself, run:

`php tools/inventory_retire_legacy_triggers.php --dry-run --json`

That tool lists the current `fat_details` stock triggers and the `DROP TRIGGER IF EXISTS` SQL, but it blocks apply until inventory reconciliation is clean, or every remaining inventory difference is exactly accepted through `--acceptance-file` and `--allow-accepted-reconciliation`, inventory accounting reconciliation is clean or exactly accepted through `--accounting-acceptance-file` and `--allow-accepted-accounting-reconciliation`, and a readable database backup is provided.

The same dry-run output includes an `accounting_reconciliation` summary. Any missing journal, missing journal entry, or debit/credit mismatch adds the `inventory_accounting_reconciliation_not_ready` blocker so trigger retirement cannot proceed while the accounting pilot is not green.

Accepted accounting problems are deliberately separate from inventory quantity
acceptance. An accounting acceptance file must match the current accounting
problem row exactly and still needs the explicit accounting allow flag; otherwise
the gate adds `accepted_inventory_accounting_reconciliation_requires_explicit_allow_flag`.

For trigger retirement, `--store=0` means all stores for both inventory and
accounting reconciliation gates, because dropping the `fat_details` triggers is
database-wide. A positive `--store` is only a scoped rehearsal signal.

Accepted reconciliation is deliberately strict:

- the acceptance file must match item, tenant, branch, store, reason, and all compared quantities exactly;
- stale or unused acceptance entries block the gate;
- accepted differences still appear in the JSON output with `accepted_reconciliation = true`;
- the explicit allow flag is required so an acceptance file cannot accidentally unlock destructive trigger retirement.

Accepted accounting reconciliation follows the same rule: match the current
review key, status, movement type, source type, movement count, movement total,
and journal debit/credit totals exactly, and treat unused entries as blockers.

## Deletion Rule

Do not delete old stock code just because live reads are available. Delete only after:

- migration dry runs are clean or accepted;
- branch/store/item-category signoff exists;
- browser smoke tests pass;
- runtime preflight and proof suite are green;
- external orders and accounting pilot remain green.
