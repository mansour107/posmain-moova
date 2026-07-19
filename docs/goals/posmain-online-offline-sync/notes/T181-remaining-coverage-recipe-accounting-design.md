# T181 remaining coverage audit after inventory accounting

## Decision

Extend the existing immutable inventory-journal bundle to the independent recipe-accounting writer before adding workflow-document aggregates. `RecipeAccountingService` posts financially authoritative COGS, production, waste, stock-adjustment, and refund-reversal journals with `source_type=recipe_movement` and `posting_kind=recipe_accounting`. These journals are not produced through `InventoryAccountingService`, are excluded from the order-financial bundle, and therefore remain absent from automatic branch-to-cloud capture.

This is a bounded extension of T180 rather than a new replication mechanism: both journal families use the same journal tables, referenced accounts, inventory movements, movement-to-journal link, deterministic aggregate identity, hosted projector, and guarded restore order.

## Current remaining-domain matrix

| Domain | Current evidence | Recovery/monitoring impact | Decision |
| --- | --- | --- | --- |
| Standard inventory valuation journals | T180 captures `inventory_movement` / `inventory_accounting` journals atomically. | Purchase, return, direct COGS, waste, adjustment, and refund valuation are covered. | Keep. |
| Recipe and production valuation journals | `RecipeAccountingService` independently posts `recipe_movement` / `recipe_accounting` journals for sale COGS, production batches, waste, stock adjustments, and refunds. | Hosted stock value and profit reporting can omit recipe/production costs even though quantity movements survive. | **Implement next** by reusing the immutable journal bundle. |
| Inventory count documents | `InventoryCountService` owns parent, line, submit, approval, close, reverse, and cancel transitions; only resulting movements/accounting are captured. | Final stock and valuation survive, but blind-count evidence, reasons, approvals, and workflow status do not. | Next operational aggregate after recipe accounting. |
| Transfers | `InventoryTransferService` owns parent/lines and send/receive/variance/cancel transitions; resulting movements are covered. | Open custody and destination receipt state can be lost. | Required later as its own aggregate; cross-branch scope makes it larger than count. |
| Procurement and receiving documents | Parent/line/status writers are centralized and movements/accounting cover their stock and value effects. | Supplier context, open obligations, and audit history remain incomplete. | Required later as a separate aggregate family. |
| Production batch and recipe-usage documents | Production movements and, after the selected slice, valuation survive; batch/line facts and usage audit do not. | Stock reconstruction is possible, but production monitoring and operator evidence remain incomplete. | Required later as a typed production aggregate. |
| Catalog children and operational masters | Coverage remains uneven across units, variants, categories, availability, payment methods, stores, registers, and areas. | A full branch rebuild can still require configuration repair. | Required before final production declaration, split by writer ownership. |
| Manual journals and vouchers | Legacy writers do not converge through either accounting service. | Non-POS/non-stock money history remains incomplete. | Required later as a separately validated financial aggregate; do not generalize this slice. |
| Sanitized staff/RBAC and Moova correlations | Some recovery facts are needed, but source rows include secrets or external payload material. | Manual setup or integration duplication risk remains. | Later explicit allowlisted contracts only. |
| Caches, debug/runtime logs, device state, sessions, raw provider payloads, credentials | Derived, transient, node-specific, or unsafe. | Not authoritative disaster-recovery state. | Exclude or rebuild. |

## Selected lean contract

1. Keep the existing `inventory_journal_bundle`, aggregate type, deterministic UUID, event version, hosted projector, and restore phase. It is already a stock-valuation journal contract rather than table-wide replication.
2. Expand provenance validation only to the two exact allowed pairs: `inventory_movement` with `inventory_accounting`, or `recipe_movement` with `recipe_accounting`. Reject every other source/posting combination.
3. Add an optional sync-event dependency to `RecipeAccountingService` without changing existing callers or business responses.
4. Capture newly posted and idempotently reused recipe journals only after entries and all movement links exist. Use a self-managed transaction when called alone and a savepoint when a production/order/refund transaction already owns the transaction.
5. Recorder failure must roll back the new journal, entries, links, counter allocation, and local outbox event while preserving the caller's ability to decide its outer transaction. No network I/O is added.
6. Branch execution records a local outbox event. Hosted execution records no automatic reverse event. Manual guarded restore remains the only cloud-to-branch apply path.

## Compatibility and proof

- Existing `RecipeAccountingService` constructor calls remain valid through an optional fifth dependency.
- Accounting-disabled and no-movement noops remain unchanged and emit nothing.
- Sale COGS, production, waste, adjustment, and refund paths all converge through the same private posting/capture boundary.
- A production journal with multiple movements and a variance entry proves the existing bundle/projector is not limited to two-entry inventory postings.
- Existing-journal replay heals a missing deterministic outbox event without creating a second journal.
- Capture failure is proved both with and without a caller-owned transaction; the caller transaction remains active after savepoint rollback.
- Strict validation rejects an arbitrary journal provenance pair, and guarded disposable restore reconstructs the recipe journal only after its exact movements.
- Recipe accounting, production, projection, restore, schema, PHP lint, diff, and Goal Maker checks remain gates.
