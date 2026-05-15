# Owner Decision Menu - 2026-05-14

## Purpose

Convert the current blocked verification state into explicit owner choices. This is documentation only; no app code, tests, runtime configuration, or database data were changed.

The current audit has already verified a large amount of POSMAIN P0-P6 behavior, but the strict objective is not achieved while the five active blocker tranches remain red.

## Current Choices

### Option A - Stop At Audit-Only

Choose this if the goal is to keep the current work as a rigorous blocked verification report and avoid all code/runtime changes.

Effect:

- Goal remains `blocked`.
- No fixes are attempted.
- The current deliverables are the receipts, artifact index, current approval handoff, and stop-rule note.

Use when:

- The user only wants evidence and does not want implementation changes.

### Option B - Approve Source/Test Fix Tranche Only

Choose this to fix the current repo-level red tests while leaving persistent Moova runtime configuration untouched.

Allowed scope:

- `js/pos_auto_lock.js`
- `tests/sync/moova_widget_bridge_contract_test.php`
- `ajax/moova_confirm_order.php`
- `ajax/moova_change_order.php`
- `classes/Pos/Service/PosOrderMutationService.php`
- `tools/audit_write_paths.php`
- `tests/sync/write_surface_inventory_test.php`
- `classes/Sync/SchemaManager.php`
- `tests/sync/table_order_counter_smoke_test.php`
- `tests/sync/uuid_backfill_smoke_test.php`

The endpoint/service files above are allowed only for the B002 owner-approved contract decision: either preserve `PosOrderMutationService` as the canonical facade and align tests, or require endpoint-level apply-service calls. They do not permit unrelated Moova behavior changes.

Primary gates:

```sh
node --check js/pos_auto_lock.js
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/moova_widget_bridge_contract_test.php
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/write_surface_inventory_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/table_order_counter_smoke_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/uuid_backfill_smoke_test.php
```

Limit:

- This cannot create a strict pass by itself because persistent Moova `/readyz` would still be red.

### Option C - Approve Runtime/Widget Tranche Only

Choose this to repair persistent local Moova health and cashier-facing degraded-state behavior while leaving source-contract/test alignment for later.

Allowed scope:

- `~/Library/LaunchAgents/com.codex.cofe-order-3001.plist`
- `/Users/Shared/cofe_order_runtime/.env`
- `/Users/Shared/cofe_order_runtime/.env.local`
- `assets/moova-pos-widget/pos-widget.js`
- `moova_pos_proxy.php`
- `moova_pos_widget.php`

Primary gates:

```sh
curl http://127.0.0.1:3001/readyz
php tools/moova_local_topology_check.php
```

Browser gates:

- Widget shows explicit degraded/offline state when Moova is unavailable.
- POS remains usable when Moova is unavailable.
- Live Moova create/confirm, decline, edit, cancel, and cancel-after-edit pass after persistent runtime is healthy.

Limit:

- This cannot create a strict pass by itself because source/test blockers would remain red.

### Option D - Approve Full Strict-Pass Fix Tranche

Choose this to pursue a real blocked-to-pass transition.

Scope:

- Option B plus Option C.
- Follow `post-fix-rerun-checklist-2026-05-14.md` as the exit gate.

Required final gates:

- Board checker green.
- JS/PHP syntax and write-surface audit green.
- Focused reproduced-failure tests green.
- Current migration dry-run remains `0 pending`.
- Broader script regression green or documented intentional skips only.
- GUI browser regression green.
- Persistent Moova live E2E green.
- Updated completion audit says no required local work remains.

Only after Option D passes all gates should the Goal Maker goal be eligible for `update_goal(status=complete)`.

## Recommended Next Choice

If the user still wants strict readiness, Option D is the only path that can satisfy the original objective.

If the user still wants no code edits, Option A is the correct current resting state.
