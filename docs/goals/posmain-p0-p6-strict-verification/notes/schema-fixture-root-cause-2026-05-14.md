# Schema Fixture Root Cause - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T11:31:06Z

## Question

Why do `tests/sync/table_order_counter_smoke_test.php` and `tests/sync/uuid_backfill_smoke_test.php` fail against disposable/minimal schemas?

## Reproduced Failures

Commands:

```bash
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/table_order_counter_smoke_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/uuid_backfill_smoke_test.php
```

Results:

- `table_order_counter_smoke_test.php` fails in `SyncSchemaManager::apply()` with `Unknown column 'table_id' in 'ot_head'`.
- `uuid_backfill_smoke_test.php` fails in `SyncSchemaManager::apply()` with `Unknown column 'table_case' in 'tables'`.

## Source Evidence

`table_order_counter_smoke_test.php` creates a minimal `ot_head` table:

- `id`
- `pro_id`
- `pro_tybe`
- `tenant`
- `branch`

It does not create `table_id`.

`uuid_backfill_smoke_test.php` creates minimal tables with only:

- `id`

It does not create `tables.table_case`.

`SyncSchemaManager::phase4LegacyTargets()` defines Phase 4 legacy upgrades such as:

- `ALTER TABLE ot_head ADD COLUMN guest_count INT NULL AFTER table_id`
- `ALTER TABLE tables ADD COLUMN area_id BIGINT UNSIGNED NULL AFTER table_case`

`phase4LegacyUpgradeStatements()` checks whether the new column already exists, but it does not check whether the `AFTER` anchor column exists before returning the SQL. `apply()` then executes the invalid `ALTER TABLE` statement.

## Classification

This is a migration-helper compatibility issue with reduced/minimal schemas.

The tests are intentionally validating that sync/migration helpers tolerate disposable fixture schemas. The current helper assumes real legacy anchor columns exist for Phase 4 column placement, so it is brittle in minimal-schema contexts.

## Safe Fix Direction

If approved, the smallest safe implementation direction is:

- keep the ordered `AFTER <anchor>` SQL when the anchor column exists;
- generate an anchorless `ADD COLUMN` statement when the anchor column is absent; or
- explicitly declare anchor columns as fixture prerequisites and update the tests if minimal-schema support is not desired.

Because the failed tests are named smoke tests for reduced schemas, the first two options are safer than expanding fixtures to match full legacy schema.

## Verification After Fix

Rerun:

```bash
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/table_order_counter_smoke_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/uuid_backfill_smoke_test.php
```

Then rerun the broader standalone direct PHP sync loop on disposable schemas.
