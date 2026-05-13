# Sync Recovery Tool

`tools/sync_recovery_tool.php` is a local operator CLI for safe queue recovery after worker crashes, outage drills, or service restarts.

It is dry-run by default. It reports what would change unless `--apply` is supplied.

## Read Current Recovery Candidates

```bash
php tools/sync_recovery_tool.php
php tools/sync_recovery_tool.php --json --limit=50
php tools/sync_recovery_tool.php --branch-uuid=00000000-0000-4000-8000-000000000000
```

The report includes:

- expired `sync_outbox` rows still stuck as `syncing`
- failed `sync_outbox` rows
- expired Moova inbound rows still stuck as `processing`
- failed Moova apply rows
- failed Moova cloud acknowledgement rows

## Dry-Run Recovery Actions

Run every recovery action as a preview:

```bash
php tools/sync_recovery_tool.php --all
```

Preview selected actions:

```bash
php tools/sync_recovery_tool.php --release-expired-outbox-locks --requeue-failed-outbox
php tools/sync_recovery_tool.php --release-expired-moova-locks --requeue-failed-moova-apply --requeue-failed-moova-ack
```

## Apply Recovery Actions

Apply only after reading the dry-run output:

```bash
php tools/sync_recovery_tool.php --all --apply
```

Actions:

- `--release-expired-outbox-locks`: moves expired `sync_outbox.status=syncing` rows back to `pending`, clears lock fields, and makes them retryable now.
- `--requeue-failed-outbox`: moves `sync_outbox.status=failed` rows back to `pending`, clears lock/error fields, and makes them retryable now.
- `--release-expired-moova-locks`: moves expired `moova_pos_inbound_events.status=processing` rows back to `received` and clears lock fields.
- `--requeue-failed-moova-apply`: moves `moova_pos_inbound_events.status=failed` rows back to `received`, clears lock fields, and clears the local apply error message.
- `--requeue-failed-moova-ack`: clears failed Moova cloud acknowledgement status/error so the ack worker can retry terminal rows.

## Boundaries

This tool modifies only recovery fields in `sync_outbox` and `moova_pos_inbound_events`.

It does not modify POS orders, cloud snapshot tables, `sync_conflicts`, cashier UI, Moova widget endpoints, service files, or hosted systems.
