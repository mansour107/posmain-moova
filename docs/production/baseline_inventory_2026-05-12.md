# POSMAIN Phase 0 Baseline Inventory

Generated: 2026-05-12 14:20 EEST  
Goal board: `docs/goals/posmain-phase-0-production-readiness/goal.md`

## Branch And Git State

- Branch: `codex/posmain-phase-0-production-readiness`
- HEAD: `68eca7dfcb360a7da1d17ce851e949e826914a12`
- Pre-Phase-0 tracked diff snapshot: `/tmp/posmain-current-diff-before-production-plan.patch`
- Pre-Phase-0 tracked diff stat: `/tmp/posmain-current-diff-before-production-plan.stat`
- Important: the worktree was already dirty before Phase 0. Phase 0 preserves those changes and does not revert them.

Tracked modified summary before Phase 0 implementation:

```text
38 files changed, 2070 insertions(+), 959 deletions(-)
```

Current dirty-state themes visible before Phase 0:

- POS/table/payment endpoints and UI: `do/doadd_invoice.php`, `ajax/save_order.php`, `ajax/process_table_payment.php`, `ajax/process_split_payment.php`, `ajax/delete_order.php`, `ajax/clear_table.php`, `ajax/clear_table_normal.php`, `ajax/update_table_status.php`, `includes/pos_content.php`, `js/pos_barcode.js`, `pos_barcode.php`, `tables.php`.
- Moova bridge: `ajax/moova_confirm_order.php`, `ajax/moova_change_order.php`, `ajax/cofe_create_order.php`, `moova_pos_proxy.php`, `elements/pos/cofe_widget.php`, `assets/moova-pos-widget/pos-widget.js`.
- Sync and branch/cloud files are untracked under `api/`, `classes/Sync/`, `cli/`, `tests/sync/`, and `tools/`.
- New config bootstrap files are untracked: `config/app_config.php`, `includes/db_bootstrap.php`.
- Existing Goal Maker work exists under `docs/goals/posmain-online-offline-sync/`; this Phase 0 work uses a separate goal root.

Counts at baseline:

- Tracked files: 5663
- Untracked files not ignored: 124
- PHP files in repo scan, excluding vendor-like folders only by command shape: 888

## Runtime Versions

- Local CLI PHP: `PHP 8.5.5 (cli) (built: Apr  7 2026 16:24:10) (NTS)`
- Docker POS PHP endpoint: `X-Powered-By: PHP/8.2.30`
- Docker MariaDB: `10.11.16-MariaDB-ubu2204`

## Local Runtime And DB Reachability

The repo test stack is defined in `docker-compose.posmain-test.yml`.

Observed compose shape:

- PHP app exposed through the MariaDB service namespace on `127.0.0.1:8010`.
- MariaDB exposed on `127.0.0.1:3307`.
- Containers: `posmain-mysql`, `posmain-php`.
- Database name: `kody2`.

Initial state:

- `php tools/run_migrations.php --dry-run` failed against the default `127.0.0.1:3306` with `Connection refused`.
- `docker compose -f docker-compose.posmain-test.yml up -d` hit a container name conflict because existing `posmain-mysql` and `posmain-php` containers already existed.

Recovery used:

```bash
docker start posmain-mysql posmain-php
POSMAIN_DB_PORT=3307 php tools/run_migrations.php --dry-run
curl -I --max-time 5 http://127.0.0.1:8010/index.php
```

Verified result:

- `posmain-mysql`: running, port `127.0.0.1:3307->3306`.
- `posmain-php`: running.
- Migration dry-run with `POSMAIN_DB_PORT=3307`: `Dry run: 0 pending sync schema change(s).`
- `http://127.0.0.1:8010/index.php`: HTTP 200.
- MariaDB database `kody2` is reachable and includes core tables such as `acc_head`, `closed_orders`, `cloud_orders`, `document_counters`, and many legacy business tables.

## DB Backups And Seed Files

Existing SQL/backup files observed before any Phase 0 migration action:

- `db/DB.sql`
- `db/moova_pos_integration.sql`
- `dbase/musk-2024-06-14.sql`
- `backup/kody2-before-table-order-e2e-20260509-210244.sql`
- Historical backup files under `backup/backup_*.sql`

No Phase 0 schema migration was applied.

## Active POS/Table/Payment/Moova/Shift Endpoints

Core cashier and table endpoints:

- `pos_barcode.php`
- `includes/pos_content.php`
- `do/doadd_invoice.php`
- `ajax/save_order.php`
- `ajax/process_table_payment.php`
- `ajax/process_split_payment.php`
- `ajax/delete_order.php`
- `ajax/clear_table.php`
- `ajax/clear_table_normal.php`
- `ajax/update_table_status.php`
- `tables.php`
- `pos_tables.php`
- `close_shift.php`
- `do_close_shift_z.php`

Moova and external order bridge:

- `elements/pos/cofe_widget.php`
- `moova_pos_proxy.php`
- `ajax/moova_confirm_order.php`
- `ajax/moova_change_order.php`
- `ajax/cofe_create_order.php`
- `classes/Moova/*`
- `classes/MoovaPosIntegration.php`

Legacy/offline/sync surfaces:

- `pos_sync.php`
- `do/offline_sync.php`
- `js/pos_offline_adapter.js`
- `pos_sw.js`

## Write Surface Audit

Latest generated audit:

- JSON: `docs/production/write_surface_audit_latest.json`
- Validation command: `php -r '$j=json_decode(file_get_contents("docs/production/write_surface_audit_latest.json"), true); if (!is_array($j)) exit(1); echo count($j["surfaces"]);'`
- Surface count: 245

Summary:

| Category | Count |
|---|---:|
| pos_order | 34 |
| table_state | 18 |
| payments/accounting | 28 |
| shift_session | 7 |
| menu_catalog | 28 |
| moova_bridge | 16 |
| user_admin | 9 |
| inventory_stock | 3 |
| other_business_write | 138 |

## Phase 0 Compatibility Risks

- Guarding D-class files must run before DB connection/output in those files.
- `do/doadd_invoice.php?debug=1` currently prints raw POST data before authentication checks; Phase 0 must deny this in production without changing normal invoice logic.
- The offline adapter is currently included from `includes/pos_content.php` unconditionally; production gating must not change normal online save/payment behavior.
- Some web migration/repair scripts have hardcoded local DB credentials and verbose output; Phase 0 should block web access in production rather than rewrite their logic.
- The Docker stack uses port `3307` for MariaDB, while default app config uses `3306`; verification commands need explicit `POSMAIN_DB_PORT=3307`.

## Baseline Verification

Commands already run:

```bash
php tools/audit_write_paths.php --json > docs/production/write_surface_audit_latest.json
php -r '$j=json_decode(file_get_contents("docs/production/write_surface_audit_latest.json"), true); if (!is_array($j)) { exit(1); } echo "surfaces=".count($j["surfaces"])."\n"; foreach ($j["summary"] as $k=>$v) echo "$k=$v\n";'
POSMAIN_DB_PORT=3307 php tools/run_migrations.php --dry-run
curl -I --max-time 5 http://127.0.0.1:8010/index.php
docker exec posmain-mysql mysql -uroot -e 'SELECT VERSION() AS version; SHOW DATABASES;'
```

Status: baseline established and DB/runtime verified through the Docker test stack.
