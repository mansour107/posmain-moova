# Phase 3 Core Ledger Contracts

Generated: 2026-05-29

Scope: Phase 3 of `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`.

This phase makes `InventoryLedgerService` a real ledger writer only when `POSMAIN_INVENTORY_LEDGER_MODE` is `bridge` or `live`. It remains unwired from legacy endpoints; POS, purchase, transfer, count, waste, and recipe pages still keep their existing behavior until the later bridge/shadow phases.

## Implemented Engine Behavior

- Validates movement requests through `InventoryMovementRequest`.
- Requires `item_id`, a supported movement type, exactly one valid direction for real stock movements, neutral reservation movements, positive unit conversion, and a deterministic idempotency key.
- Enforces movement direction by type: purchases/opening balances/transfers in/production outputs/refund reversals must be inbound, sales/recipe consumption/transfers out/production inputs/waste must be outbound, and adjustments may be inbound or outbound.
- Stores `payload_hash` and `metadata_json` on `inventory_movements` so idempotent replays can distinguish same payload from conflicting payloads.
- Locks the target `inventory_item_balances` row with `FOR UPDATE` after creating a missing scoped balance row.
- Updates `qty_on_hand`, `qty_reserved`, `qty_available`, `moving_average_cost`, and `last_movement_id`.
- Returns the touched `inventory_item_balances.id` for both new rows and duplicate-key balance updates.
- Blocks negative on-hand or available balances when strict stock is enabled, including over-reservation that would make available stock negative.
- Uses `InventoryItemPolicyService`, so `service` and non-stock items return `noop` instead of moving stock.
- Writes an audit row to existing `recipe_audit_log` when that table exists.
- Emits an availability refresh signal in the return payload when the inventory availability flag is enabled.
- Updates the legacy `myitems.itmqty` mirror only when `POSMAIN_INVENTORY_LEGACY_MIRROR` is explicitly enabled.

## Schema Additions

The existing `inventory_movements` table was extended additively with:

- `payload_hash CHAR(64) NOT NULL DEFAULT ''`
- `metadata_json JSON NULL`

The `source_type` enum now includes workflow sources for the Phase 2 tables:

- `purchase_order`
- `purchase_receipt`
- `inventory_count`
- `inventory_transfer`

## Regression Guardrails

Phase 3 deliberately does not:

- call `InventoryLedgerService` from legacy endpoints;
- turn on inventory ledger mode by default;
- replace `fat_details` or `myitems.itmqty`;
- remove recipe-specific ledger services;
- require a new audit table;
- change public cost payload defaults.
