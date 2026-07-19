# T132 Production-Readiness Plan

## Verdict and Goal Reset

The existing implementation proves that durable branch-to-cloud delivery, idempotent receipt, cloud projections, automatic workers, outage retry, and a controlled hosted-to-branch stream are feasible. It is not yet production-ready for the actual goal: durable cloud protection of the whole shop, trustworthy remote management, and disaster recovery.

There are currently no live shops. This removes legacy rollout pressure and allows the event identity, authority, deletion, financial-history, and restore contracts to be corrected before production data depends on them.

## Reference Architecture

```text
Local business transaction
  -> local database commit + transactional outbox event
  -> supervised background worker
  -> authenticated HTTPS batch delivery
  -> cloud immutable inbox/event ledger
  -> validated per-aggregate ordered apply
  -> cloud operational/financial projections
  -> remote manager dashboard with freshness/trust status

Cloud administrative action
  -> authorized versioned command
  -> branch command inbox
  -> branch validation and local transaction
  -> canonical branch event back to cloud

Disaster recovery
  -> maintenance lock + local backup if available
  -> signed cloud restore manifest
  -> base snapshot restore
  -> ordered event replay
  -> counts/hash/reconciliation verification
  -> operator approval and branch activation
```

The cloud inbox/event ledger is the durable replication record. Cloud projection tables are rebuildable read models and must not be treated as the only recovery source. Encrypted database backups provide an additional independent recovery layer.

## Data Classification

### Tier A: critical append-only history

Must replicate automatically and atomically from branch to cloud:

- Order headers, lines, modifiers, notes, status transitions and cancellations.
- Payments, payment allocations, receipts, refunds, voids and reversals.
- Journal heads/entries and accounting bridge references.
- Drawer sessions, opening float, movements, pay-ins, expenses, safe drops, counts, close summaries, resolutions and temporary overrides.
- Shift lifecycle, cashier custody and manager handovers.
- Inventory movements, reservations, consumption, waste, adjustments, transfers, counts, purchase receipts and production movements.
- Manager approvals, overrides, security-sensitive actions and business audit events.
- Delivery/Moova lifecycle events and external-id mappings needed to recover state.

These records should not be updated through last-write-wins replication. Corrections are new events linked to the original event.

### Tier B: mutable operational state

Replicate using stable UUID plus monotonic revision and tombstone:

- Items, categories, prices, units, variants, modifiers and availability.
- Recipes and their versioned component sets.
- Tables, areas, registers, stores, towns and delivery zones.
- Customers and employees, with explicit privacy/sensitive-column exclusions.
- Payment-method configuration, printers and non-secret shop settings.
- Current inventory balances as derived snapshots, while movements remain the recovery truth.

### Tier C: files and large objects

- Item images, documents and other blobs use content hashes, object versioning, resumable upload and independent retention.
- Business events reference blob hashes; they do not embed unbounded binary payloads.

### Tier D: local-only or excluded

- Password hashes unless explicitly required for controlled identity recovery.
- Session data, CSRF tokens, private keys, branch HMAC secrets and transient caches.
- Device-local spool state and logs that have no operational/audit value.
- Raw request/response payloads containing unnecessary secrets or personal data.

## Correctness Contract

- Delivery guarantee: at-least-once transport with idempotent application.
- Identity: UUID for branch, aggregate, event and command; integer IDs remain local implementation details.
- Ordering: monotonic sequence per aggregate, assigned in the same local transaction as the business write.
- Apply rule: incoming sequence must be exactly next, or be recognized as a duplicate. Gaps are held; older revisions are stale and cannot update state.
- Event schema: versioned envelope with branch, tenant, aggregate UUID/type, sequence, event UUID/type/version, occurred/recorded timestamps, causation/correlation IDs, payload hash and optional tombstone.
- Delete rule: tombstone with sequence and retention metadata; no immediate replicated hard delete.
- Conflict rule: transactional domains have a single branch authority. Mutable cloud commands use expected revision and reject mismatches for operator resolution.
- Time rule: ordering never depends only on wall-clock timestamps. Server and branch clocks are monitored, but sequence controls correctness.
- Transaction rule: business data and outbox event commit together. Failed business transactions cannot produce deliverable events.

## Remote Monitoring Contract

The hosted manager experience should be read-only initially and include:

- Sales, orders, payments/refunds and payment-method mix.
- Open/closed shifts, drawer custody, expected/actual cash, discrepancies and resolutions.
- Inventory on hand, negative-stock conditions, movement history and attention items.
- Delivery/Moova status and operational exceptions.
- Approvals, overrides and audit trail.
- Branch online/offline state, last event, replication lag, oldest pending event and projection trust.

Every report response must expose `data_as_of`, branch last-seen, projection version and trusted/stale/degraded state. Financial dashboards reconcile from append-only financial events rather than mutable order totals alone.

## Restore Contract

### Full disaster restore

1. Provision an empty compatible database and replacement branch identity in maintenance mode.
2. Verify code/schema version compatibility.
3. Select a signed restore manifest and base backup/snapshot.
4. Restore the base, then replay later events in aggregate order.
5. Restore document counters, UUID maps, external mappings and blob references.
6. Rebuild derived projections and balances.
7. Compare row counts, event high-water marks, financial trial balance, inventory totals and manifest hashes.
8. Produce a restore receipt and require operator approval before normal writes begin.

### Partial repair

