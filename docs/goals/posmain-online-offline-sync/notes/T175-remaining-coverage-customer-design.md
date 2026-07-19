# T175 remaining automatic-sync coverage audit

## Decision

The next bounded reliability slice is the POS customer aggregate. It is the smallest remaining critical branch-to-cloud data-loss gap after the inventory-ledger and order-financial bundles. The slice must cover `pos_customers`, `pos_customer_phones`, and `pos_customer_addresses` as one typed snapshot. It must not add automatic cloud-to-branch application.

## Current remaining-domain matrix

| Domain | Material writers and current evidence | Risk if branch is lost now | Decision |
| --- | --- | --- | --- |
| POS customer aggregate | `PosCustomerService::{saveCustomer,upsertForDelivery,recordOrderPaid,applyRollupDelta,mergeCustomers,softDeleteCustomer,setPrimaryPhone}` and `PosCustomerOrderSideEffects::rebuildCustomerRollups`; none records a complete customer snapshot. Delivery create/update calls `upsertForDelivery` inside an owning order transaction, while `saveCustomer` unconditionally starts and commits its own transaction. | Customer identity, phones, addresses, notes, merges, tombstones, and rollups are absent from reliable recovery. The nested transaction boundary can also commit an order before its order snapshot/outbox capture. | **Implement next.** Typed full aggregate, monotonic revision, transactional capture, stale guard, idempotent hosted projection, guarded restore. |
| Inventory movements and balances | T172 captures every `InventoryLedgerService` movement and balance update in the same transaction and proves reorder/idempotency/restore. | Covered for ledger-originated stock changes. Workflow documents are separate. | Keep T172 behavior; do not broaden this slice. |
| Order, receipt, payment and order-linked financial journals | T174 captures the order snapshot plus only scoped legacy/invoice/payment journals and sanitized account identities. Hosted validation and disposable restore are proven. | Covered for the active POS sale/payment lifecycle. Refunds and drawer data remain separate typed domains. | Keep T174 behavior. |
| Inventory workflow documents | Purchase orders, receiving, transfers, counts, adjustments, production consumption and associated statuses have multiple service writers without complete document-bundle capture. | Operational history and document state can be lost even though resulting movements survive. | High priority after customer, but defer because it is multiple aggregates rather than one lean slice. |
| Production batches and recipes | Production services mutate batches, ingredients, outputs, reservations and completion state. Recipe snapshots cover configuration, not every production document. | Batch workflow/audit context can be lost while some stock effects survive. | Defer to a typed production-document bundle after inventory workflow boundaries are mapped. |
| Catalog children | Core menu/item snapshots exist, but units, variants and some availability/modifier child writers are not uniformly captured. | Hosted menu may miss a recent child configuration while the parent item exists. | Important, but split into one catalog aggregate only after writer inventory; do not mix with customer PII. |
| Delivery fulfillment and Moova mappings | `order_fulfillment` and delivery/Moova mapping writers are only partially represented by order or Moova link events. | Remote delivery state and external correlation may be incomplete. | Defer to a typed fulfillment bundle. Keep it separate because restore ordering depends on both order and customer. |
| Users, roles and permissions | Many admin writers and credential-bearing rows; generic replication could expose password hashes, tokens or sessions. | Configuration recovery is incomplete, but a naive slice creates security and lockout risk. | Defer until a sanitized identity/RBAC aggregate is specified. Explicitly exclude password material, sessions, reset tokens and device secrets. |
| Audit/application logs | Some business audit events are captured, but general logs mix durable audit evidence with transient diagnostics. | Some forensic history can be missing; most runtime logs are not recovery state. | Keep durable approval/order/financial audit events; exclude transient request/error/debug logs unless a separate retention design requires them. |
| Derived caches and device-local state | Search caches, computed balances already rebuilt from immutable entries, UI/session state and printer/device state. | Recomputable or unsafe on another branch device. | Exclude from automatic sync and restore. |

## Customer slice contract

1. Add a typed `customer_bundle` with aggregate type `pos_customer` and deterministic aggregate UUID derived from branch identity plus local customer ID.
2. Snapshot the complete customer parent and all phone/address children, including soft-deleted tombstones. Do not snapshot fulfillment rows, authentication data, sessions, cached UI state or unrelated customers.
3. Use `document_counters` for a strictly increasing revision. Seed from legacy/outbox history so deleting a counter cannot make a later snapshot older than an already-sent snapshot.
4. Validate before enqueue and before hosted apply: payload hash, parent ID, child ownership, duplicate IDs, normalized-phone uniqueness, primary/default references, allowed columns, aggregate UUID and positive revision.
5. Make customer mutation APIs transaction-aware without breaking callers. Standalone calls own commit/rollback; order/delivery callers pass an explicit existing-transaction option. Capture before the owning commit, and propagate capture failure so business data cannot commit without its event.
6. Emit one final customer snapshot after a paid-order rollup, rather than one snapshot per intermediate counter update. Merge emits both the target aggregate and the soft-deleted source aggregate in the same transaction.
7. Hosted projection is branch-scoped, idempotent and guarded by the shared monotonic cursor. An older event arriving after a newer event is acknowledged as stale and cannot overwrite the hosted customer.
8. Guarded empty-branch restore replays the same bundle exactly and idempotently. Identity conflicts and cross-customer children fail closed. Reverse sync remains a manual disaster-recovery command; no automatic pull/apply is enabled.

## Compatibility and stop conditions

- Preserve existing public method calls by adding optional trailing options only.
- Preserve schema-v1 and all unrelated generic operational event handling.
- Do not change customer search/profile response shapes or delivery pricing/order semantics.
- Stop rather than deploy if transaction rollback, rapid-revision monotonicity, stale reorder, merge/tombstone replay, exact restore replay, or account/order adjacent tests fail because of this slice.
- Stop if safe projection would require accepting a phone/address identity conflict or overwriting another customer's child row.

## Required proof

- Existing customer service and order-side-effect suites remain green.
- A delivery-order outer-transaction rollback proves customer and outbox rows roll back together.
- Injected recorder failure rolls back the owning customer/order mutation.
- Rapid updates generate strictly increasing revisions even after counter loss.
- Hosted newer-before-older delivery retains the newer aggregate and exact replay is a no-op.
- Merge and soft-delete bundles preserve target children and source tombstones.
- Disposable restore recreates parent, phones and addresses; replay is idempotent and conflicts fail closed.
- Branch worker, projection guard, restore contract, schema migration and order snapshot adjacent suites remain green.
