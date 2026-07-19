# T191 final coverage decision after procurement

## Decision

Move to disposable end-to-end certification. Do not add an automatic transfer-document bundle in this tranche.

`InventoryTransferService` is the only normal runtime owner of transfer creation, submission, send, receive, variance close, and cancellation, but that does not make the aggregate single-authority. The header persists both source identity (`pos_branch`, `branch_uuid`, `source_store_id`) and destination identity (`destination_pos_branch`, `destination_branch_uuid`, `destination_store_id`). Send posts source-scoped `transfer_out`; receive posts destination-scoped `transfer_in`, including partial receipts. One local service/database currently performs both sides, but the code contains no distributed handoff contract saying which branch owns the document, which event stream may advance receipt state, or which branch a disaster restore may recreate it on.

Publishing the whole document from the source would allow later destination actions to overwrite a source-owned stream. Publishing it from both sides would create competing native IDs and revisions. Restoring it to the source would incorrectly recreate destination acceptance; restoring it to the destination could invent the source document and custody history. Those are policy decisions, not safe implementation details. The lean sync tranche must not guess them.

Transfer stock authority is nevertheless protected: send, receive, and sent-cancellation effects flow through the already covered immutable inventory movement, balance, and accounting bundles. A branch loss can therefore recover quantity and value facts. The remaining gap is the transfer workflow/custody document and operator evidence, explicitly deferred until a source/destination ownership and acknowledgement protocol is approved.

## Final remaining-domain matrix

| Domain | Current recoverable authority | Remaining gap | Tranche decision |
| --- | --- | --- | --- |
| Orders, payments, receipts, refunds and money journals | Typed order-financial and refund bundles with guarded restore. | None inside active restaurant order paths selected for this tranche. | Certify. |
| Shifts, drawers and money tracking | Typed drawer/shift events, close evidence and related order money facts. | Node-local UI/session state is not recovery data. | Certify business facts; exclude sessions. |
| Customers and fulfillment | Versioned customer and fulfillment aggregates, including tombstone-safe customer state. | None in the selected active paths. | Certify. |
| Inventory quantity and value | Immutable movements, versioned balances, and typed inventory/recipe journals. | Derived stock-level caches can be rebuilt. | Certify movements, balances and financial invariants. |
| Counts, production, receipts and purchase orders | Versioned or immutable parent-plus-lines bundles with atomic capture and non-destructive restore. | None in these selected document families. | Certify lifecycle ordering and blank-branch recovery. |
| Cross-branch transfers | Quantity/value effects are covered. | Source/destination custody, partial acceptance, variance, cancellation and document restore authority. | Defer pending an explicit two-branch handoff protocol; do not auto-restore the document. |
| Manual/legacy accounting | Typed order, refund, inventory and recipe posting families are covered. | Manual vouchers and unclassified legacy posting writers. | Keep outside automatic certification until writer ownership and non-duplication rules are separately audited. Never add a generic journal stream over typed families. |
| Operational masters | Menu/config composites plus explicit/manual catalog export cover many rows. | Automatic writer capture remains uneven for some categories, units, variants, stores/registers/areas and payment methods. | Certification must inventory actual enabled fixtures and report uncovered required masters as a deployment blocker, not silently pass them. |
| Staff and RBAC | Some non-secret employee projection exists. | Sanitized users, roles and grants needed to operate a rebuilt branch. | Explicitly report as a recovery prerequisite; never copy passwords, PINs, hashes, tokens, reset state or sessions through generic sync. |
| Durable business audit | Order, approval, financial and workflow evidence embedded in typed bundles is retained. | General application/security logs mix forensic evidence with diagnostics and sensitive request material. | Certify only allowlisted business evidence; retain diagnostics remotely by log policy, not branch restore. |
| Runtime logs, worker leases, caches, sessions, device state and raw provider payloads | Observable or rebuildable, and often node-specific or sensitive. | No authoritative business recovery requirement. | Intentionally exclude from restore. |

This matrix does not claim every legacy module in the repository is synchronized. It defines the enabled restaurant-POS contract. Enabling a legacy HR, rental, medical, CRM, or other optional module requires its own authoritative-domain classification and tests.

## Disposable certification topology

Use three temporary databases cloned from the disposable local schema:

1. **branch** — records representative business fixtures through the authoritative services and automatically drains the branch outbox.
2. **hosted** — receives authenticated events, applies hosted projections, retains the durable branch event stream, and never automatically writes back to the active branch.
3. **recovery** — starts with schema/config only and receives a guarded manual all-phase restore for the same branch identity.

No live shop, production credential, hosted migration, replay, or service install is part of this task.

## Required fixtures and fault cases

- At least one representative event in every typed family selected above: order/financial, refund where supported, drawer/shift, customer, fulfillment, inventory movement/balance, inventory and recipe journals, count, production batch, purchase receipt/return, and purchase order.
- Catalog/master fixtures used by those records, with an explicit coverage report distinguishing typed automatic capture from manual bootstrap requirements.
- Automatic worker delivery, duplicate delivery, retry after hosted outage, expired-lock reclaim, and ordered replay of multiple revisions.
- Hosted rejection of stale versions and changed same-version payloads without overwriting newer data.
- Manual restore dry-run refusal on the active/non-empty branch, then guarded apply only to the empty recovery database with workers acknowledged stopped and generic pull disabled.
- Idempotent restore replay and no delete-by-absence.
- Explicit assertions that credentials, password/PIN material, sessions, worker locks and raw provider payloads are not restored.

## Certification and deployment gates

The machine-readable report must fail closed unless:

- every expected domain/event fixture appears in the hosted event ledger and its required projection;
- branch outbox has no pending, failed, or expired-locked rows after recovery;
- duplicate/out-of-order delivery leaves hashes, versions and business rows unchanged;
- recovery recreates the selected business aggregates with matching counts and canonical hashes;
- order/payment totals, balanced journal debits/credits, inventory movement quantities, balances, receipt/PO progress and document revisions reconcile;
- excluded secret/session/runtime tables remain absent or empty in the recovery result;
- unresolved transfer documents, manual/legacy journals, required operational masters, and sanitized RBAC prerequisites are listed as explicit blockers or operator prerequisites rather than hidden omissions.

Passing this disposable proof is necessary but not sufficient for production deployment. Real rollout still requires reviewed hosted code/schema parity, encrypted backups, protected per-branch secrets, branch identity, service installation, a staged migration, live smoke, and operator sign-off.
