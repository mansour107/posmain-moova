# T171 Current Automatic Sync Coverage Audit

Audit date: 2026-07-16

## Verdict

The asymmetric direction is now correct: branch upload is the ordinary automatic path and generic hosted-to-branch mutation is manual, empty-branch-only and guarded. The system is still not ready to deploy as a complete cloud safety copy because several required domains remain missing, and the existing inventory producer can commit business rows without a durable outbox row.

`OperationalSyncDomains` is treated only as a registry. A domain is `RELIABLE_AUTO` below only when the normal mutation path captures the event inside the owning transaction, the hosted receiver applies it branch-scoped and idempotently with stale protection where mutable, and the guarded restore stream can consume the same event.

## Current matrix

| Domain | Current evidence | Current verdict | Required recovery role |
| --- | --- | --- | --- |
| Orders, lines, payments and receipts | Routed order services, cashier invoice, refunds, table actions and Moova apply emit versioned order snapshots; hosted order projectors are cursor-gated | `RELIABLE_AUTO` for canonical routes; legacy raw writers remain a convergence gate | Restore order aggregate and payment facts |
| Tables | Save, clear, move and merge paths emit versioned table snapshots; hosted projector is cursor-gated | `RELIABLE_AUTO` for canonical routes | Restore current floor state after catalog |
| Menu item parent aggregate and images | Main item mutations emit item snapshots and image metadata/blob jobs | `RELIABLE_AUTO` for canonical item forms; child-only edits are gaps | Restore sellable catalog and blobs before orders |
| Refunds and their referenced journals | `FinancialRefundService` emits a typed refund bundle before commit; hosted apply validates scope/balance and rejects stale settlement revisions | `RELIABLE_AUTO` | Restore immutable refund documents, tenders and referenced journal rows |
| General sales/accounting journals and account masters | Order snapshots do not contain `journal_heads`, `journal_entries` or `acc_head`; legacy invoice and posting paths write them directly | `MISSING_CRITICAL` | Required for trusted money reports and full financial recovery |
| Order audit events | `OrderEventService` inserts event and outbox row in the same caller transaction; hosted row routing is registry/table validated | `RELIABLE_AUTO` | Restore immutable order audit history |
| Manager approvals | Lifecycle service emits monotonic row snapshots inside the owning transaction; hosted cursor rejects stale lifecycle state | `RELIABLE_AUTO` | Restore approval audit trail before dependent actions |
| Drawer sessions, movements, count attempts, resolutions and close summaries | Typed monotonic session/movement/close bundles capture the canonical services atomically; hosted apply remaps by UUID and preserves append-only children | `RELIABLE_AUTO` for current drawer services | Restore custody lifecycle before management reports |
| Drawer override periods | Not present in the typed session payload or an automatic producer | `MISSING_REQUIRED` | Restore manager override audit periods |
| Inventory movements and balances | `InventoryLedgerService` calls the best-effort recorder after its self-managed commit and the wrapper swallows capture errors; caller-owned transactions can also commit after a swallowed recorder failure | `UNRELIABLE_AUTO_CRITICAL` | Movement ledger is the recovery authority; balance is a monitored/rebuildable projection |
| Inventory counts, transfers, purchase orders/receipts and reason codes | Tables are registry/bulk eligible and their quantity effects use the ledger, but normal workflow document mutations do not all emit events | `MISSING_REQUIRED` | Restore workflow documents and audit, then reconcile against movements |
| Stock levels and availability caches | Rebuildable from movement/catalog policy; no need to restore as authority | `DERIVED` | Rebuild and reconcile |
| Recipes | Recipe editor emits a composite bundle | `RELIABLE_AUTO` for recipe edits | Restore recipe definitions before production/order usage |
| Production batches, recipe usage and reservations | Movement effects may reach inventory ledger, but workflow/fact rows are registry/bulk or absent | `MISSING_REQUIRED`; availability/reservation cache portions are `DERIVED` | Restore production/usage facts; rebuild transient reservations where policy permits |
| Categories | Add and picker-save hooks exist; edit/delete paths are not complete and generic hard delete is quarantined | `PARTIAL_UNRELIABLE` | Restore catalog dependency with tombstone-safe lifecycle |
| Units, variants, modifiers, nutrition, availability, payment methods, areas, stores, registers and shop settings | Some are included by parent snapshots or manual bulk; independent normal mutation coverage is incomplete | `MISSING_REQUIRED` when used; caches remain `DERIVED` | Dependency-first master restore |
| Current POS customers, phones and addresses | `PosCustomerService` has no typed sync aggregate; registry entry covers only legacy `customers` | `MISSING_CRITICAL` | Restore customer aggregate before fulfillment/order links |
| Delivery fulfillment, clients and zones | Registry/bulk definitions exist but normal services do not provide complete atomic automatic capture | `MISSING_REQUIRED` | Restore delivery masters and fulfillment links |
| Moova mapping/link state | Business orders are covered, but shop/table/order/change/line mappings are only partial/bulk and raw payloads require exclusion | `MISSING_REQUIRED` with sensitive-field policy | Restore non-secret idempotency/mapping facts before integration resumes |
| Employees | Add/edit/soft-delete forms call a best-effort recorder after raw writes; failures do not roll back the business change | `PARTIAL_UNRELIABLE` | Restore non-secret employee profile only |
| Users, roles and permission grants | No complete automatic aggregate; password/PIN/session secrets must never be copied | `MISSING_REQUIRED` for non-secret identity/RBAC state | Guarded restore of identities and grants without authentication secrets |
| General business/security/recipe/repair audit | No unified transactional outbox capture or retention contract | `MISSING_REQUIRED` by explicit retention class | Remote audit retention; restore only business audit rows |
| Printers and durable print-job history | Registry/bulk only | `MISSING_REQUIRED` for configured printers; job history retention may be cloud-only | Restore printer config; retain job outcome for monitoring |
| KDS tickets, temporary locks, sessions, login throttles, caches and sync control tables | Rebuildable or node/security-local | `EXCLUDED` or `DERIVED` | Never business-restore from another node |
| Pulse logs | Add/delete/type paths have recorder hooks, but generic deletes are quarantined and capture is best effort | `PARTIAL_UNRELIABLE`; feature-conditional | Retain remotely only if feature is enabled |
| Legacy HR/medical/rental/tasks/CRM modules | Outside the active restaurant contract | `DEFERRED_CONDITIONAL` | Enabling a module creates a separate release gate |

