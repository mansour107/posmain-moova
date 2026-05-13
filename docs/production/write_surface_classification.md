# POSMAIN Phase 0 Write Surface Classification

Generated: 2026-05-12  
Source audit: `docs/production/write_surface_audit_latest.json`

## Classification Legend

- A: Active production path that must be protected and sync-aware.
- B: Legacy path to disable, guard, or redirect before production exposure.
- C: Admin/reporting/business maintenance path; not core cashier.
- D: Test/debug/setup/repair/log/backup path; never public in production.

## Pre-Change Checklist

Impacted surfaces:

- API contracts: JSON AJAX endpoints and legacy HTML form handlers.
- Shared utilities: `config/app_config.php`, `includes/connect.php`, `includes/db_bootstrap.php`, and the new production guard.
- Database access: Phase 0 does not change business mutations or schema. It documents write surfaces and blocks dangerous production exposure.
- State shape: unchanged for orders, payments, tables, journals, stock, Moova, and sync.
- UI flows: cashier POS, table screen, close-shift UI, and Moova widget.
- Auth/permissions: Phase 0 adds production denial for D-class surfaces but does not centralize auth/CSRF yet.
- Integrations: Moova widget/proxy and legacy offline/sync endpoints.

Compatibility risks:

- Existing cashier mutations must keep current behavior.
- Guard checks must happen before output and before unsafe DB connection attempts on D-class files.
- CLI-only tools must remain usable from CLI.
- Offline gating must not hide or change normal online cashier controls.

Test strategy:

- Syntax-check changed PHP files.
- Verify audit JSON is valid.
- Verify migration dry-run against Docker MariaDB.
- Verify local POS login endpoint still returns HTTP 200.
- Verify guarded D-class routes deny in production mode.

## Core Write Surface Classification