- Dry-run by default and select entities/event ranges explicitly.
- Back up affected local rows first.
- Refuse to overwrite a newer local revision.
- Apply compensating events where financial history is involved.
- Record who approved the repair and exactly what changed.

### Backup policy targets

- Define final business RPO/RTO with the owner before certification.
- Initial engineering target: event replication RPO under five minutes while online, encrypted daily full backups plus frequent incremental/binlog protection, and a rehearsed complete restore in under four hours for a normal shop database.
- Retention, regional copy and deletion policy must be documented and tested.

## Delivery Phases

### Phase 0: architecture and coverage freeze

- Approve authority, direction, event envelope, UUID, sequence, tombstone and restore contracts.
- Build an authoritative domain/write-surface matrix with include/exclude direction, sensitivity, retention, recovery role and automatic hook evidence.
- Define compatibility/migration strategy for existing sync tables and queued events.

Exit: Judge-approved architecture decision record and zero unclassified critical write surfaces.

### Phase 1: transport and apply correctness

- Add aggregate sequence allocation and cloud high-water tracking.
- Make snapshot/projector writes conditional on newer revisions.
- Add gap quarantine, stale acknowledgement and schema-version rejection.
- Replace generic hard-delete replication with tombstones.
- Separate cloud command processing from branch event replication.

Exit: duplicate, reorder, delayed retry, gap, concurrent command and delete/recreate tests pass.

### Phase 2: complete critical-domain capture

- Wire Tier A and Tier B writes to the transactional outbox.
- Introduce typed event builders/projectors rather than unrestricted generic table mirroring for critical domains.
- Add privacy filtering and payload size limits.
- Backfill through manifest-driven snapshot events without overwriting newer revisions.

Exit: automated coverage test proves every critical write path produces the expected event inside its transaction.

### Phase 3: cloud ledger and trusted projections

- Keep an immutable cloud event ledger and projection checkpoints.
- Build order, financial, drawer/shift, inventory, approval/audit and delivery projections.
- Add reconciliation jobs and projection rebuild commands.
- Mark dashboards trusted only after projection and branch high-water reconciliation.

Exit: cloud reports reconcile to a seeded branch dataset across full business-day scenarios.

### Phase 4: worker automation and operational resilience

- Install one branch-scoped supervised worker per shop.
- Add bounded batches, exponential backoff with jitter, circuit breaking, lock expiry, backlog limits and disk-capacity protection.
- Guarantee the POS remains usable during prolonged outages.
- Add dead-letter and operator recovery workflows with audited actions.

Exit: 24-hour simulated outage, restart, network flapping and large-backlog drain tests pass without POS disruption or data regression.

### Phase 5: security and tenant isolation

- Per-branch credentials with rotation and revocation.
- Replay protection, request limits, payload validation and constant-time authentication.
- Tenant/branch routing isolation tests and least-privilege database/service accounts.
- Encryption at rest for backups and protected personal data.
- Security logging without leaking secrets.

Exit: threat-model review and automated cross-tenant/replay/rotation tests pass.

### Phase 6: backup and restore implementation

- Create signed restore manifests, encrypted backups, snapshot/event export and controlled restore tooling.
- Implement empty-database restore, partial repair, resumability and verification receipts.
- Add scheduled restore drills using disposable databases.

Exit: a destroyed disposable branch is reconstructed and reconciles to the original high-water mark, accounting totals and inventory state.

### Phase 7: observability and remote administration

- Metrics and alerts for last seen, event lag, oldest pending, throughput, failures, dead letters, conflicts, disk space, backup age and restore drill age.
- Cloud manager dashboard freshness/trust banners and drill-down to branch/event issues.
- Controlled cloud command flow for explicitly approved configuration operations.

Exit: alerts trigger in drills, dashboards degrade honestly during lag, and commands cannot overwrite unexpected revisions.

### Phase 8: deployment and certification

- Back up Hetzner and local databases.
- Deploy one identical reviewed commit and migrations to branch and hosted environments.
- Register a dedicated `focushouse -> erp.withmoova.com` worker and rotate test credentials.
- Run shadow ingestion, reconcile, canary critical domains, then enable continuous branch-to-cloud replication.
- Keep cloud commands disabled until their independent gate passes.
- Perform a full disposable restore from hosted artifacts before certification.

Exit: production-readiness audit maps every acceptance gate to current evidence and a rollback plan.

## Required Test Campaign

- Transaction rollback and process crash between business write and outbox handling.
- Duplicate HTTP delivery and duplicate worker execution.
- Older event delivered after newer event.
- Missing sequence followed by later sequence.
- Delete followed by recreate and delayed pre-delete update.
- Multi-day offline backlog and worker restart during drain.
- Cloud unavailable, slow, returning 429/500, or returning partial batch results.
- Branch clock skew and cloud clock skew.
- Credential rotation, revoked branch and replayed signed request.
- Cross-tenant branch UUID and payload attacks.
- Financial day with sales, split payments, refunds, pay-ins, expenses, safe drops, mismatch and manager resolution.
- Inventory day with purchase, transfer, consumption, waste, count and adjustment.
- Full empty-database restore and scoped partial repair.

## Rollout Safety

- No production deployment before local contract and failure tests pass.
- Backups precede migrations and configuration changes.
- Shadow ingestion precedes trusted reporting.
- Branch-to-cloud critical replication precedes any cloud-to-branch commands.
- A kill switch can stop cloud commands without stopping local POS or branch-to-cloud backup replication.
- Every phase has a rollback, reconciliation check and explicit stop condition.
