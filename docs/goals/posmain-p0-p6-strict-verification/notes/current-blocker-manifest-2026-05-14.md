# Current Blocker Manifest - 2026-05-14

## Purpose

Provide a stable ID-based manifest of the remaining blockers after the strict P0-P6 verification campaign. This is documentation only; no app code, tests, runtime configuration, services, or database data were changed.

## Manifest

### B001 - POS Auto-Lock Script Syntax

Status: red

Proof command:

```sh
node --check js/pos_auto_lock.js
```

Current failure:

```text
SyntaxError: Unexpected token '}' at js/pos_auto_lock.js:116
```

Primary scope if approved:

```text
js/pos_auto_lock.js
```

Pass criteria:

- `node --check js/pos_auto_lock.js` exits 0.
- First-party JS syntax sweep remains green.
- POS unlock/reload/focus browser smoke is rerun if this script is wired into the active UI.

### B002 - Moova Endpoint Contract Alignment

Status: red

Proof command:

```sh
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/moova_widget_bridge_contract_test.php
```

Current failure:

```text
The test expects endpoint-level MoovaNewOrderApplyService and MoovaChangeOrderApplyService requires.
Current endpoints use MoovaLocalIngestService plus PosOrderMutationService.
```

Primary scope if approved:

```text
tests/sync/moova_widget_bridge_contract_test.php
ajax/moova_confirm_order.php
ajax/moova_change_order.php
classes/Pos/Service/PosOrderMutationService.php
```

Pass criteria:

- The contract test is green under the owner-approved facade/endpoint contract.
- Live Moova create/confirm, decline, edit, cancel, and cancel-after-edit are rerun after persistent Moova health is green.

### B003 - Write-Surface Inventory Classification

Status: red

Proof command:

```sh
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/write_surface_inventory_test.php
```

Current failure:

```text
Expected ajax/moova_confirm_order.php to have category moova_bridge.
```

Primary scope if approved:

```text
tools/audit_write_paths.php
tests/sync/write_surface_inventory_test.php
```

Pass criteria:

- `php tools/audit_write_paths.php --json` completes.
- `write_surface_inventory_test.php` is green.
- Moova facade-based writes are classified without overbroad file-name-only detection.

### B004 - Minimal Fixture Schema Compatibility

Status: red

Proof commands:

```sh
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/table_order_counter_smoke_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/uuid_backfill_smoke_test.php
```

Current failures:

```text
table_order_counter_smoke_test.php: Unknown column 'table_id' in 'ot_head'
uuid_backfill_smoke_test.php: Unknown column 'table_case' in 'tables'
```

Primary scope if approved:

```text
classes/Sync/SchemaManager.php
tests/sync/table_order_counter_smoke_test.php
tests/sync/uuid_backfill_smoke_test.php
```

Pass criteria:

- Both minimal-schema smoke tests are green.
- Focused Phase 4 schema/midscale tests remain green.
- Real/current schema migration dry-run remains green.

### B005 - Persistent Moova Runtime Health

Status: red

Proof command:

```sh
curl http://127.0.0.1:3001/readyz
```

Current failure:

```text
HTTP 503
{"ok":false,"database":true,"redis":false}
```

Primary scope if approved:

```text
~/Library/LaunchAgents/com.codex.cofe-order-3001.plist
/Users/Shared/cofe_order_runtime/.env
/Users/Shared/cofe_order_runtime/.env.local
```

Pass criteria:

- Persistent `/readyz` returns `ok=true,database=true,redis=true`.
- `php tools/moova_local_topology_check.php` is green.
- The healthy state is from the persistent LaunchAgent, not a temporary foreground runtime.

### B006 - Moova Widget Degraded/Offline UX

Status: red

Proof evidence:

```text
Browser widget smoke shows the POS-side widget can show empty-queue copy while persistent Moova is unhealthy.
```

Primary scope if approved:

```text
assets/moova-pos-widget/pos-widget.js
moova_pos_proxy.php
moova_pos_widget.php
```

Pass criteria:

- When Moova is stopped or unreachable, POS remains usable.
- Proxy returns retryable `MOOVA_UNREACHABLE`.
- Visible widget state clearly communicates degraded/offline status and does not settle into misleading empty-queue copy.
- Persistent service can be restored to healthy state afterward.

## Current Non-Blocker

`order_fulfillment` migration readiness is green in the current state:

```text
tools/run_migrations.php --dry-run -> 0 pending sync schema change(s)
current kody2 has order_fulfillment
```

Keep migration dry-run in future gates, but do not treat `order_fulfillment` as an active blocker unless new drift appears.

## Completion Rule

The goal can only be marked complete after all active blockers above have either direct green rerun evidence or explicit owner/Judge reclassification, followed by a full completion audit that maps the original objective to current evidence.
