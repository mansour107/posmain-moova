# Phase 10 Production Inventory Contracts

## Scope

Phase 10 keeps the existing production tables and recipe movement services, then makes the production screen usable as an inventory operation surface.

No new tables are introduced in this phase.

## Functional Contracts

- Operators create a draft production batch from `recipe_production.php` by choosing an active production recipe, planned output quantity, store, and notes.
- Draft batches show an input preview before commit.
- The preview shows each input item by name, required quantity, on-hand quantity, reserved quantity, available quantity, shortage quantity, and cost columns when the user can view costs.
- Available quantity is calculated as `qty_on_hand - qty_reserved` from `inventory_item_balances`.
- If actual output differs from planned output, `variance_reason` remains required by `ProductionBatchService::commit()`.
- Commit remains restricted to draft batches and blocks duplicate commits by status.
- Strict stock mode still blocks commit when required inputs are not available.
- A committed batch writes linked `production_input` and `production_output` rows in `inventory_movements`, creates `production_batch_lines`, refreshes availability when recipe availability mode is enabled, and records the final committed status.
- When `POSMAIN_INVENTORY_LEDGER_MODE` is `bridge` or `live`, production input/output movements are posted through `InventoryLedgerService`. This gives production the same payload-hash idempotency, audit write, strict-stock enforcement, availability signals, and optional legacy mirror behavior as the rest of the Inventory module.
- Selling a batch-prepared recipe consumes the prepared output item, not the raw ingredients again. `RecipeExplosionService` maps `batch_prepared` sales to a single `prepared_stock` requirement whose `ingredient_item_id` is the recipe sellable/output item, and `RecipeOrderLifecycleServiceTest::testBatchPreparedPaidOrderConsumesPreparedStockNotRawInputs()` proves the prepared balance decreases while the raw-input balance remains unchanged.
- The old recipe production route and form field names remain stable: `create_draft`, `commit`, `cancel`, `recipe_id`, `planned_output_qty`, `store_id`, `actual_output_qty`, `variance_reason`, and `cancel_reason`.

## UI Contracts

- The production page is Arabic-first and RTL.
- Recipe selection labels prioritize recipe name, output item name, version, and yield instead of exposing raw item IDs as the main label.
- Draft creation includes a recipe/output-item search field that filters the existing `recipe_id` select by recipe name or output item and shows an Arabic match count, keeping the stable form contract while avoiding long-dropdown scrolling.
- Store selection uses stock account names from `acc_head.is_stock = 1` when that table exists, with numeric fallback for isolated test schemas.
- Batch detail cards show status, recipe, output item, store, planned quantity, actual quantity, commit time, variance reason, and batch UUID.
- The input preview highlights shortages and shows a clear `متاح` state when inputs are sufficient.
- The committed lines table shows input/output/variance lines and linked movement IDs.

## Compatibility Notes

- Existing backend commit semantics were not replaced at the page/form boundary. Production still calls `RecipeInventoryMovementService::recordProductionInput()` and `recordProductionOutput()`, but those methods now delegate production input/output writes to `InventoryLedgerService` whenever Inventory ledger writes are enabled.
- The older recipe repository write path remains only as the compatibility path for recipe-enabled deployments that have not yet enabled Inventory ledger bridge/live mode.
- The page keeps the same route and POST contract so existing endpoint runtime tests and authenticated smoke checks continue to cover the flow.

## Tests

- `tests/sync/inventory_phase10_production_surface_contract_test.php`
- `tests/sync/recipe_production_endpoint_runtime_test.php`
- `tests/sync/recipe_stock_operations_surface_smoke_contract_test.php`

These tests cover the UI contract, endpoint draft/commit behavior, linked production movements, duplicate commit protection, and authenticated smoke expectations.
The Phase 10 surface contract also pins the prepared-stock sale path so a future production rewrite cannot accidentally consume both prepared output stock and raw input ingredients for the same sale.
