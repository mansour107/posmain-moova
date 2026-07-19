# T177 remaining coverage audit after customer sync

## Decision

Implement order fulfillment as the next lean reliability slice. The current order snapshot contains the order, lines, payments, receipts and scoped journals, but not the one-to-one `order_fulfillment` row. Normal delivery-status transitions update only the branch database. This prevents reliable remote delivery monitoring and leaves a restored order without its channel, delivery status, customer link, address snapshot, fee, ETA and sanitized dispatch metadata.

## Remaining-domain matrix

| Domain | Current recovery evidence | Risk and priority | Decision |
| --- | --- | --- | --- |
| POS customers, phones and addresses | T176 typed aggregate is transactionally captured, monotonic, stale-safe and manually restorable. | Covered for canonical customer writers. | Keep. |
| Delivery/order fulfillment | `OrderFulfillmentService` is the central one-row-per-order writer. Order creation paths usually record an order snapshot after fulfillment, but the snapshot omits the row; `transitionDeliveryStatus()` records no outbox event. | Hosted management can show an order with missing/stale delivery status, and disaster recovery loses dispatch state and customer/order linkage. | **Implement next** by making fulfillment part of the existing order aggregate and making standalone status transitions transactionally capture a newer order snapshot. |
| Inventory workflow documents | Movement/balance authority is covered by T172, but count, transfer, purchase order and receipt documents are not uniformly captured. | Workflow history and open-document status can be lost, while quantity authority survives. | Required after fulfillment, split by coherent document families rather than one broad generic replica. |
| Production documents and usage | Recipe definitions and stock movements are covered, but production batch/line and usage facts are not complete. | Production audit and batch state can be lost. | Required later as a typed production bundle. |
| Catalog children and operational masters | Parent item and modifier-group coverage exists; units, variants, availability, nutrition, category lifecycle, payment methods, areas, stores and registers are uneven. | Restore may require manual configuration repair even though sales facts survive. | Required before production declaration, but lower immediate data-loss risk than active fulfillment. |
| Moova mapping facts | Order business state is covered, while order/change/line/table mappings are partial and raw provider payloads are unsafe. | Integration resume may duplicate or lose external correlation. | Later sanitized mapping bundle; exclude raw request/response payloads and secrets. |
| Sanitized employee/RBAC configuration | Employee writers are best-effort and grants are incomplete; credentials and sessions are unsafe to copy. | Recovery can require manual user/permission setup. | Later explicit identity/grant contract with no password, PIN, token, session or reset data. |
| Durable audit | Order, approval, drawer and financial audit facts are covered selectively; general runtime logs have no retention contract. | Some forensic evidence may be incomplete. | Keep durable business audit only; exclude request/debug/error logs and derived caches. |

## Fulfillment slice contract

1. Extend the existing versioned order snapshot with one nullable, strictly validated fulfillment object. Do not create a separate independently ordered feed for a row whose lifecycle is owned by the order.
2. Include only recovery/monitoring fields from `order_fulfillment`: row/order identity, channel/type, external provider/order ID, customer snapshot, legacy/modern customer IDs, zone/fee/status, ETA, CRM rollup markers, timestamps and a bounded JSON metadata object. Reject unknown columns, cross-order identity and malformed or oversized metadata.
3. Hosted apply and guarded restore must project the fulfillment only after the order parent, using the order's existing branch-scoped monotonic projection cursor. Older order events must never rewind delivery status.
4. `transitionDeliveryStatus()` must own commit/rollback when standalone, capture the updated order snapshot before commit, and propagate capture failure. It must remain usable inside an existing transaction through optional trailing options.
5. Existing order creation, edit, cancellation and Moova paths must continue to capture after their fulfillment mutation. Add an explicit capture to any active canonical POS edit path that commits fulfillment without a later order snapshot; do not make low-level upsert emit a premature snapshot before the rest of an order mutation finishes.
6. Reverse apply remains manual and guarded. Do not enable a background cloud-to-branch status writer.

## Compatibility and proof

- Existing schema-v1/v2 order events without fulfillment remain accepted; the new schema version requires a valid nullable fulfillment field.
- Existing delivery API response shapes and allowed status transitions remain unchanged.
- A standalone status change and its outbox event commit or roll back together; injected recorder failure leaves the old status intact.
- A caller-owned transaction is not committed by the service, and outer rollback removes both status and event.
- Newer-before-older hosted apply and exact replay preserve the newest status and one fulfillment row.
- Disposable guarded restore recreates the fulfillment row linked to the restored order and customer ID without accepting cross-order payloads.
- Raw Moova request/response bodies, credentials, sessions and device state are never added to the order payload.
