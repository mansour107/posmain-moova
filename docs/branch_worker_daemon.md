# POSMAIN Branch Worker Daemon

`cli/branch_worker_daemon.php` is the portable branch-side runner for online/offline sync workers.

It runs these existing workers in one supervised cycle:

| Job | Worker | Purpose |
| --- | --- | --- |
| `sync_outbox` | `BranchSyncWorker` | Sends local `sync_outbox` events to the cloud receive API. |
| `moova_catalog` | `MoovaCatalogSyncWorker` | Pushes authoritative menu and table snapshots from the local POS database to the paired Moova branch, with retry and fingerprint repair. |
| `moova_poller` | `BranchMoovaPollWorker` | Pulls cloud Moova order events into the local inbound queue. |
| `cloud_sync_poller` | `BranchCloudSyncPollWorker` | Optional generic hosted-to-branch POS editing; disabled for the offline-first production profile. |
| `moova_apply` | `BranchMoovaApplyWorker` | Applies queued Moova events to POS table orders. |
| `moova_ack` | `BranchMoovaAckWorker` | Acknowledges terminal Moova event results back to cloud. |

## Smoke Commands

List the configured jobs without touching the database:

```bash
php cli/branch_worker_daemon.php --list
```

Check schema/config readiness:

```bash
php cli/branch_worker_daemon.php --preflight
```

Fail when required branch/cloud config is only warning-level:

```bash
php cli/branch_worker_daemon.php --preflight --strict
```

Run one supervised cycle:

```bash
php cli/branch_worker_daemon.php --once
```

Run continuously for a supervisor such as launchd, systemd, Windows Task Scheduler, or Docker:

```bash
php cli/branch_worker_daemon.php --loop --sleep=5 --max-runtime=300
```

Limit a smoke run to one job:

```bash
php cli/branch_worker_daemon.php --once --only=sync_outbox
```

Force the catalog worker to compare the local fingerprint and retry any due Moova snapshot:

```bash
php cli/branch_worker_daemon.php --once --only=moova_catalog
```

## Operational Notes

- The daemon does not install an OS service by itself.
- Each cycle opens a fresh database connection from `includes/db_bootstrap.php`.
- One failed job does not stop the rest of the cycle; the JSON line reports per-job `success`, `skipped`, or `failed`.
- `--preflight` returns exit code `2` when sync schema work is pending.
- Missing cloud URL, branch UUID, wrong role, or branch secret is reported as a warning in normal `--preflight`; use `--preflight --strict` before service enablement so those warnings fail the gate.
- Keep `POSMAIN_MOOVA_APPLY_ENABLED=0` until the branch is ready to apply queued Moova events automatically.
- Keep `POSMAIN_CLOUD_PULL_ENABLED=0` for the offline-first production profile. Pairing never restores automatically, and disaster recovery uses the guarded manual restore command instead of the worker.
- Before installing or enabling any supervisor service, run the non-destructive go-live checklist in `docs/branch_go_live_readiness.md`.

## Service Templates

These examples are intentionally non-installing templates. Copy one, replace paths/users/env files for the target branch machine, run `--preflight`, then install using the platform's normal service tooling.

Use `deploy/branch-worker/branch-worker.env.example` as the starting point for `/etc/posmain/branch-worker.env`, `.env.branch-worker`, or the equivalent service-account environment. Keep the real env file outside git.

| Platform | Template |
| --- | --- |
| Linux systemd | `deploy/branch-worker/systemd/posmain-branch-worker.service.example` |
| macOS launchd | `deploy/branch-worker/launchd/com.posmain.branch-worker.plist.example` |
| Windows Task Scheduler | `deploy/branch-worker/windows/posmain-branch-worker-task.xml.example` plus `deploy/branch-worker/windows/posmain-branch-worker-wrapper.ps1.example` |
| Docker Compose | `deploy/branch-worker/docker-compose.branch-worker.yml.example` with a strict preflight healthcheck |

Minimum environment values should live outside git, for example in `/etc/posmain/branch-worker.env`, `.env.branch-worker`, the launchd plist environment, or the Windows service account environment:

```bash
POSMAIN_ROLE=branch
POSMAIN_DB_HOST=127.0.0.1
POSMAIN_DB_PORT=3306
POSMAIN_DB_NAME=kody2
POSMAIN_DB_USER=posmain
POSMAIN_DB_PASS=change-me-outside-git
POSMAIN_BRANCH_UUID=replace-with-branch-uuid
POSMAIN_CLOUD_BASE_URL=https://cloud.example.com
POSMAIN_BRANCH_SYNC_SECRET=replace-with-protected-secret
POSMAIN_CLOUD_PULL_ENABLED=0
POSMAIN_MOOVA_APPLY_ENABLED=0
```

Before a hosted-to-branch recovery, stop the branch worker service and run `php tools/restore_branch_from_hosted.php --all` first. The dry-run prints the empty-target decision, manifest, event count, and confirmation token. Apply is accepted only when all phases are selected, generic cloud pull is disabled, the business database is empty, a fresh backup and stopped-worker acknowledgement are supplied, the dry-run values still match, and a new receipt path is provided.

Before enabling a service, verify:

```bash
php cli/branch_worker_daemon.php --list
php cli/branch_worker_daemon.php --preflight --strict
php tools/branch_go_live_readiness.php --json --env-file=/etc/posmain/branch-worker.env --backup-file=/absolute/path/to/posmain-backup.sql
php tools/branch_worker_status.php --json
php cli/branch_worker_daemon.php --once --only=moova_apply
```

For Windows, copy `posmain-branch-worker-wrapper.ps1.example` to `posmain-branch-worker-wrapper.ps1` after replacing paths. The wrapper runs `--preflight --strict` before the daemon loop so missing branch/cloud config stops the scheduled task early.

For Docker Compose, keep the healthcheck enabled. It runs `php cli/branch_worker_daemon.php --preflight --strict` with the same `.env.branch-worker` values and marks the worker unhealthy when required config or schema readiness is missing.

After enabling a service, use `docs/branch_worker_status.md` to interpret outbox backlog, Moova inbound/ack backlog, expired locks, and recent worker failures.
