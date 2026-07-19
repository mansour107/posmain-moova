# T133 Lean Reliability Scope

## Scope Decision

The user narrowed the production-readiness program to the smallest model that still satisfies the real business outcome:

```text
shop database
  -> transactional outbox
  -> automatic supervised upload
  -> authenticated hosted inbox
  -> safe cloud projections
  -> remote monitoring

hosted cloud
  -> operator-started dry-run restore/repair
  -> local backup and validation
  -> manual apply to an empty or explicitly selected scope
```

This is intentionally asymmetric. It avoids building continuous multi-master synchronization and removes the largest source of conflict complexity.

## Work Package 1: Coverage and Contract

- Produce one authoritative matrix of required domains and write surfaces.
- For each domain record: source table/service, direction, event builder, automatic hook, identity, revision, sensitive exclusions, hosted destination, restore role and tests.
- Mark secrets, sessions, caches and device-only state as excluded.
- Treat a declared `OperationalSyncDomains` entry without a real write hook as missing coverage.

Deliverable: zero unclassified critical business write paths.

## Work Package 2: Upload Reliability

- Keep the existing transactional outbox and HMAC transport.
- Add or correct stable entity identity and monotonic revision generation.
- Make hosted projectors reject stale revisions and accept exact duplicates.
- Hold/report unsupported or conflicting events instead of overwriting data.
- Replace replicated hard deletes with tombstone state.
- Preserve existing queued-event compatibility or migrate it explicitly.

Deliverable: duplicate, delayed, reordered, retry and delete/recreate tests pass.

## Work Package 3: Complete Automatic Data Capture

- Add transactional hooks for missing financial, drawer/shift, inventory, order/audit, approval, catalog and delivery domains.
- Use typed payloads for critical financial/business aggregates; use the generic row snapshot only for safe, simple master data.
- Reuse existing image/file synchronization.
- Add a coverage test tying each required write surface to its outbox producer.

Deliverable: representative full-business-day data reaches hosted projections and reconciles with the branch.

## Work Package 4: Worker, Monitoring and Manual Reverse

- Install a dedicated `focushouse -> erp.withmoova.com` supervised worker.
- Expose last success, backlog count/age, dead/conflict rows and branch last-seen.
- Keep ordinary hosted-to-branch polling disabled.
- Harden the existing hosted restore tooling as an explicit operator workflow:
  1. dry-run and compatibility check;
  2. local backup;
  3. empty-database or selected-scope guard;
  4. apply in dependency order;
  5. refuse stale overwrite;
  6. reconcile counts, money, inventory and event high-water marks;
  7. write a restore receipt.

Deliverable: outage backlog drains automatically and a disposable lost branch is restored manually without touching an active branch.

## Work Package 5: Deploy and Certify

- Make focused and full sync tests green.
- Back up branch and Hetzner.
- Deploy the same reviewed commit and migrations to both environments.
- Run receive/shadow reconciliation first, then a small branch-to-cloud canary.
- Verify remote monitoring freshness and manual restore from hosted data.
- Keep continuous hosted-to-branch synchronization disabled.

Deliverable: a final Judge audit maps every lean acceptance gate to current evidence and rollback instructions.

## Deferred Work

- Continuous automatic hosted-to-branch updates.
- Cloud-originated business transactions.
- General multi-master conflict resolution.
- A broad cloud command bus.
- Large dashboard redesign.
- Legacy table/endpoint removal.
- Infrastructure beyond what is needed for reliable upload, monitoring, backup and manual recovery.

## Regression Boundaries

- Do not make order/payment/shift/inventory writes depend on network availability.
- Do not alter financial history through mutable last-write-wins synchronization.
- Do not reuse the existing `kody2 -> Railway` worker configuration for this shop.
- Do not replay the current pending queue until stale guards and compatible hosted code are in place.
- Do not enable general cloud-to-branch polling as part of this lean tranche.

## T133 Current-Checkout Audit

Audit date: 2026-07-16. This section supersedes the older Phase 0 assumption that sync infrastructure did not exist.

### Coverage status legend

- `AUTO`: the normal mutation path records an outbox event.
- `PARENT`: the data is embedded in a parent aggregate snapshot.
- `BULK`: the domain can be queued by a bulk snapshot job but normal mutations are not durably hooked.
- `MISSING`: required shop state has no complete automatic capture path.
- `DERIVED`: rebuild from an included source of truth; do not restore as an independent authority.
- `LOCAL`: operational/device/security state that must not be restored as business data.
- `CONDITIONAL`: an old ERP module outside the active restaurant POS; enabling it makes its coverage a release gate.

