# T232 resumable recovery design

## Decision

Use an additive local `sync_branch_restore_runs` table as the only authority for resuming a partially applied empty-shop recovery. Do not add a generic non-empty restore override.

## Safety binding

Each run is bound to one run UUID, branch UUID, contract version, `cloud_snapshot` source, recovery profile, snapshot checkpoint, immutable history cutoff, dry-run manifest, expected event count, confirmation token, and backup SHA-256. A resume must match every binding and an incomplete run status before any non-empty target can be accepted.

## Progress and concurrency

- Store one cursor for each ordered restore phase plus cumulative fetched/mirrored/skipped/failed counts.
- Persist progress only after a complete page succeeds. An interrupted page is replayed from its prior cursor and relies on existing domain idempotency/stale guards; hosted staging must prove this across every recovery domain.
- Hold one database advisory lock for the branch/run while apply or resume is active.
- Mark completion only after cumulative reconciliation equals the manifest-bound expected count.

## Compatibility map

- API: no v1 or v2 hosted wire change is required for the state-table slice.
- Forward sync: no outbox worker, cadence, payload, or hosted ingestion change.
- Database: one additive sync-control table; it is excluded from business emptiness checks.
- Restore safety: initial apply still requires an empty business database. Only an exact incomplete run may later resume a now-partial database.
- CLI: integration will add explicit run/resume identifiers; dry-run and normal apply arguments remain fail-closed.
- Integrations/UI: no Moova, cashier, reporting, image, or automatic reverse-sync behavior changes.

## Increment plan

1. Add the state table and a focused lifecycle service with contract tests only.
2. Integrate initial apply, page progress, advisory lock, exact resume authorization, and CLI options.
3. Test interruption after a completed page, metadata tampering, concurrent writer refusal, non-empty restore refusal, and final reconciliation.
4. Run volume/interruption proof only on hosted staging.

