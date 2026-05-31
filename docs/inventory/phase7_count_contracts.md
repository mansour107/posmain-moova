# Phase 7 Inventory Count Contracts

Generated: 2026-05-30

Scope: Phase 7 of `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`.

## Implemented

- `InventoryCountService` uses the existing `inventory_counts` and `inventory_count_lines` tables.
- A count starts as `draft`, can be submitted, approved, then closed.
- Count approval requires `inventory.approve` or `accounting.view`; ordinary `inventory.edit` users can create, save, and submit counts but cannot approve them by crafting an approval request.
- Count UUID replay is only accepted when the retry matches the original tenant, branch, store, count type, guarded options, and manual line identity. Changed retries fail with `COUNT_IDEMPOTENCY_CONFLICT` instead of silently reusing the old draft.
- Count lines snapshot `inventory_item_balances.qty_on_hand` and `last_movement_id` at creation.
- Counted quantities are saved only while the count is `draft`.
- selected count units are validated against `item_units`; selected-unit count lines store snapshot, counted, and variance quantities in the operator-selected unit.
- Count lines freeze `unit_conversion_to_base` when the line is opened so later edits to `item_units.u_val` cannot change the close variance.
- Full and category counts can auto-fill lines server-side from stock-tracked `myitems` rows, so the browser does not need to submit a large item list.
- Count opening can be limited to low-stock items using existing `inventory_item_stock_levels` plus scoped balances.
- Count list store labels fall back to a generic unnamed-store label when the stock-account join is incomplete instead of exposing raw store ids.
- Closing an approved count writes `adjustment` movements through `InventoryLedgerService`.
- Count close movements post base-unit variance quantities using `unit_conversion_to_base`, while metadata keeps the selected-unit snapshot/count/variance and the derived base variance.
- Positive variance writes inbound adjustment; negative variance writes outbound adjustment; zero variance writes no stock movement.
- Close is idempotent after the count reaches `closed`.
- Closed count corrections are reversal-only: `InventoryCountService::reverseClosed()` writes inverse `adjustment` movements under the existing `inventory_count` source type, marks reversal rows with `inventory-count-reversal` idempotency/metadata, preserves the original count lines/movements, then moves the count to `cancelled`.
- `ajax/inventory_count_reverse.php` requires the same count CSRF boundary plus manager/accounting approval context, so ordinary `inventory.edit` users cannot reverse an already closed count.
- If stock changed after the snapshot, close raises `COUNT_STALE_SNAPSHOT` unless the caller explicitly passes a stale-close override.
- The stale-close override is accepted by the endpoint only for manager/accounting approval context; ordinary `inventory.edit` users cannot unlock it by crafting `allow_stale_close`.
- Count endpoints are POST-only, CSRF-protected by `inventory_count`, and require `inventory.edit`.
- `inventory_counts.php` and `inventory_count_detail.php` provide Arabic operator screens for opening a count and reviewing/closing variances.
- Count list/detail pages render Arabic status and count-type labels such as `مسودة`, `بانتظار الاعتماد`, `معتمد`, `مغلق`, `ملغي`, `أصناف محددة`, and `جرد كامل` while keeping the stored workflow enum values unchanged for service logic and idempotency checks.
- `inventory_counts.php` includes a unit selector when adding selected items, and `inventory_count_detail.php` displays each line's selected unit/conversion.
- `inventory_counts.php` includes Arabic controls for selected, spot, category, full, and low-stock-only count scopes.
- The count-opening page includes item search by name/barcode and a selected-item counter so operators can build selected-item counts without scanning a long raw item list.
- Category selectors fall back to generic unnamed-category labels when a name is missing instead of exposing raw category ids in the normal count-opening workflow.
- Count detail headers and line rows fall back to generic unnamed-store and unnamed-item labels when joins are incomplete instead of exposing raw store/item ids in the normal count-review workflow.
- `inventory_count_detail.php` includes a barcode count-entry mode. In draft counts, scanning a barcode or item id increments the existing counted quantity by the operator-entered scan quantity, highlights the matched line, and keeps saving through the existing count-save endpoint.
- Barcode count entry is UI-only; it does not close counts or write ledger movements. Ledger adjustments still occur only through the existing approved close workflow.
- In-app browser smoke verified the bridge-mode count list and count-detail missing state render in RTL Arabic, have no current-page console/PHP errors, and avoid horizontal viewport overflow.
- In-app browser smoke verified the full create/save/submit/approve/close count workflow on 2026-05-30 using the Arabic UI in bridge mode: a selected-item count was opened, counted quantity was entered through the count-entry controls, the draft was saved, submitted, approved, and closed. Final state was `closed`, counted quantity was `8.000000`, variance was `0.000`, the close action did not show the bridge-mode warning, and the page had no PHP warnings/fatals or horizontal overflow. A local read-only verification confirmed zero `inventory_movements` rows for that count line because the variance was zero.
