# Post-Refresh Environment Hygiene - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T12:36:28Z

## Purpose

After the fresh current-state blocker probes, verify that the failed disposable-schema smoke tests did not leave temporary MariaDB schemas behind.

## Context

The refreshed completion audit reran these failing smoke tests:

```sh
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/table_order_counter_smoke_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/uuid_backfill_smoke_test.php
```

Those tests create disposable schemas named:

- `posmain_table_counter_smoke_<pid>`
- `posmain_uuid_smoke_<pid>`

Both tests failed while applying `SyncSchemaManager`, so a residue check was warranted.

## Commands

```sh
mysql -h127.0.0.1 -P3307 -uroot -N -e "SHOW DATABASES"
```

Result: blocked locally because the host shell does not have `mysql` on PATH.

```sh
docker exec posmain-mysql mariadb -uroot -N -e "SHOW DATABASES"
docker exec posmain-mysql mysql -uroot -N -e "SHOW DATABASES"
```

Result: both succeeded and listed only:

```text
information_schema
kody2
mysql
performance_schema
sys
u173148011_focus
```

## Decision

No disposable schemas from the fresh failed smoke probes were left behind. No cleanup/drop command was needed or run.

The existing `u173148011_focus` schema was not touched because it does not match the disposable schema names created by the two refreshed smoke tests and may be an intentional local fixture.

## Boundary

- No implementation files or tests were edited.
- No runtime configuration was changed.
- No services were stopped, started, or restarted.
- No database cleanup or mutation was performed.
- The goal remains blocked pending owner approval for the fix/reclassification paths.
