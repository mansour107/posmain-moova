# Current Approval Handoff - 2026-05-14

## Purpose

Provide the current smallest safe approval packet after the strict P0-P6 verification campaign.

This supersedes the older six-blocker `fix-scope-2026-05-14.md` shape for current status. The `order_fulfillment` migration item is no longer an active blocker because current `kody2` has `order_fulfillment` and `tools/run_migrations.php --dry-run` reports `0 pending sync schema change(s)`.

No implementation files or tests were edited for this handoff.

## Current Blocking Tranches

### 1. POS Auto-Lock Script Syntax

- Current failure: `node --check js/pos_auto_lock.js` fails at line 116 with `SyntaxError: Unexpected token '}'`.
- Likely smallest fix: remove only the duplicate trailing IIFE terminator if code inspection confirms it is duplicate.
- Candidate files if approved: `js/pos_auto_lock.js`.
- Verification gate:
  - `node --check js/pos_auto_lock.js`
  - first-party JS syntax sweep
  - browser POS unlock/reload/focus smoke if the file is wired into the active UI.

### 2. Moova Endpoint Contract Alignment

- Current failure: `tests/sync/moova_widget_bridge_contract_test.php` fails 2 assertions because it expects endpoint-level `MoovaNewOrderApplyService` / `MoovaChangeOrderApplyService` requires.
- Current implementation evidence: `ajax/moova_confirm_order.php` and `ajax/moova_change_order.php` use `MoovaLocalIngestService` plus `PosOrderMutationService`, and prior root-cause evidence found `PosOrderMutationService` delegates to the Moova apply services.
- Decision needed: approve `PosOrderMutationService` as the canonical endpoint facade and update the contract test, or require endpoint-level apply-service calls.
- Candidate files if approved:
  - `tests/sync/moova_widget_bridge_contract_test.php`
  - `ajax/moova_confirm_order.php`
  - `ajax/moova_change_order.php`
  - `classes/Pos/Service/PosOrderMutationService.php`
- Verification gate:
  - `php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/moova_widget_bridge_contract_test.php`
  - live Moova create/confirm, decline, edit, cancel, cancel-after-edit after persistent Moova health is green.

### 3. Write-Surface Inventory Alignment

- Current failure: `tests/sync/write_surface_inventory_test.php` fails because `ajax/moova_confirm_order.php` is not classified as `moova_bridge`.
- Current root cause: `tools/audit_write_paths.php` still recognizes the older direct `Moova*ApplyService` strings, not the facade method path.
- Candidate files if approved:
  - `tools/audit_write_paths.php`
  - `tests/sync/write_surface_inventory_test.php`
- Verification gate:
  - `php tools/audit_write_paths.php --json`
  - `php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/write_surface_inventory_test.php`

### 4. Minimal Fixture Schema Compatibility

- Current failures:
  - `tests/sync/table_order_counter_smoke_test.php` fails on missing `ot_head.table_id`.
  - `tests/sync/uuid_backfill_smoke_test.php` fails on missing `tables.table_case`.
- Current root cause: `SyncSchemaManager` phase 4 legacy upgrade SQL uses `AFTER` anchors that reduced fixtures can lack.
- Candidate files if approved:
  - `classes/Sync/SchemaManager.php`
  - `tests/sync/table_order_counter_smoke_test.php`
  - `tests/sync/uuid_backfill_smoke_test.php`
- Recommended smallest fix direction: make migration SQL omit `AFTER <anchor>` when the anchor column does not exist, unless the owner decides those anchor columns are hard prerequisites for all supported schemas.
- Verification gate:
  - `POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/table_order_counter_smoke_test.php`
  - `POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/uuid_backfill_smoke_test.php`
  - focused Phase 4 schema/midscale tests

### 5. Persistent Moova Runtime And Widget Degraded UX

- Current runtime failure: `http://127.0.0.1:3001/readyz` returns HTTP 503 with `{"ok":false,"database":true,"redis":false}`.
- Current UX gap: POS Moova widget can show an empty queue while persistent Moova is unhealthy, instead of an explicit degraded/offline state.
- Current root cause evidence: persistent LaunchAgent does not set `NODE_ENV`; `.env.local` can force `NODE_ENV=test`; Redis is disabled in test mode while `/readyz` still requires Redis when `REDIS_URL` exists.
- Candidate files/targets if approved:
  - `~/Library/LaunchAgents/com.codex.cofe-order-3001.plist`
  - `/Users/Shared/cofe_order_runtime/.env`
  - `/Users/Shared/cofe_order_runtime/.env.local`
  - `assets/moova-pos-widget/pos-widget.js`
  - `moova_pos_proxy.php`
  - `moova_pos_widget.php`
- Verification gate:
  - `curl http://127.0.0.1:3001/readyz` returns `ok=true,database=true,redis=true`
  - `php tools/moova_local_topology_check.php`
  - browser widget smoke verifies degraded/offline copy when Moova is stopped
  - live Moova create/confirm, decline, edit, cancel, cancel-after-edit

## Current Non-Blocking Item

`order_fulfillment` is no longer a current blocker:

```text
tools/run_migrations.php --dry-run -> 0 pending sync schema change(s)
SHOW TABLES LIKE 'order_fulfillment' -> order_fulfillment
```

Keep Gate 4 migration readiness in the rerun checklist as a non-mutating dry-run guard only. Do not apply any current-DB migration unless a new drift appears and the owner approves backup plus apply.

## Recommended Approval Path

If the owner approves fixes, handle one tranche at a time in this order:

1. Script syntax and source-contract/tooling alignment.
2. Minimal fixture schema compatibility.
3. Persistent Moova runtime health.
4. Widget degraded/offline UX.
5. Full post-fix rerun checklist.

Until that approval exists, the correct Goal Maker state is `blocked`, not complete.
