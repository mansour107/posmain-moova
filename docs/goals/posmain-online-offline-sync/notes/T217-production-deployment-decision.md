# T217 production deployment decision

## Decision

Authorize one bounded production maintenance Worker. The T213 exact-label blocker is cleared: the fresh T216 artifact is manifest-bound, its functional delta is exact, every active tenant independently dry-runs the same 113 additive and 35 reviewed rewrite labels, and there are zero destructive or ambiguous statements.

This authorization deploys hosted code/schema parity only. It does not yet authorize repurposing the unrelated local kody2/Railway worker, replaying superseded dead rows, enabling automatic cloud-to-branch behavior, or treating the full offline/cloud objective as complete. Focus House worker provisioning, controlled backlog drain, initial authoritative seed and reconciliation remain later gated tasks.

## Release boundary

Create one deployable Git commit/branch from exactly the 103 non-Goal-Maker paths in the T216 overlay manifest. Do not commit `docs/goals/posmain-online-offline-sync/**`. Prove every committed runtime/test/tool file hash equals the T216 staged artifact, push the exact commit, fetch it on hosted, and record both commit identities before maintenance.

The hosted checkout is a clean physical Git directory and nginx targets `/var/www/posmain/current`. Promote with an exact detached Git checkout rather than copying the dirty worktree. Preserve ignored `.env`, `uploads`, `logs`, `backup`, and `var`; hash/size/mode them before and after. Stop if any non-ignored untracked path would collide with the release.

## Authorized maintenance sequence

1. Revalidate the T216 payload checksum, 140-file manifest, 103-file release boundary, four-tenant 113/35 partition, live commit, services, free space, reverse-direction flags, and absence of schema drift.
2. Create fresh timestamped backups outside the web root for router configuration, all four tenant databases, live runtime paths, and the current Git identity. Use restrictive permissions, exact hashes, nonzero sizes and dump-completion checks. Restore-rehearse the smallest tenant into a uniquely named disposable database and require exact objects, schema, row counts and integrity before the first database write; remove and verify absence of that database.
3. Confirm the release commit is fetchable and its 103 manifest-selected file hashes equal T216. Confirm checkout will preserve ignored runtime paths.
4. Stop PHP 8.5 FPM for the maintenance window. Keep nginx and MariaDB active. Recheck that there is no hosted sync worker/timer/cron, no long transaction, metadata-lock waiter or table in use, and no data/schema drift since the backup. If drift exists, take and verify a replacement backup before continuing.
5. For each tenant, invoke the staged runner with `--apply`, that tenant's verified backup, `--scope=additive`, and the exact current 113-label set. After each apply, require the only pending set to be the exact audited 35 rewrite labels.
6. Take a second verified tenant backup of the additive state. For each tenant, invoke the staged runner with `--apply`, the additive-state backup, `--scope=reviewed`, and the exact 35-label rewrite set. The label list contains only audited `ALTER ... MODIFY/CHANGE` rewrites, so `--allow-destructive` must not be supplied; stop if the planner newly requires it.
7. Before code promotion, require zero pending sync migrations, no `started` ledger entries, checksum-consistent applied receipts, exact target column definitions, preserved historic NULL counts, approved six-decimal conversion bounds, unchanged business row counts, and clean database integrity. Any failure triggers database restoration while FPM remains stopped.
8. Checkout the exact release commit in `/var/www/posmain/current`. Recheck all 103 release file hashes and unchanged ignored runtime identities. Resolve hosted role/config with `cloud_pull_enabled=false` and `cloud_to_branch_publish_enabled=false`. Do not change secrets or branch credentials.
9. Run critical PHP lints, production profile, migration-zero-pending, restore safety, CLI/bootstrap and read-only report checks. Start PHP-FPM only after they pass.
10. Require nginx, MariaDB and PHP-FPM active; `api/health.php`, `api/ready.php`, login/read-only pages and `version.json` healthy; sync receive GET still fail-closed with 405; unauthenticated restore export remains rejected; status remains unauthorized; router/tenant connectivity, direction flags, queues and worker inventory remain sane. Observe logs without exposing credentials.

## Rollback boundary

- Before PHP-FPM restarts, any failure means keep it stopped, checkout the old commit, restore every changed tenant from its pre-maintenance backup, restore runtime paths only if their verified identities changed, validate, then restart the old application.
- After PHP-FPM restarts, any failed smoke check means stop it immediately. Determine whether new application writes occurred before restoring databases; never overwrite post-enable writes blindly. With no post-enable writes, restore the old commit and pre-maintenance databases. With any writes, stop and preserve both states for a forward repair or explicit reconciliation.
- Never perform partial tenant rollback while serving mixed code/schema states.

## Hard stops

- Artifact, release path, hosted commit, runtime path, tenant count, migration label, schema or configuration drift.
- Missing, unreadable, incomplete, mismatched or non-restorable backup.
- Any ambiguous/destructive/new migration; any need for a broad unlabeled scope.
- Any historical total/tax/profit NULL fabrication, unapproved rounding/overflow, row loss, integrity failure or stuck migration receipt.
- Any checkout conflict or inability to preserve `.env`, uploads, logs, backup and var.
- Any automatic cloud-to-branch pull/publish becoming enabled.

This sequence avoids unexpected behavior by isolating all schema/code incompatibility behind a stopped PHP runtime, preserving the current clean checkout and ignored runtime state, using exact manifest and label allowlists, requiring two rollback points around rewrites, and refusing to serve traffic until the complete schema/code/config contract passes.
