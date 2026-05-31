# Phase 0 Completion Audit

Generated: 2026-05-29

Scope: Phase 0 of `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`.

This audit checks whether the discovery-only goal is proven before any sensitive stock behavior changes. It intentionally records only current state and does not change runtime behavior or UI.

## Result

Phase 0 is complete for source-level write-path discovery and non-runtime proof. Earlier read-only local runtime preflight evidence passed with `POSMAIN_DB_PORT=3307`, but the latest continuation still cannot connect to the Docker daemon or the default DB endpoint, so live-schema proof must be rerun before any behavior-changing phase.

## Requirement Audit

| Plan requirement | Evidence | Status |
| --- | --- | --- |
| Run `git status --short` | `implementation_discovery.md` records the current continuation worktree as dirty with many existing tracked and untracked POS/inventory changes. This Phase 0 pass only edits docs and the contract test. | Proven |
| Run `git diff --stat` | Current tracked/untracked state is not a clean baseline, so this audit treats the current worktree as authoritative and does not claim ownership of unrelated changes. | Proven for safe continuation |
| Checkpoint or isolate unrelated dirty work | Phase 0 changes stay limited to `docs/inventory/` and `tests/sync/inventory_write_path_map_contract_test.php`; unrelated runtime changes are not reverted or normalized. | Proven |
| Verify DB connection | Latest `php tools/recipe_runtime_preflight.php --json` returned `db_connect_failed` / `Connection refused` at `2026-05-31T12:09:29Z`; earlier `POSMAIN_DB_PORT=3307 php tools/recipe_runtime_preflight.php --json` passed with `ready_for_recipe_operator_qa = true`; latest Docker check still has no fresh live DB proof. | Source proof complete; live DB must be rerun |
| Verify migration mechanism | `implementation_discovery.md` identifies `tools/run_migrations.php` and `classes/Sync/SchemaManager.php`; `current_schema_findings.md` cites recipe inventory schema lines; earlier `POSMAIN_DB_PORT=3307 php tools/run_migrations.php --dry-run` returned `0 pending sync schema change(s)`; latest default `php tools/run_migrations.php --dry-run` failed with `Connection refused`. | Earlier local proof; rerun before behavior change |
| Create `docs/inventory/implementation_discovery.md` | File exists and records scope, runtime baseline, migration mechanism, source-of-truth findings, impacted surfaces, and Phase 0 decision. | Proven |
| Create `docs/inventory/write_path_map.md` | File exists and maps primary, secondary, sync, legacy helper, catalog, invoice bridge, legacy mirror, recipe ledger, and CLI migration/repair write paths with source-line proof. | Proven |
| Create `docs/inventory/invoice_type_map.md` | File exists and documents invoice type constants, seeded `pro_tybes`, UI route mapping, and conflicts. | Proven |
| Create `docs/inventory/current_schema_findings.md` | File exists and documents legacy schema, recipe ledger schema, units, store/branch identity, monitoring/reporting, and future test direction. | Proven |
| Audit `INSERT/UPDATE/DELETE fat_details` | `write_path_map.md` names all runtime PHP files found by the automated sweep and records exact source-line proof for triggers, add/edit/delete invoice paths, `do/doadd_invoice.php` internal `edit_id` replacement paths, opening balance, sync, direct legacy helpers, and table/order flows. | Proven |
| Audit `UPDATE myitems.itmqty/cost_price/last_price` | `write_path_map.md` covers trigger-driven summary refresh, global reindex, opening-balance mirror, reconciliation repair mirror, optional live-ledger mirror, item edit/import/price update, variant, and catalog metadata writers. | Proven |
| Audit `inventory_movements` writes | `write_path_map.md` covers repository/service writers, `InventoryInvoiceBridge`, `InventoryLedgerService`, opening balance direct write, CLI backfill, and pilot fixture setup path. | Proven |
| Audit `inventory_item_balances` writes | `write_path_map.md` covers repository/service upserts, recipe zero-balance placeholder creation before balance locks, opening balance, CLI balance rebuild, and pilot fixture setup path. | Proven |
| Audit `stock_reservations` writes | `write_path_map.md` covers `RecipeReservationService`, `StockReservationRepository`, reservation/release movements, consumed/released/expired status changes, and the apply-capable reservation expiry tool. | Proven |
| Audit recipe production batch stock | `write_path_map.md` covers `recipe_production.php`, `ProductionBatchMutationService`, `ProductionBatchService`, `ProductionBatchRepository`, input/output production movements, batch-line evidence, production accounting, availability refresh, and commit/cancel state changes. It also separates the old `productions` payroll-style page from the recipe production inventory workflow. | Proven |
| Audit invoice bridge/mirror ownership | `write_path_map.md` documents `InventoryInvoiceBridge` movement mapping, idempotency, reversal skip behavior, savepoint-protected ledger writes, and `InventoryLegacyMirrorService` compatibility mirror writes. | Proven |
| Audit service-level stock delegates | `write_path_map.md` documents endpoint-to-service calls for purchase receive/return, transfer send/receive, count close/reverse, waste/adjustment, recipe order lifecycle, and legacy operation delete reversal. | Proven |
| Audit CLI migration/repair tools | `write_path_map.md` documents apply-capable inventory tools separately from runtime PHP, including backfill, balance rebuild, reconciliation mirror repair, and legacy trigger retirement gates. | Proven |
| Audit recipe CLI stock/fixture tools | `write_path_map.md` documents recipe pilot fixture apply, guarded fixture stock adjustment, migrated paid-order write smoke, isolated browser fixture seeding, and reservation expiry as non-operator stock-affecting tools with safety gates. | Proven |
| Audit POS/table/external endpoints | `write_path_map.md` covers invoice POS, table mutation, table cancel/merge, Cofe, Cloud mirror, offline sync, and legacy POS sync surfaces. | Proven |
| Audit purchase invoice endpoints | `write_path_map.md` and `invoice_type_map.md` document purchase direction, cost update, and missing universal purchase-ledger movement. | Proven |
| Audit returns/refunds/voids | `write_path_map.md` documents invoice delete, table cancel, paid reversal/recipe reversal hooks, and legacy delete helpers. | Proven |
| Audit opening balance | `write_path_map.md` documents `items_start_balance.php` behavior and `save_start_balance.php` legacy plus recipe ledger writes. | Proven |
| List invoice `pro_tybe`/`fat_tybe` meanings | `invoice_type_map.md` lists code constants, seeded `pro_tybes`, UI route meanings, and type conflicts. | Proven |
| Verify branch/store identity | `implementation_discovery.md`, `write_path_map.md`, and `current_schema_findings.md` document `pos_tenant`, `pos_branch`, `branch_uuid`, `det_store`, and `store_id` behavior. | Source-level proven |
| Verify item unit model | `current_schema_findings.md` documents `myunits`, `item_units`, and `u_val`; `write_path_map.md` documents item edit and variant unit writers. | Source-level proven |
| Verify quantity read/monitoring surfaces | `current_schema_findings.md` maps current item amount reads across item list, dashboard recent items, opening balance, invoice item popup, item summary, stock levels, adjustments, inventory reports, recipe operational dashboard, and recipe availability. | Source-level proven |
| Separate workflow/config writes from stock movement writes | `current_schema_findings.md` maps stock levels, reason codes, purchase orders, purchase receipts, counts, and transfers as workflow/control writes, with notes on when they do or do not post ledger movements. | Source-level proven |
| Verify current tests and baseline failures | Non-runtime tests listed below passed; earlier read-only DB preflight and migration dry-run passed with `POSMAIN_DB_PORT=3307`; latest Docker daemon and default DB checks failed, so no fresh live DB proof was added. No behavior-changing runtime tests were run in this discovery phase. | Proven for source-level Phase 0 |
| Do not change runtime behavior | Only Markdown docs and a read-only contract test were added. No production PHP behavior was edited. | Proven |
| Arabic premium/smooth UI goal | No UI was changed in Phase 0 because the first goal explicitly forbids runtime behavior changes. The Arabic/premium UI requirement remains for later UI phases. | Deferred |

