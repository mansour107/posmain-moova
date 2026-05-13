# Branch Worker Status

`tools/branch_worker_status.php` is a read-only operator check for the branch worker daemon.

Use it after service start, after cloud/branch outages, and during rollout support to answer:

- are branch `sync_outbox` rows pending, failed, or stuck in expired `syncing` locks?
- are Moova inbound rows waiting to apply or waiting for cloud ack?
- did any worker recently log a failed run?

## Commands

Human-readable report:

```bash
php tools/branch_worker_status.php
```

JSON report for scripts:

```bash
php tools/branch_worker_status.php --json
```

Exit non-zero when queue or worker-log problems are detected:

```bash
php tools/branch_worker_status.php --json --fail-on-problems
```

Limit recent error/log rows:

```bash
php tools/branch_worker_status.php --json --limit=20
```

Treat only failed worker logs from the last 10 minutes as current health problems:

```bash
php tools/branch_worker_status.php --json --recent-minutes=10
```

Include all historical failed worker logs in the health result:

```bash
php tools/branch_worker_status.php --json --recent-minutes=0
```

## Protected HTTP Status API

For dashboards or a local admin panel, the same read-only report is available at:

```text
GET /api/sync/status.php?limit=10&recent_minutes=60
Authorization: Bearer <POSMAIN_SYNC_STATUS_TOKEN>
```

The endpoint also accepts `X-POSMAIN-STATUS-TOKEN: <token>` for simple local probes.

Required behavior:

- `POSMAIN_SYNC_STATUS_TOKEN` must be set before the endpoint is exposed. If it is missing, the endpoint returns `status_token_not_configured` with HTTP 503.
- Missing or wrong tokens return HTTP 403.
- `limit` is clamped to `1..50`.
- `recent_minutes` is clamped to `0..10080`.
- `fail_on_problems=1` returns HTTP 503 when the report is reachable but unhealthy.
- The HTTP response uses the same JSON shape as `php tools/branch_worker_status.php --json`, adds `api: sync_status`, and removes the database username from the API payload.

## Reading The Report

Important fields:

- `healthy`: false when the tool detects stuck or failed work.
- `recent_minutes`: the worker-log failure window used for current health.
- `checks.sync_outbox.retryable_due`: branch-to-cloud events ready to send.
- `checks.sync_outbox.expired_syncing_locks`: rows that were claimed by a worker and should be reclaimed.
- `checks.moova_inbound.pending_apply`: cloud-to-branch Moova events waiting for local apply.
- `checks.moova_inbound.pending_cloud_ack`: local Moova results waiting to be acknowledged back to cloud.
- `checks.worker_logs.recent_failed`: failed daemon/worker runs inside the configured `recent_minutes` window.

## Operator Triage

- If `database_unreachable` appears, check the DB service and env file before restarting workers.
- If `outbox_retryable_due` rises after cloud downtime, run or restart `cli/branch_worker_daemon.php`; rows should move to `synced` once cloud returns.
- If `outbox_expired_syncing_locks` is non-zero, a worker may have crashed after claiming rows; the daemon should reclaim them on the next cycle.
- If `moova_pending_apply` rises, keep `POSMAIN_MOOVA_APPLY_ENABLED=0` until cashier acceptance is complete, then run the apply worker.
- If `moova_pending_cloud_ack` rises, check cloud reachability and the Moova ack worker.
- If `recent_worker_failures` appears, inspect `sync_worker_logs.metrics_json` for the failing worker details. If the shop is recovering after old outage tests, rerun with a smaller `--recent-minutes` window to distinguish stale history from current failures.

## Boundaries

This tool and status endpoint never write sync rows, install services, fix queues, restore backups, or change worker behavior. They report current state only.
