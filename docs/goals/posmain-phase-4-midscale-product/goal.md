# POSMAIN Phase 4 Mid-Scale Restaurant Product

## Objective

Implement Phase 4 of `/Users/ab.mansour1agmail.com/Desktop/pos expansion plan.txt`: make POSMAIN more practical for daily mid-scale cafe/restaurant operations while preserving the completed Phase 0-3 production, state, and security foundations.

## Goal Kind

`specific`

## Current Tranche

Phase 4 is complete when the current repository has a reviewable, tested first implementation tranche for mid-scale restaurant/cafe product completion: fast item loading/search and availability foundation; modifiers/line notes/KOT payload foundation; table area/move/merge foundation; payment methods/drawer session/print-job foundations; focused report/inventory/nutrition foundations where safe; and a final Judge/PM audit that maps completed work to Phase 4 acceptance criteria.

Because Phase 4 is broad and the worktree is dirty from prior phases, the PM may split the tranche into smaller bounded Worker tasks. Each Worker task must declare allowed files and verification commands before implementation.

## Non-Negotiable Constraints

- Follow the AGENTS.md major-change safety rule before every behavior-changing Worker task.
- Base decisions on completed Phase 0, Phase 1, Phase 2, and Phase 3 artifacts in `docs/goals/posmain-phase-0-production-readiness`, `docs/goals/posmain-phase-1-production-foundation`, `docs/goals/posmain-phase-2-pos-state`, `docs/goals/posmain-phase-3-security-hardening`, and `docs/production`.
- Do not overwrite unrelated dirty worktree changes; inspect overlapping diffs before editing a dirty file.
- Do not mix unrelated worktree changes into Phase 4 receipts.
- Keep local cashier mutations safe: money, stock, table state, payments, accounting, Moova links, sync/outbox, auth/CSRF, and audit behavior must not regress.
- Test after each considerable change with focused checks first, then broader checks when a task touches shared behavior.
- Use feature flags for disruptive restaurant features where the plan requires them.
- Preserve Arabic RTL cashier usability and existing barcode/keyboard flow.
- No public production deployment, credential rotation, destructive migration, or file deletion without explicit owner approval.

## Stop Rule

Stop when the Phase 4 audit passes, every safe local next action is blocked, or continuing would require credentials, destructive operations, product strategy outside this board, or owner confirmation.

## Canonical Board

Machine truth lives at:

`docs/goals/posmain-phase-4-midscale-product/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/posmain-phase-4-midscale-product/goal.md
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
