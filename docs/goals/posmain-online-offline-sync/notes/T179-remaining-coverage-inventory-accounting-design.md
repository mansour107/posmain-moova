# T179 remaining coverage audit after fulfillment

## Decision

Implement inventory accounting journals as the next lean reliability slice. T172 makes inventory movements and balances durable, but `InventoryAccountingService` creates the balanced journal only after the ledger call has already recorded the movement snapshot. It then attaches `journal_head_id` to the movement without emitting another durable sync contract. A lost branch can therefore recover the quantity while losing its valuation posting and the movement-to-journal relationship.

This is higher priority than synchronizing count, transfer, and purchase workflow screens alone: those documents improve operational history, but a document-only slice would still leave hosted money reports and disaster recovery financially incomplete.

## Current remaining-domain matrix

| Domain | Current evidence | Recovery/monitoring impact | Decision |
| --- | --- | --- | --- |
| Inventory movement and balance authority | T172 records both inside the owning transaction with deterministic replay healing and stale protection. | Quantity and rebuildable stock balance are covered. | Keep. |
| POS order financial facts | T174 restores order receipts, payments, scoped invoice/payment journals, and sanitized referenced accounts. | Active POS sale/payment money is covered. | Keep. |
| Inventory accounting journals | `InventoryAccountingService` centrally posts purchase, return, COGS, waste, adjustment, and refund-reversal journals, then attaches the journal to movements. No inventory-journal outbox bundle is recorded, and the original movement event was captured before this attachment. | Cloud inventory value and the general ledger can be incomplete even when quantity looks correct. | **Implement next** as one immutable typed journal bundle emitted by the central writer before the outer commit. |
| Manual vouchers, manual journals, and legacy settlement writers | Several `do/` scripts still insert journal rows directly. | Non-POS/non-inventory financial activity is not yet a complete safety copy. | Required later; converge separately because transaction and validation shapes differ. |
| Inventory count documents | Parent and line mutations are transaction-aware, while resulting movements are covered. | Count workflow, blind-count evidence, reasons, and approvals can be lost while final quantity survives. | Next operational family after accounting; use one parent-plus-lines aggregate. |
| Transfers and procurement documents | Services own coherent transactions, but parent/line/status events are not automatic. | Open transfer and purchase state, supplier invoice context, and audit history can be lost. | Required later as separate transfer and procurement aggregates. |
| Production batches and recipe usage | Recipes and resulting movements are partly covered; batch/usage facts remain registry-only or absent. | Production audit and cost/consumption context can be lost. | Required later as typed production/usage aggregates; reservations remain rebuildable where policy permits. |
| Catalog children and operational masters | Parent menu coverage exists; units, variants, categories, availability, payment methods, areas, stores, and registers remain uneven. | Restore can require configuration repair. | Required before final production declaration, split by aggregate ownership. |
| Moova mapping facts | Business orders are covered, but sanitized idempotency/correlation mappings are incomplete. | Integration resume can duplicate or lose external correlation. | Later typed mapping contract; exclude raw request/response payloads and secrets. |
| Sanitized employee/RBAC state | Automatic coverage is incomplete and source rows contain credential-bearing fields. | Recovery requires some manual identity/permission setup. | Later explicit non-secret identity/grant contract; never copy passwords, PINs, tokens, sessions, or reset state. |
| Runtime/debug logs, caches, stock-level projections, KDS/session/device state | Derived, transient, or unsafe on another node. | Not authoritative recovery data. | Exclude or rebuild. Retain only separately classified durable business audit. |

## Inventory accounting bundle contract

1. Emit one immutable `inventory_journal_bundle` for each inventory journal head after entries are posted and movements are linked, but before the caller-owned transaction commits.
2. Include only the journal head, its complete balanced entries, sanitized referenced account identities (including required ancestors), and the exact referenced inventory movement IDs. Exclude balances, contact data, credentials, sessions, and unrelated journals.
3. Validate positive identities, exact head/entry ownership, at least two entries, debit equals credit and head total, allowed `source_type`/`posting_kind`, movement scope, branch identity, payload hash, and immutable replay identity before enqueue and hosted apply.
4. Use a deterministic branch-scoped journal aggregate UUID and immutable event version. Exact replay is idempotent; any different payload for the same journal identity fails closed rather than overwriting financial history.
5. Hosted projection and manual restore apply sanitized accounts first, then the journal head and entries, then link only the declared branch-owned movements to that journal. Missing or conflicting identities fail closed.
6. Every `InventoryAccountingService` path must capture both newly posted and idempotently reused journals. Capture failure propagates so the outer purchase/count/order/recipe transaction rolls back. Hosted management execution must not create an automatic reverse event.
7. Do not add network access to the transaction, generic journal replication, automatic cloud-to-branch apply, or manual-voucher convergence in this slice.

## Compatibility and proof

- Existing inventory accounting return shapes and public constructor calls remain compatible through an optional injected recorder.
- Accounting-disabled/noop behavior remains unchanged and emits no bundle.
- Purchase receipt, purchase return, count adjustment, waste, sale COGS, and refund reversal all exercise the same central capture boundary.
- Recorder failure proves the journal, entries, movement link, inventory document, and movements roll back together in an owning service transaction.
- Exact replay heals a missing outbox bundle without adding a second journal.
- Newer/duplicate delivery remains idempotent, while a mutated same-journal payload, unbalanced entries, wrong movement identity, or wrong branch fails closed.
- Disposable guarded restore recreates sanitized accounts, one journal head, its entries, and movement links without enabling automatic reverse apply.
- Existing order-financial, refund, inventory accounting, count, receiving, recipe accounting, projection, restore, and migration tests remain green.
