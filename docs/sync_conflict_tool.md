# Sync Conflict Tool

`tools/sync_conflict_tool.php` is a local operator CLI for the `sync_conflicts` table.

Use it when branch/cloud or Moova idempotency checks record a conflict and an operator needs to inspect or close the conflict after deciding the correct business outcome.

## Read-Only Listing

List open conflicts:

```bash
php tools/sync_conflict_tool.php
```

JSON output:

```bash
php tools/sync_conflict_tool.php --json
```

Filter by status, branch, id, or result size:

```bash
php tools/sync_conflict_tool.php --status=all --limit=50
php tools/sync_conflict_tool.php --branch-uuid=00000000-0000-4000-8000-000000000000
php tools/sync_conflict_tool.php --id=123 --include-payloads --json
```

Default listing does not include full payload JSON. Use `--include-payloads` only when the operator needs to compare local and remote payloads.

## Resolve One Conflict

Preview the action first:

```bash
php tools/sync_conflict_tool.php --resolve=123 --resolution-status=ignored --notes="Known duplicate retry" --dry-run
```

Apply the resolution:

```bash
php tools/sync_conflict_tool.php --resolve=123 --resolution-status=ignored --notes="Known duplicate retry"
```

Allowed resolution statuses:

- `ignored`
- `resolved`
- `remote_rejected`
- `local_rejected`

The tool only updates a single `sync_conflicts` row and only when its current `resolution_status` is `open`.

## Boundaries

This tool does not modify `sync_outbox`, `sync_inbox`, `moova_pos_inbound_events`, POS orders, cloud snapshots, or Moova widget state.

It does not decide who is correct in a conflict. The operator must compare payloads or business evidence first, then choose a resolution status deliberately.
