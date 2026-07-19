# T228 Focus House manual recovery rehearsal decision

## Decision

Proceed with one guarded restore rehearsal into a uniquely named disposable empty local database. Do not stop, repoint, or otherwise change the live `focushouse` database or its isolated automatic shop-to-cloud worker.

The real hosted dry-run is complete and internally consistent. It authenticated as Focus House branch `1e608952-0a07-40d4-bfef-3107f0c229bc`, selected `sync_inbox`, and exposed exactly 47,837 accepted events with zero skipped or failed:

- menu: 2,010 events in 21 pages;
- tables: 144 events in 2 pages;
- orders and their typed financial state: 44,820 events in 449 pages;
- operational data: 863 events in 9 pages.

That total equals the 47,837 locally synced outbox rows and the hosted accepted inbox total already reconciled during automatic activation. The generated manifest is bound to the branch identity, four complete phases, per-phase source/count/page facts and the exact total. An apply reruns the dry-run and refuses if the manifest or count changes.

The same dry-run proved the live-target guard: `business_database_empty=false`, it listed the non-empty business tables, and `apply_allowed=false`. Generic cloud pull is disabled. Apply is not exposed through the web UI, defaults off in the CLI, supports only all phases and empty scope, requires explicit worker-stopped acknowledgement, rejects an active PID file, requires a fresh readable backup, exact manifest/count/token, and an append-only receipt path.

## Compatibility and regression map

- API/auth: recovery uses the authenticated read-only restore-export endpoint and existing branch HMAC identity. No hosted write endpoint is involved.
- Database: only a new disposable database may be created. Its schema must be copied without business rows from the current migrated Focus House schema, then verified empty through the same guard before apply.
- State and timing: live automatic branch-to-hosted sync stays active. If a new accepted event appears between dry-run and apply, the manifest/count gate must block rather than restore an older plan.
- Ordering: hosted rows whose projector decision was explicitly stale are excluded; accepted and exact-duplicate history stays ordered by hosted inbox ID. Projection-version guards remain active during restore.
- Data coverage: the four phases carry menu/catalog, tables, order/payment/receipt/journal facts, and typed operational bundles for drawers/shifts, refunds, customers/fulfillment, inventory, accounting, counts, production and procurement. Deferred cross-branch transfer workflow documents, generic manual legacy journals, secrets/sessions/leases/caches and raw provider payloads remain outside the certified restaurant-POS recovery contract.
- Live isolation: the disposable target gets an explicit database-name override while branch UUID, hosted URL and secret remain the existing protected values. The live database name must never appear as the target, and its row/queue fingerprints plus service PID/status must be captured before and after.

## Authorized Worker gates

1. Remove the PHP 8.5-only `curl_close()` deprecation that polluted otherwise machine-readable CLI output; do not change HTTP semantics, auth, timeouts or response handling. Run focused restore contracts.
2. Capture live database, queue and worker health fingerprints without exposing credentials.
3. Create a uniquely named disposable database and load schema/triggers only from current migrated `focushouse`; verify zero guarded business rows and exact Focus House branch identity/config resolution.
4. Create a fresh protected schema backup of that empty target, record its hash/mode, and use a nonexistent PID path plus explicit stopped-worker acknowledgement scoped only to the disposable target.
5. Run a fresh all-phase dry-run with warnings suppressed only by the code correction. Require the same 47,837 accepted events unless the live forward worker has legitimately added newer events; any drift must be understood and the newest manifest used.
6. Apply with the exact dry-run manifest, event count and confirmation token to the disposable database only. Require zero failures/skips and `reconciliation.ok=true`; retain an append-only protected receipt.
7. Compare representative and aggregate recovery counts/hashes for menu, tables, orders/lines, payments/receipts, journals, drawers/shift data, customers/fulfillment, inventory and enabled operational masters against hosted projections or current authoritative facts. Verify excluded secret/session/runtime state remains empty or sanitized.
8. Prove live `focushouse`, its outbox and the hosted inbox/projections did not change because of restore; ordinary new shop-to-cloud events, if any, must be separately identified as normal forward activity.
9. Drop only the uniquely named disposable database after evidence capture. Keep the protected receipt and rehearsal note; never delete or alter live backups.

Stop immediately if target identity/name is ambiguous, the empty guard is not exact, the hosted plan drifts without explanation, any restore event fails/skips, canonical reconciliation fails, a secret appears, or live worker/queue health changes unexpectedly.
