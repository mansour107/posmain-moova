# Phase 4 Shadow Bridge Contracts

Generated: 2026-05-29

Scope: Phase 4 of `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`.

This phase starts with a safe bridge capability before endpoint wiring. The old `fat_details` / `myitems.itmqty` behavior remains the operational source for runtime screens until reconciliation proves the new ledger.

## Implemented Shadow Capability

- `InventoryLedgerService::recordShadowMovement()` can write `inventory_movements` and `inventory_item_balances` when `POSMAIN_INVENTORY_LEDGER_MODE=shadow`.
- Shadow writes reuse the same idempotency, payload hash, balance locking, audit, item policy, and decimal math as bridge/live writes.
- Shadow writes do not update the legacy `myitems.itmqty` mirror, even if the legacy mirror flag is enabled.
- Shadow writes do not enforce strict-stock blocking, so old sales/purchases can continue while the ledger captures negative evidence for reconciliation.
- Normal `recordMovement()` remains unchanged: it still writes only in `bridge` or `live`.

## Invoice Bridge Capability

`InventoryInvoiceBridge` maps legacy invoice/fat_detail rows into ledger requests:

- purchase invoice inbound rows -> `purchase`
- sales/POS outbound rows -> `sale_direct`
- sales return inbound rows -> `refund_reversal`
- purchase return outbound rows -> `purchase_return`
- purchase orders, sales orders, and offers -> no stock movement
- service/non-stock items -> no ledger movement row
- delete/edit reversals keep purchase reversals as `purchase_return`, purchase-return reversals as `purchase`, sales reversals as `refund_reversal`, and sales-return reversals as outbound `adjustment`

Each bridged line gets a deterministic idempotency key scoped by tenant, branch, store, invoice type, invoice id, and fat_detail/detail id.

## Runtime Wiring Status

Wired narrowly into `do/doadd_invoice.php` for newly inserted invoice lines only:

- skipped for edit/replacement flows until reversal handling is added;
- skipped for split-payment creation until split ownership is bridged deliberately;
- shadow bridge errors are logged and do not block the legacy invoice transaction;
- line writes use savepoints so a failed shadow movement does not leave partial ledger rows.

Wired into `do/dodel_invoice.php` for delete reversals only when the original invoice line already has a matching shadow movement:

- old invoices without original shadow movements are skipped;
- reversal rows use separate idempotency keys;
- delete bridge errors are logged and do not block legacy invoice deletion;
- reversal writes use the same savepoint guard as add-line writes.

Wired into `do/dodel_pro.php` for the legacy hard-delete operation path:

- active detail rows are loaded before `fat_details` hard deletion;
- reversals are written only when the original shadow movement exists;
- bridge errors are logged and do not block the old delete behavior;
- the broader legacy hard-delete behavior is intentionally left unchanged for Phase 4 and should be retired in a later cutover phase.

Wired into `do/doedit_invoice.php` for replacement-style edits:

- active old detail rows are read before legacy deletion;
- old rows are reversed only when their original shadow movement exists;
- new replacement detail rows are shadow-added after insert;
- edit bridge errors are logged and do not block the legacy edit transaction;
- purchase/sales order constants are defined so edit quantity classification can skip non-stock documents consistently.

Wired into `ajax/cofe_create_order.php` for Cofe-created POS orders:

- detail rows are shadow-added after legacy `fat_details` insert;
- provider line IDs are preserved as `order_line_uuid` when present;
- bridge errors are logged with a `[Cofe]` prefix and do not block order creation;
- existing Cofe idempotency replay still exits before duplicate stock rows are created.

Wired into `classes/Pos/Service/PosOrderMutationService.php` for the guarded POS mutation surface:

- takeaway order detail inserts are shadow-added after legacy `fat_details` insert;
- table order creates are shadow-added after inserted detail rows;
- table order updates reverse old lines only when the original shadow movement exists, then shadow-add replacement lines;
- bridge errors are logged and do not block the legacy mutation transaction;
- split-payment ownership is deliberately not bridged as a new sale, because it redistributes already-created table `fat_details` rows; this avoids double `sale_direct` movements when an original table order has already been shadow-recorded.

Wired into `classes/PosOrderService.php` for Moova table-order apply/change paths:

- new/merged Moova provider lines shadow-add direct-sale rows using `moova_pos_order_lines:{mapping_id}` as line identity;
- multiple Moova provider lines that share one legacy `fat_details` row still create separate shadow movements;
- replace/cancel reverses only the mapped provider lines whose original shadow movement exists;
- replay remains idempotent through the Moova apply/change idempotency layer and the inventory bridge payload hash.

Aligned in `save_start_balance.php` without adding a duplicate bridge writer:

- the old page still writes the legacy type-14 `fat_details` opening row and refreshes the `myitems` summary;
- when recipe inventory tables exist, it writes or updates the existing scoped `opening_balance` row and upserts `inventory_item_balances`;
- on migrated schemas, the opening-balance movement now stores `payload_hash` and `metadata_json` for reconciliation evidence;
- compatibility guards keep older recipe-ledger schemas working if the metadata columns have not been added yet.

Split-payment child cancel/refund ownership still needs a later explicit lifecycle decision, but the split creation step is covered as a no-duplicate stock redistribution.

The next increment should wire one remaining surface at a time and add reversal/idempotency coverage before enabling edit/delete paths.
