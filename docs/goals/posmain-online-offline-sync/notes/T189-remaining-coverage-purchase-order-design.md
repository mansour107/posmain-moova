# T189 remaining coverage audit after purchase receipts

## Decision

Implement a strictly versioned purchase-order aggregate next. Disposable end-to-end certification is still premature because open supplier commitments are authoritative management data: draft/submitted/approved orders, ordered quantities, received progress, expected dates and approval actors are needed for remote monitoring and branch-loss recovery, and are not reconstructed by receipt, stock or accounting bundles.

The aggregate has two normal mutation owners but remains bounded. `InventoryPurchaseOrderService` exclusively creates the header/lines and performs draft-to-submitted-to-approved transitions. `InventoryPurchaseReceivingService` only advances each line's `received_qty` and derives `partially_received` or `received` on the same header. Both already use explicit transaction ownership and row locks. One shared payload/recorder can therefore cover the aggregate without restructuring procurement. `tools/inventory_merge_stores_to_operational.php` is a one-time operator maintenance path that cancels off-store open orders; it is not an ordinary POS writer and must remain a documented sync-paused maintenance/reconciliation operation rather than silently becoming a third live writer in this lean slice.

## Updated remaining-domain matrix

| Domain | Current authority | Remaining gap | Decision |
| --- | --- | --- | --- |
| Purchase orders | Receipt documents and effects now recover; generic manual export can copy PO rows. | Open obligation, approval lifecycle, expected delivery and exact ordered/received progress are not automatic. | **Implement next** as one versioned parent-plus-lines aggregate. |
| Inventory transfers | Transfer movements/balances cover stock effects. | Source/destination custody, acceptance, variance and cancellation document. | Required later; defer until cross-branch ownership and restore policy are explicit. |
| Manual/legacy accounting | Typed order, refund, inventory and recipe families are covered. | Manual vouchers and a few legacy posting paths. | Audit by posting owner; do not create a duplicate generic journal stream. |
| Operational masters | Menu/config bundles and manual bulk exist. | Categories, units, variants, stores/registers/areas and payment methods have uneven writer capture. | Required when actively used; split by owner after transactional documents. |
| Staff/RBAC | Employee generic export excludes password material. | Sanitized user/role/grant recovery. | Required later under a strict secret-free allowlist. |
| Runtime logs, sessions, locks, caches and worker state | Local or rebuildable. | No authoritative business recovery value. | Exclude; keep selected audit events only. |

## Purchase-order aggregate contract

1. Add one additive `inventory_purchase_orders.sync_revision BIGINT UNSIGNED NOT NULL DEFAULT 0`. New drafts are revision 1. Every real service transition and every receipt-driven progress mutation increments the parent exactly once, including successive partial receipts whose status remains `partially_received`.
2. Add `purchase_order_bundle` with the exact header and all current lines ordered by ID. Header: native UUID, branch/store scope, supplier, status, expected date, lifecycle actors/times, notes, created/updated times and revision. Lines: identity, item/unit, ordered/received quantities, cost, notes and times.
3. Reject unknown fields, malformed UUID/scope/status/decimal/time/text, duplicate or cross-parent/item lines, non-positive ordered quantity, received quantity outside `[0, ordered]`, inconsistent totals, lifecycle status/timestamp contradictions and payload/hash/revision mismatch.
4. Capture draft after all lines exist; capture transitions after the locked status update; capture receipt progress after all line/header progress and accounting work but before the final receipt event and transaction commit. Exact idempotent create/transition/receipt replay may heal the current deterministic revision event.
5. Hosted projection and guarded restore accept strictly newer revisions, treat exact same revision/hash as duplicate, reject older or changed-same-version content, preserve immutable UUID/scope/order identities and never delete lines by absence. A newer revision may only change lifecycle/progress fields; ordered line identity, item/unit, ordered quantity and cost remain immutable after creation.
6. Restoring a purchase order recreates only the order document and progress. It does not create receipts, stock movements, balances, journals or suppliers. Hosted-role mutations emit no automatic reverse event.
7. Keep the one-time operational-store merge tool outside automatic event capture. Its production runbook must stop workers, take a backup, run the tool, perform an explicit authoritative reconciliation/resync, then resume workers; certification must fail if maintenance-created divergence remains.

## Compatibility and proof

- Append optional sync dependencies to both services and preserve all existing constructor callers, responses, permissions, supplier/item validation, over-receipt guards, stock/accounting math and transaction ownership.
- Prove draft, submit, approve, successive partial receipt and final receipt revisions are monotonic even within one second; `createAndSubmit` safely queues two ordered versions inside one transaction.
- Prove required capture failure rolls back header/lines, status/progress, stock, balance, accounting, receipt and all outbox events for self-managed transactions, while caller-owned transactions remain caller-controlled.
- Prove strict projection conflicts, exact replay, no delete-by-absence, hosted-role silence and disposable manual restore without duplicating receipt or financial effects.
- Treat the currently red purchase-order fixture (`OPERATIONAL_STORE_NOT_CONFIGURED`) as an adjacent test-fixture repair required for this slice, not a production behavior change.
