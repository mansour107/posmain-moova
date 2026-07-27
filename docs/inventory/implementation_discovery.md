# Inventory Implementation Discovery

Generated: 2026-05-29

This is Phase 0 discovery for the Foodics-level inventory restructure plan in `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`. It documents the current write paths and compatibility risks only. No runtime behavior is changed by this phase.

## Scope

- Map where inventory quantities, item costs, reservations, and recipe balances are currently written.
- Identify the legacy and recipe-ledger sources of truth.
- Record migration/schema mechanism and current runtime verification limits.
- Create a baseline that later phases can use before removing legacy inventory behavior.

## Git And Runtime Baseline

- Repo root: `/Users/ab.mansour1agmail.com/Desktop/projects/posmain`
- Current continuation worktree: dirty with many existing tracked and untracked POS/inventory changes. Phase 0 documentation/testing work must not revert or assume ownership of unrelated changes.
- Runtime behavior changed by this phase: none.
- Runtime preflight command, default CLI config: `php tools/recipe_runtime_preflight.php --json`
- Default CLI runtime preflight result: failed with `db_connect_failed` / `Connection refused`.
- Earlier local Docker DB evidence: `docker ps -a` showed `posmain-mysql` running on `127.0.0.1:3307->3306`.
- Runtime preflight command, local Docker port: `POSMAIN_DB_PORT=3307 php tools/recipe_runtime_preflight.php --json`
- Earlier local Docker runtime preflight result: passed with `ready_for_recipe_operator_qa = true`, mode `consume_pilot`, no blockers, and warning `recipe_runtime_preflight_active_mode_use_pilot_evidence_gate`.
- Migration dry-run command: `POSMAIN_DB_PORT=3307 php tools/run_migrations.php --dry-run`
- Earlier migration dry-run result: `Migration tracking: ready.` and `Dry run: 0 pending sync schema change(s).`
- Latest continuation live-schema attempt: `docker ps` and `docker start posmain-mysql posmain-php` both failed with `Cannot connect to the Docker daemon at unix:///Users/ab.mansour1agmail.com/.docker/run/docker.sock. Is the docker daemon running?`
- Latest continuation default DB attempt: `php tools/recipe_runtime_preflight.php --json` failed with `db_connect_failed` / `Connection refused` at `2026-05-31T12:09:29Z`; `php tools/run_migrations.php --dry-run` also failed with `Connection refused` against `127.0.0.1:3306`.

## Current Inventory Shape

POSMAIN currently has two stock models:

| Model | Tables | Main writers | Main readers | Current role |
| --- | --- | --- | --- | --- |
| Legacy invoice stock | `fat_details.qty_in`, `fat_details.qty_out`, `myitems.itmqty`, `myitems.cost_price`, `myitems.last_price` | invoice add/edit/delete, POS/table order services, Cofe, opening balance | item list, sales reports, receipt/summary pages, POS grid snippets | Still the broad/default stock source for normal items and legacy reports. |
| Recipe inventory ledger | `inventory_movements`, `inventory_item_balances`, `stock_reservations`, `recipe_order_line_usage` | recipe lifecycle, production, waste/adjustment, opening balance bridge | recipe availability, reconciliation, recipe reports, ingredient opening balance display | Newer scoped ledger for ingredients/packaging/recipe consumption. |

The restructure should treat this as a dual-truth migration problem. The goal is not to add another layer, but to converge all stock-affecting writes onto one explicit inventory service and then retire legacy quantity side effects safely.

## Migration Mechanism

The active additive schema path is:

- `tools/run_migrations.php`
- `classes/Sync/SchemaManager.php`

Important observed behavior:

- `tools/run_migrations.php --dry-run` or `--apply` connects through `includes/db_bootstrap.php`.
- `--apply` requires `--backup-file` or explicit `--confirm-no-backup`.
- The tool detects pending statements from `SyncSchemaManager`.
- Destructive statements require `--allow-destructive` plus a readable backup.
- Recipe inventory tables are already planned in `SyncSchemaManager`, including `recipe_headers`, `recipe_lines`, `recipe_order_line_usage`, `inventory_movements`, `inventory_item_balances`, and `stock_reservations`.