### Authoritative domain and write-surface matrix

| Domain and tables | Class and branch authority | Current automatic path | Hosted projection / manual restore role | Verdict |
| --- | --- | --- | --- | --- |
| Orders: `ot_head`, `fat_details`, order line notes/modifiers, receipts | Versioned order aggregate; branch authoritative | `SyncOutboxEventService::recordOrderSnapshot()` is reached from the routed order mutation service, cashier invoice, table actions, refund, merge/move and Moova apply paths | `cloud_orders`, lines, payments and receipts; restore order aggregate | `AUTO` for canonical paths; legacy invoice edit/delete and settlement paths still need convergence or explicit retirement |
| Order payments: `order_payments` and payment receipt rows in `ot_head` | Append-only money facts; corrections are reversals | Embedded by `PosOrderSnapshotBuilder` when an order snapshot is emitted | Cloud order payment/receipt projections; restore with order | `PARENT`; independent refund/settlement mutations are not fully covered |
| Accounting ledger: `journal_heads`, `journal_entries`, `acc_head`, vouchers | Append-only journals plus versioned account master | Order snapshots do not include journal rows; journal/admin/repair paths have no typed outbox event | Required cloud financial ledger and reconciliation; restore journals before balances | `MISSING` and critical |
| Refunds/credit: `payment_refunds`, `credit_notes`, `credit_note_lines` | Append-only financial correction documents | No sync event in `FinancialRefundService` | Required immutable cloud projection and restore | `MISSING` and critical |
| Drawer/shift custody: `drawer_sessions`, `drawer_movements`, `drawer_count_attempts`, `drawer_session_resolutions`, `drawer_override_periods`, `drawer_session_close_summaries`, legacy `closed_orders` | Append-only movements/counts plus versioned session lifecycle | Only close-summary snapshots are emitted by shift-close services; session/movement/count/override domains are registry-only or absent | `cloud_shifts` plus operational mirror; restore drawer lifecycle before close summary | `MISSING` except final close (`AUTO`) |
| Inventory ledger: `inventory_movements` | Append-only stock facts | `InventoryLedgerService` records movement snapshots | Operational projection; restore ledger | `AUTO` only through this service; all alternate inventory mutation paths must be proven converged |
| Inventory balances/stock levels: `inventory_item_balances`, `inventory_item_stock_levels` | Derived current state, useful for monitoring but not the history authority | Balance is hooked from `InventoryLedgerService`; stock level is registry/bulk only | Cloud current-state projection; restore ledger then rebuild/reconcile | balance `AUTO`; stock level `BULK`; both `DERIVED` for recovery |
| Counts/transfers/purchasing: count, transfer, PO and receipt headers/lines, reason codes | Versioned workflow documents; their resulting ledger movements remain authoritative for quantity | Listed in `OperationalSyncDomains`; normal services do not emit all row events | Required remote workflow/audit projection; restore documents, then ledger | `BULK`, therefore `MISSING` automatic coverage |
| Recipes/production: recipe header/lines/variants/cost snapshots, production batches/lines, recipe usage | Versioned recipe bundle plus append-only production/usage facts | Recipe editor emits bundle; production/usage are registry/bulk or absent; stock reservations and availability cache are derived | Recipe projection and restore; production/usage event ledger; rebuild caches | recipe editor `AUTO`; other facts `MISSING`; caches `DERIVED` |
| Menu item aggregate: `myitems`, variants, availability, item units, nutrition, modifier links | Versioned catalog masters | Main item add/edit/delete/price/status/upload and quick-create call menu recorder; units, availability, nutrition and modifier-only mutations are registry/bulk unless they also trigger item snapshot | `cloud_menu_items`; restore catalog before orders | item aggregate mostly `AUTO`; independent child mutations `MISSING` |
| Categories/units/settings/tables/areas/stores/payment methods/registers | Versioned master data | Category add and picker save are hooked, table mutations are hooked; category edit/delete, units, settings and most other masters are bulk-only or absent | Cloud menu/table/master projections; dependency-first restore | tables `AUTO`; remaining masters `BULK`/`MISSING` |
| Images: `imgs` metadata and item files | Blob metadata plus content hash/file | Item forms use image recorder and image queue | Cloud image endpoints; download after metadata restore | `AUTO` on canonical item forms; orphan image/admin upload paths require classification |
| Customers: legacy `customers`; current `pos_customers`, phones and addresses | Versioned customer aggregate; branch authoritative | Registry covers only legacy `customers`; current POS customer services have no outbox producer | Required customer projection and restore before delivery/order links | `MISSING` and critical |
| Delivery: clients, zones, fulfillment and external line mapping | Versioned master/workflow records | Domains exist in registry, but service mutation hooks are incomplete | Required remote fulfillment projection; restore before external links | `BULK`, therefore `MISSING` automatic coverage |
| Moova links: shop/table/order/change/line maps and external map | Integration identity/idempotency metadata | Order snapshots cover business order; links are mostly bulk-only and some sensitive request/response fields are excluded | Restore mappings needed for safe replay; never restore secrets/tokens | `BULK`/`MISSING`; sensitive payload exclusion is required |
| Approvals and order audit: `manager_approvals`, `order_events` | Append-only audit facts | Registered domains, but mutation services do not emit durable events | Required immutable remote audit and restore | `MISSING` and critical |
| General audit: `process`, `security_audit_log`, `recipe_audit_log`, repair-run ledger | Append-only audit/security facts with retention policy | No unified branch outbox path | Cloud audit projection; business/audit rows restored by policy, diagnostics retained remotely only | `MISSING`; raw credentials/request bodies must be excluded |
| Users/RBAC/employees | Versioned identities and permissions; secrets never synced | Employee add/edit is hooked; delete incorrectly records a saved snapshot after deletion and can yield no event; users/roles/grants are not covered | Remote management projection; guarded restore of users/roles without password/PIN/session secrets | employees partial; users/RBAC `MISSING` |
| Printing/KDS/fulfillment telemetry | Printer config versioned; jobs/tickets mostly append-only operational history | Printer/job domains are bulk-only; KDS rows are not in the sync contract | Printer config restore; job/fulfillment status for remote monitoring; KDS can be rebuilt from orders where safe | printers/jobs `MISSING`; rebuildable KDS state `DERIVED` |
| Pulse/customer visits and similar enabled operational logs | Append-only log plus versioned type/master | Pulse add/delete/type save is hooked; visit updates are not | Remote operational monitoring; restore only if feature enabled | pulse `AUTO`; visits `CONDITIONAL` gap |
| Sync infrastructure: outbox/inbox/checkpoints/conflicts/worker logs, request idempotency, document counters, schema migrations | Transport/control state | Internal | Preserve only through system backup/diagnostics; never business-restore from another node | `LOCAL` |
| Sessions, login throttles, locks, caches, availability cache, temporary queues | Ephemeral security/runtime state | Internal | Never restore as business state | `LOCAL`/`DERIVED` |
| Legacy HR/medical/rental/tasks/CRM modules visible in `do/` write audit | Outside the active restaurant POS contract unless explicitly enabled | No coherent sync contract | Excluded from this lean restaurant recovery tranche; enabling any module requires its own included-domain row and tests | `CONDITIONAL`, not silently covered |

