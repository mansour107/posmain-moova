# POSMAIN Phase 0 Production Readiness

## Objective

Implement Phase 0 of `/Users/ab.mansour1agmail.com/Desktop/pos expansion plan.txt`: freeze the current release state, document active write ownership, add production guardrails for dangerous surfaces, disable the legacy browser-offline prototype in production, and verify the system still works.

## Goal Kind

`specific`

## Current Tranche

Phase 0 is complete when the repository has a dedicated branch, a baseline inventory, write-surface classification, active route map, production guard integration for D-class routes, production-safe offline prototype gating, focused tests/checks, and a final audit receipt.

## Non-Negotiable Constraints

- Preserve the existing dirty worktree; do not revert or discard prior changes.
- Follow the global major change safety rule before behavior edits.
- Keep changes scoped to Phase 0, with dangerous route denial behind `POSMAIN_PRODUCTION_MODE`.
- Do tests after meaningful changes and iterate until Phase 0 verification is green or a blocker is documented.
- Maintain Arabic RTL cashier behavior and avoid changing business mutation logic except to remove/guard debug/offline surfaces in production.

## Stop Rule

Stop when the Phase 0 audit passes, all safe local work is blocked, or continuing would require credentials, destructive operations, or owner input.

## Canonical Board

Machine truth lives at:

`docs/goals/posmain-phase-0-production-readiness/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/posmain-phase-0-production-readiness/goal.md
```

## PM Loop

On every `/goal` continuation:

1. Read this charter.
2. Read `state.yaml`.
3. Work only on the active board task.
4. Assign Scout, Judge, Worker, or PM according to the task.
5. Write a compact task receipt.
6. Update the board.
7. Select the next active task or finish with a Judge/PM audit receipt.