## Risk ranking

1. Inventory ledger capture can be lost even though the stock movement committed. This is frequent, financially relevant and currently advertised as automatic coverage.
2. General sales journals/account balances are absent from the cloud copy, so hosted money reporting and full accounting recovery are incomplete despite refund coverage.
3. Current POS customer and delivery aggregates are absent, so a restored shop would lose customer identity and fulfillment links.
4. Inventory workflow documents and production facts are absent even when their quantity effects survive in the ledger.
5. Catalog child/master, Moova mapping, RBAC and retained-audit surfaces require typed or tombstone-safe contracts before full recovery can be claimed.

## Approved next slice

Fix the existing inventory producer before adding breadth:

- record movement and balance snapshots before the owning transaction commits;
- call `OperationalSyncEventService` directly so capture failure aborts/rolls back instead of being swallowed;
- use the immutable movement id as the event revision for both movement and resulting balance, preventing same-second balance conflicts and stale overwrite;
- preserve shadow-write exclusion;
- on idempotent replay, recreate missing deterministic outbox events for the existing movement/current balance without changing stock;
- keep the existing hosted operational schema and branch-scoped projection cursor; do not add automatic reverse sync or workflow-document coverage in this slice.

Required proof: self-managed and caller-managed atomic rollback on recorder failure, outbox visibility before commit, idempotent replay healing without duplicate movements, two rapid movements producing increasing balance revisions, stale hosted balance rejection, shadow writes remaining excluded, and adjacent count/transfer/purchase/recipe inventory tests remaining green.

