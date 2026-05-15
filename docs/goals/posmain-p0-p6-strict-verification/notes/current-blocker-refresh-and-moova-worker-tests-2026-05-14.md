# Current Blocker Refresh And Moova Worker Tests - 2026-05-14

## Purpose

Refresh the current blocker list after the focused P0-P6 passes, and cover the remaining safe Moova branch worker tests without mutating current `kody2`.

## Current Blocker Refresh

Commands and results:

```sh
node --check js/pos_auto_lock.js
```

Result:

```text
SyntaxError: Unexpected token '}' at js/pos_auto_lock.js:116
```

```sh
POSMAIN_DB_HOST=127.0.0.1 POSMAIN_DB_PORT=3307 POSMAIN_DB_USER=root POSMAIN_DB_PASS='' POSMAIN_DB_NAME=kody2 php tools/run_migrations.php --dry-run
```

Result:

```text
Migration tracking: ready.
Dry run: 0 pending sync schema change(s).
```

Current `kody2` table evidence:

```text
SHOW TABLES LIKE 'order_fulfillment' -> order_fulfillment
COUNT(*) FROM information_schema.tables -> 1
COUNT(*) FROM order_fulfillment -> 0
```

The prior pending `order_fulfillment` migration blocker is cleared in the current runtime state. I did not intentionally apply this migration to current `kody2` during this receipt; the current evidence is that the table now exists and the migration dry-run is clean.

```sh
curl -sS -o /tmp/moova-final-readyz.json -w '%{http_code}\n' http://127.0.0.1:3001/readyz
```

Result:

```text
503
{"ok":false,"database":true,"redis":false}
```

```sh
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/moova_widget_bridge_contract_test.php
```

Result:

```text
FAILURES!
Tests: 3, Assertions: 15, Failures: 2.
```

The two failures are still the stale endpoint-level direct-service expectations:

```text
ajax/moova_confirm_order.php does not require MoovaNewOrderApplyService.php
ajax/moova_change_order.php does not require MoovaChangeOrderApplyService.php
```

```sh
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/write_surface_inventory_test.php
```

Result:

```text
FAILURES!
Tests: 2, Assertions: 314, Failures: 1.
Expected ajax/moova_confirm_order.php to have category moova_bridge.
```

```sh
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/table_order_counter_smoke_test.php
```

Result:

```text
Fatal error: Unknown column 'table_id' in 'ot_head'
```

```sh
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/uuid_backfill_smoke_test.php
```

Result:

```text
Fatal error: Unknown column 'table_case' in 'tables'
```

## Moova Branch Worker Tests

The three remaining Moova worker tests honor `POSMAIN_TEST_MYSQL_DB`. Because the apply worker needs real POS table/item fixtures, I created a full disposable clone of current `kody2`:

```text
posmain_moova_worker_clone_47869
```

Preflight on the clone:

```text
Migration tracking: ready.
Dry run: 0 pending sync schema change(s).
```

Worker test results:

```text
tests/sync/branch_moova_ack_worker_test.php: OK (2 tests, 19 assertions)
tests/sync/branch_moova_poll_worker_test.php: OK (2 tests, 21 assertions)
tests/sync/branch_moova_apply_worker_test.php: OK (7 tests, 118 assertions)
```

Cleanup:

```text
DROP DATABASE posmain_moova_worker_clone_47869
SHOW DATABASES LIKE 'posmain_moova_worker_clone_%' -> no rows
SHOW DATABASES LIKE 'posmain_p0p2_%' -> no rows
```

POS remained reachable after this pass:

```text
200 http://127.0.0.1:8010/index.php
```

## Current Strict Verdict

Improved since the earlier audit:

- Current `kody2` now has `order_fulfillment`.
- `tools/run_migrations.php --dry-run` now reports `0 pending sync schema change(s)`.
- Moova branch ack, poll, and apply worker tests now passed on a full disposable clone.

Still blocking a strict "all green" verdict:

- `js/pos_auto_lock.js` has a syntax error at line 116.
- Persistent Moova runtime is degraded: `/readyz` is HTTP 503 with `redis=false`.
- `moova_widget_bridge_contract_test.php` is still red against the current facade architecture.
- `write_surface_inventory_test.php` still misses the current Moova bridge classification.
- `table_order_counter_smoke_test.php` and `uuid_backfill_smoke_test.php` still fail on minimal-schema fixtures.
- The POS Moova widget still has the degraded/offline empty-queue UX gap from the browser smoke.
- No post-fix full rerun can be completed until the blockers are fixed or explicitly accepted.
