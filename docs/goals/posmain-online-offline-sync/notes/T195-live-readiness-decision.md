# T195 Live Readiness Decision

## Verdict

Run a read-only live preflight next. Do not deploy or apply migrations yet.

The disposable certification and focused typed restore gates prove the current local implementation contract. They do not prove that `erp.withmoova.com`, the hosted tenant database, or the shop branch currently run matching code, schema, identity, secrets, worker configuration, or service supervision.

## Current gap matrix

| Surface | Current evidence | Deployment gate |
| --- | --- | --- |
| Local branch-to-hosted transport | Green automatic delivery, outage retry, duplicate and stale/conflict proof | Preserve current worker flags and endpoint contract |
| Manual hosted-to-branch recovery | Green blank-target, manifest, backup and worker-stop guard | Confirm hosted export endpoint/code parity; never target the active shop DB |
| Required typed data | Focused money, customer, fulfillment, inventory, production, procurement and shift gates green | Confirm live schema contains the required sync and projection columns/tables |
| Secrets | Moova token hash excluded from transport/recovery | Confirm live secrets are outside git/web root without printing values |
| Hosted runtime | Not currently verified | Identify release path, code revision/manifest, PHP version, document root and service/container ownership |
| Hosted database | Not currently verified | Identify the exact tenant DB read-only; inspect migration ledger and pending dry-run only |
| Branch runtime | Not currently verified | Identify branch UUID, cloud URL, worker flags/status and DB schema without exposing secret values |
| Backup/rollback | Tools and runbooks exist; no current live receipt | Confirm backup destination, freshness policy, free space and restore-rehearsal evidence before apply |
| Release artifact | Local implementation is an uncommitted working-tree set on `main` | Build an explicit scoped manifest before any copy/deploy; do not deploy unrelated files |

## Compatibility and timing risks

- Hosted new code with an old schema can reject events or partially project them.
- Branch worker enablement before hosted parity can build retries or dead-letter rows.
- Enabling automatic cloud pull would violate the approved direction and risks replacing newer local state.
- Running a restore against a non-empty branch is destructive and remains prohibited.
- Copying the whole dirty worktree could mix unrelated or unfinished files into production.
- Applying migrations before a fresh backup and dry-run would remove the rollback boundary.

## Selected next action

Perform one read-only live preflight over SSH and local branch inspection. Record only redacted configuration presence/hashes, code/schema/service versions, queue counts, recent worker health, endpoint reachability, migration dry-run output, backup metadata, and exact deployment topology.

The preflight must not deploy, pull code, install packages, restart services, run migration apply, replay events, restore data, rotate secrets, enable workers, or print credential values.

## Stop conditions

- The exact tenant database, code root, branch identity or service owner cannot be proven.
- Any command would mutate live state.
- A secret would need to be printed or copied into the repository.
- The hosted server has pending failed/dead/conflicted events that require operator judgment.
- Backup/rollback evidence is absent before the later staged deployment.