| File | Class | Input | Writes / owns | Txn | Auth/permission | CSRF | Idempotency | Sync/outbox | MAX()+1 risk | UI reachable | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `do/doadd_invoice.php` | A | POST form, optional GET `debug` | `ot_head`, `fat_details`, journals, `myitems`, `process` | Yes | Session user check | No central CSRF seen | No canonical idempotency | Records outbox when available | Yes, legacy counter fallback | Yes, POS form | Main cashier submit; debug output must be denied in production. |
| `do/doedit_invoice.php` | C | POST form | `ot_head`, `fat_details`, journals, `myitems`, `process` | Yes | Session user check | No central CSRF seen | No | Not clearly outbox-aware | Yes for journals | Admin/business edit | Not core cashier route in Phase 0. |
| `do/dodel_invoice.php` | C | POST form | Soft-delete invoice/details/journals | Yes | Session user check | No central CSRF seen | No | Not clearly outbox-aware | No | Admin/business delete | Needs later permission/audit hardening. |
| `do/dodel_pro.php` | B | GET `id`, POST `editpass` | Hard deletes `ot_head`, `fat_details`, journals, inserts `process` | No explicit txn seen | Edit password only | No | No | No | No | Legacy delete | Dangerous legacy delete flow; not canonical cashier behavior. |
| `do/settle_credit.php` | C | POST | Settlement `ot_head`, journals | Yes | Uses session user fallback | No central CSRF seen | No | No | Yes, journal `MAX` | Admin/business settlement | Not core cashier route. |
| `do/doadd_invoice_waiter.php` | B | POST waiter form | `ot_head`, `ot_details`, item quantity, tables | No explicit txn seen | Waiter session check | No central CSRF seen | No | No | Yes | Waiter flow if enabled | Legacy waiter route, not current canonical cashier path. |
| `do/doadd_close.php` | B | POST | `ot_head`, `closed_orders` | No | None visible in header | No | No | No | Shift increment from last row | Legacy close | Prints POST data; should not be public production path. |
| `close_shift.php` | A | POST from POS close-shift UI | `closed_orders` | No explicit txn seen | Session user check | No central CSRF seen | No | Not clearly outbox-aware | No | Yes | Active close-shift path from POS content. |
| `do_close_shift_z.php` | A | POST | `closed_orders` | No explicit txn seen | Session user check | No central CSRF seen | No | Not clearly outbox-aware | No | Yes, Z close | Active Z close path. |
| `ajax/save_order.php` | A | JSON POST | `ot_head`, `fat_details` | Yes | Session fallback user id | No central CSRF seen | No | Yes | Yes, `MAX(pro_id)+1` | Yes, table/POS | Active table save/add/update path. |
| `ajax/process_table_payment.php` | A | POST form/AJAX | `ot_head`, journals | Yes | Session fallback user id | No central CSRF seen | No | Yes | Yes, journal `MAX` | Yes, tables screen | Active table payment path. |
| `ajax/process_split_payment.php` | A | JSON POST | `ot_head`, `fat_details`, `order_payments` | Yes | Session fallback user id | No central CSRF seen | No | Yes | Yes, `MAX(pro_id)+1` | Yes, tables screen | Active split payment path. |
| `ajax/delete_order.php` | A | POST AJAX | order/table state via `TableOrderService` | Yes | Session fallback user id | No central CSRF seen | No | Yes | No | Yes, POS JS | Active cancel/delete path. |
| `ajax/clear_table.php` | A | POST AJAX | table/order state via `TableOrderService` | Yes | Session fallback user id | No central CSRF seen | No | Yes | No | Possibly active | Active-compatible clear table path. |
| `ajax/clear_table_normal.php` | A | POST AJAX | table/order state via `TableOrderService` | Yes | Session fallback user id | No central CSRF seen | No | Yes | No | Yes, tables screen | Active clear table path. |
| `ajax/update_table_status.php` | A | POST AJAX | table/order state via `TableOrderService` | Yes | Session fallback user id | No central CSRF seen | No | Yes | No | Yes, tables screen | Active table status path. |
| `ajax/cofe_create_order.php` | B | JSON POST | `ot_head`, `fat_details`, journals, tables, `process` | Yes | No POS session required | No | Partial `idempotencyKey` if column exists | Yes | Yes, `MAX` fallback | Integration path | Legacy/direct Cofe path; should converge with Moova/POS service later. |
| `ajax/moova_confirm_order.php` | A | JSON POST, device token | Delegates to `MoovaNewOrderApplyService` | Yes | Device token required | N/A for device API | Required idempotency key | Through service path | Service-dependent | Yes, Moova widget | Active Moova new-order apply. |
| `ajax/moova_change_order.php` | A | JSON POST, device token | Delegates to `MoovaChangeOrderApplyService` | Yes | Device token required | N/A for device API | Required idempotency key | Through service path | Service-dependent | Yes, Moova widget | Active Moova edit/cancel apply. |
| `classes/PosOrderService.php` | A | Service calls | POS/Moova order, journals, tables, process | Caller-owned/service-owned | Caller-owned | Caller-owned | Partial through caller | Yes in callers | Yes fallback counters remain | Indirect | Shared service, not web route. |
| `classes/TableOrderService.php` | A | Service calls | table/order/payment helpers | Caller-owned | Caller-owned | Caller-owned | No standalone | Callers record outbox | No | Indirect | Shared table service. |
| `pos_sync.php` | B | Public JSON API | offline POS order/menu sync | Yes for some ops | None visible | No | No | No | No | Legacy/offline prototype | Browser-only offline is not production-safe in Phase 0. |
| `do/offline_sync.php` | B | Public JSON API | offline order/customer sync | Yes for order sync | None visible | No | No | No | No | Legacy/offline prototype | Disable/guard before production. |
| `js/pos_offline_adapter.js` | B | Browser script | Client-side offline queue | N/A | N/A | N/A | N/A | N/A | N/A | Loaded by POS content | Must not be included in production unless flag enabled. |
| `pos_sw.js` | B | Service worker | Offline cache | N/A | N/A | N/A | N/A | N/A | N/A | Browser service worker | Must not be registered in production until real offline strategy. |
| `run_migrations.php` | D | GET `confirm=yes` | Applies migration SQL files | No central migration tracking | None visible | No | No | N/A | N/A | Public if reachable | Web migration runner; deny in production. |
| `run_payment_updates.php` | D | Web request | Applies update SQL | No | None visible | No | No | N/A | N/A | Public if reachable | Deny in production. |
| `run_table_updates.php` | D | Web request | Applies update SQL | No | None visible | No | No | N/A | N/A | Public if reachable | Deny in production. |
| `repair_database_jal.php` | D | Web/CLI request | Alters `ot_head` | No | None visible | No | No | N/A | N/A | Public if reachable | Hardcoded local DB credentials; deny in production. |
| `quick_fix.php` | D | Web request | Creates `system_logs`, filesystem writes | No | None visible | No | No | N/A | N/A | Public if reachable | Deny in production. |
| `setup_demo_data.php` | D | Web request | Demo menu/category/table data | No | None visible | No | No | N/A | N/A | Public if reachable | Deny in production. |
| `pre_start.php` | D | Web request | DB setup UI | N/A | None visible | No | No | N/A | N/A | Public if reachable | Deny in production. |
| `ajax/db_setup.php` | D | JSON POST/upload | Creates DB/imports SQL | No | None visible | No | No | N/A | N/A | Setup UI backend | Deny in production. |
| `logs_viewer.php` | D | Web request | Reads logs | N/A | UI-level unknown | No | No | N/A | N/A | Public if reachable | Deny unless later hardened admin-only. |
| `view_logs.php` | D | Web request | Reads/writes log test file | N/A | None visible | No | No | N/A | N/A | Public if reachable | Deny unless later hardened admin-only. |
| `do/debug_columns.php` | D | Web request | DB metadata output | N/A | None visible | No | No | N/A | N/A | Debug | Deny in production. |
| `do/debug_ot_head.php` | D | Web request | DB metadata output | N/A | None visible | No | No | N/A | N/A | Debug | Deny in production. |
| `do/debug_post.php` | D | Web request | Dumps session/POST | N/A | None visible | No | No | N/A | N/A | Debug | Deny in production. |
| `do/debug_schema.php` | D | Web request | DB metadata output | N/A | None visible | No | No | N/A | N/A | Debug | Deny in production. |
| `do/debug_schema_2.php` | D | Web request | DB metadata output | N/A | None visible | No | No | N/A | N/A | Debug | Deny in production. |
| `do/dbase/do_turncate.php` | D | POST | Table truncation/delete operations | Yes internally | Password gate | No | No | N/A | N/A | Dangerous maintenance | Deny in production. |
| `do/dobackup.php` | D | Web request | DB dump/backup | N/A | None visible | No | No | N/A | N/A | Backup | Move to CLI/private admin later; deny in production now. |

