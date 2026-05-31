# Phase 12 Inventory Accounting Contracts

## Scope

Phase 12 uses the existing `journal_heads`, `journal_entries`, `document_counters`, and `inventory_movements.accounting_journal_id` fields. It does not add accounting tables.

## Configuration

Inventory accounting is controlled by:

- `POSMAIN_INVENTORY_ACCOUNTING=1`
- `POSMAIN_INVENTORY_ASSET_ACCOUNT_ID`
- `POSMAIN_INVENTORY_PURCHASE_CLEARING_ACCOUNT_ID`
- `POSMAIN_INVENTORY_COGS_ACCOUNT_ID`
- `POSMAIN_INVENTORY_WASTE_EXPENSE_ACCOUNT_ID`
- `POSMAIN_INVENTORY_ADJUSTMENT_GAIN_LOSS_ACCOUNT_ID`

Purchase receiving may use the supplier account from the purchase document instead of the purchase clearing account.

## Posting Contracts

- Purchase receipt: debit inventory asset, credit supplier or purchase clearing.
- Purchase return: debit supplier or purchase clearing, credit inventory asset.
- Sale direct COGS: debit COGS, credit inventory asset.
- Refund reversal: debit inventory asset, credit COGS.
- Waste: debit waste expense, credit inventory asset.
- Positive adjustment: debit inventory asset, credit adjustment gain/loss.
- Negative adjustment and count variance loss: debit adjustment gain/loss, credit inventory asset.
- Counts with both positive and negative variance split into separate direction journals.

Every successful posting:

- creates one `journal_heads` row;
- creates balanced `journal_entries`;
- links all posted movement rows through `inventory_movements.accounting_journal_id`;
- reuses the existing journal if the movement was already linked;
- fails closed when required accounts or decimal-safe journal columns are missing.

## Workflow Integration

- `InventoryPurchaseReceivingService` posts purchase receipt and purchase return journals when inventory accounting is enabled.
- `InventoryInvoiceBridge` posts sale-direct COGS and refund-reversal journals only in bridge/live ledger modes when inventory accounting is enabled. Shadow mode still writes stock evidence without financial journals.
- `InventoryAdjustmentService` posts waste and manual adjustment journals when inventory accounting is enabled.
- `InventoryCountService` posts count variance adjustment journals when inventory accounting is enabled.
- Existing recipe accounting remains in `RecipeAccountingService`.

## Accountant Review

`tools/inventory_accounting_reconciliation.php` is a read-only review tool that compares accounting-relevant inventory movements with linked journal debit/credit totals.

It flags:

- missing journal links;
- missing journal heads;
- missing journal entries;
- debit/credit mismatches.

If any flagged row exists, the review result is not ready: JSON output returns
`ok: false` with `status: problems_found`, and the CLI exits non-zero. The rows
remain in the payload so accounting can review the exact missing or mismatched
movement groups before cutover.

For signed historical exceptions, the tool can take
`--acceptance-file=/absolute/path/to/accepted-accounting.json`. Accepted entries
must match the current problem row exactly by review key, status, movement type,
source type, movement count, movement total, debit total, and credit total. If
the journal state changes, the old acceptance becomes unused and blocks the
review. Acceptance is audit evidence only; it does not create journals, update
movement links, or repair accounting data.

The trigger-retirement gate uses a separate
`--accounting-acceptance-file` and still requires
`--allow-accepted-accounting-reconciliation` before accepted accounting problems
can unlock a destructive trigger-removal run.

## Tests

- `tests/sync/inventory_phase12_accounting_service_test.php`
- `tests/sync/pos_accounting_inventory_service_test.php`
- `tests/sync/recipe_production_endpoint_runtime_test.php`
- `tests/sync/inventory_adjustment_endpoint_runtime_test.php`
