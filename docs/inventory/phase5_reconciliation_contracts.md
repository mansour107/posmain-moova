# Phase 5 Reconciliation Contracts

Generated: 2026-05-30

Scope: Phase 5 of `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`.

Phase 5 is a proof layer, not a cutover. It must stay read-only and compare old stock behavior against the new ledger before `bridge` or `live` mode is trusted.

## Implemented Proof Surfaces

- `RecipeReconciliationService` remains the shared calculation engine for UI and CLI reconciliation.
- `recipe_stock_reconciliation.php` continues to provide the operator/admin report and CSV export.
- `tools/inventory_reconciliation_check.php` provides a read-only CLI check:
  - `--tenant=`
  - `--branch=`
  - `--store=`
  - `--item=`
  - `--limit=`
  - `--acceptance-file=`
  - `--differences-only`
  - `--csv`
  - `--json`

## Compared Values

Each row compares:

- `myitems.itmqty`
- scoped `fat_details` quantity: `SUM(qty_in - qty_out)`
- scoped `inventory_movements` quantity: `SUM(qty_in - qty_out)`
- `inventory_item_balances.qty_on_hand`

When `--store` is omitted or `--store=0`, reconciliation aggregates all stores for the tenant/branch. Supplying a positive `--store` performs a single-store check. This avoids treating store `0` as a fake operational store when old rows were posted to another `det_store`.

## Difference Reasons

The service now exposes both `difference_reasons` and a compact `difference_reason` string. Current reason codes:

- `legacy_summary_mismatch`
- `missing_balance_row`
- `ledger_balance_mismatch`
- `missing_bridge_movement`
- `deleted_fat_detail_or_ledger_only`
- `movement_scope_or_quantity_mismatch`
- `non_stock_item_has_stock_movement`

These are intentionally diagnostic. The tool does not repair data or mutate stock.

The CLI can emit the same proof set as JSON for automation or CSV for daily review/export. CSV output includes the compared quantities, compact reason string, acceptance flag, recommendation, and last movement id.

## Accepted Differences

For cutover review, `tools/inventory_reconciliation_check.php` can take an
explicit acceptance JSON file. Accepted entries must match the current row
exactly by tenant, branch, store, item, difference reason, legacy quantity,
`fat_details` quantity, ledger quantity, and balance quantity. If the quantity
or reason changes, the old acceptance becomes unused and the live row remains
unaccepted.

Acceptance is an audit gate, not a data repair. It is only appropriate for
reviewed QA fixture rows, intentionally ledger-only pilot evidence, or signed
business decisions where the ledger is the desired final truth.

## Cutover Guardrail

Before moving to bridge/live behavior, reconciliation should show no unexplained
high-value differences for the target tenant, branch, store, and pilot item set.
Known differences must be categorized and either fixed or explicitly accepted
with exact current quantities instead of hidden.