## Active Route Ownership

Canonical owners for Phase 0 documentation:

- Main POS order submit: `do/doadd_invoice.php`.
- Table save/add/update: `ajax/save_order.php` with `TableOrderService`.
- Table payment: `ajax/process_table_payment.php`.
- Split payment: `ajax/process_split_payment.php`.
- POS/tables cancel and clear: `ajax/delete_order.php`, `ajax/clear_table.php`, `ajax/clear_table_normal.php`, `ajax/update_table_status.php`.
- Shift close: `close_shift.php` and `do_close_shift_z.php`.
- Moova direct widget confirm/change: `ajax/moova_confirm_order.php` and `ajax/moova_change_order.php`.
- Legacy offline/sync prototype: `pos_sync.php`, `do/offline_sync.php`, `js/pos_offline_adapter.js`, `pos_sw.js`.
- D-class setup/debug/repair/log/backup routes: guard with `includes/production_guard.php` in production mode.

## Phase 0 Result Expectations

- D-class routes are denied when `POSMAIN_PRODUCTION_MODE=1`.
- Existing A-class business behavior is not refactored in Phase 0.
- Legacy offline adapter is not loaded in production unless `POSMAIN_ENABLE_LEGACY_OFFLINE_PROTOTYPE=1`.
- Remaining CSRF, permission, SQL, idempotency, and counter gaps are documented for Phase 1/2/3, not silently changed here.