This matrix has no unclassified critical restaurant-POS category. It does not claim that the `BULK` or `MISSING` rows are safe; those rows define the remaining implementation backlog. The runtime write audit still contains many legacy and maintenance scripts, so a future coverage test must use an explicit production-route manifest rather than assume every PHP file is an active shop workflow.

### Reliability and compatibility findings

1. Inbox idempotency is per idempotency key, but snapshot keys include the payload hash. Two different states of the same aggregate therefore use different keys and bypass the existing conflict check.
2. Order and table outbox rows currently persist `event_version = 1` even though their payloads contain a computed revision. Menu events persist their revision. Operational rows derive revisions from second-resolution timestamps or local ids; operational deletes always use version `1`.
3. Order, table, menu and generic operational projectors use unconditional `ON DUPLICATE KEY UPDATE`. Except for a terminal-drawer special case, they do not reject a delayed older event.
4. Generic deletes hard-delete the hosted/local mirror row by source integer id. Recipe bundle apply deletes and recreates child rows. Neither path has an aggregate cursor or durable tombstone guard.
5. Stable UUIDs are deterministic from branch UUID plus local table/id. This is branch-isolated and compatible with existing rows, but delete/recreate with an id reuse has the same identity. New event contracts need a durable entity UUID or generation marker before safe delete/recreate semantics can be claimed.
6. Existing queued schema-v1 order/table events need revision normalization from their embedded payload. A legacy event with no usable revision may seed an empty projection, but must never overwrite a projection that already has a reliable higher cursor.
7. Hosted code/schema must match the reviewed branch code before any pending queue replay. Current audit evidence says it does not.
8. Branch UUID must remain part of every idempotency, cursor and projection key. No aggregate state may be shared across tenants/branches.

### Manual restore contract

