# POSMAIN Backup And Restore Runbook

Phase 1 requires backup/restore to be operationally explicit before public staging or pilot use.

## Backup

Create backups from a private shell, not a browser route:

```bash
php tools/backup_database.php --output=/var/backups/posmain/posmain-$(date +%Y%m%d-%H%M%S).sql
```

The tool uses the environment-backed database config and passes the DB password through process environment, not through a printed command. To review the command shape without exposing the password:

```bash
php tools/backup_database.php --output=/tmp/posmain-test.sql --dry-run
```

Backups must include:

- Full schema.
- Data.
- Triggers and routines.
- `document_counters`.
- Moova link tables.
- `sync_outbox` and `sync_inbox` rows if sync is enabled.

## Restore Rehearsal

Restore into staging or a disposable local database first:

```bash
mysql --host="$POSMAIN_DB_HOST" \
  --port="$POSMAIN_DB_PORT" \
  --user="$POSMAIN_DB_USER" \
  --default-character-set=utf8mb4 \
  "$POSMAIN_DB_NAME" < /absolute/path/to/verified-posmain-backup.sql
```

Never restore over a production database from this runbook without an explicit operator-approved maintenance window.

## Post-Restore Smoke Checks

After restoring into staging:

```bash
php tools/run_migrations.php --dry-run
curl -s http://127.0.0.1:8010/api/health.php
```

Then verify manually:

- Login page opens.
- POS opens.
- Last 10 orders are visible.
- Last 10 payments match order totals.
- Stock quantities for sampled sold items are reasonable.
- Journal entries are balanced for sampled sales.
- Table occupancy matches active unpaid/partial orders.
- Moova mapping tables are present if Moova is enabled.

## Evidence To Keep

Record privately:

- Backup file path.
- Backup size.
- Restore target database.
- Restore timestamp.
- Smoke-test results.
- Any incident or mismatch found.
