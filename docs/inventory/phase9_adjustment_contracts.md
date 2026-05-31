# Phase 9 Waste And Adjustment Contracts

Generated: 2026-05-30

Scope: Phase 9 of `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`.

## Implemented

- `InventoryAdjustmentService` writes waste and stock adjustments through `InventoryLedgerService`.
- No new tables were added.
- Waste writes outbound `waste` movements.
- Increase adjustments write inbound `adjustment` movements.
- Decrease adjustments write outbound `adjustment` movements.
- Operators can select an active reason code from the existing `inventory_reason_codes` table for waste or adjustment operations; free-text reason notes remain supported for compatibility.
- Selected reason codes are validated against tenant/branch scope, reason group, movement direction, active state, and manager-approval requirements before any ledger movement is posted.
- The ledger movement metadata stores `reason_code_id`, `reason_code`, `reason_name`, `reason_group`, and `reason_requires_approval` in addition to the existing free-text `reason`.
- selected adjustment units are validated against `item_units`; operator-entered quantities stay in the selected unit while ledger movements post base quantities using `unit_conversion_to_base`.
- Unit cost is stored as base-unit cost in the ledger. When a selected unit cost is entered, the service derives base cost from entered total divided by base quantity.
- Unit cost defaults from the current scoped moving average cost when not provided.
- Non-stock/service items are blocked.
- Backdated operations are blocked unless the caller has approval context.
- Waste and decrease adjustments that would make on-hand stock negative are blocked unless the caller has manager/accounting approval context.
- Approval context is supplied only by `inventory.approve` or `accounting.view`; ordinary `inventory.edit` users can post normal current-date, non-negative operations but cannot unlock backdated, negative-result, or approval-required reason-code adjustments.
- The new `inventory_adjustments.php` Arabic operator screen hides raw item/store IDs behind selectors and shows on-hand/available balance for the selected item/store.
- The recent-operation table translates `waste` / `adjustment` and `in` / `out` technical movement tokens into Arabic labels while keeping the ledger values unchanged.
- The item picker is now an Arabic autocomplete/combobox. Operators search by item name or barcode, select from a compact result list, and the page keeps the existing hidden item select as the canonical value so unit, cost, balance, and submit logic keep using the same safe item id contract.
- The adjustment UI includes a unit selector for each selected item and sends `unit_id` only when the operator selects a non-base unit.
- The adjustment UI defaults the unit selector from existing stock-level preferences, preferring `preferred_count_unit_id` then `preferred_purchase_unit_id` for the selected store/item. This only changes the visible selected unit; `InventoryAdjustmentService` still validates the submitted unit and posts base quantities.
- The adjustment UI includes a reason-code selector that changes with the selected action: waste, increase, or decrease.
- `inventory_reason_codes.php` provides an Arabic administration screen for creating, editing, retiring, and reactivating non-system reason codes.
- The reason-code page can auto-generate internal codes from the selected group and entered name when a manager leaves the internal-code field blank, so normal setup can be done by choosing Arabic labels instead of inventing technical constants.
- `ajax/inventory_reason_code.php` is POST-only, CSRF-protected by `inventory_reason_code`, and requires `inventory.edit`.
- Reason-code administration reuses the existing `inventory_reason_codes` table. It never writes inventory movements, balances, or adjustment records.
- System reason codes are visible but locked from operator edits, so seeded or migration-owned codes cannot be accidentally renamed.
- In-app browser smoke verified the bridge-mode adjustment screen renders in RTL Arabic, has no current-page console/PHP errors, enables posting when the ledger can write, and avoids horizontal viewport overflow.
- `ajax/inventory_adjustment.php` is POST-only, CSRF-protected by `inventory_adjustment`, and requires `inventory.edit`.
- Photo attachment for waste is implemented without adding a new table. The Arabic adjustment screen shows an optional image upload only for the waste action, sends it as `waste_photo`, and `ajax/inventory_adjustment.php` stores validated images through the shared upload guard under `uploads/inventory_waste`.
- Waste photo evidence is stored on the resulting ledger movement as sanitized `metadata_json.photo_attachment` fields: relative path, generated file name, original name, MIME type, size, SHA-256 hash, upload timestamp, and storage adapter. The service rejects photo metadata for non-waste adjustments.
- `reports.php` links the waste/adjustment operator action to `inventory_adjustments.php?from=recipe_reports`.
- Recipe operator readiness now treats `inventory_adjustments.php` as the primary waste/adjustment operator surface: `tools/recipe_stock_operations_surface_smoke.php` performs its read-only waste/adjustment GET smoke against the Arabic Inventory screen, and `RecipeRuntimePreflightService` checks the Inventory page, CSRF meta boundary, and `ajax/inventory_adjustment.php` endpoint wiring instead of using the legacy recipe page as the operator readiness surface.
- The guarded Recipe QA fixture stock replenishment helper now writes through `InventoryAdjustmentService`, requires writable inventory ledger mode, and still preserves the previous production/hosted/runtime, dry-run, Recipe-QA-only, idempotency, and no-direct-balance-update guardrails.
- Recipe rollout evidence parsing now requires the Inventory module waste/adjustment proof surface (`inventory_adjustments.php`) or `InventoryAdjustmentService` evidence for the waste/stock-adjustment operator detail instead of accepting the old `recipe_waste.php` operator token.
- Isolated endpoint runtime proof now executes the real Inventory module endpoint `ajax/inventory_adjustment.php` with the `inventory_adjustment` CSRF boundary, inventory bridge mode, temporary database stock, idempotent waste replay, and stock adjustment balance assertions.
- The old PHPUnit service-level `RecipeWasteAdjustmentServiceTest` has been retired. Service-level waste/adjustment behavior is now covered by `tests/sync/inventory_phase9_adjustment_service_test.php`, and inventory accounting behavior is covered by `tests/sync/inventory_phase12_accounting_service_test.php`.
- `recipe_waste.php` and `RecipeWasteAdjustmentService` have been deleted after the Inventory module page, endpoint runtime proof, rollout evidence parser, fixture stock helper, and service-level tests moved to `InventoryAdjustmentService`.
- The old `tests/sync/recipe_waste_adjustment_endpoint_runtime_test.php` compatibility proof has been deleted; `tests/sync/inventory_adjustment_endpoint_runtime_test.php` is now the isolated endpoint proof for waste and adjustment writes.

## Not Implemented Yet

- No Phase 9 waste/adjustment legacy deletion blocker remains.
