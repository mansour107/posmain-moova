# Phase 0-2 Focused Foundation Tests - 2026-05-14

## Purpose

Refresh safe P0/P1/P2 foundation evidence without editing code/tests, applying migrations to current `kody2`, or submitting irreversible GUI writes.

This slice covers production foundation, branch/cloud sync contracts, POS state mutation services, counters, idempotency, outbox events, table/takeaway payment routing, and validation contracts.

## Direct PHP Batch

Command shape:

```sh
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php <test-file>
```

Passed outputs:

```text
pos-accounting-inventory-service-ok db=posmain_accounting_inventory_45579
pos-endpoint-validation-contract-ok
pos-input-validation-ok
pos-mutation-service-skeleton-ok db=posmain_pos_mutation_skeleton_45590
pos-order-mutation-event-recording-ok db=posmain_order_events_45592
pos-request-keys-idempotency-service-ok
pos-split-payment-service-ok db=posmain_split_payment_service_45596
pos-table-endpoint-idempotency-ok
pos-table-save-service-ok db=posmain_table_save_service_45600
pos-takeaway-invoice-endpoint-routing-ok
pos-takeaway-invoice-handler-ok db=posmain_takeaway_handler_45604
pos-takeaway-order-idempotency-ok
pos-takeaway-order-service-ok db=posmain_takeaway_service_45618
pos-uuid-population-ok db=posmain_uuid_population_45621
process-split-payment-endpoint-routing-ok
process-table-payment-endpoint-routing-ok
production-error-handling-ok
save-order-endpoint-routing-ok
search-sql-injection-ok
table-cancel-endpoint-routing-ok
table-order-state-payment-contract-ok db=posmain_table_state_contract_45640
```

## PHPUnit P0/P2 Batch

First pass used an empty disposable database and found five environment/fixture failures:

```text
branch_worker_daemon_contract_test.php: worker preflight saw an empty DB as schema-pending.
branch_worker_status_test.php: empty DB lacked sync_outbox.
pos_order_outbox_event_service_test.php: empty DB lacked myitems.
pos_order_service_counter_test.php: empty DB lacked ot_head.
remaining_write_surfaces_outbox_test.php: empty DB lacked tables.
```

The failing files were then rerun against a schema-only clone of current `kody2` named `posmain_p0p2_schema_clone_46892`, with migrations dry-run clean before the rerun.

Command shape:

```sh
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' POSMAIN_TEST_MYSQL_DB=posmain_p0p2_schema_clone_46892 php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never <test-file>
```

Rerun results:

```text
tests/sync/branch_worker_daemon_contract_test.php: OK (4 tests, 35 assertions)
tests/sync/branch_worker_status_test.php: OK (5 tests, 58 assertions)
tests/sync/pos_order_outbox_event_service_test.php: OK (2 tests, 25 assertions)
tests/sync/pos_order_service_counter_test.php: OK (1 test, 6 assertions)
tests/sync/remaining_write_surfaces_outbox_test.php: OK (2 tests, 56 assertions)
```

Other PHPUnit files in the P0/P2 batch passed on the first pass:

```text
branch_cloud_runtime_test.php: OK (4 tests, 25 assertions)
branch_go_live_readiness_test.php: OK (12 tests, 159 assertions)
branch_identity_test.php: OK (4 tests, 17 assertions)
branch_worker_deployment_templates_test.php: OK (3 tests, 68 assertions)
cloud_menu_snapshot_test.php: OK (3 tests, 37 assertions)
cloud_order_snapshot_test.php: OK (3 tests, 58 assertions)
cloud_register_branch_test.php: OK (4 tests, 19 assertions)
cloud_report_service_test.php: OK (2 tests, 40 assertions)
cloud_shift_snapshot_test.php: OK (3 tests, 38 assertions)
cloud_table_snapshot_test.php: OK (3 tests, 32 assertions)
document_counter_tests.php: OK (4 tests, 20 assertions)
e2e_mock_online_offline_sync_contract_test.php: OK (3 tests, 40 assertions)
legacy_cofe_counter_test.php: OK (2 tests, 13 assertions)
legacy_invoice_counter_test.php: OK (2 tests, 17 assertions)
local_sync_worker_supervisor_test.php: OK (3 tests, 23 assertions)
order_event_service_test.php: OK (2 tests, 11 assertions)
outbox_worker_reclaim_test.php: OK (1 test, 19 assertions)
sync_apply_response_test.php: OK (3 tests, 20 assertions)
sync_conflict_tool_test.php: OK (3 tests, 44 assertions)
sync_recovery_tool_test.php: OK (2 tests, 51 assertions)
sync_schema_migration_test.php: OK (3 tests, 156 assertions)
```

## Cleanup And Runtime Checks

Dropped the schema-only clone and one leftover clone from an earlier failed setup attempt:

```text
posmain_p0p2_schema_clone_46892 dropped
posmain_p0p2_schema_clone_46837 dropped
```

Cleanup query checked these patterns and returned no rows:

```text
posmain_p0p2_%
posmain_accounting_inventory_%
posmain_takeaway_%
posmain_table_%
posmain_uuid_population_%
posmain_split_payment_%
posmain_order_events_%
posmain_pos_mutation_%
```

POS remained reachable after the batch:

```text
200 http://127.0.0.1:8010/index.php
```

Persistent Moova remained degraded:

```text
503
{"ok":false,"database":true,"redis":false}
```

## Classification

The focused P0/P1/P2 foundation slice is green when tests run against an appropriate legacy-compatible schema fixture.

The initial five red PHPUnit files were fixture-environment failures from using an empty database, not confirmed app defects. This does not clear the separate known minimal-schema smoke failures from `table_order_counter_smoke_test.php` and `uuid_backfill_smoke_test.php`, which remain their own schema-fixture compatibility blocker.

Overall strict certification remains blocked by the existing blockers: `js/pos_auto_lock.js` syntax failure, persistent Moova `redis=false`, stale/red Moova widget bridge contract, write-surface inventory failure, minimal-schema smoke failures, degraded Moova widget UX gap, and missing post-fix rerun gates.

Superseding update: a later current blocker refresh on 2026-05-14 found that current `kody2` now has `order_fulfillment` and `tools/run_migrations.php --dry-run` reports `0 pending sync schema change(s)`, so the earlier fulfillment-migration blocker is cleared in the current runtime state.
