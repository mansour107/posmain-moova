# T173 - Lean order financial bundle design

## Decision

Use the existing versioned POS order event as the transaction-owned carrier for the active restaurant sale ledger. Extend that event with one bounded `financial_bundle`; do not introduce generic accounting-table replication, network calls inside checkout, or automatic cloud-to-branch apply.

The bundle is required for new order snapshots and contains only:

- journal heads demonstrably scoped to the order;
- every entry belonging to those heads;
- the referenced account identities plus their ancestor identities;
- the order's receipt headers and `order_payments` rows already represented by the snapshot; and
- a deterministic financial hash and balanced totals used by both hosted projection and restore validation.

`acc_head.balance`, `debit`, and `credit` are derived and must not be uploaded as authority. Account phone, address, email, free-form info, timestamps, and other unrelated legacy/accounting rows are excluded. Refund/credit-note journals remain in the existing typed `financial_refund_bundle`; inventory and recipe journals remain owned by their typed movement domains rather than being swept into a sale by broad ID matching.

## Canonical writer and hook map

| Active path | Financial writes | Transactional snapshot hook | Result |
|---|---|---|---|
| Takeaway/delivery create and update in `PosOrderMutationService` | `FinancialInvoicePostingService` posts invoice finalization; receipt helpers post payment journals | `recordOrderSnapshot()` runs after journals, receipt rows, inventory bridge and order totals, before caller commit | Bundle can be built from committed-to-be transaction state without network |
| Table payment in `PosOrderController` | `AccountingPostingService::postTablePaymentReceipt()` posts receipt and balanced journal | `OrderMutationSideEffectsService::recordTablePayment()` emits the order snapshot before controller commit | Partial and final payments are captured atomically |
| Routed table/cashier saves | `PosOrderService` and the mutation services post invoice journals through `JournalPostingService` | `OrderMutationSideEffectsService::recordOrderLifecycle()` runs in the owning transaction | Same carrier and rollback behavior |
| Legacy `do/doadd_invoice.php` | Direct main journal plus cash/bank receipt journals | Existing `recordOrderSnapshot()` is after all journal and receipt writes and before commit | Compatibility capture is possible, but only through strict order linkage |
| Refunds | `FinancialRefundService` posts credit/tender journals | Existing `recordFinancialRefundSnapshot()` | Keep separate; never duplicate in order bundle |
| Drawer, recipe, inventory | Their typed services may post linked journals | Their existing typed movement/refund bundles | Keep separate from order journal selection |

An order journal is in scope only when the head is linked through `op2 = order_id`, or when `op_id = order_id` and one of its own entries also links to that order. This includes the legacy main invoice journal while preventing an unrelated head whose numeric `op_id` merely collides with an order ID.

## Identity, revision, validation, and hosted behavior

- Local immutable IDs remain recovery identities inside one persisted `branch_uuid`: `(branch_uuid, journal_heads.id)` and `(branch_uuid, journal_entries.id)`. Empty-branch restore is explicitly for the same branch identity.
- Every included head must have at least two entries, positive/equal debit and credit totals, and a head total matching the balanced entry total at money precision.
- Every entry account must exist in the included sanitized account set. Duplicate IDs, cross-head entries, missing heads/accounts, unbalanced values, or a mismatched financial hash fail the event transaction.
- The order event revision must be strictly monotonic even when several payment snapshots occur in one second. Allocate the next version under the existing aggregate outbox lock/range and never rely only on `mdtime` or `kitchen_revision`.
- `CloudOrderSnapshotService` validates the bundle before accepting the versioned `cloud_orders.payload_json`. Existing `cloud_orders`, payment and receipt projections continue serving the current hosted money dashboard; the validated journal evidence in the latest payload and durable inbox makes those totals auditable and recoverable without adding a parallel accounting reporting schema in this lean tranche.
- Already queued schema-v1 order events without `financial_bundle` remain accepted for compatibility. Newly produced schema-v2 order events require the bundle, including an explicit empty bundle for unpaid/save-only orders.

## Guarded restore ordering

For an empty-branch, operator-authorized restore only:

1. Insert missing sanitized account identities and ancestors. Never overwrite an existing account's balance or private/contact fields.
2. Restore the order and lines through the existing mirror.
3. Restore scoped receipt headers and `order_payments` rows.
4. Insert journal heads and entries as immutable facts. Exact replay is a no-op; an ID with different immutable content is a hard conflict, never last-write-wins.
5. Recompute balances for referenced accounts from local non-deleted journal entries; never copy the branch's previously cached balance.

Historical schema-v1 order events restore orders/lines as before. Replaying several schema-v2 snapshots is safe: exact financial rows are idempotent. Legacy direct edits that hard-delete and recreate journals are represented by the latest complete journal-set membership. This tranche does not delete accounting history during normal upload or hosted projection; destructive reconciliation of old legacy journal sets is deferred and the active legacy edit path remains a known compatibility risk rather than silently guessing tombstones.

## Compatibility and regression controls

- No change to invoice calculation, journal posting, account selection, payment timing, refund semantics, inventory, recipe accounting, or drawer accounting.
- No network work enters the sale transaction; only local reads plus the existing local outbox insert are added.
- If bundle construction or outbox persistence fails, canonical modern mutation paths follow the existing rollback policy. Legacy paths that already call the outbox without a catch also roll back.
- Cloud-to-branch polling remains disabled by production profile; pairing remains non-restoring; only the guarded CLI empty-branch workflow can apply hosted data.
- Do not include secrets, authentication data, arbitrary journal/admin edits, cached account balances, or unrelated journals.

## Approved Worker slice

T174 may edit only the order snapshot builder/outbox revision allocator, order hosted validator/projector, legacy restore mirror, focused order/restore tests, this note, and Goal Maker state. It must prove balanced capture, rollback with the business transaction, strict monotonic revisions for rapid payments, hosted older-after-newer rejection, immutable replay/conflict behavior, empty-branch dependency order, derived balance rebuild, schema-v1 compatibility, and no inclusion of unrelated/refund/inventory/recipe journals.
