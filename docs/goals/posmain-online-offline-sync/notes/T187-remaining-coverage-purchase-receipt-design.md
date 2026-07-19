# T187 remaining coverage audit after production batches

## Decision

Implement immutable purchase receipt/return documents next, then re-audit. End-to-end certification is not yet an honest release gate because posted supplier receipts are used for remote purchasing and audit but only their movement, balance and accounting effects are currently automatic. The receipt header and exact lines remain dependent on explicit generic bulk export.

`InventoryPurchaseReceivingService` is the only runtime writer for `inventory_purchase_receipts` and `inventory_purchase_receipt_lines`. It creates a terminal posted receipt or returned receipt, appends all lines, attaches each movement ID, posts accounting, and never updates or deletes the document afterward. That makes the document a bounded immutable aggregate even though a posted receipt can also update purchase-order received progress. Purchase-order state is explicitly outside this slice and remains a later gap.

## Updated remaining-domain matrix

| Domain | Current recoverable authority | Remaining gap | Decision |
| --- | --- | --- | --- |
| Purchase receipts and returns | Purchase/return movements, balances and inventory accounting journals. | Supplier invoice identity, supplier/store/order context, posted/returned evidence, actors/times, costs and exact receipt lines. | **Implement next** as one immutable parent-plus-lines aggregate. |
| Purchase orders | Generic manual export only; receipt effects identify related line IDs. | Draft/submission/approval, ordered quantity, received progress and open obligation. | Required later; separate because `InventoryPurchaseOrderService` and receiving both mutate it. |
| Inventory transfers | Transfer movements and balances. | Cross-branch custody, acceptance, partial receipt, variance and cancellation evidence. | Required later; needs explicit source/destination ownership policy. |
| Accounting journals | Order, refund, inventory and recipe families now have typed coverage. | Manual vouchers and a few legacy posting paths. | Re-audit by posting owner; do not add a generic duplicate journal feed yet. |
| Catalog/operational masters | Menu and several typed/config bundles plus manual bulk. | Writer capture remains uneven for categories, units, variants, stores/registers/areas and payment methods. | Required by active feature; split by writer ownership after transactional documents. |
| Staff/RBAC | Employee generic export excludes password. | Sanitized users, roles and grants without PIN/password/token/session material. | Required later under a strict identity allowlist. |
| Logs, sessions, locks, caches and worker state | Runtime-local or rebuildable. | No business recovery authority. | Exclude; retain only selected business audit events. |

## Purchase receipt aggregate contract

1. Add no schema column. Receipts are terminal immutable documents; typed revision and event version are always 1. Native `purchase_receipt_uuid` is the aggregate identity.
2. Add `purchase_receipt_bundle` with one exact parent and every current line ordered by ID. Parent allowlist: IDs/UUID, optional purchase order, branch/store scope, supplier and legacy invoice references, supplier invoice number, terminal status, received/posted times and actors, creation/update times and notes. Line allowlist: IDs, parent/order-line/item/unit identities, received/returned quantity, unit/total cost, movement/reason references, notes and times.
3. Permit only `posted` or `returned`. Posted lines require positive received quantity and zero returned quantity; returned lines require the reverse. Every line requires one movement belonging to that line with `source_type=purchase_receipt`, the expected receipt/return movement type and matching receipt UUID evidence.
4. Reject unknown fields, malformed UUID/scope/status/decimal/time/text values, duplicate or cross-parent line IDs, inconsistent totals and wrong movement ownership. Exclude balances, journal rows, supplier secrets, raw request/provider payloads and purchase-order snapshots.
5. Capture after all lines are attached, purchase-order progress is updated, and accounting has posted, but before the owning transaction commits. The final receipt event must follow movement/balance and inventory-journal events in outbox order. Recorder failure rolls back the receipt, lines, movements, balances, accounting, purchase-order progress and all related events.
6. On exact idempotent service replay, rebuild the same deterministic typed event if missing without creating business rows or changing stock. Hosted execution emits no automatic reverse event.
7. Hosted projection and guarded restore accept only revision 1. Exact replay is a no-op; changed same-version, parent ID/UUID/scope identity, line identity, movement-scope and unexpected hosted-line cases conflict. Absence never deletes a line.
8. Restoring the receipt document does not recreate movement, balance, journal or purchase-order rows. Those remain owned by their existing typed bundles or a later purchase-order aggregate.

## Compatibility and proof

- Add one optional `OperationalSyncEventService` dependency at the end of `InventoryPurchaseReceivingService`; preserve constructor callers, responses, inventory/accounting calculations, supplier-invoice uniqueness, PO validation/status behavior and caller-owned transaction support.
- Prove posted and returned payloads, native UUID, event version 1, final-event ordering, exact replay healing, hosted-role no reverse event and required capture rollback for self-managed and caller-owned transactions.
- Prove strict unknown/cross-parent/movement-scope validation, exact duplicate, changed same-version, immutable identity and no-delete-by-absence behavior on disposable hosted projection and guarded restore.
- Keep receiving, purchase bridge/order, movement, inventory accounting, reporting, projection, restore and schema suites as adjacent gates.
- Stop if another receipt writer exists, if capture cannot stay inside the existing transaction, or if the slice expands into purchase-order synchronization, supplier masters, transfers, generic replication, automatic reverse sync or deployment.