## Automated Sweep Coverage

`tests/sync/inventory_write_path_map_contract_test.php` now scans runtime PHP files outside `tests/`, `tools/`, `docs/`, `vendor/`, and dump folders for:

- `INSERT INTO fat_details`
- `UPDATE fat_details`
- `DELETE FROM fat_details`
- `UPDATE myitems SET`
- `INSERT INTO inventory_movements`
- `UPDATE inventory_movements`
- `INSERT INTO inventory_item_balances`
- `UPDATE inventory_item_balances`
- destructive inventory-table operations such as `DELETE FROM inventory_movements`,
  `DELETE FROM inventory_item_balances`, `DELETE FROM stock_reservations`,
  `DELETE FROM inventory_*` workflow tables, `TRUNCATE inventory_*`, and
  `DROP TABLE inventory_*`
- guarded destructive admin reset paths that use dynamic `DELETE FROM $table`
  SQL with stock/catalog table names such as `fat_details` or `myitems`
- catalog item creation/import/sync paths with `INSERT INTO myitems` and
  cost/price/barcode fields
- unit conversion writers with `INSERT INTO item_units`, `UPDATE item_units`,
  or `DELETE FROM item_units`
- recipe/BOM writers with `INSERT/UPDATE recipe_headers`,
  `INSERT/UPDATE/DELETE recipe_lines`, `DELETE/INSERT recipe_variant_lines`,
  and `INSERT recipe_cost_snapshots`
