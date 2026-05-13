# POSMAIN Phase 2 POS State

## Objective

Implement Phase 2 of `/Users/ab.mansour1agmail.com/Desktop/pos expansion plan.txt`: create the canonical POS mutation foundation for active order, table, payment, cancel, counter, idempotency, and Moova paths without regressing existing cashier behavior.

## Goal Kind

`specific`

## Current Tranche

Phase 2 is complete when active POS/table/payment/cancel/Moova mutations have a reviewable canonical service path, idempotency and event foundations exist, document counters and UUID foundations are in place, endpoint contracts are documented, focused tests cover the changed behavior, and a final audit receipt confirms the tranche against the Phase 2 checklist.

## Non-Negotiable Constraints

- Base the work on the completed Phase 0/1 artifacts in `docs/goals/posmain-phase-0-production-readiness` and `docs/production`.
- Preserve the existing dirty worktree; do not revert, reformat, or overwrite unrelated changes.
- Follow the AGENTS.md major-change safety rule before behavior edits: map impacted surfaces, identify compatibility risks, add or update focused coverage, change in smallest safe increments, and verify adjacent flows.
- Do not change cashier-visible Arabic RTL behavior unless Phase 2 requires it.
- Keep local branch DB as the operational source of truth; sync/cloud behavior must not block local cashier actions.
- Use feature-compatible, migration-safe additions. Avoid destructive schema changes.
- Test after each considerable change and record commands in task receipts.
- If a task needs files outside its `allowed_files`, stop and update the board instead of expanding scope silently.

## Stop Rule

Stop when the Phase 2 audit passes, all safe local work is blocked, or continuing would require credentials, destructive operations, owner input, or product strategy outside this board.

## Canonical Board

Machine truth lives at:

`docs/goals/posmain-phase-2-pos-state/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/posmain-phase-2-pos-state/goal.md
```

## PM Loop

On every continuation:

1. Read this charter.
2. Read `state.yaml`.
3. Work only on the active board task.
4. Assign Scout, Judge, Worker, or PM according to the task.
5. Write a compact task receipt.
6. Update the board.
7. Select the next active task or finish with a Judge/PM audit receipt.
