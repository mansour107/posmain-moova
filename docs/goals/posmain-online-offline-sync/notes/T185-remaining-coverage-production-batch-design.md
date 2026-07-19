# T185 remaining coverage audit after inventory counts

## Decision

Implement `production_batches` plus the complete current `production_batch_lines` set as the next lean authoritative aggregate.

Production is the smallest remaining workflow with both remote-management and disaster-recovery value. `ProductionBatchService` owns draft creation, commit, and draft cancellation; the aggregate is confined to one branch and store; lines are created only during commit and are never edited or deleted. T172 and T182 already preserve committed input/output stock effects and recipe valuation journals, but they do not preserve the planned batch, actual yield, variance reason, operator evidence, or the exact input/output/variance line document.

The generic `operational_row` path is not sufficient for this guarantee. It is queued only by the explicit supported-data push, sends parent and child rows separately, derives versions from second-resolution timestamps, and upserts rows without aggregate validation. The automatic worker only drains rows already in the outbox. A typed aggregate captured by the authoritative writer is therefore required for automatic, ordered, recoverable production monitoring.

## Current remaining-domain matrix

| Domain | Existing recoverable facts | Remaining authoritative gap | Decision |
| --- | --- | --- | --- |
| Production batches | Input/output movements, balances, availability consequences, and recipe valuation journal. | Draft plan, recipe/output identity, planned versus actual yield, variance reason, status, actors/times, and exact production lines. | **Implement next** as one versioned parent-plus-lines aggregate. |
| Purchase orders and receipts | Receipt/return movements, balances, and inventory accounting journals; generic manual row export exists. | Open obligation, approval, received progress, supplier/invoice context, receipt document and lines. | Required later, but one receipt mutates a purchase order owned by another service, so safe atomic coverage spans two related aggregates. |
| Inventory transfers | Send/receive movements and balances; generic manual row export exists. | Open custody, destination acceptance, partial receipt, variance and cancellation workflow. | Required later; defer because authority crosses source and destination branch identities. |
| Catalog and operational masters | Menu composites and generic row domains cover several rows during explicit bulk push. | Automatic writer capture and strict stale-safe contracts remain uneven for units, variants, categories, stores/registers/areas, availability and payment methods. | Required before production declaration; split by writer ownership rather than one generic replication slice. |
| Manual accounting | POS/order, inventory, recipe and refund journal families have typed coverage. | Manual vouchers and other legacy journal writers. | Required later as one financial writer-family slice after its mutation paths are enumerated. |
| Staff and RBAC | Employee rows can be manually exported with password excluded. | Sanitized staff identity and permission/grant state needed to operate a restored branch. | Required later under an explicit allowlist; passwords, PINs, tokens, sessions and credential hashes remain excluded. |
| Runtime/debug state | Logs, caches, sessions, availability cache, worker leases and device state are observable or rebuildable. | No authoritative business recovery requirement. | Exclude from restore payloads. |

## Production batch aggregate contract

1. Add only `production_batches.sync_revision BIGINT UNSIGNED NOT NULL DEFAULT 0` through the normal additive schema upgrade. A new draft is revision 1; commit or cancel advances it once to revision 2. A migrated revision-zero row advances to revision 1 on first typed capture.
2. Add a strict `production_batch_bundle` with one parent and every current line ordered by ID. Use native `batch_uuid` as aggregate UUID; event version, payload revision, and parent revision must match.
3. Parent allowlist: database ID and UUID, branch/store scope, recipe and output item IDs, planned/actual output quantities, status, started/committed timestamps, created/committed actors, variance reason, notes, created/updated timestamps, and revision. Line allowlist: ID, batch ID, type, item/unit IDs, planned/actual quantity, unit/total cost, linked movement ID, and creation timestamp.
4. Reject unknown fields, malformed UUID/scope/status/type/decimal/time/text values, duplicate line IDs, lines owned by another parent, and line movement references inconsistent with the batch. Exclude availability cache, current balances, duplicated movement/journal rows, credentials, request data, IP/user agent and raw provider payloads.
5. Capture draft creation only after the header exists. Capture commit after input/output movement and balance events, production journal, availability refresh, line creation, and final header update. Capture cancel after the successful status change. Every required capture remains inside the owning transaction.
6. Draft and cancelled snapshots must have no lines. A committed snapshot must contain its exact input and output lines and may contain variance lines; referenced movement IDs must belong to the same batch. No line absence ever means deletion.
7. Hosted projection uses the shared version cursor. Stale revision is rejected, an exact same-version replay is a no-op, changed same-version content conflicts, and only a newer version may update mutable terminal fields. Parent ID/UUID/scope/recipe/output/planned quantity/creator/creation time and all line identities are immutable.
8. An unexpected existing hosted line is a conflict, not permission to delete it. Hosted execution creates no branch outbox event. Cloud-to-branch use remains the guarded empty-database manual restore path.
9. Generic production parent/line row domains may remain for compatibility with explicit bulk export, but the typed writer event is authoritative for automatic synchronization and must not be weakened to generic row apply semantics.

## Transaction and compatibility plan

- Add one optional `OperationalSyncEventService` dependency at the end of `ProductionBatchService`'s constructor. Existing callers, mutation adapter responses, read services, permissions and feature-flag behavior remain unchanged.
- Wrap standalone draft creation and cancellation in transaction-aware boundaries before making capture required. `commit` already owns a retryable transaction; record the typed event inside its retry callback after `updateCommitted` and line creation.
- Propagate `RecipeFeatureFlags::appConfig()` through the production context so movement, accounting and batch capture share the injected branch identity rather than falling back to unrelated global configuration.
- Do not change recipe explosion, quantities, costs, journal math, availability calculations, movement IDs, UI routes, generic worker networking, reverse-sync policy, or production deployment.

## Focused proof and stop conditions

- Prove draft revision 1, committed revision 2, cancelled revision 2, complete line set, native UUID, same-second monotonicity, event ordering, hosted-role no reverse event, legacy revision-zero capture, and strict payload rejection.
- Force recorder failure during create, commit and cancel. Assert rollback of header/status/revision, production lines, movements, balances, journal/entries/links, availability cache changes, and all related outbox rows.
- Prove stale, exact duplicate, changed same-version, immutable parent, wrong-scope, unknown/cross-parent line, and missing-line conflicts on disposable hosted projection and guarded restore.
- Keep existing `ProductionBatchServiceTest`, `ProductionBatchMutationReadServiceTest`, production UI/concurrency/contracts, T172 movement, T182 accounting, schema, projection and restore suites as adjacent gates.
- Stop if production has an untraced writer outside `ProductionBatchService`/`ProductionBatchRepository`, if required capture cannot stay inside `RecipeTransactionRetryService`, or if implementation expands into recipe definition, procurement, transfer, generic replication, automatic cloud-to-branch apply or deployment.
