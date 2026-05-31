# Phase 6 Purchase Bridge Contracts

Generated: 2026-05-30

Scope: Phase 6 Step A of `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`.

This increment keeps the existing invoice/purchase UI intact and proves the ledger purchase path before adding a new purchasing UI.

## Implemented Step A

- Legacy purchase invoices can flow through `InventoryInvoiceBridge` in `bridge` or `live` mode.
- Purchase rows create `purchase` movements.
- Purchase returns now create dedicated outbound `purchase_return` movements.
- Duplicate purchase receipt rows replay through the existing idempotency key and payload hash.
- Moving average cost is maintained in `inventory_item_balances`.
- When `legacy_mirror` is enabled in bridge/live mode, `myitems.itmqty` and `myitems.cost_price` mirror the ledger balance.
- For purchase movements only, `myitems.last_price` mirrors the received unit cost.
- Shadow mode still does not mutate legacy item fields.

## Implemented Step B Seed

- `InventoryPurchaseReceivingService` records direct purchase receipts into existing `inventory_purchase_receipts` and `inventory_purchase_receipt_lines`.
- The service writes `purchase` ledger movements through `InventoryLedgerService`.
- Purchase receipt replay is idempotent by `purchase_receipt_uuid`.
- Receipt UUID replay now compares the requested supplier/store/order context and normalized line payload against the stored receipt lines. A changed retry is rejected with `PURCHASE_RECEIPT_IDEMPOTENCY_CONFLICT` instead of silently replaying a different purchase.
- The service records purchase returns as outbound `purchase_return` movements, so supplier returns are not mixed into manual adjustment history.
- `ajax/inventory_purchase_receive.php` exposes guarded receive/return actions with `inventory.edit` permission and `inventory_receiving` CSRF.
- `inventory_purchasing.php` adds the first Arabic operator screen for `Inventory > Purchasing / Receiving`.
- `includes/sidebar.php` links the page from the existing purchases menu as `استلام المخزون`.

## Implemented Step C Purchase Order Lifecycle

- `InventoryPurchaseOrderService` uses the existing `inventory_purchase_orders` and `inventory_purchase_order_lines` tables.
- Purchase orders can be created as `draft`, submitted to `submitted`, then approved to `approved`.
- The service is idempotent by `purchase_order_uuid`; duplicate drafts replay instead of creating duplicate headers or lines.
- Purchase order UUID replay compares the requested supplier/store context and normalized line payload against the stored order lines. A changed retry is rejected with `PURCHASE_ORDER_IDEMPOTENCY_CONFLICT`.
- `ajax/inventory_purchase_order.php` exposes guarded draft and submit actions with the same `inventory.edit` permission and `inventory_receiving` CSRF boundary as receiving.
- Purchase order approval additionally requires `inventory.approve` or `accounting.view`; ordinary inventory editors can draft and submit POs but cannot approve them by crafting an `approve` request.
- The Arabic receiving page now includes lightweight controls to save a purchase order, send it for approval, approve submitted orders, and load approved/partially received PO lines into receipt rows.
- The Arabic receiving page shows purchase-order statuses in Arabic in operator dropdowns while keeping the stored workflow enums unchanged for services, APIs, and receipt validation.
- Receiving from a PO now requires `purchase_order_id` plus matching `purchase_order_line_id`, locks the order/lines, allows only `approved` or `partially_received` orders, and blocks over-receipt.
- Partial receipts update `inventory_purchase_order_lines.received_qty` and move the order to `partially_received`; full receipt moves it to `received`.
- Direct and PO receiving block duplicate supplier invoice numbers for the same tenant, branch, and supplier when `supplier_invoice_no` is provided. Purchase returns are not blocked by this rule so they can reference the original supplier invoice.
- `purchase_return` is included in the planned `inventory_movements.movement_type` enum, the additive schema upgrade path, ledger outbound validation, accounting posting, accounting reconciliation, and reviewed historical backfill decisions.
- Purchase receiving and purchase returns now accept an item unit from the existing `item_units` / `myunits` model. The workflow stores the operator-entered quantity and unit cost on the receipt line, resolves `item_units.u_val`, writes ledger quantities in base units, writes `unit_conversion_to_base`, and converts entered unit cost to base-unit cost for moving-average valuation.
- Receiving from a purchase order requires the receipt line unit to match the purchase-order line unit, preventing accidental mixed-unit partial receipts.
- The Arabic receiving page defaults direct receiving lines from existing stock-level preferences, choosing `preferred_purchase_unit_id` first and then `preferred_count_unit_id` for the selected destination store and item. Purchase order lines still keep their explicit PO unit.
- The Arabic receiving page includes barcode entry for direct receiving. Scanning a barcode or item id adds or increments a receipt line, applies the preferred purchase unit when present, and leaves posting to the existing guarded receive action.
- Direct receiving and purchase-order receipt lines now include an in-row item search by item name, barcode, or item id, with an Arabic match count. This keeps the low-bloat select-based implementation but removes the operator pain of scrolling a long item dropdown.
- The Arabic receiving page infers supplier/item defaults from existing supplier purchase history. When the selected supplier previously received the item, direct lines and barcode lines prefer that supplier's last received unit and unit cost before falling back to stock-level preferred purchase units and catalog costs. This is deliberately inferred from receipt history; no supplier catalog table or new supplier pricing table was added.
- The Arabic receiving page can preselect a supplier from the existing stock-level `default_supplier_account_id` for the selected store and item, but only while the supplier field is empty. It does not override a supplier chosen by the operator or loaded from a purchase order.
- The receiving page detects whether `inventory_item_stock_levels.default_supplier_account_id` exists. Older schemas still load and keep preferred purchase-unit behavior; supplier defaulting simply stays disabled until the optional column is migrated.

## Not Implemented Yet

- Explicit supplier item catalogs, supplier SKU/barcode aliases, and contracted supplier-specific pack pricing remain intentionally not implemented. The current low-bloat path covers practical defaults through `inventory_item_stock_levels.default_supplier_account_id` and receipt history; a catalog table should only be added after real supplier-specific SKUs or contracted pack prices are required.

Those belong to later Phase 6 increments after the bridge proof remains green.
