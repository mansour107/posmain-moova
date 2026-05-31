# Phase 15 Cutover Contracts

Phase 15 lets operator read surfaces use the ledger as stock truth when `POSMAIN_INVENTORY_LEDGER_MODE=live`.

## Guardrails

- `off`, `shadow`, and `bridge` continue to read legacy quantities on legacy screens.
- `live` reads from `inventory_item_balances` and `inventory_movements` only when both tables exist.
- If the ledger tables are missing, read surfaces fall back to legacy behavior instead of crashing.
- `myitems.itmqty` remains a compatibility mirror; it is not treated as authoritative by cutover-ready pages.
- Writes still go through existing services; this phase does not add direct stock writes.

## Cutover Read Surfaces

- `myitems.php` decorates item rows through `InventoryStockReadService`; in live mode the displayed quantity comes from `inventory_item_balances`.
- The dashboard "آخر أصناف تم إنشاءها" widget also decorates recent item
  quantities through `InventoryStockReadService`, so live-mode operators do not
  see `myitems.itmqty` as the current stock balance.
- `api/items.php` decorates public item payload quantities through `InventoryStockReadService`; in live mode the `quantity` field comes from ledger balances and exposes `stock_quantity_source = ledger` without exposing internal cost fields.
- `item_summery.php` uses `inventory_movements` for item movement history in live mode; legacy `fat_details` history remains active outside live mode.
- `stagnant-items-report.php` filters, orders, and values stock by ledger balances in live mode.
- `inventory_reports.php` already reads the ledger reports service.

## Compatibility

The cutover is intentionally read-side first:

- no feature flag default is flipped in code;
- no legacy trigger is removed here;
- no old quantity column is dropped;
- old screens keep working until Phase 16 retirement proof is complete.

## Exit Checks

Run the combined read-only gate:

`php tools/inventory_cutover_readiness.php --json`

This command does not change feature flags, repair data, post journals, or drop
triggers. It combines the migration dry-run, inventory reconciliation,
accounting reconciliation, required hardening indexes, and legacy-trigger
retirement status into one payload. `ready_for_cutover` can pass before live mode
is flipped; non-live mode is reported as a warning so rehearsals can run safely.
`ready_for_legacy_retirement` is stricter and remains blocked while
`fat_details` stock triggers are still present or unsafe legacy stock endpoints
such as detached `fat_details` helpers, offline stock replay, and global
`myitems.itmqty` reindex helpers still exist.

Historical balance rebuild differences can be reviewed through
`--rebuild-acceptance-file` (for example,
`--rebuild-acceptance-file=/absolute/path/to/accepted-rebuild.json`), but the
file must match the current rebuild row exactly, including the row-state flags
`current_balance_exists`, `has_difference`, `has_cost_difference`, and
`has_last_movement_difference`, and still needs
`--allow-accepted-rebuild-differences`. Otherwise the gate adds
`accepted_balance_rebuild_differences_require_explicit_allow_flag`. This is for
signed valuation or last-movement review evidence only; it does not rebuild
balances or change moving-average costs.

Reviewed ambiguous `fat_details` decisions use the same JSON file shape as the
backfill tool and can be passed to the cutover gate with
`--decisions-file=/absolute/path/to/reviewed-decisions.json`. This lets the
read-only gate prove that the current migration scope has no unreviewed
ambiguous rows without applying backfill movements or guessing legacy
`pro_tybe=14` intent.

- reconciliation is clean or explicitly accepted;
- historical migration has no unreviewed ambiguous rows, unapplied safe
  candidates, rebuild quantity differences, or rebuild cost differences;
- runtime preflight is green;
- POS availability and recipe flows remain green;
- accounting pilot remains green where enabled;
- browser smoke tests cover the inventory pages through the in-app browser path.
