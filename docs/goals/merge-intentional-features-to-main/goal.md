# Merge Intentional Features To Main

## Objective

Integrate every intentional client-facing feature that exists only on committed POSMAIN branches into local `main`, while preserving newer `main` behavior and avoiding blind replay of historical WIP stashes.

## Goal Kind

`recovery`

## Current Tranche

Map the unique feature intent in `agent/production-update-safety` and `codex/main-worktree-safety-20260727`, merge it on a temporary integration branch, resolve conflicts in favor of newer compatible behavior, run affected regression coverage, and fast-forward local `main` only after verification passes.

## Non-Negotiable Constraints

- Keep both existing stashes untouched as recovery snapshots.
- Preserve newer `main` behavior when histories conflict.
- Do not discard unique client-facing features merely because they overlap newer files.
- Do not push or modify `origin/main` without separate authorization.
- Preserve unrelated worktrees and branch refs.
- Verify financial, POS ordering, KDS, delivery, sync/schema, reporting, RBAC, update, and branding surfaces affected by the integration.

## Stop Rule

Stop when the tranche audit passes, all safe local work is blocked, or continuing would require owner input, credentials, destructive operations, or strategy the board cannot decide.

## Canonical Board

Machine truth lives at:

`docs/goals/merge-intentional-features-to-main/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/merge-intentional-features-to-main/goal.md
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
