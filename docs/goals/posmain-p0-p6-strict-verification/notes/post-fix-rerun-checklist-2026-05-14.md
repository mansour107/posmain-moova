# Post-Fix Rerun Checklist - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T11:21:07Z

This checklist defines the minimum evidence needed to change the current strict verdict from `blocked` to `pass` after an approved fix tranche. It is documentation only; no implementation files were edited.

Current-status note: current `kody2` migration readiness is already green as of `fresh-completion-audit-2026-05-14.md`; keep Gate 4 as a non-mutating dry-run guard unless new drift appears.

## Entry Conditions

Before using this checklist:

- The user must explicitly approve switching from audit-only work to a bounded fix tranche.
- Any current-DB migration must have a readable backup file and an explicit target database.
- Persistent Moova runtime changes must preserve the local service restoration path.
- Dirty worktree boundaries must be checked before editing files already modified by earlier phase work.

## Gate 1 - Board And Runtime Preflight

Commands:

```bash
node /Users/ab.mansour1agmail.com/.codex/skills/goal-maker/scripts/check-goal-state.mjs docs/goals/posmain-p0-p6-strict-verification/state.yaml
curl -sS -o /dev/null -w 'pos_http=%{http_code}\n' http://127.0.0.1:8010/index.php
curl -sS http://127.0.0.1:3001/readyz
docker ps --format '{{.Names}} {{.Ports}}'
```

Pass criteria:

- Board checker has no errors.
- POS HTTP returns `200`.
- Moova `/readyz` returns `{"ok":true,"database":true,"redis":true}` before live Moova E2E.
- Expected containers/services are running; no disposable verification containers are left behind.

## Gate 2 - Script Syntax And Static Sweeps

Commands:

```bash
node --check js/pos_auto_lock.js
find . -path ./.git -prune -o -path './.playwright-mcp' -prune -o -path './backup' -prune -o -name '*.php' -print0 | xargs -0 -n 1 php -l
php tools/audit_write_paths.php --json
```

Pass criteria:

- `js/pos_auto_lock.js` parses cleanly.
- PHP syntax sweep has no syntax errors.
- Write-surface audit completes and still classifies Moova bridge writes correctly.

## Gate 3 - Focused Reproduced-Failure Tests

Commands:

```bash
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/moova_widget_bridge_contract_test.php
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/write_surface_inventory_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/table_order_counter_smoke_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/uuid_backfill_smoke_test.php
```

Pass criteria:

- Moova widget contract is green under the final chosen facade/endpoint contract.
- Write-surface inventory is green.
- Table-order counter and UUID backfill smoke tests are green against minimal fixture schemas.

## Gate 4 - Migration Readiness

Non-mutating check:

```bash
POSMAIN_DB_HOST=127.0.0.1 POSMAIN_DB_PORT=3307 POSMAIN_DB_USER=root POSMAIN_DB_PASS='' POSMAIN_DB_NAME=kody2 php tools/run_migrations.php --dry-run
```

If a future dry-run reports new pending changes and the user approves current-DB mutation:

```bash
php tools/backup_database.php --output=/absolute/path/to/posmain-kody2-before-migrations.sql
POSMAIN_DB_HOST=127.0.0.1 POSMAIN_DB_PORT=3307 POSMAIN_DB_USER=root POSMAIN_DB_PASS='' POSMAIN_DB_NAME=kody2 php tools/run_migrations.php --apply --backup-file=/absolute/path/to/posmain-kody2-before-migrations.sql
POSMAIN_DB_HOST=127.0.0.1 POSMAIN_DB_PORT=3307 POSMAIN_DB_USER=root POSMAIN_DB_PASS='' POSMAIN_DB_NAME=kody2 php tools/run_migrations.php --dry-run
```

Pass criteria:

- Dry-run reports `0 pending sync schema change(s)`.
- The backup file exists and is non-empty if `--apply` was used.
- No destructive statements are applied without explicit approval and backup.

## Gate 5 - Broader Script Regression

Commands:

```bash
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never --testdox tests
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/pos_takeaway_order_service_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/phase5_order_fulfillment_service_test.php
php tools/moova_reachability_smoke.php --self-test
php tools/moova_local_topology_check.php
```

Pass criteria:

- Default PHPUnit remains green except documented intentional skips.
- Takeaway order service still proves receipt voucher behavior and no duplicate POS order replay.
- Phase 5 fulfillment test is green.
- Moova mock reachability and local topology are green.

## Gate 6 - GUI Browser Regression

Run against normal POS on `http://127.0.0.1:8010` unless a disposable clone is explicitly required for write actions.

Required browser scenarios:

- Login as a test user and reach `dashboard.php`.
- Open/unlock `pos_barcode.php`.
- Add item, change quantity, and verify totals.
- Open/cancel payment modal without side effects.
- Verify POS auto-lock/unlock still works after reload or focus transition if `pos_auto_lock.js` was changed.
- Open `pos_tables.php`, table view, item pages, Moova integration page, and POS-side widget.
- Run save order, payment/receipt, table save, and shift close either on an approved disposable DB clone or on current `kody2` only with explicit owner approval.

Pass criteria:

- No SQL/fatal/PHP errors are visible.
- Arabic cashier labels and core controls still render.
- Write-flow DB evidence matches expected `ot_head`, `fat_details`, `tables`, `closed_orders`, receipt voucher, and receipt-page behavior.

## Gate 7 - Live Moova E2E

Precondition:

- Persistent `http://127.0.0.1:3001/readyz` is healthy with Redis enabled.

Required live scenarios:

- Seed/verify POSMAIN local shop/device/menu.
- Create and confirm a Moova order from the POS widget.
- Decline a Moova order with cashier reason.
- Edit a confirmed Moova order and verify POS detail replacement/link status.
- Cancel after edit and verify POS detail deletion/zeroing plus Moova status.
- Trigger stale/expired edit or cancel and verify the guard response.
- Stop Moova temporarily and verify POS remains usable, proxy returns retryable `MOOVA_UNREACHABLE`, and the visible widget state does not mislead the cashier.
- Restore persistent Moova and verify `/readyz` returns healthy again.

Pass criteria:

- No duplicate POS orders or details.
- Moova draft/command queues drain.
- `syncStatus`, `syncEventType`, provider statuses, and POS links are coherent.
- Persistent service stays healthy after the test, not only a temporary foreground runtime.

## Exit Criteria For Strict Pass

The P0-P6 strict verification can be marked pass only when:

- All known blockers in `state.yaml` have direct green rerun evidence.
- Script, GUI, migration, and Moova gates above pass.
- Any remaining untested production-only work is explicitly outside local pilot scope and documented as external evidence, not silently ignored.
- The board checker passes after receipts are updated.
