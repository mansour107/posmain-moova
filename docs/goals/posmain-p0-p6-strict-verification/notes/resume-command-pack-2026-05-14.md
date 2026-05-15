# Resume Command Pack - POSMAIN P0-P6 Strict Verification

Status: blocked, no active task.

Purpose: make the next owner-approved step copy-safe and unambiguous. This file is a handoff artifact only; it does not approve implementation, runtime edits, database mutation, or a completion claim.

## Always Run First

```sh
node /Users/ab.mansour1agmail.com/.codex/skills/goal-maker/scripts/check-goal-state.mjs docs/goals/posmain-p0-p6-strict-verification/state.yaml
git status --short
```

Expected board shape before any approved tranche:

- Goal status: `blocked`.
- Active task: `null`.
- Blocked future tasks: `T045`, `T046`, `T047`.
- Dirty implementation worktree is expected and must not be reverted as cleanup.

## Option A - Audit-Only Stop

No fix or runtime commands are required.

Use this path if the owner wants to pause with the current blocked verdict. The strongest current owner-facing summary is:

- `pilot-readiness-one-page-summary-2026-05-14.md`
- `current-blocker-manifest-2026-05-14.md`
- `current-approval-handoff-2026-05-14.md`

Do not call `update_goal(status=complete)` on this path.

## Option B - Source/Test Fix Tranche

Owner approval needed: explicit approval for Option B or Option D.

Board task to activate: `T045`.

Allowed source/test files are limited to the `T045.allowed_files` list in `state.yaml`.

Minimum verification gates after the fix or approved reclassification:

```sh
node --check js/pos_auto_lock.js
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/moova_widget_bridge_contract_test.php
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/write_surface_inventory_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/table_order_counter_smoke_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/uuid_backfill_smoke_test.php
```

Pass criteria:

- `B001` syntax gate is green.
- `B002` Moova contract is either green or formally reclassified by owner/Judge as a stale test contract.
- `B003` write-surface inventory recognizes the current Moova facade path.
- `B004` minimal-schema smoke tests no longer fail on missing legacy anchors.

Stop if the fix needs files outside `T045.allowed_files`, changes live order/payment/table/stock semantics, or the same gate fails twice.

## Option C - Runtime/Widget Tranche

Owner approval needed: explicit approval for Option C or Option D.

Board task to activate: `T046`.

Allowed runtime/widget files are limited to the `T046.allowed_files` list in `state.yaml`.

Preflight probes:

```sh
curl -sS -i http://127.0.0.1:3001/readyz
php tools/moova_local_topology_check.php
launchctl print gui/$(id -u)/com.codex.cofe-order-3001
```

Minimum verification gates after the runtime/widget work:

```sh
curl -sS http://127.0.0.1:3001/readyz
php tools/moova_local_topology_check.php
```

Browser/live gates:

- POS Moova widget shows a clear degraded/offline state when Moova is unavailable.
- POS remains usable while Moova is unavailable.
- Live Moova create/confirm passes with the persistent service.
- Live decline, edit, cancel, and cancel-after-edit pass with the persistent service.
- Persistent service can be restarted and still returns healthy `/readyz`.

Stop if persistent Moova cannot be restored, the change would start noisy production-only services without an explicit disable/allow plan, or files outside `T046.allowed_files` are needed.

## Option D - Full Strict-Pass Tranche

Owner approval needed: explicit approval for Option D.

Expected sequence:

1. Complete or reclassify `T045` source/test blockers.
2. Complete or reclassify `T046` runtime/widget blockers.
3. Activate `T047`.
4. Run the checklist in `post-fix-rerun-checklist-2026-05-14.md`.
5. Create a fresh completion audit.
6. Call `update_goal(status=complete)` only if the fresh audit shows no required work remains.

Checklist reference:

```sh
sed -n '1,260p' docs/goals/posmain-p0-p6-strict-verification/notes/post-fix-rerun-checklist-2026-05-14.md
```

Do not skip GUI or live Moova E2E if the claim is still "P0-P6 strict-ready and no known bugs."

## Current Red Gates To Expect Until Fixed

- `node --check js/pos_auto_lock.js`
- `php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/moova_widget_bridge_contract_test.php`
- `php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/write_surface_inventory_test.php`
- `POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/table_order_counter_smoke_test.php`
- `POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/uuid_backfill_smoke_test.php`
- `curl http://127.0.0.1:3001/readyz`
- `php tools/moova_local_topology_check.php`

## Completion Rule

The board can remain useful while blocked. It must not be marked complete until the current blocker manifest is either green or explicitly reclassified, the post-fix rerun checklist has current evidence, and the final completion audit says the original objective is achieved.