The existing restore command is dry-run by default and applies each event in a transaction, but `--apply` currently does not enforce a fresh backup, empty/selected scope, worker stop, local-newer comparison, reconciliation, or an operator confirmation token. It also prefers raw inbox history whenever any inbox event exists. Production apply must therefore remain disabled until it:

1. authenticates and verifies schema/event compatibility;
2. produces a dry-run plan with counts and conflicts;
3. requires a fresh local backup receipt and stopped branch workers;
4. permits only an empty database or an explicit bounded scope;
5. restores latest projections/dependency-ordered event facts without accepting stale versions;
6. records tombstones without destructive broad clears;
7. reconciles orders, money, drawers, inventory, catalog and high-water marks;
8. records an immutable restore receipt.

### Failure-test map

| Failure | Required proof |
| --- | --- |
| Exact retry/duplicate | one projection mutation, outbox marked synced |
| Delayed older event | returns `stale`; newer cloud state and tombstone remain unchanged |
| Equal revision with different hash | conflict/dead-letter; no overwrite |
| Sequence gap | held/reported or safely snapshot-healed; never silently skipped for append-only facts |
| Delete then delayed save | tombstone wins |
| Delete then legitimate recreate | new generation/UUID is accepted without reviving the deleted generation |
| Network outage/restart | local commit succeeds; expired claims recover; ordered backlog drains automatically |
| Cross-branch identity collision | independent cursor/projection state per branch UUID |
| Unsupported schema/event version | inbox retains event and reports incompatibility; no projection apply |
| Manual restore into non-empty/newer scope | dry-run reports conflict and apply refuses without explicit scoped policy |
| Restore interruption | each aggregate transaction rolls back/restarts idempotently and receipt shows partial progress |

### Recommended first Worker slice

The first implementation should be deliberately smaller than full coverage: establish a reusable aggregate projection-version gate and correct order/table producer versions before adding more domains.

Proposed allowed files:

- `classes/Sync/SyncProjectionVersionGuard.php` (new)
- `classes/Sync/SchemaManager.php`
- `classes/Sync/SyncInboxService.php`
- `classes/Sync/SyncOutboxEventService.php`
- `tests/sync/sync_projection_version_guard_test.php` (new)
- `tests/sync/sync_schema_migration_test.php`
- `tests/sync/pos_order_outbox_event_service_test.php`

Required behavior:

- Add a branch-and-aggregate-scoped projection cursor table through `SchemaManager`.
- Normalize order/table/menu revisions from payload for existing schema-v1 events.
- Persist the real payload revision for newly queued order/table events instead of hardcoded `1`.
- Before projection apply: accept newer, return `stale` for older, accept exact same-version/same-hash replay, and return conflict for same-version/different-hash.
- Update the cursor only in the same transaction after successful projection apply.
- Treat legacy version `1` without a stronger embedded revision as seed-only: it may initialize an absent aggregate but cannot overwrite a reliable cursor.
- Do not change delete behavior, restore apply, domain hooks, hosted flags or worker deployment in this slice.

Verification:

- focused migration test for fresh and upgraded schemas;
- duplicate/newer/older/equal-version-conflict/cross-branch/rollback tests;
- order/table outbox assertions for stored event version;
- existing inbox/apply/worker result tests;
- `git diff --check` and PHP lint on changed files.

Rollback is code rollback only after the new cursor table exists; the additive table can remain unused. Stop if equal-revision collisions cannot be handled compatibly, a required payload has no stable aggregate UUID, or the slice needs any production replay/deployment.

## Verification receipts

- `php tools/audit_write_paths.php --json`: completed; current scanner finds hundreds of runtime, legacy, tool and maintenance write surfaces, confirming the need for an active-route manifest.
- `php tests/sync/operational_sync_contract_test.php`: pass, but it checks registry/wiring strings rather than mutation-time completeness.
- `php tests/sync/branch_restore_contract_test.php`: pass, but it checks phase classification and endpoint wiring rather than safe `--apply` guards.
- `docker exec -e POSMAIN_TEST_MYSQL_HOST=mysql -e POSMAIN_TEST_MYSQL_PORT=3306 posmain-php vendor/bin/phpunit tests/sync/remaining_write_surfaces_outbox_test.php`: fail because the contract still expects direct endpoint hooks after routing moved capture into the mutation service.
- `php tests/sync/financial_float_journal_contract_test.php`: fail on a float cast in `FinancialLegacyRepairService`.
- `php tests/sync/single_store_operational_contract_test.php`: fail because the Cofe endpoint no longer contains the expected operational-store resolver call.
