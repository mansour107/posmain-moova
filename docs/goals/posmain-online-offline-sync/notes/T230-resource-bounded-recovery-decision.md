# T230 Resource-Bounded Recovery Decision

## Decision

Proceed with a recovery-only v2 contract. Keep automatic branch-to-cloud delivery unchanged. Manual cloud-to-branch recovery must use hosted current-state projections first, capture one accepted-inbox checkpoint, and then replay only events newer than that checkpoint. Full-history `sync_inbox` replay is retained only as an explicit legacy diagnostic source and must not be the default recovery path.

## Evidence

- `CloudBranchRestoreExportService` already exposes `cloud_snapshot` pages for menu, tables, orders, and operational domains, but `auto` currently chooses `sync_inbox` whenever any eligible historical event exists.
- `BranchRestoreFromHostedService` currently starts every phase at cursor zero, shares the selected source across phases, applies one event per transaction, and records only a final informational checkpoint.
- `cloud_orders` contains stable branch/order identifiers, status/date columns, current payloads, and child projection tables, so a bounded recent/open-order bootstrap is possible without changing normal event delivery.
- Operational projections already cover the required catalog, customer, drawer, inventory, recipe, purchasing, production, settings, link, and operator domains. The Focus House inbox rehearsal was dominated by historical order/financial events, not operational snapshots.

## Contract

1. Start a signed recovery plan and return a stable `snapshot_checkpoint` equal to the newest eligible processed/duplicate branch inbox id at plan creation.
2. Export `source=cloud_snapshot` using a named `recovery_profile=operational_v1` and the pinned checkpoint.
3. Restore all current masters and balances, all open business documents, active shift/drawer state, and recent closed orders required for local continuity. Default recent-order window: 31 days; explicit range is manifest-bound.
4. After snapshot pages complete, export `source=sync_inbox` beginning strictly after `snapshot_checkpoint` and apply only those increments.
5. Persist a manifest-bound recovery run and per-phase/page cursor so interruption resumes without requiring an empty target again. A non-empty target is accepted only when it contains the exact incomplete recovery run id, branch UUID, manifest hash, profile, and checkpoint.
6. Keep full historical data in the cloud. Historical hydration is separate, optional, low-priority, and never blocks shop reopening.

## Resource Budgets

- One recovery writer and one HTTP request at a time; no parallel phase or entity replay.
- Default page size 25; hard maximum 100.
- Maximum decoded response body 8 MiB; fail closed before unbounded buffering.
- One event transaction at a time and one durable cursor update per successful page.
- Default 50 ms pause between pages, configurable from 0 to 2,000 ms for recovery only.
- PHP recovery worker target under 96 MiB; hard CLI memory limit 128 MiB.
- Temporary files limited to the compressed snapshot/receipt and never a second full-history event archive.
- Local tests use small fixtures. Performance/volume proof runs only in an isolated hosted database/service with explicit CPU, memory, and I/O limits.

## Impact Map

- API/auth: extend the existing signed restore export contract with version/profile/checkpoint/window fields; v1 signatures and current branch worker stay valid.
- Database: add a small local recovery-run/checkpoint table; additive migration only. Hosted projection schemas remain authoritative.
- Services: source selection, snapshot filters, incremental cursor, response-size guard, pacing, and resumable page commits.
- CLI/operator: dry-run remains default; apply remains manual, empty-target/backup/confirmation guarded; add explicit operational profile and resume token/run id.
- Monitoring: receipt records profile, checkpoint, cursors, retries, bytes, elapsed time, peak memory, and reconciliation.
- UI: no automatic reverse-sync UI or cashier-path change. Recovery remains an administrator maintenance action.
- Integrations: normal Moova, outbox, images, reporting, and hosted apply behavior are unchanged; image hydration remains background-only after core reconciliation.

## Compatibility and Failure Rules

- Do not change the branch-to-cloud worker cadence, batches, payloads, or accepted decisions.
- Preserve `sync_inbox` export for audit/legacy use but require an explicit source; `auto` recovery must select v2 snapshot behavior.
- A changed checkpoint/profile/window/manifest blocks resume.
- Non-retryable authentication and contract errors fail immediately; transient HTTP errors retain the bounded retry fix from T229.
- Projection rows may change while paged because ids are stable; the pinned post-checkpoint incremental pass converges the restored target to the latest accepted state.
- No migration or restore may clear a non-empty normal shop database.

## Smallest Worker Slice

First implement the versioned plan/source/checkpoint contract, explicit bounded client options, and lightweight contract tests. Do not yet add status-specific operational filtering or deploy until the versioned contract tests prove backward compatibility. The next Worker may touch only the restore endpoint/service/client/CLI, additive schema migration, and focused recovery tests named on its task card.
