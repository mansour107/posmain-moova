# Phase 8 Inventory Transfer Contracts

Generated: 2026-05-30

Scope: Phase 8 of `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`.

## Implemented

- `InventoryTransferService` uses the existing `inventory_transfers` and `inventory_transfer_lines` tables.
- A transfer can be created as `draft`, submitted, sent, partially received, then received.
- Source and destination stores must both exist in the request. The same store id is blocked inside the same branch, but allowed for a cross-branch transfer because store ids can overlap between branches.
- Sending a transfer writes `transfer_out` movements through `InventoryLedgerService` from the source store.
- Receiving a transfer writes `transfer_in` movements through `InventoryLedgerService` into the destination store.
- Cross-branch destination branch selection is supported through `destination_pos_branch` and `destination_branch_uuid` on `inventory_transfers`; existing transfers without those columns/values fall back to the source branch to preserve same-branch behavior.
- On older schemas that do not yet have `destination_pos_branch` and `destination_branch_uuid`, same-branch transfers can still be created and duplicate `transfer_uuid` replay still works; cross-branch transfer creation returns `TRANSFER_DESTINATION_BRANCH_SCHEMA_MISSING` until the migration is applied.
- Send and cancel movements stay scoped to the source branch, while receive movements and transfer-variance reason validation use the destination branch scope.
- Transfer-in cost carries the unit cost captured from the source balance during send.
- Transfer lines preserve the operator-entered selected unit and quantity, while ledger movements post base quantities using `item_units.u_val` as `unit_conversion_to_base`.
- selected transfer units are validated against `item_units`; unknown units fail before a draft is saved, and invalid/zero conversion values are rejected.
- Transfer movement metadata keeps the entered quantity and receive target so support can reconcile selected-unit UI values against base-unit ledger quantities.
- Duplicate transfer creation replays by `transfer_uuid` only when the retry matches the stored source branch/store, destination branch/store, and normalized transfer lines. Changed retries fail with `TRANSFER_IDEMPOTENCY_CONFLICT`.
- Duplicate send replays once the transfer is already `sent`.
- Duplicate receive is safe when the UI sends total received quantity per line, because the service posts only the delta between stored received quantity and the submitted target.
- Explicit variance-close workflow is supported for `sent` or `partially_received` transfers with missing quantity. It requires a reason code or free-text reason, records `variance_qty`, `reason_code_id`, line/header notes, stamps `closed_at`, and moves the transfer to `variance_closed` without adding the missing quantity to the destination.
- Variance close approval is gated by `inventory.approve` or `accounting.view`; ordinary `inventory.edit` users can create and process transfers but cannot approve variance closure by crafting a request.
- Transfer variance reasons reuse the existing `inventory_reason_codes` table with `transfer_variance` or `manual` groups; no separate reason table was added.
- Draft and submitted transfers can be cancelled without stock movement. Sent-but-not-received transfers can be cancelled by writing inverse `transfer_in` movements back to the source store using the original `transfer_out` movement quantities/costs, then stamping `cancelled_at`.
- Partially received, fully received, and variance-closed transfers are not cancelled through the simple cancellation path; those require variance close or a separate return/reversal workflow so stock is not double-moved.
- Over-receipt is blocked.
- Non-stock/service items are blocked.
- Transfer endpoints are POST-only, CSRF-protected by `inventory_transfer`, and require `inventory.edit`.
- `inventory_transfers.php` and `inventory_transfer_detail.php` provide Arabic operator screens for creating, sending, and receiving stock transfers.
- Transfer list/detail store, branch, and item labels fall back to generic unnamed labels when joins are incomplete instead of exposing blank cells or raw store/item/branch ids in the normal operator workflow.
- Transfer list/detail pages render Arabic status labels such as `مسودة`, `مرسل من المصدر`, `استلام جزئي`, `تم الاستلام`, and `مغلق بفرق` while keeping the stored workflow enum values unchanged for service logic and idempotency checks.
- `inventory_transfers.php` exposes a destination branch selector sourced from existing `cloud_branches` plus the configured current branch. No supplier catalog or branch-routing subsystem was added.
- Transfer list/detail UI shows the destination branch beside the destination store so operators can distinguish same-store-id cross-branch transfers.
- `inventory_transfers.php` exposes a unit selector beside each item, and `inventory_transfer_detail.php` displays the unit/conversion used by each line.
- The transfer creation page includes per-line item search by name/barcode and a selected-line counter, so storekeepers can build transfers without scrolling through a long item select.
- Default transfer unit selection uses stock-level preferred units from `inventory_item_stock_levels`, preferring `preferred_count_unit_id` then `preferred_purchase_unit_id` for the selected source store. This only changes the visible selected unit; the service still validates the submitted unit and quantity.
- `inventory_transfer_detail.php` includes a barcode receive-entry mode. In sent or partially received transfers, scanning a barcode or item id increments the visible total received quantity by the operator-entered scan quantity and blocks UI over-receipt before posting.
- Barcode receive entry is UI-only; stock still moves only when the operator presses receive and `InventoryTransferService` validates the submitted total received quantities.
- The create-transfer UI blocks same-store source/destination selection before posting only when the destination branch is the current source branch, while the service and endpoint still enforce the same rule server-side.
- In-app browser smoke verified the transfer list renders in RTL Arabic with the unit selector column/control, has no current-page console/PHP errors, and avoids horizontal viewport overflow.
- In-app browser smoke verified the full create/submit/send/receive transfer workflow on 2026-05-30 using the Arabic UI in bridge mode: a selected-item transfer was opened from store `27` to store `274`, submitted for review, sent from the source, received in the destination through the barcode receive-entry controls, and ended in status `received`. Final requested/sent/received totals were `1.000 / 1.000 / 1.000`, variance was `0.000`, the bridge-mode warning did not appear, and the page had no PHP warnings/fatals or horizontal overflow. A local read-only verification confirmed one `transfer_out` movement from store `27` and one `transfer_in` movement into store `274` for the transfer line.

## Not Implemented Yet

- No Phase 8 transfer blocker remains in this contract. Future multi-tenant transfers, freight/logistics steps, or inter-company accounting should be planned separately if the business actually needs them.
