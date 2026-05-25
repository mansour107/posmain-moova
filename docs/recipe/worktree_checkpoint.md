# Recipe System Worktree Checkpoint

Generated: 2026-05-23

## Status

Recipe implementation started from branch `main` with existing dirty router/sync work in the checkout. This is a hard rollout risk from the recipe plan, so active recipe stock, accounting, availability, and order lifecycle changes must not be enabled until the router/sync work is committed, stashed, or moved to a clean implementation branch.

This checkpoint intentionally allows only low-risk planning artifacts, disabled no-op recipe scaffolding, and additive planned schema that is tested in disposable databases. It does not enable recipe runtime behavior.

active recipe behavior remains blocked in this dirty state.

## Existing Dirty Work

Tracked files already modified before recipe implementation:

- `.env.example`
- `ajax/sync_credentials.php`
- `api/moova/ack_branch_events.php`
- `api/moova/branch_events.php`
- `api/sync/ack_branch_events.php`
- `api/sync/branch_events.php`
- `api/sync/receive_branch_events.php`
- `classes/Moova/MoovaChangeOrderApplyService.php`
- `classes/Moova/MoovaNewOrderApplyService.php`
- `classes/Sync/BranchMoovaApplyWorker.php`
- `classes/Sync/SyncRuntimeSettings.php`
- `cli/branch_worker_daemon.php`
- `config/app_config.php`
- `do/save_customer.php`
- `do/search_customer.php`
- `do/update_customer.php`
- `get/iname.php`
- `includes/config.php`
- `includes/db_bootstrap.php`
- `includes/session_bootstrap.php`
- `index.php`
- `print/receipt.php`
- `setting.php`
- `tests/sync/local_sync_worker_supervisor_test.php`
- `tests/sync/login_security_integration_test.php`
- `tests/sync/moova_widget_bridge_contract_test.php`
- `tests/sync/phase4_cashier_table_transfer_ui_contract_test.php`
- `tests/sync/phase4_print_template_payload_contract_test.php`
- `tests/sync/pos_takeaway_invoice_handler_test.php`
- `tests/sync/sync_credentials_ui_contract_test.php`
- `tools/local_sync_worker_supervisor.php`

Untracked router files already present:

- `classes/Router/`
- `includes/sync_route.php`
- `tests/sync/shop_router_contract_test.php`
- `tests/sync/shop_router_endpoint_contract_test.php`
- `tests/sync/shop_router_runtime_integration_test.php`
- `tools/shop_router.php`

## Allowed In This Dirty State

- Recipe discovery documentation.
- Feature flags defaulting off.
- No-op service shells that write nothing when disabled.
- Recipe schema planned through `SyncSchemaManager` without applying it to the main runtime DB.
- Focused tests proving disabled behavior.

## Not Allowed In This Dirty State

- Recipe schema migrations applied to runtime DBs without an explicit backup/apply decision.
- Order lifecycle wiring in POS, table, Moova, Cofe, or sync replay paths.
- Stock reservations, stock consumption, accounting journals, or availability blocking.
- Customer-facing payload changes outside the dedicated cost-leak audit tranche.

## Required Before Active Recipe Work

Before Tranche 3 or any live integration begins:

1. Checkpoint or isolate the existing router/sync work.
2. Re-run baseline tests.
3. Confirm `tools/run_migrations.php --dry-run` is clean before adding recipe schema.
4. Work from a recipe branch or otherwise keep recipe changes separate from router/sync changes.