- recipe usage and availability cache writers with `insertRow($conn, 'recipe_order_line_usage')`,
  `UPDATE recipe_order_line_usage`, `INSERT INTO recipe_availability_cache`,
  and `putAvailability()` callers
- zero balance initialization through `INSERT IGNORE INTO inventory_item_balances`
- `UPDATE stock_reservations`
- helper writes through `insertRow($conn, 'inventory_movements')`, `insertRow($conn, 'stock_reservations')`, `insertRow($conn, 'production_batches')`, and `insertRow($conn, 'production_batch_lines')`
- `createMovement(`
- `putBalance(`
- reservation ownership through `RecipeReservationService` / `StockReservationRepository`
- production batch ownership through `ProductionBatchService` / `ProductionBatchMutationService` / `ProductionBatchRepository`
- POS line normalization through `InventoryMovementService::normalizeInvoiceLines()`

It also separately scans `tools/inventory_*.php` for CLI apply surfaces such as `applyFatDetailsBackfill()`, `applyBalanceRebuild()`, `applyMirrorRepair()`, and `inventoryRetireLegacyTriggersApply()`.
The map also names recipe CLI stock/fixture tools such as `tools/recipe_pilot_fixture.php`, `tools/recipe_fixture_stock_adjustment.php`, `tools/recipe_migrated_write_smoke.php`, `tools/recipe_cashier_browser_fixture.php`, and the apply-capable `tools/recipe_expire_reservations.php` path because they can write stock evidence even though they are not `inventory_*.php` files.
The CLI sweeps also catch direct stock/catalog SQL inside tools, including fixture-only `UPDATE myitems`, fixture `INSERT INTO fat_details`, isolated fixture `DROP DATABASE`, and trigger-retirement `DROP TRIGGER` statements.
They also scan other `tools/*.php` helpers for stock-like item master seeds and cleanup, so `tools/phase6_load_concurrency_check.php` and `tools/seed_demo_restaurant.php` remain visible even though they are not inventory or recipe tools.
The runtime sweep now includes guarded destructive admin reset files under `do/dbase/` and no longer hides them behind the root database-dump directory exclusion. `do/dbase/do_turncate.php` is documented because it dynamically deletes from `fat_details` and `myitems` in non-production reset contexts.
The same runtime sweep documents item-master creation/import/sync paths separately from stock movement paths. `do/doadd_item.php`, `js/ajax/doadd_item.php`, `setup_demo_data.php`, `pos_sync.php`, and `CloudLegacyPosMirrorService` do not create quantity movements, but they create or mirror catalog cost/price/barcode/unit metadata that inventory decisions rely on.
It also documents unit conversion writers separately. `do/doadd_item.php`, `js/ajax/doadd_item.php`, `do/doedit_item.php`, and `ItemVariantService` can create, update, delete, or reset `item_units`; those writes do not move stock immediately, but they control the `u_val` conversion applied by future sales, purchases, counts, transfers, adjustments, and recipes.
The map also documents recipe/BOM writers separately from actual movement writers. `recipe_manage.php`, `RecipeDefinitionService`, `RecipeRepository`, `RecipeLineRepository`, `RecipeVariantLineRepository`, `RecipeCostService`, and `RecipeCostSnapshotRepository` define active recipes, ingredient lines, variant overrides, and cost snapshots. They do not immediately change on-hand quantity, but future order reservations, paid consumption, production batches, and cost payloads depend on them.
The map also documents recipe usage and availability cache writers separately. `RecipeOrderLifecycleService`, `RecipeOrderLineUsageRepository`, `RecipeAvailabilityService`, `RecipeAvailabilityCacheRepository`, and `RecipePilotFixtureService` do not directly add or subtract on-hand quantity through these rows, but they preserve the evidence chain from a sold line to recipe explosion/reservation/consumption status and control cached POS/menu availability.

