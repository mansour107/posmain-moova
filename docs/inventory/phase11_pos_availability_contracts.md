# Phase 11 POS Availability, Reservations, and Strict Stock Contracts

## Scope

Phase 11 keeps the existing recipe POS pilot infrastructure and closes the reservation expiry gap. It does not add new tables.

## Functional Contracts

- Availability and reservation rollout remains controlled by the recipe POS feature layer. The new inventory flag names are accepted as fallback aliases so the inventory rollout plan can enable POS stock behavior without forcing a second legacy naming decision:
  - `POSMAIN_RECIPE_AVAILABILITY=1`
  - `POSMAIN_INVENTORY_AVAILABILITY=1` when the recipe-specific flag is absent
  - `POSMAIN_RECIPE_RESERVATIONS=1`
  - `POSMAIN_INVENTORY_RESERVATIONS=1` when the recipe-specific flag is absent
  - `POSMAIN_RECIPE_STRICT_STOCK=1` when strict blocking is intended
  - `POSMAIN_INVENTORY_STRICT_STOCK=1` when the recipe-specific flag is absent
  - pilot item/category/branch filters decide where the behavior applies.
- POS item cards and category payloads are decorated by `ItemAvailabilityService`.
- Barcode search uses the same availability payload before adding an item, so
  cashier barcode entry cannot bypass recipe/strict-stock availability gates.
- POS payloads expose cashier-safe availability state and do not expose internal cost keys.
- Make-to-order recipes compute availability from ingredient balances.
- Batch-prepared recipes compute availability from prepared item stock through `prepared_stock` requirements.
- Open order lines create stock reservations when reservation mode applies.
- Reservation rows now receive `expires_at` from `RecipeSettingsService::defaultReservationMinutes()`.
- Cancel/update flows release reservations and return reserved quantity to available quantity.
- Payment/commit flows consume active reservations exactly once.
- Expiry uses `RecipeReservationService::expireReservations()` and the existing reservation release movement path.
- Expired reservations stamp `released_at` so monitoring, reports, and operator support can tell when the hold stopped affecting available stock.
- Strict stock blocks add/commit when effective recipe availability is below the requested quantity.
- Manager override remains logged through `manager_approvals` and recipe audit when negative-stock approval is enabled and strict stock is not enabled.

## UI Contracts

- POS cards include data attributes for availability, cashier add permission, reason, recipe state, effective available quantity, and availability revision.
- Cashiers see unavailable/low-stock states through the POS grid and barcode
  JavaScript gates.
- Manager override is available only when the backend marks the item as override-allowed and the user has `pos.recipe_stock_override`.

## Compatibility Notes

- The reservation lifecycle continues to use `stock_reservations`, `recipe_order_line_usage`, `inventory_movements`, and `inventory_item_balances`.
- No competing reservation table or POS-side stock counter was introduced.
- Expiry is attached in `RecipeOrderLifecycleService::lineOrderContext()` so all normal POS/table/external open-order reservation paths get the same default window.

## Tests

- `tests/sync/inventory_phase11_pos_availability_contract_test.php`
- `tests/sync/recipe_reservation_lifecycle_runtime_test.php`
- `tests/sync/recipe_pos_grid_availability_endpoint_runtime_test.php`
- `tests/sync/recipe_manager_override_endpoint_runtime_test.php`
- `tests/sync/recipe_pos_grid_availability_surface_smoke_contract_test.php`
- `tests/sync/recipe_stock_override_contract_test.php`
