# POSMAIN Online/Offline Sync Implementation

## Objective

Apply the regenerated POSMAIN online/offline sync plan in the local `posmain` repository, incorporating the five blocking findings before any implementation reaches shared sync, counter, cloud, or Moova flows.

## Goal Kind

`specific`

## Current Tranche

Continue from the completed foundation, runtime-prerequisite, first branch/cloud delivery, branch-provisioning, first cloud order snapshot apply, cloud Moova pull/ack API, local Moova inbound queue, cloud order line/payment/receipt snapshots, cloud table/shift/menu snapshot slices, cloud report summary, and branch Moova inbound poller into the next production-readiness slice. The next decision should focus on local Moova application/decline semantics, deployment/smoke readiness, and rollback/backup evidence, while still avoiding production-secret, destructive-migration, or deployment assumptions.

## Non-Negotiable Constraints

- Follow the Global Major Change Safety Rule in `AGENTS.md` before each implementation slice.
- Treat the provided plan file as the baseline: `/Users/ab.mansour1agmail.com/Desktop/POSMAIN_online_offline_sync_modification_plan_for_codex_REGENERATED_2026-05-10.txt`.
- Preserve existing local POS behavior unless the sync plan intentionally changes it.
- Do not overwrite unrelated dirty worktree changes. Understand and work with them.
- Reclaim expired worker claims: outbox claiming must include `status='syncing' AND locked_until < now` so crashed workers cannot leave rows stuck forever.
- Allocate document counters with a safe two-step `LAST_INSERT_ID(current_value + 1)` update pattern after ensuring the counter row exists. Do not rely on first-insert auto-increment behavior.
- Resolve HMAC storage before implementation: cloud HMAC validation requires the actual secret or equivalent protected HMAC key, not only `sync_secret_hash`.
- Keep shadow mode distinct from receive-only mode: shadow mode should apply snapshots and mark reports untrusted; `POSMAIN_CLOUD_APPLY_ENABLED=0` means receive-only. Worker response handling must accept `accepted_shadow`.
- Fix the Moova branch-event cursor design so it does not require a non-null `cursor_value` before the auto-generated `id` exists.
- Add or update focused tests for changed behavior, especially worker reclaim, counter allocation, HMAC validation, shadow responses, and Moova cursor behavior.

## Stop Rule

Stop when the tranche audit passes, all safe local work is blocked, or continuing would require owner input, credentials, destructive operations, live production secrets, or strategy the board cannot decide.

## Canonical Board

Machine truth lives at:

`docs/goals/posmain-online-offline-sync/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/posmain-online-offline-sync/goal.md
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
