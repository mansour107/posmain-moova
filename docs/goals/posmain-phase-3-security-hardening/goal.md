# POSMAIN Phase 3 Security Hardening

## Objective

Implement Phase 3 of `/Users/ab.mansour1agmail.com/Desktop/pos expansion plan.txt`: add central auth, CSRF, permissions, validation, upload hardening, audit logging, production-safe errors, login throttling, and focused tests for active production routes.

## Goal Kind

`specific`

## Current Tranche

Phase 3 is complete when active browser-origin POS/admin writes consistently use central auth and CSRF where applicable, a documented permission matrix exists, high-risk active SQL/input/upload surfaces are hardened in small verified slices, critical actions leave audit records, production errors avoid raw leakage, login throttling is present, focused tests cover the changed behavior, and a final audit receipt maps the tranche to the Phase 3 checklist.

## Non-Negotiable Constraints

- Base the work on completed Phase 0, Phase 1, and Phase 2 artifacts in `docs/goals/posmain-phase-0-production-readiness`, `docs/goals/posmain-phase-1-production-foundation`, `docs/goals/posmain-phase-2-pos-state`, and `docs/production`.
- Follow the AGENTS.md major-change safety rule before behavior edits: map impacted surfaces, identify compatibility risks, add or update focused coverage, change in smallest safe increments, and verify adjacent flows.
- Do not discard, revert, reformat, or mix unrelated dirty worktree changes.
- Keep local cashier/table/payment/Moova behavior compatible unless a Phase 3 security requirement intentionally blocks unsafe unauthenticated or invalid browser writes.
- Do not add CSRF requirements to machine-to-machine Moova/cloud endpoints that authenticate with device tokens or HMAC-style credentials; document the exception.
- Prefer additive schema and wrapper helpers over risky broad rewrites.
- Test after each considerable change and record commands in task receipts.
- If a task needs files outside its `allowed_files`, stop and update the board instead of expanding scope silently.

## Stop Rule

Stop when the Phase 3 audit passes, all safe local work is blocked, or continuing would require credentials, destructive operations, owner input, or product strategy outside this board.

## Canonical Board

Machine truth lives at:

`docs/goals/posmain-phase-3-security-hardening/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/posmain-phase-3-security-hardening/goal.md
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