Earlier proof with `POSMAIN_DB_PORT=3307` reached the local Docker database and reported zero pending sync schema changes. In the latest continuation, Docker is not reachable and the default DB endpoint refuses connections at `127.0.0.1:3306`, so this is historical evidence only. Future behavior-changing phases must rerun the DB preflight and migration dry-run against the intended DB before enabling live stock writes or retiring legacy behavior.

## Phase 0 Evidence Commands

Read-only inspection used these command families:

- `rg` scans for `INSERT/UPDATE/DELETE fat_details`, `myitems.itmqty/cost_price/last_price`, `inventory_movements`, `inventory_item_balances`, invoice types, store/branch fields, and unit fields.
- `nl -ba` source inspections for the exact write paths documented in `write_path_map.md`.
- `php tools/recipe_runtime_preflight.php --json` to check runtime DB reachability.
- `php tests/sync/inventory_write_path_map_contract_test.php` to prove the write-path map names every scanned runtime writer and inventory CLI apply surface.

Additional runtime stock-write surfaces found after the first pass:

- Cloud legacy mirror: `classes/Sync/CloudLegacyPosMirrorService.php`.
- POS mutation orchestration: `classes/Pos/Service/PosOrderMutationService.php`.
- Table merge/cancel helpers: `classes/Pos/Service/TableMergeService.php`, `classes/TableOrderService.php`.
- Retired direct legacy stock helpers: `js/ajax/add_row_to_fat_details.php`, `js/ajax/insertfatdet.php`, `js/ajax/delitmdet.php`, `js/ajax/reindex.php`, `do/offline_sync.php` stock replay, `pos_sync.php` stock replay, and `do/doadd_invoice_clothes.php` now return stable retired-endpoint responses instead of writing/deleting stock or rebuilding `myitems.itmqty`.
- Clothes POS order entry now posts to the general `do/doadd_invoice.php` POS handler with CSRF and payment fields, so it uses the supported POS/invoice lifecycle instead of a specialized stock writer.
- Guarded destructive admin reset: `do/dbase/do_turncate.php` dynamically deletes from a broad table list containing `fat_details` and `myitems` after `production_guard_deny_route()` allows the route. It is not an inventory workflow, but it is a stock/catalog destruction path and must stay visible during legacy retirement.
- Legacy operation delete: `do/dodel_pro.php`.
- Catalog item/cost/unit/stock-policy writers: `do/doadd_item.php`, `js/ajax/doadd_item.php`, `setup_demo_data.php`, `do/uploaditems.php`, `do/update_item_price.php`, `do/doedit_item.php`, `pos_sync.php`, `classes/Sync/CloudLegacyPosMirrorService.php`, `classes/Pos/Service/ItemVariantService.php`, and `classes/Items/ItemRecipeCatalogService.php`. Item and unit creation does not move quantity, but it creates cost, unit conversion, barcode, item type, and track-stock inputs that later inventory movements and screens depend on.
- POS line normalization: `classes/Pos/Service/InventoryMovementService.php`.
- Inventory invoice bridge: `classes/Inventory/InventoryInvoiceBridge.php`.
- Legacy mirror writers: `classes/Inventory/InventoryLegacyMirrorService.php` and optional live-ledger mirror writes inside `classes/Inventory/InventoryLedgerService.php`.
- inventory CLI apply tools: `tools/inventory_backfill_from_fat_details.php`, `tools/inventory_rebuild_balances.php`, `tools/inventory_reconciliation_repair.php`, and `tools/inventory_retire_legacy_triggers.php`.
- Reservation writers: `classes/Recipe/RecipeReservationService.php`, `classes/Recipe/Repository/StockReservationRepository.php`, and the apply-capable `tools/recipe_expire_reservations.php`.
- Recipe production writers: `recipe_production.php`, `classes/Recipe/ProductionBatchMutationService.php`, `classes/Recipe/ProductionBatchService.php`, and `classes/Recipe/Repository/ProductionBatchRepository.php`. This path consumes inputs and adds output stock through `production_input` / `production_output` movements without going through invoice add/edit/delete.
- Recipe definition writers: `recipe_manage.php`, `classes/Recipe/RecipeEditorMutationService.php`, `classes/Recipe/RecipeDefinitionService.php`, `classes/Recipe/Repository/RecipeRepository.php`, `classes/Recipe/Repository/RecipeLineRepository.php`, `classes/Recipe/Repository/RecipeVariantLineRepository.php`, `classes/Recipe/RecipeCostService.php`, and `classes/Recipe/Repository/RecipeCostSnapshotRepository.php`. These do not move stock immediately, but they define future ingredient/packaging/sub-recipe consumption, variant overrides, and cost snapshots used by order/production movements.
- Recipe usage and availability cache writers: `classes/Recipe/RecipeOrderLifecycleService.php`, `classes/Recipe/Repository/RecipeOrderLineUsageRepository.php`, `classes/Recipe/RecipeAvailabilityService.php`, `classes/Recipe/Repository/RecipeAvailabilityCacheRepository.php`, and `classes/Recipe/RecipePilotFixtureService.php`. These do not directly add or subtract on-hand quantity, but they preserve the evidence chain from a sold line to its recipe explosion/reservation/consumption status and refresh the cached availability that POS/menu channels use to decide whether a recipe item can be sold.
- Recipe CLI stock/fixture writers: `tools/recipe_pilot_fixture.php`, `tools/recipe_fixture_stock_adjustment.php`, `tools/recipe_migrated_write_smoke.php`, and `tools/recipe_cashier_browser_fixture.php`. These are not ordinary operator workflows, but they can seed fixture stock, post a guarded QA adjustment, run a migrated paid-order stock smoke, or insert browser-fixture `fat_details` rows in a temporary DB.
- Legacy daily production/payroll surface: `production.php`, `do/doadd_production.php`, `do/doedit_production.php`, and `do/dodel_production.php` use the old `productions` table only. They are not current inventory stock writers, but the name collision should be handled during UI/IA cleanup so operators do not confuse payroll-style production entries with stock-producing recipe batches.