Runtime PHP sweep is clean against the current map. The inventory CLI apply-surface sweep is clean against the current map too. The recipe CLI stock/fixture sweep is clean against the current map as well. If any matching runtime PHP path, apply-capable inventory CLI tool, recipe CLI stock/fixture tool, or reservation expiry apply tool is not named in `write_path_map.md`, the test fails. The same contract also checks exact source lines for the highest-risk stock paths, including reservation create/status lines, so stale line references fail with `write path source-line proof drifted`.
The contract includes endpoint delegate line checks for stock services, because some stock effects are requested through service calls rather than direct SQL in the endpoint.
It also includes recipe production delegate line checks, because production batch stock is posted through service calls and repository writes rather than invoice `fat_details` rows.
The contract also checks that `current_schema_findings.md` keeps the quantity read/monitoring map visible, so future phases do not only migrate writers while leaving operator screens on old stock truth.
It also checks that workflow/control writes stay documented separately from stock movements, which protects later phases from treating draft purchase/count/transfer/config edits as if they already changed on-hand quantity. The purchase receipt evidence is documented through receipt headers/lines because they link received/returned quantities to movement IDs without becoming the balance source of truth.

## Verification Commands

Passed:

```sh
php tests/sync/inventory_write_path_map_contract_test.php
php -l tests/sync/inventory_write_path_map_contract_test.php
php tests/sync/items_start_balance_contract_test.php
php tests/sync/recipe_inventory_concurrency_contract_test.php
php tests/sync/save_order_endpoint_routing_test.php
php tests/sync/process_split_payment_endpoint_routing_test.php
php tests/sync/pos_takeaway_invoice_endpoint_routing_test.php
php tests/sync/recipe_waste_adjustment_contract_test.php
php tests/sync/inventory_phase1_noop_contract_test.php
php tests/sync/inventory_phase14_migration_tools_contract_test.php
rg -n "[ \t]+$" docs/inventory/write_path_map.md docs/inventory/implementation_discovery.md docs/inventory/invoice_type_map.md docs/inventory/current_schema_findings.md docs/inventory/phase0_completion_audit.md tests/sync/inventory_write_path_map_contract_test.php
```

Trailing-whitespace scan result: no matches.

DB-backed proof command with expected local-DB limitation in this session:

```sh
php tests/sync/inventory_phase4_invoice_bridge_test.php
```

Result: `inventory-phase4-invoice-bridge-skipped-db-unavailable` when the local DB is not available to the test process.

Runtime preflight:

```sh
php tools/recipe_runtime_preflight.php --json
```

Latest result: failed with `db_connect_failed`, `Connection refused`, blocker `recipe_runtime_database_unreachable`, checked at `2026-05-31T12:09:29Z`.

Earlier local Docker runtime preflight:

```sh
POSMAIN_DB_PORT=3307 php tools/recipe_runtime_preflight.php --json
```

Result: passed with `ready_for_recipe_operator_qa = true`, mode `consume_pilot`, no blockers, and warning `recipe_runtime_preflight_active_mode_use_pilot_evidence_gate`.

