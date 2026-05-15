# Order Fulfillment Migration Readiness - 2026-05-14

## Purpose

Read-only follow-up on the pending `order_fulfillment` migration that blocks strict Phase 5/6 database-readiness claims for the current `kody2` database.

## Current Live Result

`kody2` does not currently have `order_fulfillment`.

Evidence:

```sh
docker exec posmain-mysql mariadb -uroot kody2 -N -e "SHOW TABLES LIKE 'order_fulfillment';"
```

Output: no rows.

The migration tracker exists:

```sh
docker exec posmain-mysql mariadb -uroot kody2 -N -e "SELECT TABLE_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('schema_migrations','order_fulfillment');"
```

Output:

```text
schema_migrations  1
```

Latest tracker row:

```text
sync_schema_manager  classes/Sync/SchemaManager.php  2026-05-13 13:51:36
```

## Migration Shape

The current dry-run still reports exactly one pending sync schema change:

```sh
POSMAIN_DB_HOST=127.0.0.1 POSMAIN_DB_PORT=3307 POSMAIN_DB_USER=root POSMAIN_DB_PASS='' POSMAIN_DB_NAME=kody2 php tools/run_migrations.php --dry-run
```

Output summary:

```text
Migration tracking: ready.
Dry run: 1 pending sync schema change(s).

-- order_fulfillment
CREATE TABLE IF NOT EXISTS order_fulfillment (...)
```

The planned SQL is additive: `CREATE TABLE IF NOT EXISTS order_fulfillment`, with primary key, unique `order_id`, channel/status index, provider/external-order index, and delivery/customer metadata columns. It does not alter or drop existing tables.

Source evidence:

- `classes/Sync/SchemaManager.php:12` includes `order_fulfillment` in `plannedStatements()`.
- `classes/Sync/SchemaManager.php:740-764` defines the additive table.

## Runtime Behavior Before Migration

`OrderFulfillmentService` is intentionally missing-table tolerant by default:

- `classes/Pos/Service/OrderFulfillmentService.php:68-85` returns a skipped result with reason `ORDER_FULFILLMENT_TABLE_MISSING` when the table is absent.
- It throws only when callers pass `require_table`.
- `classes/Pos/Service/OrderFulfillmentService.php:316-329` checks `INFORMATION_SCHEMA.TABLES`.

This means existing order creation can continue, but Phase 5/6 fulfillment metadata is not persisted in current `kody2` until the migration is applied.

## Safety Rails Confirmed

The backup dry-run prints the expected masked command and creates no file:

```sh
POSMAIN_DB_HOST=127.0.0.1 POSMAIN_DB_PORT=3307 POSMAIN_DB_USER=root POSMAIN_DB_PASS='' POSMAIN_DB_NAME=kody2 php tools/backup_database.php --output=/tmp/posmain-order-fulfillment-precheck.sql --dry-run
```

Output:

```text
MYSQL_PWD=******** mysqldump --single-transaction --routines --triggers --default-character-set='utf8mb4' --host='127.0.0.1' --port='3307' --user='root' 'kody2' > '/tmp/posmain-order-fulfillment-precheck.sql'
```

A bare apply is blocked by the migration runner:

```sh
POSMAIN_DB_HOST=127.0.0.1 POSMAIN_DB_PORT=3307 POSMAIN_DB_USER=root POSMAIN_DB_PASS='' POSMAIN_DB_NAME=kody2 php tools/run_migrations.php --apply
```

Output:

```text
--apply requires --backup-file=/absolute/path/to/recent.sql, or --confirm-no-backup for local/dev only.
```

No `order_fulfillment` table was created by this failed apply attempt.

## Focused Tests

These focused tests still pass under disposable/non-current-db conditions:

```sh
php tests/sync/moova_delivery_foundation_test.php
```

Output:

```text
moova-delivery-foundation-ok
```

```sh
POSMAIN_TEST_MYSQL_PORT=3307 php tests/sync/phase5_order_fulfillment_service_test.php
```

Output:

```text
phase5-order-fulfillment-service-ok db=posmain_phase5_fulfillment_12904
```

Post-test schema cleanup check:

```sh
docker exec posmain-mysql mariadb -uroot -N -e "SHOW DATABASES LIKE 'posmain_phase5_fulfillment_%';"
```

Output: no rows.

## Readiness Decision

This blocker is well-scoped and low-risk from a schema-shape perspective, but it is still a current-database mutation.

To clear it safely:

1. Create a real current backup file with `tools/backup_database.php`.
2. Apply with `tools/run_migrations.php --apply --backup-file=<that file>`.
3. Rerun the dry-run and require `0 pending sync schema change(s)`.
4. Rerun `moova_delivery_foundation_test.php` and `phase5_order_fulfillment_service_test.php`.
5. Recheck POS HTTP and Moova readiness after the migration.

Until owner approval for the backup/apply step, strict P0-P6 readiness remains blocked.