These are classified in `docs/inventory/write_path_map.md` so later phases do not accidentally route only the obvious invoice/POS paths and leave old direct stock writes behind.

Focused verification added by this phase:

- `php tests/sync/inventory_write_path_map_contract_test.php`
- Result: passed, output `inventory-write-path-map-contract-ok`.
- Coverage: scans runtime PHP files outside `tests/`, `tools/`, `docs/`, `vendor/`, and dump folders for legacy stock writes, item master writes, recipe movement writes, recipe balance writes, stock reservation writes, repository movement/balance calls, and POS line normalization.
- Coverage: separately scans `tools/inventory_*.php` for apply-capable inventory CLI surfaces.
- Coverage: separately checks recipe CLI stock/fixture tools and the recipe reservation expiry tool because they can write stock evidence even though they are not `inventory_*.php` tools.
- Coverage: catches direct stock/catalog SQL inside CLI tools, including fixture-only `UPDATE myitems`, fixture `INSERT INTO fat_details`, isolated fixture `DROP DATABASE`, and trigger-retirement `DROP TRIGGER` statements, so apply-capable utilities cannot hide outside runtime endpoint scans.
- Coverage: checks non-inventory/non-recipe CLI tools for stock-like item master seeds and cleanup, including the Phase 6 load-concurrency checker and the demo restaurant seeder.
- Coverage: checks exact source lines for the highest-risk stock paths, including `fat_details` triggers, add/edit/delete invoice writes, opening balance writes, `InventoryInvoiceBridge`, `InventoryLegacyMirrorService`, `InventoryLedgerService` mirror writes, stock reservation create/status writes, and CLI apply gates. Stale line proof fails with `write path source-line proof drifted`.
- Coverage: names the internal `do/doadd_invoice.php` `edit_id` replacement behavior separately from `do/doedit_invoice.php`, because it can soft-delete or hard-delete legacy `fat_details` rows before reinserting replacement details.
- Coverage: names recipe zero-balance initialization separately from real on-hand changes, because `RecipeInventoryMovementService` can create an empty scoped `inventory_item_balances` row before locking and posting a later movement.
- Coverage: checks endpoint-to-service delegate lines for purchase receive/return, transfer send/receive, count close/reverse, waste/adjustment, recipe order lifecycle, and legacy operation delete reversal so service-level stock writes cannot hide behind raw SQL scans.
- Coverage: checks recipe production delegate lines for draft creation, commit, input/output movement posting, batch-line evidence, production accounting, availability refresh, and commit/cancel status updates so non-invoice production stock cannot hide behind the generic `RecipeInventoryMovementService` row.
- Coverage: checks destructive inventory-table operations such as deleting ledger movements, deleting balance rows, deleting reservations, deleting inventory workflow rows, truncating inventory tables, or dropping inventory tables, so a future destructive stock path cannot bypass the Phase 0 map just because it is not an insert/update writer.
- Coverage: checks guarded destructive admin reset paths that use dynamic `DELETE FROM $table` SQL with stock/catalog table names, so reset routes cannot hide behind variable table names.
- Coverage: checks catalog creation/import/sync paths with `INSERT INTO myitems` and cost/price/barcode fields, so item-master writers stay separate from stock movement writers but visible to the restructuring plan.
- Coverage: checks `item_units` insert/update/delete writers separately as unit conversion writers, because changing `u_val` changes how future invoice, purchase, count, transfer, adjustment, and recipe quantities become base stock quantities.
- Coverage: checks recipe/BOM writers separately from movement writers, including `recipe_headers`, `recipe_lines`, `recipe_variant_lines`, and `recipe_cost_snapshots`, so future consumption rules cannot change outside the inventory restructuring map.
- Coverage: checks recipe usage/cache writers separately from movement writers, including `recipe_order_line_usage` status changes, `recipe_availability_cache` upserts, and `putAvailability()` callers, so a future migration cannot preserve stock movements while losing order-line evidence or POS/menu availability control.
- Coverage: checks the current quantity read/monitoring map in `current_schema_findings.md`, including item list, invoice popup, opening balance, item summary, stock levels, adjustments, inventory reports, recipe operations dashboard, and recipe availability.
- Coverage: checks the current inventory workflow/control map in `current_schema_findings.md`, including stock-level thresholds, reason codes, purchase orders, inventory counts, and transfer headers/lines. These are separated from direct stock movement writes so future phases do not confuse draft/config edits with posted ledger movements.

