# T002 Phase 1 Scout Map

## Inputs Read

- `/Users/ab.mansour1agmail.com/Desktop/pos expansion plan.txt`, Phase 1.
- `docs/production/baseline_inventory_2026-05-12.md`
- `docs/production/write_surface_classification.md`
- `docs/production/active_route_map.md`
- `config/app_config.php`
- `includes/db_bootstrap.php`
- `includes/connect.php`
- `print/includes/connect.php`
- `config/database.php`
- `index.php`
- `do/doadd_user.php`
- `do/doedit_user.php`
- `do/dochange_password.php`
- `tools/run_migrations.php`
- `api/sync/status.php`

## Impacted Surfaces

- API contracts: `api/sync/status.php`, future `api/health.php`, existing `config/database.php` consumers `api/categories.php` and `api/items.php`.
- Shared utilities: `config/app_config.php`, `includes/db_bootstrap.php`, `includes/connect.php`, `print/includes/connect.php`, new `includes/bootstrap.php`, new `includes/session_bootstrap.php`, new `classes/PasswordService.php`.
- Database access: connection bootstrap only; Phase 1 should not alter order/payment/table mutation semantics.
- State shape: possible `schema_migrations` table creation support in migration runner, but no destructive schema changes.
- UI flows: login in `index.php`, user create/edit/change-password forms, and existing POS pages that include `includes/connect.php`.
- Auth/permissions: session cookie settings and login session regeneration; deeper route auth/CSRF belongs to Phase 3.
- Integrations: health/status endpoints must not expose secrets; Moova/sync worker health can be summarized behind token.

## Current Gaps

- `includes/connect.php`, `print/includes/connect.php`, and `index.php` still hardcode `localhost/root/''/kody2` instead of using `includes/db_bootstrap.php`.
- `config/database.php` contained real-looking credentials and must delegate to env config or be neutralized.
- `config/app_config.php` exists but does not yet expose every Phase 1 variable such as `POSMAIN_ENV`, `POSMAIN_PRODUCTION_MODE`, `POSMAIN_STATUS_TOKEN`, direct/queued Moova flags, ETA flag, and public base URL.
- `.gitignore` ignores `.env.*`, which also ignores `.env.example`; add an exception so the template can be tracked.
- No `includes/session_bootstrap.php` exists. Many pages call `session_start()` directly; Phase 1 should introduce the central bootstrap and wire the most critical login/connect path first.
- New/changed password writes still use MD5 in `do/doadd_user.php`, `do/doedit_user.php`, and `do/dochange_password.php`; `index.php` already supports MD5 login migration.
- Root `.htaccess` lacks production deny rules for private directories/files. `uploads/` has no `.htaccess`, and `uploads/dashboard.php` exists, so PHP execution in uploads must be blocked.
- `tools/run_migrations.php` is CLI-only and dry-run aware, but it has no `schema_migrations` discipline yet and still uses a local/dev `--confirm-no-backup` gate for apply.
- `run_migrations.php` web route is already guarded by Phase 0; keep it guarded and do not revive web migration behavior.
- `api/sync/status.php` exists and is token-protected via `POSMAIN_SYNC_STATUS_TOKEN`; Phase 1 still needs a broader protected/public-safe health endpoint.
- Backup/restore is partly documented in branch sync docs, but there is no Phase 1 `docs/production/backup_restore_runbook.md` or dedicated backup tool.

## Compatibility Risks

- `includes/connect.php` initializes `$rowstg`, `$restwn`, `$role`, `$edit_pass`, `$today`, `$user`, and `$userErrorMassage`; refactoring it must preserve these globals for legacy pages.
- Changing `index.php` login must preserve Arabic messages, CSRF behavior, MD5 migration, and `session_time` insertion.
- Password hashes require enough DB column length. `db/DB.sql` shows `users.password` exists, but live length should be checked before forcing changes; if too short, stop or document a migration need.
- Production deny rules must not block normal asset directories or active PHP pages.
- Health endpoints must not print DB username/password, branch secret, or detailed stack traces publicly.
- Real credential rotation cannot be performed from repo code; Phase 1 can document the requirement and remove/neutralize committed secrets.

## Verification Available Now

- Docker stack is running: `posmain-php` and `posmain-mysql`, with MariaDB on `127.0.0.1:3307`.
- `POSMAIN_DB_PORT=3307 php tools/run_migrations.php --dry-run` currently passes with `Dry run: 0 pending sync schema change(s).`
- Current syntax checks pass for `config/app_config.php`, `includes/db_bootstrap.php`, `includes/connect.php`, `print/includes/connect.php`, `index.php`, `tools/run_migrations.php`, and `api/sync/status.php`.

## Recommended Worker Sequence

1. Config/db bootstrap: complete `config/app_config.php`, add `.env.example`, neutralize `config/database.php`, route `includes/connect.php`, `print/includes/connect.php`, and `index.php` through `includes/db_bootstrap.php`.
2. Exposure/docs: add `.htaccess` deny rules, `uploads/.htaccess`, deployment profile, credential rotation doc, and least-privilege DB SQL.
3. Session/password: add central session bootstrap and `PasswordService`; change active user password writes to `password_hash` while preserving legacy verification.
4. Migration/backup/health: add migration tracking helper behavior, backup tool/runbook, and `api/health.php`.
5. Run final verification, audit dirty diff, and mark only Phase 1 scope complete.
