# T196 Redacted Live Preflight

Checked at 2026-07-16 UTC. All host, HTTP, database and local-branch inspection was read-only. No code, service, migration, queue, credential or business row was changed.

## Verdict

Deployment remains blocked, but the path is bounded. The new implementation is locally certified; the current Focus House live topology is still on the older sync contract and is not actively draining the Focus House branch queue.

## Topology

### Hosted ERP

- HTTPS: `https://erp.withmoova.com`, nginx, PHP 8.5.4, MariaDB 11.8.6.
- Code root: `/var/www/posmain/current`, clean `main` checkout at `0183eb57ac949497d23c62cbbcd7f145a3e32c0b`.
- Local `main` is 18 commits ahead before the current working-tree implementation; 1,024 tracked paths differ between the hosted commit and local HEAD.
- Nginx denies dotfiles and private code/data directories; health is 200, receive endpoint rejects GET with 405, restore endpoint rejects unauthenticated/incomplete GET with 400, status endpoint is 403, and `version.json` is 200.
- Hosted role is `cloud`, router is enabled, automatic reverse publish is disabled, but the old resolved `cloud_pull_enabled` value is true. It is not a hosted worker action today, but it must be false in the release profile.
- Focus House router registration is active. Its redacted branch fingerprint matches the local Focus House identity, and its encrypted sync secret is present.

### Focus House branch on this Mac

- Local app database is `focushouse`; redacted branch fingerprint matches the hosted route.
- Local app resolves the correct Hetzner cloud URL, but no usable Focus House branch secret is resolved in the ordinary app process.
- Local app resolves `cloud_pull_enabled=true`, contrary to the approved manual-only hosted-to-branch policy.
- The currently running supervisor is not the Focus House/Hetzner worker. Its env file targets `kody2`, a different redacted branch identity, and a Railway cloud URL. It must not be repurposed or stopped as part of Focus House rollout without separately identifying its owner.
- There is no currently proven supervised Focus House branch worker service.

## Live sync health

### Focus House local outbox

- `47,828` synced rows.
- `9` pending `table.updated` rows created 2026-07-06, with zero attempts, no retry delay and no lock. They are not being drained because the running supervisor targets another branch/database.
- `7` old dead `order.saved` rows are explicitly marked as superseded by a migration rebuild snapshot; retain them as audit evidence and do not replay blindly.

### Focus House hosted inbox

- `47,553` processed and `275` exact duplicates; no recorded conflicts.
- Latest accepted event is 2026-07-04. The router last-seen timestamp for Focus House is 2026-07-05.
- Historical event types cover categories, menu items/images/availability, tables, orders, limited order payments/refunds/split payments, inventory movements/balances, and pulse types.
- Historical live data does not contain the newly implemented customer, drawer/shift, full financial, fulfillment, inventory-count/accounting, production or procurement snapshot families. Existing authoritative rows therefore need a guarded initial bulk seed after code/schema parity, not only future automatic capture.

## Code and schema parity

- Hosted receive and restore endpoint wrapper hashes match local, but the services behind them do not.
- Hosted hashes differ for restore export, operational snapshot production, operational projection, schema manager and restore CLI.
- `SyncProjectionVersionGuard.php` is absent on hosted.
- Focus House hosted schema lacks `sync_projection_versions`, `drawer_session_close_summaries`, and required `sync_revision` columns on inventory counts, purchase orders, production batches and drawer sessions.
- The hosted migration ledger is the legacy one-row shape without the current `status` column.
- The local Focus House strict preflight reports nine pending schema changes, including projection versions and typed revision columns. This is current-local evidence only; a staged release dry-run must enumerate the exact hosted pending statements before apply.

## Backup and host hygiene

- The newest observed Focus House SQL backup is dated 2026-06-27 and is not fresh enough for deployment.
- No backup automation reference was found in cron or systemd paths inspected.
- Disk space is healthy: about 29 GB available.
- Live `.env` is mode `0644` in the code root. Nginx blocks dotfiles, but OS permissions should be tightened before or with rollout without changing values.
- A zero-byte, anomalously named file from 2026-06-19 exists at the live code-root top level. It was fingerprinted without exposing its name and should be quarantined only in an approved maintenance action.

## Safe staged rollout shape

1. Freeze an explicit release manifest from the current implementation; do not copy the entire dirty worktree or unrelated files.
2. Stop before mutation unless a fresh Focus House backup and a separate router/config backup exist, with hashes, sizes and a restore rehearsal target.
3. Stage the release outside the active web root and run lint, focused sync gates, redacted config validation, and migration dry-runs against exact tenant databases.
4. Require the production profile to keep cloud pull and reverse publish off.
5. Apply additive migrations with the fresh backup receipt, then atomically promote hosted code and smoke authenticated receive/status/restore-export behavior.
6. Provision a separate Focus House branch-worker env/service pointing to Hetzner; do not modify the unrelated kody2/Railway supervisor.
7. Drain only the nine normal pending table events after parity. Do not replay the seven superseded dead rows.
8. Run a guarded initial bulk push of current authoritative domains, reconcile counts/hashes, then leave automatic branch-to-hosted running.
9. Keep hosted-to-branch automatic pull disabled. Test recovery only into an empty disposable database with the manifest/backup/worker-stop gates.

## Recommendation

The next Judge should authorize one bounded staged hosted-release task: create fresh backups, assemble a scoped release artifact outside the live root, run exact tenant migration dry-runs and stop for review before any migration apply or code promotion. Branch service provisioning and initial bulk seed should remain a later stage after hosted parity is proven.