Migration dry-run:

```sh
POSMAIN_DB_PORT=3307 php tools/run_migrations.php --dry-run
```

Result:

```text
Migration tracking: ready.
Dry run: 0 pending sync schema change(s).
```

Latest live-schema attempt in this continuation:

```sh
docker ps --format '{{.Names}} {{.Status}} {{.Ports}}'
```

Result: failed with `Cannot connect to the Docker daemon at unix:///Users/ab.mansour1agmail.com/.docker/run/docker.sock. Is the docker daemon running?`

Latest default migration dry-run attempt:

```sh
php tools/run_migrations.php --dry-run
```

Result: failed with `Connection refused` when connecting to `127.0.0.1:3306`.

## Stop Conditions

| Stop condition | Current finding |
| --- | --- |
| DB cannot be reached and migration/schema work depends on runtime DB | Active for fresh live proof in the latest continuation because the Docker daemon is unavailable and the default DB endpoint refuses connections; earlier local proof with `POSMAIN_DB_PORT=3307` remains historical evidence only. |
| Active write path cannot be classified | Not active. Runtime PHP sweep and inventory CLI apply-surface sweep are clean against `write_path_map.md`. |
| Item/store/branch identity is ambiguous | Not blocking for source discovery. Final ownership still needs design decisions, but current code paths are documented. |
| Baseline tests fail for unrelated reasons | Not observed in non-runtime checks. Fresh read-only runtime preflight/dry-run could not be rerun because Docker is unavailable; behavior-changing runtime tests were intentionally not run in Phase 0. |

## Next Phase Gate

Before Phase 1 changes behavior, rerun DB preflight with the intended local or deployment DB configuration. For this local Docker stack, first make the Docker daemon available, then use `POSMAIN_DB_PORT=3307`. If the DB is reachable, keep migration dry-run green and run the relevant runtime recipe/POS tests before any live stock-write behavior is enabled. The default `127.0.0.1:3306` path is currently not a valid fresh proof path because it refuses connections.

### Allowed Without DB

The next phase may add disabled-by-default, no-op service scaffolding only if it preserves old behavior and passes source-level tests. Safe examples from the plan:

- `classes/Inventory/InventoryFeatureFlags.php`
- `classes/Inventory/InventoryScopeResolver.php`
- `classes/Inventory/InventoryDecimal.php`
- no-op/shadow-safe service constructors that do not write stock
- tests proving default flags are off and old behavior is unchanged

### Must Wait For DB

Do not start these unless runtime preflight passes for the intended DB configuration:

- live stock ledger proof,
- live reconciliation against `inventory_movements` / `inventory_item_balances`,
- any behavior that writes new inventory ledger rows from POS, purchase, refund, transfer, count, or waste workflows.

### Carry-Forward Invariants

Future behavior-changing phases must keep these invariants true:

- old `fat_details` / `myitems.itmqty` behavior remains active until shadow/reconciliation proves replacement behavior;
- no new table is added unless existing recipe/inventory tables cannot safely represent the workflow;
- every new inventory writer has an idempotency key and store/tenant/branch scope;
- every stock-affecting flow is represented in `write_path_map.md`, including bridge/mirror writers and CLI apply tools;
- `POSMAIN_INVENTORY_COST_PUBLIC_PAYLOADS` or equivalent public-cost flag must default off;
- Arabic UI changes are deferred to the UI phase and must be operator-friendly, not developer-facing diagnostics.

### Minimum Phase 1 Entry Tests

Before any Phase 1 branch proceeds beyond no-op service scaffolding, keep these green:

```sh
php tests/sync/inventory_write_path_map_contract_test.php
php tests/sync/items_start_balance_contract_test.php
php tests/sync/recipe_inventory_concurrency_contract_test.php
php tests/sync/save_order_endpoint_routing_test.php
php tests/sync/process_split_payment_endpoint_routing_test.php
php tests/sync/pos_takeaway_invoice_endpoint_routing_test.php
php tests/sync/recipe_waste_adjustment_contract_test.php
```