Existing adjacent non-runtime checks to run with this phase are:

- `php -l tests/sync/inventory_write_path_map_contract_test.php`
- `php tests/sync/items_start_balance_contract_test.php`
- `php tests/sync/recipe_inventory_concurrency_contract_test.php`
- `php tests/sync/inventory_phase1_noop_contract_test.php`
- `php tests/sync/inventory_phase14_migration_tools_contract_test.php`
- `git diff --check -- docs/inventory/phase0_completion_audit.md docs/inventory/write_path_map.md tests/sync/inventory_write_path_map_contract_test.php`
- Results: all focused non-runtime checks passed in the latest Phase 0 proof pass.

DB-backed adjacent proof that may skip without local DB:

- `php tests/sync/inventory_phase4_invoice_bridge_test.php`
- Latest observed result without local DB access in the test process: `inventory-phase4-invoice-bridge-skipped-db-unavailable`.

Runtime tests that mutate DB state were not run in this discovery phase. The read-only preflight and migration dry-run were run against the local Docker DB with `POSMAIN_DB_PORT=3307`.
Those DB proofs are now historical because the latest Docker daemon check failed and the default DB endpoint refused connections; rerun them before the first behavior-changing phase.

## Source-Of-Truth Findings

### Legacy Stock

`db/DB.sql` defines `fat_details` with `qty_in`, `qty_out`, `det_store`, `pro_tybe`, `fat_tybe`, `tenant`, and `branch`. The base schema no longer creates the legacy `update_after_update` / `update_balance_trigger` triggers that recomputed `myitems.itmqty` from `fat_details`.

Existing databases may still contain those old triggers until the guarded trigger-retirement tool is applied after reconciliation. Those triggers are unsafe as final inventory truth because they compute a global item summary from `fat_details` and do not scope by store, tenant, or branch.

`myitems` stores `itmqty`, `cost_price`, and `last_price`, so item cost and quantity are mixed into the catalog table. That is easy for old screens, but unsafe as the final production inventory truth.

### Recipe Ledger

`InventoryMovementRepository::createMovement()` inserts validated rows into `inventory_movements` through the shared `insertRow()` repository helper, so Phase 0 treats explicit helper calls into inventory tables as write paths, not just raw SQL. It enforces:

- movement/source type allowlists,
- required idempotency key,
- no negative movement quantities/costs,
- positive unit conversion,
- no row with both `qty_in` and `qty_out` positive.

