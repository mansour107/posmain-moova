# T002 Phase 0 Scout Map

## Impacted Surfaces

Phase 0 touches route ownership and production exposure, not deep POS mutation behavior. The impacted surfaces are:

- API contracts: active AJAX endpoints return JSON for table, payment, cancel, and Moova paths; debug/setup routes may return HTML or JSON but should be denied in production.
- Shared utilities: existing `config/app_config.php` and `includes/db_bootstrap.php`; new guard should reuse env-style configuration without forcing DB access.
- Database access: no schema writes are required for Phase 0, but baseline/migration tooling depends on DB reachability.
- State shape: unchanged for `ot_head`, `fat_details`, `order_payments`, `tables`, `closed_orders`, journals, Moova links, and sync tables.
- UI flows: cashier POS loads `includes/pos_content.php`; tables screen calls payment/clear/status AJAX; Moova widget calls confirm/change endpoints.
- Auth/permissions: not centralized yet; Phase 0 only blocks D-class routes in production mode.
- Integrations: Moova direct widget paths, local sync/offline prototype, and web-accessible migration/repair/backup surfaces.

## Active Route Evidence

- `pos_barcode.php` and `includes/pos_content.php` set the main cashier form target to `do/doadd_invoice.php`.
- `includes/pos_content.php` posts close-shift UI to `close_shift.php`.
- `tables.php` calls `ajax/process_table_payment.php`, `ajax/process_split_payment.php`, `ajax/clear_table_normal.php`, and `ajax/update_table_status.php`.
- `js/pos_barcode.js` calls `ajax/get_tables.php` and `ajax/delete_order.php`.
- `elements/pos/cofe_widget.php` calls `ajax/moova_confirm_order.php` and `ajax/moova_change_order.php`.
- `moova_pos_proxy.php` is the local bridge/proxy surface for the external/local Moova widget.

## Write Surface Evidence

`php tools/audit_write_paths.php --json` passes and detects broad write exposure:

- `pos_order`: 34 surfaces.
- `table_state`: 18 surfaces.
- `payments/accounting`: 28 surfaces.
- `shift_session`: 7 surfaces.
- `menu_catalog`: 28 surfaces.
- `moova_bridge`: 16 surfaces.
- `user_admin`: 9 surfaces.
- `inventory_stock`: 3 surfaces.
- `other_business_write`: 138 surfaces.

Key Phase 0 files all exist:

- Active POS/table/payment/Moova paths: `do/doadd_invoice.php`, `ajax/save_order.php`, `ajax/process_table_payment.php`, `ajax/process_split_payment.php`, `ajax/delete_order.php`, `ajax/clear_table.php`, `ajax/clear_table_normal.php`, `ajax/update_table_status.php`, `ajax/cofe_create_order.php`, `ajax/moova_confirm_order.php`, `ajax/moova_change_order.php`, `classes/PosOrderService.php`, `classes/TableOrderService.php`.
- Legacy/sync/offline paths: `pos_sync.php`, `do/offline_sync.php`, `js/pos_offline_adapter.js`, `pos_sw.js`.
- D-class public danger paths: `pre_start.php`, `setup_demo_data.php`, `quick_fix.php`, `repair_database_jal.php`, `run_migrations.php`, `run_payment_updates.php`, `run_table_updates.php`, `logs_viewer.php`, `view_logs.php`, `ajax/db_setup.php`, `do/debug_columns.php`, `do/debug_ot_head.php`, `do/debug_post.php`, `do/debug_schema.php`, `do/debug_schema_2.php`, `do/dbase/do_turncate.php`, `do/dobackup.php`.

## Compatibility Risks

- Guarding web routes must not break CLI use of `tools/run_migrations.php`; top-level web `run_migrations.php` is separate and should be guarded.
- Some D-class files emit Arabic HTML/JSON directly and connect to DB at top level; guard must be required before DB or output.
- `do/doadd_invoice.php` has a `?debug` branch before normal processing. Phase 0 should deny this debug output in production without changing invoice mutation logic.
- Offline adapter is always included by `includes/pos_content.php`, so production gating must keep non-production behavior and avoid changing save/payment code.
- Docker daemon is currently unavailable, and `php tools/run_migrations.php --dry-run` fails against default `127.0.0.1:3306`; baseline should document this unless Docker becomes available later.

## Focused Verification Commands

- `node /Users/ab.mansour1agmail.com/.codex/skills/goal-maker/scripts/check-goal-state.mjs docs/goals/posmain-phase-0-production-readiness/state.yaml`
- `php tools/audit_write_paths.php --json`
- `php tools/run_migrations.php --dry-run`
- `php -l includes/production_guard.php`
- `php -l do/doadd_invoice.php`
- `php -l includes/pos_content.php`
- `php -l pos_barcode.php`
- Start a local PHP server with production mode and verify D-class routes return 403 or 404.
- If Docker is available: `docker compose -f docker-compose.posmain-test.yml up -d`, then `curl -I http://127.0.0.1:8010/index.php`.
