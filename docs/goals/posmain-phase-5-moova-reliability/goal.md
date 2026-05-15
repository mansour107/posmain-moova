# POSMAIN Phase 5 Moova Reliability

## Objective

Implement Phase 5 of `/Users/ab.mansour1agmail.com/Desktop/pos expansion plan.txt`: make Moova QR/online order handling safe for a pilot by deciding the official mode, unifying direct and queued mutation behavior, protecting visible device tokens with permissions/audit, adding delivery-order foundations, improving cashier-facing Moova states, and proving duplicate/stale/conflict behavior with focused tests.

## Goal Kind

`specific`

## Current Tranche

Phase 5 is complete when the current repository has reviewable receipts for P5-001 through P5-007: `docs/production/moova_mode_decision.md`, aligned Moova mode flags, shared direct/queued ingest/apply behavior, protected token visibility and rotation guidance, structured delivery fulfillment data, cashier-facing Moova conflict/offline evidence, the required Moova test scenarios, and a final audit receipt.

## Non-Negotiable Constraints

- Follow the AGENTS.md major-change safety rules before each behavior change: map impacted surfaces, identify compatibility risks, check/add focused tests, make the smallest safe increment, and verify adjacent flows.
- Base the tranche on completed Phase 0, Phase 1, Phase 2, Phase 3, and Phase 4 artifacts in `docs/goals/posmain-phase-0-production-readiness`, the later phase boards under `docs/goals/`, and `docs/production`.
- Do not mix unrelated dirty worktree changes into Phase 5; keep every Worker allowed file list narrow and report any pre-existing dirty files touched by a task.
- Preserve existing cashier/table/payment/accounting/stock behavior unless a Phase 5 change intentionally touches Moova order ingest, Moova edit/cancel, device-token visibility, or delivery metadata.
- Keep Moova writes idempotent and conflict-safe; duplicate direct/queued delivery of the same provider event must not create duplicate POS mutations.
- Preserve Arabic RTL cashier usability and clear visible messages for stale edit/cancel, unreachable Moova, invalid token, unmapped table/item, and session-expired states.
- Test after each considerable change and record the commands in the task receipt.

## Stop Rule

Stop when the Phase 5 audit passes, all safe local work is blocked, or continuing would require production credentials, destructive operations, real-shop cashier acceptance that cannot be simulated locally, or product strategy outside the board.

## Canonical Board

Machine truth lives at:

`docs/goals/posmain-phase-5-moova-reliability/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/posmain-phase-5-moova-reliability/goal.md
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
