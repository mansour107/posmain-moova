# Branch Go-Live Readiness

Use this checklist before enabling `cli/branch_worker_daemon.php` as a real branch service.

The readiness tool is intentionally non-destructive. It does not run `mysqldump`, restore data, install services, run migrations, or write sync rows. It only verifies that the operator has a readable backup file, that daemon preflight can inspect the database, and that required branch/cloud sync configuration is present.

## 1. Create A Branch Backup

Run the dump from the POSMAIN branch machine before migrations or service enablement:

```bash
mkdir -p backups
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 --host=127.0.0.1 --port=3306 --user=posmain kody2 > backups/posmain-kody2-$(date +%Y%m%d-%H%M%S).sql
```

Do not commit database passwords or backup files. Use a protected local option file or let MySQL prompt for the password.

## 2. Prepare The Branch Worker Env File

Copy the template and replace every placeholder outside git:

```bash
cp deploy/branch-worker/branch-worker.env.example /etc/posmain/branch-worker.env
```

The template documents the required branch-side variables:

- `POSMAIN_ROLE=branch`
- database host, port, name, user, and password;
- `POSMAIN_BRANCH_UUID`;
- `POSMAIN_CLOUD_BASE_URL`;
- `POSMAIN_BRANCH_SYNC_SECRET`;
- worker enablement flags.

Do not leave `replace-with-*`, `change-me`, or `https://cloud.example.com` values in the real service env file.

## 3. Run Non-Destructive Checks

```bash
php tools/run_migrations.php --dry-run
php cli/branch_worker_daemon.php --list
php cli/branch_worker_daemon.php --preflight --strict
php tools/branch_go_live_readiness.php --json --env-file=/etc/posmain/branch-worker.env --backup-file=/absolute/path/to/posmain-backup.sql
POSMAIN_TEST_MYSQL_PORT=3307 php tools/e2e_mock_online_offline_sync.php
php tests/sync/e2e_bidirectional_operational_sync_contract_test.php
php tools/e2e_bidirectional_operational_sync.php
```

The last command is the lean offline/cloud certification described in
`docs/bidirectional_operational_sync_certification.md`. Require
`disposable_certification_pass=true` before staging a release. Its
`production_ready=false` field is intentional: live hosted parity, secrets,
service supervision, backup/rollback evidence, and authenticated smoke tests
remain separate go-live gates.

The readiness command returns exit code `0` only when:

- the backup file exists, is readable, is not empty, and is not older than the configured `--max-backup-age-hours` limit;
- daemon preflight can connect to the database;
- sync schema is ready;
- `POSMAIN_ROLE=branch`;
- `POSMAIN_BRANCH_UUID`, `POSMAIN_CLOUD_BASE_URL`, and `POSMAIN_BRANCH_SYNC_SECRET` are configured.
- any supplied `--env-file` exists, is readable, includes required branch-worker keys, and does not contain placeholder values for go-live-critical fields.
- if `POSMAIN_MOOVA_APPLY_ENABLED=1`, `--moova-acceptance-file=/absolute/path/to/acceptance.md` points to readable cashier acceptance evidence with all required pass markers and within the configured `--max-moova-acceptance-age-hours` limit.

By default, backup and Moova acceptance evidence must be no older than 24 hours. Use `--max-backup-age-hours=0` or `--max-moova-acceptance-age-hours=0` only as an explicit operator override.

`POSMAIN_MOOVA_APPLY_ENABLED=0` is reported as a warning, not a blocker. Keep it off until the branch passes cashier acceptance for queued Moova edits/cancellations.

## Moova Cashier Acceptance Evidence

Before setting `POSMAIN_MOOVA_APPLY_ENABLED=1`, copy `deploy/branch-worker/moova-cashier-acceptance.md.example` to a path outside git and fill it in. It should record the branch, operator, date/time, POS URL, Moova URL, and the cashier result for queued Moova new order, edit order, cancel order, POS drop/recovery, and Moova drop/recovery.

For a repeatable local rehearsal, run `tools/moova_cashier_acceptance_runner.php`. It checks the configured local POS/Moova topology, runs the two-mock-server drop/recovery scenarios, and writes a local/mock-backed evidence file. Keep final real-shop hosted cashier acceptance separately.

The readiness tool requires these exact pass markers in the completed file:

```text
queued_new_order=pass
queued_edit_order=pass
queued_cancel_order=pass
pos_drop_recovery=pass
moova_drop_recovery=pass
```

Then pass it to readiness:

```bash
php tools/branch_go_live_readiness.php --json \
  --env-file=/etc/posmain/branch-worker.env \
  --backup-file=/absolute/path/to/posmain-backup.sql \
  --moova-acceptance-file=/absolute/path/to/moova-cashier-acceptance.md \
  --max-moova-acceptance-age-hours=24
```

The mock online/offline E2E command is documented in `docs/online_offline_mock_e2e.md`. It uses two temporary local mock servers to prove receive-only, shadow apply, live apply, cloud drop/recovery, worker-crash reclaim, and branch drop/recovery behavior before a real shop rollout.

## Rollback

If the service causes a shop-impacting problem:

```bash
# 1. Stop the installed supervisor service using the shop platform's normal tooling.

# 2. Keep workers disabled while diagnosing.
POSMAIN_SYNC_WORKER_ENABLED=0
POSMAIN_MOOVA_APPLY_ENABLED=0

# 3. Restore only after operator approval because this overwrites the target database.
mysql --host=127.0.0.1 --port=3306 --user=posmain --default-character-set=utf8mb4 kody2 < /absolute/path/to/verified-posmain-backup.sql
```

For first rollout, prefer restoring the dump into a temporary database first and verifying table counts/report totals before overwriting the branch production database.

## Service Enablement Order

1. Create and retain the backup file.
2. Copy `deploy/branch-worker/branch-worker.env.example` to the target machine env path and replace placeholders.
3. Run the readiness tool with `--env-file` and `--backup-file`.
4. Run the two-mock online/offline E2E harness.
5. Run the three-database lean offline/cloud certification and retain its JSON report.
6. Copy the matching service template from `deploy/branch-worker/`.
7. Run `php cli/branch_worker_daemon.php --preflight --strict` in the same service environment.
8. For Windows, copy `deploy/branch-worker/windows/posmain-branch-worker-wrapper.ps1.example` to the path referenced by the scheduled task so strict preflight runs before the loop.
9. For Docker Compose, keep the strict preflight healthcheck in the compose example enabled.
10. Start with `POSMAIN_MOOVA_APPLY_ENABLED=0`.
11. Run `php cli/branch_worker_daemon.php --once --only=moova_apply` and confirm it skips safely.
12. Complete and retain the Moova cashier acceptance evidence file.
13. Enable automatic Moova apply only after cashier acceptance of queued new/edit/cancel behavior, then rerun readiness with `--moova-acceptance-file`.
