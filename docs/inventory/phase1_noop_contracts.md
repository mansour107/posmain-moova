# Phase 1 No-Op Inventory Domain Contracts

Generated: 2026-05-29

Scope: Phase 1 of `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`.

This phase creates the inventory domain entry points and does not change stock behavior. The classes are intentionally not wired into POS, purchase, refund, count, transfer, waste, or recipe endpoints yet.

## Added Contracts

| Contract | Purpose | Runtime behavior in default mode |
| --- | --- | --- |
| `classes/Inventory/InventoryFeatureFlags.php` | Normalizes inventory ledger rollout flags. | `ledger_mode` defaults to `off`; all sensitive features are disabled. |
| `classes/Inventory/InventoryScopeResolver.php` | Resolves tenant, branch, branch UUID, store, channel, order type, and source. | Returns a non-null integer `store_id`, defaulting to `0`. |
| `classes/Inventory/InventoryDecimal.php` | Reuses existing recipe decimal helper for inventory quantity and cost math. | No float math is introduced. |
| `classes/Inventory/InventoryItemPolicyService.php` | Centralizes stock-tracking decisions. | `service` items always return `track_stock = false`. |
| `classes/Inventory/InventoryLedgerService.php` | Future stock ledger entry point. | Returns intended action and `writes = []`; no SQL writes are performed. |
| `classes/Inventory/InventoryBalanceService.php` | Future balance read/refresh entry point. | Read/refresh requests return intended action and `writes = []`. |
| `classes/Inventory/InventoryAuditService.php` | Future inventory audit entry point. | Returns intended action and `writes = []`. |
| `classes/Inventory/InventoryPermissionService.php` | Centralizes future inventory permissions. | Allows only explicit permissions, `inventory.*`, or `admin`. |

## Config Flags

`config/app_config.php` now exposes this disabled-by-default inventory block:

- `POSMAIN_INVENTORY_LEDGER_MODE`: `off`, `shadow`, `bridge`, or `live`; invalid values normalize to `off`.
- `POSMAIN_INVENTORY_LEGACY_MIRROR`: default `0`.
- `POSMAIN_INVENTORY_STRICT_STOCK`: default `0`.
- `POSMAIN_INVENTORY_RESERVATIONS`: default `0`.
- `POSMAIN_INVENTORY_ACCOUNTING`: default `0`.
- `POSMAIN_INVENTORY_AVAILABILITY`: default `0`.
- `POSMAIN_INVENTORY_SYNC`: default `0`.
- `POSMAIN_INVENTORY_COST_PUBLIC_PAYLOADS`: default `0`.

## Regression Guardrails

Phase 1 deliberately did not:

- update `fat_details`;
- call the new inventory services from old POS endpoints;
- disable or replace legacy `fat_details` / `myitems.itmqty` behavior.

Later phases may add gated SQL inside the new inventory services. The old stock behavior remains active until later shadow and reconciliation phases prove the new ledger can safely replace it, and runtime endpoints must remain unwired until the bridge phase.
