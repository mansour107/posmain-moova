# POSMAIN Phase 1 Production Foundation

## Objective

Implement Phase 1 of `/Users/ab.mansour1agmail.com/Desktop/pos expansion plan.txt`: make the existing PHP/MariaDB POS safer to stage by externalizing runtime configuration, tightening production exposure, adding session/password foundations, improving migration discipline, documenting backup/restore and deployment posture, and adding operator health checks.

## Goal Kind

`specific`

## Current Tranche

Phase 1 is complete when the repo has reviewable production-foundation changes that build on the completed Phase 0 artifacts in `docs/goals/posmain-phase-0-production-readiness` and `docs/production`, with focused verification after each major slice and a final audit receipt.

## Non-Negotiable Constraints

- Follow the global major-change safety rule before behavior edits.
- Do not discard, revert, or mix unrelated dirty worktree changes.
- Keep changes scoped to Phase 1 foundations; do not rewrite POS order/payment/table mutation logic.
- Preserve current local POS behavior while making production/staging configuration safer.
- Test after each considerable change and record verification on the board.
- Pause before any destructive DB, credential rotation, or real production operation.

## Stop Rule

Stop when the Phase 1 audit passes, all safe local work is blocked, or continuing would require credentials, destructive operations, production access, or owner input.

## Canonical Board

Machine truth lives at:

`docs/goals/posmain-phase-1-production-foundation/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/posmain-phase-1-production-foundation/goal.md
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
