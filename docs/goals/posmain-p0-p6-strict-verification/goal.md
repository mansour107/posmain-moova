# POSMAIN P0-P6 Strict Verification

## Objective

Run a rigorous, evidence-backed verification campaign for POSMAIN phases 0 through 6 and the live POS/Moova flows, using existing script tests, newly added focused tests only where gaps are proven, complete GUI/browser checks, and Moova local-server end-to-end validation.

## Goal Kind

`audit`

## Current Tranche

Establish whether the implemented P0-P6 surfaces are complete and working well enough for a controlled pilot by mapping current evidence, choosing a safe test order, running non-destructive scripted and GUI verification, recording every pass/fail/blocker, and proposing bounded fixes only when failures are reproduced.

## Non-Negotiable Constraints

- Preserve all existing user and phase work; do not revert dirty worktree changes.
- Follow the global major-change safety rule before any code or test edits.
- Prefer read-only Scout/Judge tasks until current test coverage, DB target, Moova topology, and GUI flow risks are mapped.
- Do not run destructive database resets against a non-disposable database.
- Keep POS cashier, accounting, stock, table state, Moova, sync/outbox, security, and phase-board completion evidence separate.
- Use local Docker/MariaDB and Moova/COFE services only after verifying the current target ports, containers, seeded data, and tokens.
- Any new tests or helper scripts must be narrow, reviewable, and justified by a documented coverage gap.
- No implementation fixes without a Worker or PM task that explicitly allows the touched files.

## Stop Rule

Stop when a Judge or PM audit receipt says the P0-P6 verification tranche is complete, all safe local testing is blocked, or continuing would require owner input, credentials, destructive operations, production data, or product strategy outside this board.

## Canonical Board

Machine truth lives at:

`docs/goals/posmain-p0-p6-strict-verification/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/posmain-p0-p6-strict-verification/goal.md
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
