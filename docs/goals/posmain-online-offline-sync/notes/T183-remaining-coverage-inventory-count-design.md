# T183 remaining coverage audit after stock valuation

## Decision

Implement `inventory_counts` plus all `inventory_count_lines` as the next lean authoritative aggregate. T172, T180, and T182 preserve the count's quantity movements, balance effects, and valuation journals, but they do not preserve the count document itself: blind-count settings, frozen snapshot, counted quantities, stale-stock evidence, operator notes, assignment, approvals, closure, and reversal state can still disappear with the branch.

`InventoryCountService` is one authoritative writer for draft creation, line saves, submit, approve, close, and closed-count reversal. The aggregate has one branch/store scope and no line deletion or cross-branch custody behavior. It is therefore smaller and safer than transfers, procurement, or production documents.

## Remaining-domain matrix

| Domain | What is already recoverable | Remaining authoritative gap | Decision |
| --- | --- | --- | --- |
| Inventory counts | Close/reversal movements, balances, and standard inventory valuation journals. | Parent/lines, blind snapshot, counted quantities, stale flag, approvals, notes, status, and actor/timing evidence. | **Implement next** as one versioned parent-plus-lines aggregate. |
| Inventory transfers | Send/receive movements and balances. | Open custody, source/destination workflow, variance, lines, acceptance, cancellation, and cross-branch state. | Required later; larger because destination scope and two-sided lifecycle need explicit policy. |
| Purchase orders/receipts | Receipt movements and inventory accounting. | Supplier context, ordered/received quantities, open obligations, approvals, invoice references, and document status. | Required later as procurement aggregates. |
| Production batches/usage | Recipe input/output/consumption movements and recipe valuation journals. | Batch/line facts, planned versus actual output, variance reason, status, and usage audit. | Required later as production aggregates. |
| Catalog and operational masters | Menu parent coverage and some typed children. | Units, variants, categories, availability, payment methods, stores/registers/areas remain uneven for a full rebuild. | Required before production declaration, separated by writer ownership. |
| Manual accounting | POS/order, inventory, and recipe journals are covered. | Manual vouchers and other legacy journal writers remain outside typed contracts. | Required later as a separate financial slice. |
| Staff/RBAC and external correlations | Business activity rows survive in several bundles. | Sanitized identity/grants and allowlisted Moova resume mappings remain incomplete. | Later explicit contracts; never copy passwords, PINs, tokens, sessions, or raw provider payloads. |
| Debug/runtime logs, caches, projections, sessions, device state | Rebuildable or node-specific. | None for authoritative recovery. | Exclude. |

## Inventory count aggregate contract

1. Add `inventory_counts.sync_revision BIGINT UNSIGNED NOT NULL DEFAULT 0` through the normal additive schema upgrade. Every real aggregate mutation increments it exactly once inside the owning transaction; new drafts start at revision 1 after all initial lines exist.
2. Capture a typed `inventory_count_bundle` containing one strict parent row and all current lines, ordered by ID. The parent `count_uuid` is the native aggregate UUID; event version, payload revision, and parent revision must match.
3. Allowlist only count business columns. Preserve blind-count and audit evidence, but cap free text and exclude credentials, sessions, files, raw requests, item details, current balances, and unrelated movements/journals.
4. Validate UUID, branch/tenant/store scope, parent/line ownership, unique line IDs and item IDs, decimal shapes, allowed statuses/types, actor/timestamp shapes, payload hash, and event identity before enqueue and apply.
5. Capture draft creation, line save, submit, approve, close, and reverse-to-cancel only after the final mutation. Close/reverse ordering remains movement events, valuation journal event, then count bundle, all in the same transaction.
6. Idempotent service replay captures the current revision without incrementing it, healing a missing deterministic event. A migrated legacy row at revision 0 advances to revision 1 before its first capture.
7. Hosted projection uses the shared version cursor: stale revisions do nothing, exact same-version replay is duplicate, changed same-version content is conflict, and only newer content may update mutable fields. Parent UUID/scope and line ID/item ownership are immutable.
8. The complete line set is non-destructive. Absence never deletes a line. Because the writer has no removal path, a hosted extra line for the same count is an identity conflict rather than permission to delete it.
9. Hosted execution emits no automatic reverse event. The typed bundle is applied back to a branch only through guarded empty-database manual restore, after catalog identity and before or after its already ordered stock effects without overwriting movements.

## Compatibility and proof

- Add only an optional sixth service dependency; endpoints and return shapes stay unchanged.
- Use the injected `InventoryFeatureFlags::appConfig()` as the recorder fallback so isolated callers cannot accidentally inherit another global branch/sync identity.
- Recorder failure must roll back header, lines, state transition, movements, journals, links, revision, and outbox rows when the service owns the transaction. With `in_transaction=true`, it must propagate without committing and leave the caller in control.
- Focused proof covers create, line edit, submit, approve, close, reverse, idempotent healing, same-second monotonic revisions, caller rollback, recorder failure, hosted no-reverse behavior, stale/duplicate/conflict ordering, unknown/cross-scope rejection, and disposable restore.
- Existing inventory count, accounting, ledger, reporting, schema, projection, restore, and UI contracts remain gates.
