# Phase 6 Focused Readiness Tests - 2026-05-14

## Purpose

Run the safe Phase 6 pilot/readiness checks that had not been refreshed in the latest blocked audit loop, without touching current `kody2`, saving POS orders, applying migrations, or changing runtime configuration.

## Safety Classification

Commands reviewed before execution:

- `tests/sync/phase6_pilot_docs_contract_test.php`: read-only docs contract.
- `tests/sync/phase6_e2e_docs_contract_test.php`: read-only docs contract.
- `tests/sync/phase6_seed_demo_restaurant_test.php`: creates a disposable `posmain_phase6_demo_seed_<pid>` database, runs dry-run/apply/idempotency/reset checks inside it, verifies production-mode refusal, then drops the database.
- `tests/sync/phase6_load_concurrency_check_test.php`: creates a disposable `posmain_phase6_load_<pid>` database, runs load/concurrency scenarios, verifies unsafe current-db and production-mode refusals, then drops the database.

## Commands And Results

```sh
php tests/sync/phase6_pilot_docs_contract_test.php
```

Result:

```text
phase6-pilot-docs-contract-ok
```

```sh
php tests/sync/phase6_e2e_docs_contract_test.php
```

Result:

```text
phase6-e2e-docs-contract-ok
```

```sh
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/phase6_seed_demo_restaurant_test.php
```

Result:

```text
phase6-seed-demo-restaurant-ok db=posmain_phase6_demo_seed_31914
```

```sh
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/phase6_load_concurrency_check_test.php
```

Result:

```text
phase6-load-concurrency-check-ok db=posmain_phase6_load_31915
```

Temporary schema cleanup check:

```sh
docker exec posmain-mysql mariadb -uroot -N -e "SHOW DATABASES LIKE 'posmain_phase6_%';"
```

Output: no rows.

Current POS baseline after tests:

```sh
curl -s -o /tmp/posmain-after-phase6-tests-http.txt -w '%{http_code}\n' http://127.0.0.1:8010/index.php
```

Result:

```text
200
```

## What This Covers

- Phase 6 pilot go-live, daily review, and exit-criteria documents are not placeholders and include required readiness gates.
- Phase 6 local E2E command document names the required local services, roles, GUI flows, and mutation endpoints.
- Demo restaurant seed is dry-run safe, idempotent, reset-safe, production-mode refused, and capable of applying into a disposable schema.
- Load/concurrency check covers cashier sales uniqueness, waiter table saves, same-table conflict, duplicate payment idempotency, remaining amount/table-clear guards, item search request volume, cleanup, current-db refusal, and production-mode refusal.

## Verdict

This Phase 6 focused slice passed.

It does not clear the full P0-P6 strict certification because the current blockers remain outside this slice:

- `js/pos_auto_lock.js` syntax failure.
- Pending `order_fulfillment` migration on current `kody2`.
- Persistent Moova `/readyz` is still degraded with `redis=false`.
- Moova widget contract test failure.
- Write-surface inventory test failure.
- Minimal schema fixture smoke failures.
- Cashier-facing Moova degraded-state UX still presents empty-queue copy while runtime health is red.
