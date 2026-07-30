# Online/Offline Mock E2E

`tools/e2e_mock_online_offline_sync.php` is the local pre-go-live proof for POSMAIN online/offline sync behavior.

It starts two temporary mock HTTP servers:

- mock cloud server: receives branch `sync_outbox` pushes and returns receive-only, shadow-apply, or live-apply results;
- mock branch server: receives cloud Moova events and returns branch acknowledgements.

The harness creates a generated `posmain_sync_e2e_*` database on an explicitly
local MySQL host and drops the complete database in cleanup. It does not accept
a standing database name, use production credentials, install services,
restore data, or talk to a live shop.

## Command

```bash
POSMAIN_TEST_MYSQL_PORT=3307 php tools/e2e_mock_online_offline_sync.php
```

For usage and the scenario list:

```bash
php tools/e2e_mock_online_offline_sync.php --help
```

## Proof Points

The run covers these proof points. The cloud/branch drop first-attempt details are folded into the matching recovery result so the final JSON stays compact:

- `cloud_receive_only`: cloud receives branch events but does not apply snapshots; branch outbox is marked synced.
- `cloud_shadow_apply`: cloud accepts and applies shadow snapshots while reports remain untrusted.
- `cloud_live_apply`: cloud accepts and marks report output trusted.
- `online_cloud_down_first_attempt`: branch cannot reach cloud, so the outbox event stays retryable.
- `online_cloud_back_retries_failed_event`: after cloud returns, the failed event is retried and synced.
- `branch_worker_crash_lock_expires_and_reclaims`: an expired `syncing` outbox claim is reclaimed and delivered.
- `offline_branch_down_first_attempt`: cloud cannot reach branch for a Moova event, so the event remains pending with an attempt recorded.
- `offline_branch_back_cloud_event_delivered_and_acked`: after branch returns, the pending event is delivered and acknowledged.

## Reading The Result

A successful run exits with code `0` and prints JSON like:

```json
{
  "run_id": "e2e:...",
  "mock_servers": {
    "cloud": "http://127.0.0.1:12345",
    "branch": "http://127.0.0.1:12346"
  },
  "results": [
    {"name": "cloud_live_apply", "pass": true}
  ],
  "report_path": "/tmp/posmain-sync-e2e-.../report.json"
}
```

If any scenario has `"pass": false`, treat the branch/cloud outage behavior as not ready for rollout until the failed details are understood.

## Production Boundary

This is a controlled local proof. It should be run before a real shop rollout, but it does not replace:

- real branch UUID/cloud URL/secret provisioning;
- a verified database backup file;
- installing and supervising the branch worker service;
- live cashier acceptance smoke for the target branch.
