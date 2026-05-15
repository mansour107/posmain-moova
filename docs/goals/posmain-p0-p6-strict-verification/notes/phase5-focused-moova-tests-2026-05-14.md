# Phase 5 Focused Moova Tests - 2026-05-14

## Purpose

Refresh safe Phase 5/Moova reliability evidence without applying migrations to current `kody2`, changing Moova runtime configuration, or editing code/tests.

## Safety Classification

Run:

- Source-contract/config tests.
- Local disposable-schema tests.
- PHPUnit source-contract tests.
- PHPUnit DB-dependent tests against a manually created disposable database.

Not run in this green batch:

- `tests/sync/moova_local_topology_check_test.php`: already blocked by persistent Moova `/readyz` returning `redis=false`.
- `tests/sync/moova_widget_bridge_contract_test.php`: already documented as stale/red against the current `PosOrderMutationService` facade architecture.
- `branch_moova_ack_worker_test.php`, `branch_moova_poll_worker_test.php`, `branch_moova_apply_worker_test.php`: these default to `kody2` and call `SyncSchemaManager::apply()` in setup, so they need a separate fixture-safe wrapper or explicit current-DB mutation approval before inclusion.

## Direct PHP Batch

```sh
for f in \
  tests/sync/moova_cashier_ux_contract_test.php \
  tests/sync/moova_confirm_change_routing_test.php \
  tests/sync/moova_direct_queued_convergence_test.php \
  tests/sync/moova_mode_config_test.php \
  tests/sync/moova_pos_mutation_convergence_test.php \
  tests/sync/moova_reliability_scenarios_test.php \
  tests/sync/moova_delivery_foundation_test.php \
  tests/sync/phase5_order_fulfillment_service_test.php; do
  POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php "$f"
done
```

Results:

```text
moova-cashier-ux-contract-ok
moova-confirm-change-routing-ok
moova-direct-queued-convergence-ok
moova-mode-config-ok
moova-pos-mutation-convergence-ok
moova-reliability-scenarios-ok
moova-delivery-foundation-ok
phase5-order-fulfillment-service-ok db=posmain_phase5_fulfillment_44797
```

## PHPUnit Source-Contract Batch

```sh
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never \
  tests/sync/moova_apply_response_contract_test.php \
  tests/sync/moova_cashier_acceptance_runner_test.php \
  tests/sync/moova_change_order_apply_service_test.php \
  tests/sync/moova_new_order_apply_service_test.php \
  tests/sync/moova_reachability_smoke_contract_test.php \
  tests/sync/moova_widget_reachability_messages_test.php
```

Result:

```text
OK (5 tests, 18 assertions)
```

## PHPUnit Disposable-DB Batch

Created a temporary DB named like `posmain_phase5_moova_phpunit_<pid>`, ran:

```sh
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' POSMAIN_TEST_MYSQL_DB="$db" php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never \
  tests/sync/cloud_moova_event_service_test.php \
  tests/sync/moova_branch_event_cursor_test.php \
  tests/sync/moova_inbound_queue_test.php \
  tests/sync/moova_local_ingest_service_test.php
```

Then dropped the temporary DB.

Result:

```text
OK (4 tests, 19 assertions)
```

Cleanup check:

```sh
docker exec posmain-mysql mariadb -uroot -N -e "SHOW DATABASES LIKE 'posmain_phase5_%'; SHOW DATABASES LIKE 'posmain_phase5_moova_%';"
```

Output: no rows.

## Runtime Baseline After Tests

POS remained reachable:

```text
200
```

Persistent Moova remained degraded:

```text
503
{"ok":false,"database":true,"redis":false}
```

## What This Covers

- Moova mode config and direct/queued convergence.
- Current facade-based POS mutation convergence.
- Direct confirm/change routing source contracts.
- Cashier UX contracts, reachability messages, and scenario docs.
- Delivery foundation and `OrderFulfillmentService` behavior on disposable schema.
- Moova apply response contracts.
- New/change apply service source contracts.
- Local mock cashier acceptance runner contract.
- Reachability smoke harness contract.
- Cloud Moova event service, branch event cursor, inbound queue, and local ingest service behavior on a disposable schema.

## Verdict

This safe Phase 5/Moova focused slice passed.

Overall strict certification remains blocked because the green slice does not clear:

- Persistent Moova `/readyz` degraded with `redis=false`.
- Stale/red `moova_widget_bridge_contract_test.php`.
- Pending `order_fulfillment` migration on current `kody2`.
- Write-surface inventory failure.
- `js/pos_auto_lock.js` syntax failure.
- Minimal schema fixture smoke failures.
- Cashier-facing degraded-state widget UX gap.