`InventoryBalanceRepository::putBalance()` upserts `inventory_item_balances` by `(pos_tenant, pos_branch, store_id, item_id)` and stores `qty_on_hand`, `qty_reserved`, `qty_available`, `moving_average_cost`, and `last_movement_id`.

`RecipeReservationService` and `StockReservationRepository` own the active reservation lifecycle. They create `stock_reservations`, write reservation/release movements, update reservation status to consumed/released/expired, and therefore change availability through `qty_reserved` even when on-hand quantity is unchanged.

This is the better final inventory shape, but it is not yet the universal writer for all inventory-affecting actions.

## Invoice Type Map

See `docs/inventory/invoice_type_map.md` for the detailed map. The highest-risk discovery is that type `14` is used inconsistently:

- `do/doadd_invoice.php` and UI routing treat `14` as `OFFER`.
- `db/DB.sql` seeds `pro_tybes.id = 14` as `رصيد افتتاحي مخازن`.
- `save_start_balance.php` hard-codes `POSMAIN_OPENING_BALANCE_PRO_TYPE = 14`.

This conflict must be resolved before the legacy system is removed or before invoice types become part of a strict production inventory ledger.

## Compatibility Risks

| Surface | Risk |
| --- | --- |
| POS/table sales | Selling items currently writes `fat_details.qty_out`; recipe consumption is additionally recorded only through recipe lifecycle hooks. |
| Purchase invoices | Purchases update `fat_details.qty_in` and can update item `last_price`/`cost_price`, but do not universally write `inventory_movements` as purchase movements. |
| Opening balance | Writes both legacy `fat_details`/`myitems` summary and recipe ledger when recipe tables exist. Uses type `14`, which conflicts with offer/opening-balance meanings. |
| Deletes/voids/refunds | Legacy delete marks `fat_details.isdeleted = 1`; recipe bridge records delete/refund behavior for POS orders, but older non-POS invoice deletes remain legacy-only. |
| Store/branch | Legacy trigger ignores `det_store`, `tenant`, and `branch`; recipe balance is scoped by tenant/branch/store. |
| Units | Legacy quantities are multiplied by `u_val`; recipe ledger has `unit_id` and `unit_conversion_to_base`. These need a single rule before final cutover. |
| Reports/UI | Many screens still read `myitems.itmqty` or `fat_details` directly; newer recipe reports read the recipe ledger. |

The detailed read/monitoring surface map is in `current_schema_findings.md`. The important Phase 0 finding is that write-path convergence alone is not enough: quantity display and availability reads also need a controlled cutover, because today the item list and invoice popup can still show legacy `myitems.itmqty` while inventory reports and recipe availability use ledger balances.

`current_schema_findings.md` also separates workflow/control writes from direct stock writes. Stock levels, reason codes, purchase order drafts, purchase receipt evidence, count drafts, and transfer drafts are important inventory workflows, but they should not be treated as on-hand changes until the relevant receiving, closing, sending, receiving, or adjustment service posts a ledger movement. Receipt/count/transfer lines are still important Phase 0 evidence because they explain why ledger movements were posted and link operators back to the transaction they used.

## Impacted Surfaces For Future Phases

- API contracts: POS invoice add/edit/delete, table order mutation endpoints, Cofe/Moova external order endpoints, recipe production/waste/adjustment endpoints.
- Shared services: `InventoryMovementService`, `RecipeInventoryMovementService`, `RecipeOrderLifecycleService`, `LegacyInvoiceRecipeLifecycleBridge`, `PosOrderMutationService`, `PosOrderService`.
- Database access: `fat_details` triggers, `myitems` summary fields, `inventory_movements`, `inventory_item_balances`, `stock_reservations`.
- State shape: tenant/branch/store identity, unit conversion, idempotency keys, movement source IDs.
- UI flows: item list, opening balance, invoice screens, POS grid availability, recipe reconciliation/audit/operations pages.
- Auth/permissions: manager approvals for refunds/voids already exist; future inventory count/transfer/waste should require explicit permissions.
- Integrations: Cofe direct order endpoint, Moova order apply services, sync outbox/reporting.

## Phase 0 Decision

Do not remove or rewrite legacy stock yet. The next safe step is to choose the final inventory writer contract and then migrate one flow at a time behind focused tests. The current docs and contract test should be updated together whenever a later phase intentionally changes a write path.
