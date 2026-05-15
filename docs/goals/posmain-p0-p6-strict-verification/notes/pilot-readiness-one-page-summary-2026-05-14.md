# Pilot Readiness One-Page Summary - 2026-05-14

## Verdict

POSMAIN is **not strict-ready for pilot yet**.

The verification campaign is broad and well-receipted, and many P0-P6 areas passed focused checks. However, strict readiness cannot be claimed while six current blockers remain red and no post-fix full rerun has passed.

## What Is Green

- Goal Maker board is valid and has durable receipts.
- POS HTTP is reachable on `http://127.0.0.1:8010`.
- Current `kody2` migration dry-run is clean: `0 pending sync schema change(s)`.
- Current `kody2` has `order_fulfillment`.
- Focused Phase 0-2 foundation/POS-state tests passed on an appropriate legacy-compatible schema fixture.
- Focused Phase 3 security tests passed.
- Focused Phase 4 midscale tests passed, except separate minimal-schema smoke compatibility.
- Safe focused Phase 5/Moova tests passed on source/disposable paths.
- Safe focused Phase 6 docs/demo/load checks passed.
- Moova branch ack, poll, and apply workers passed on a full disposable clone.
- GUI smoke passed for login, POS unlock, item add, quantity changes, payment modal open/close, and key POS pages.
- Disposable GUI write tests passed for save order, payment/receipt, table save, and shift close.
- Live Moova create/confirm/decline/edit/cancel/cancel-after-edit passed under a temporary healthy foreground runtime.
- Disposable verification schemas were cleaned up.

## What Is Still Red

- `B001`: `js/pos_auto_lock.js` has a syntax error at line 116.
- `B002`: `moova_widget_bridge_contract_test.php` is red until facade-vs-endpoint contract alignment is approved and implemented.
- `B003`: `write_surface_inventory_test.php` is red because Moova confirm is not classified as `moova_bridge`.
- `B004`: `table_order_counter_smoke_test.php` and `uuid_backfill_smoke_test.php` fail on minimal schema fixtures.
- `B005`: persistent Moova `/readyz` returns HTTP 503 with `redis=false`.
- `B006`: POS Moova widget can show empty-queue copy while Moova is unhealthy instead of a clear degraded/offline state.

## Most Important Risk

The heaviest pilot blocker is Moova reliability in the normal persistent setup:

- live Moova E2E proved behavior under a temporary healthy runtime;
- the persistent LaunchAgent runtime still reports `redis=false`;
- the cashier widget can mislead the user when Moova is unhealthy.

That means Moova can work, but the normal local service path is not yet reliable enough to certify.

## Current Non-Blocker

`order_fulfillment` is no longer a blocker. Current evidence shows:

```text
tools/run_migrations.php --dry-run -> 0 pending sync schema change(s)
current kody2 has order_fulfillment
```

## Decision Needed

The current no-code audit path is exhausted. To move from `blocked` to `pass`, the owner must choose one of the paths in `owner-decision-menu-2026-05-14.md`.

The only path that can satisfy the original strict-readiness objective is:

```text
Option D - approve the full strict-pass fix tranche
```

That means fixing or reclassifying all active blockers, then running the full post-fix checklist and a final completion audit.

## Current Goal State

```text
goal_status=blocked
active_task=null
update_goal was not called
```
