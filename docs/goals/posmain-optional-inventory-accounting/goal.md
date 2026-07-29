# POSMAIN Optional Inventory and Accounting

## Objective

Implement the approved product policy that stock quantities, recipes, and financial ledger accounting are independently optional per shop, while preserving atomic money/stock behavior and permissive zero/negative-stock sales.

## Goal Kind

`specific`

## Current Tranche

Complete the capability/configuration contract, quantity/accounting decoupling, conditional accounting gates, cashier zero-clamped stock presentation, focused regression coverage, and a final local verification audit.

## Non-Negotiable Constraints

- Do not modify standing-shop catalog data, balances, journals, migrations, opening counts, or historical reconciliation state.
- Do not commit, deploy, alter shared git history, or make external changes.
- Preserve legacy configuration compatibility through an explicit adapter.
- Inventory-disabled and non-stock shops/items must gain no new requirements or misleading warnings.
- Zero or negative tracked quantity must never block a POS sale.
- Accounting failures must be atomic and must never silently fall back to COGS.
- Work in small increments and verify adjacent sale, refund, reversal, recipe, outbox, and UI contracts.

## Stop Rule

Stop when the tranche audit passes, all safe local work is blocked, or continuing would require owner input, credentials, destructive operations, standing-shop mutation, or a new product-policy decision.

## Canonical Board

Machine truth lives at:

`docs/goals/posmain-optional-inventory-accounting/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins.

## Run Command

```text
/goal Follow docs/goals/posmain-optional-inventory-accounting/goal.md
```

## PM Loop

1. Read this charter and the board.
2. Work only on the active task.
3. Record a compact receipt.
4. Select the next active task.
5. Finish only after the final audit maps every product decision to current evidence.
