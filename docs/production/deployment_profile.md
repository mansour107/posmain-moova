# POSMAIN Production Deployment Profile

Generated for Phase 1 production-foundation work.

## Target Shape

Use a branch-local deployment first:

- Web: Apache or Nginx with PHP-FPM.
- PHP: 8.2 or 8.3 after compatibility testing.
- Database: MariaDB 10.11+ unless an existing production database requires a pinned version.
- App code: read-only release directory where possible.
- Writable paths: `uploads/`, `logs/`, and private backup destination only.
- Secrets: environment variables or a private `.env` file outside web access.
- Workers: CLI/systemd services only for sync/Moova workers when those features are enabled.

The current `Dockerfile.posmain-php` is acceptable for local test scaffolding, not a final public production profile.

## Required Environment

Set the values shown in `.env.example` without committing real secrets:

- `POSMAIN_ENV=production`
- `POSMAIN_PRODUCTION_MODE=1`
- `POSMAIN_DB_HOST`, `POSMAIN_DB_PORT`, `POSMAIN_DB_USER`, `POSMAIN_DB_PASS`, `POSMAIN_DB_NAME`
- `POSMAIN_STATUS_TOKEN`
- `POSMAIN_BRANCH_UUID`, `POSMAIN_BRANCH_NAME`, `POSMAIN_POS_TENANT`, `POSMAIN_POS_BRANCH`
- `POSMAIN_PUBLIC_BASE_URL`

Keep disruptive features disabled until verified:

- `POSMAIN_ENABLE_CLOUD_SYNC=0`
- `POSMAIN_ENABLE_MOOVA_DIRECT_APPLY=0`
- `POSMAIN_ENABLE_MOOVA_QUEUED_APPLY=0`
- `POSMAIN_ENABLE_KDS=0`
- `POSMAIN_ENABLE_MODIFIERS=0`
- `POSMAIN_ENABLE_NUTRITION=0`
- `POSMAIN_ENABLE_AI_ANALYTICS=0`
- `POSMAIN_ENABLE_ETA_ERECEIPT=0`

## Web Server Rules

If the app is served from the repo root, the root `.htaccess` denies private directories and sensitive file extensions. A stronger production setup should point the document root at a future `public/` directory, but Phase 1 keeps the current layout and hardens exposure.

Must deny direct browser access to:

- `.git/`
- `backup/`
- `logs/`
- `db/`
- `dbase/`
- `update/`
- `tools/`
- `tests/`
- `cli/`
- `deploy/`
- `docs/`
- `.env`
- SQL, log, backup, and patch files

`uploads/.htaccess` disables PHP/script execution in uploaded files. Confirm this at the web-server layer too for Nginx/PHP-FPM.

## Writable Paths

Create only these writable paths for the app user:

```bash
mkdir -p logs uploads /var/backups/posmain
chown -R posmain:www-data logs uploads /var/backups/posmain
chmod 0750 logs /var/backups/posmain
chmod 0755 uploads
```

Do not make the whole app tree writable by the web user.

## Start/Stop Checks

For a VM deployment:

```bash
systemctl restart php-fpm
systemctl reload nginx
systemctl status php-fpm nginx mariadb
```

For the current local Docker test stack:

```bash
docker start posmain-mysql posmain-php
POSMAIN_DB_PORT=3307 php tools/run_migrations.php --dry-run
curl -I http://127.0.0.1:8010/index.php
```

## Health Checks

Public checks should only report ok/not ok. Detailed checks require `POSMAIN_STATUS_TOKEN`.

```bash
curl -s http://example.test/api/health.php
curl -s -H "X-Posmain-Status-Token: $POSMAIN_STATUS_TOKEN" http://example.test/api/health.php?detail=1
```

## Backup Command

Use the Phase 1 backup tool or equivalent private `mysqldump` command:

```bash
php tools/backup_database.php --output=/var/backups/posmain/posmain-$(date +%Y%m%d-%H%M%S).sql
```

## Restore Command

Restore only into staging first:

```bash
mysql --host="$POSMAIN_DB_HOST" --port="$POSMAIN_DB_PORT" --user="$POSMAIN_DB_USER" --default-character-set=utf8mb4 "$POSMAIN_DB_NAME" < /absolute/path/to/verified-backup.sql
```

Then run smoke checks for login, POS open, last orders, payments, stock, journals, tables, and health.
