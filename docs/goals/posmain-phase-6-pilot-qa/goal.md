# POSMAIN Phase 6 Pilot QA

## Objective

Implement Phase 6 of `/Users/ab.mansour1agmail.com/Desktop/pos expansion plan.txt`: prove POSMAIN under realistic mid-scale restaurant service conditions before calling it production-ready, using the completed Phase 0 through Phase 5 artifacts as the safety baseline.

## Goal Kind

`specific`

## Current Tranche

Phase 6 is complete when the repository has reviewable receipts for P6-001 through P6-006: a realistic demo restaurant seed/reset tool, documented or automated browser E2E coverage for cashier/table/print/Moova pilot flows, load/concurrency checks for counters/table/payment/search, a pilot go-live checklist, a pilot daily review template, explicit pilot exit criteria, and a final audit receipt.

## Non-Negotiable Constraints

- Follow the AGENTS.md major-change safety rules before each behavior change: map impacted surfaces, identify compatibility risks, check/add focused tests, make the smallest safe increment, and verify adjacent flows.
- Base this tranche on completed Phase 0, Phase 1, Phase 2, Phase 3, Phase 4, and Phase 5 artifacts in `docs/goals/posmain-phase-0-production-readiness`, later phase boards under `docs/goals/`, and `docs/production`.
- Do not mix unrelated dirty worktree changes into Phase 6; every Worker task must have a narrow `allowed_files` list and must report any pre-existing dirty files it touches.
- Protect local business data. Demo reset tooling must be explicitly non-production, require confirmation or dry-run support, and avoid destructive writes unless the caller has opted into a reset on a test database.
- Preserve cashier/table/payment/accounting/stock/Moova behavior unless the active Worker task explicitly touches pilot data setup or QA harness behavior.
- Test after each considerable change and record commands in the task receipt.
- Keep live pilot steps honest: production credentials, real printers, real cashier acceptance, and real shop pilot days may be documented as blockers or external evidence requirements when they cannot be completed locally.

## Stop Rule

Stop when the Phase 6 audit passes, all safe local work is blocked, or continuing would require production credentials, destructive operations on non-test data, real-shop cashier acceptance that cannot be simulated locally, or product strategy outside the board.

## Canonical Board

Machine truth lives at:

`docs/goals/posmain-phase-6-pilot-qa/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/posmain-phase-6-pilot-qa/goal.md
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
