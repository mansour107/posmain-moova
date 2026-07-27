# Refund Reversal Consistency

## Objective

Implement immutable full and partial refund reversals so every local and cloud monetary report, shift total, drawer balance, accounting view, and admin drilldown reflects each refund exactly once without deleting the original sale.

## Goal Kind

`specific`

## Current Tranche

Complete the approved V1 refund-reversal plan: unify refund writes, add durable refund attribution, establish one refund-aware reporting model, update all affected financial/shift/admin/cloud consumers, and prove the behavior with focused regression and browser-flow coverage.

## Non-Negotiable Constraints

- Preserve original sales and existing unrelated workspace changes.
- Attribute refunds to the refund business day, operator, and shift; never rewrite a closed original-sale shift.
- Keep the cashier UI whole-order only while making backend/reporting partial-refund ready.
- Use fixed-precision money and idempotent writes.
- Treat posted revenue reversals separately from tender settlement and drawer custody.
- Apply changes incrementally and verify adjacent order, payment, inventory, shift, sync, and reporting flows.

## Stop Rule

Stop when the tranche audit passes, all safe local work is blocked, or continuing would require owner input, credentials, destructive operations, or strategy the board cannot decide.

## Canonical Board

Machine truth lives at:

`docs/goals/refund-reversal-consistency/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/refund-reversal-consistency/goal.md
```

## PM Loop

1. Read this charter and `state.yaml`.
2. Work only on the active task.
3. Preserve the dirty worktree and stay within the task's allowed files.
4. Record verification and a receipt before selecting the next task.
5. Finish only after a final PM audit maps every plan requirement to passing evidence.
