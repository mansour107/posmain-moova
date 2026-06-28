# POSMAIN Local-First Write Surface Inventory

This inventory is a Phase 0 sync-planning artifact. It documents current PHP write surfaces only; it does not change POS runtime behavior.

Refresh it with:

```sh
php tools/audit_write_paths.php
php tools/audit_write_paths.php --json
```

## Scope

The audit script scans repository PHP files, excluding dependency/vendor trees, for SQL write statements: `INSERT`, `UPDATE`, `DELETE`, `REPLACE`, `ALTER TABLE`, `CREATE TABLE`, and `DROP TABLE`.

Every discovered write path is assigned at least one sync-planning category:

| Category | Meaning for sync planning |
| --- | --- |
| `pos_order` | Cashier/order documents, order headers, line details, and order lifecycle mutations. |
| `table_state` | Dine-in table occupancy/current-order state. |
| `payments/accounting` | Journals, account heads, vouchers, and payment/accounting side effects. |
| `shift_session` | Shift close/session boundaries and closed order tracking. |
| `menu_catalog` | Items, item groups, units, and catalog metadata used to price or resolve orders. |
| `moova_bridge` | Moova/Cofe integration links, idempotency, mappings, and bridge endpoints. |
| `user_admin` | Users, roles, and permissions. |
| `inventory_stock` | Stores, production, and stock/inventory operations. |
| `other_business_write` | Business writes that do not match a more specific Phase 0 category yet. |

## Current High-Risk Write Surfaces

| Path | Categories | Why it is high risk for local-first sync |
| --- | --- | --- |
| `do/doadd_invoice.php` | `pos_order`, `payments/accounting`, `menu_catalog` | Direct cashier save/pay/edit path. It writes `ot_head`, `fat_details`, journals, process logs, and item cost/last-price fields. Any future outbox/counter hook must preserve current validation, table-order behavior, and accounting order. |
| `classes/PosOrderService.php` | `pos_order`, `table_state`, `payments/accounting`, `menu_catalog`, `moova_bridge` | Shared service used by Moova table orders. It creates/merges/replaces/cancels order lines, refreshes totals/accounting, maps Moova lines, and marks tables busy. |
| `classes/Moova/MoovaNewOrderApplyService.php` | `pos_order`, `table_state`, `moova_bridge` | Shared Moova new-order apply service. It owns new-order idempotency/link persistence and delegates POS table-order mutation to `PosOrderService` for both direct widget and queued worker delivery. |
| `classes/Moova/MoovaChangeOrderApplyService.php` | `pos_order`, `table_state`, `moova_bridge` | Shared Moova edit/cancel apply service. It owns change-link persistence, stale-state decline checks, POS replace/cancel delegation, and direct/queued response metadata. |
| `ajax/cofe_create_order.php` | `pos_order`, `payments/accounting`, `menu_catalog`, `moova_bridge` | Legacy Cofe widget order-creation endpoint. Integration signature required in production when secret configured. |
| `ajax/save_order.php` | `pos_order`, `table_state` | Modern table save JSON endpoint with auth, CSRF, idempotency, and pricing. |
| `do/doadd_invoice_waiter.php` | `pos_order`, `table_state` | Waiter raw SQL writer; legacy retirement candidate. |
| `api/pos/index.php` | `pos_order`, `table_state` | Routed POS API entry for table save when router enabled. |
| `ajax/moova_confirm_order.php` | `pos_order`, `table_state`, `moova_bridge` | Moova confirm endpoint. It validates the device token/idempotency key and delegates new-order idempotency/link persistence plus order creation/merge to `MoovaNewOrderApplyService`. |
| `ajax/moova_change_order.php` | `pos_order`, `table_state`, `moova_bridge` | Moova change/cancel endpoint. It validates cashier confirmation and delegates change-link persistence, state checks, and POS replace/cancel apply to `MoovaChangeOrderApplyService`. |
| `close_shift.php` | `shift_session` | Shift close path that inserts closed shift/order session records. |
| `do_close_shift_z.php` | `shift_session` | Z close path that inserts closed shift/order session records and overlaps with shift-session boundaries. |
| `do/doadd_group.php` / `do/doedit_group.php` / `do/dodel_group.php` | `menu_catalog` | Menu category and group changes affect offline catalog resolution and item filtering. The visible category page is `item_categories.php`; the writes are in the `do/` handlers. |

## Compatibility Notes

This Phase 0 inventory deliberately avoids runtime implementation files. The audit script and PHPUnit coverage are read-only against POS code, so existing cashier, Moova, shift, menu, user, accounting, and inventory behavior is unchanged.

The sync plan should treat `do/doadd_invoice.php`, `classes/PosOrderService.php`, and the Moova endpoints as separate entry points into the same order/accounting state. Adding counters or outbox writes later should be done in small slices after this inventory remains green, because those paths have ordering dependencies around invoice numbers, journal rows, line deletion/reinsertion, table occupancy, idempotency, and external Moova responses.

## Inventory Agreement Rule

`tests/sync/write_surface_inventory_test.php` executes `tools/audit_write_paths.php --json` and asserts the known sync-critical cashier, Moova, shift, and menu paths are classified. If the audit rules change, update this document and the test together so Markdown inventory and machine-readable audit output continue to agree.
