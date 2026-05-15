# Approval Scope Consistency - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T12:39:16Z

## Purpose

Check whether the blocked future task scopes match the current blocker manifest and owner approval handoff before any approved fix tranche starts.

## Finding

`T045` is the blocked source/test fix task for B001-B004. Its original `allowed_files` covered:

- `js/pos_auto_lock.js`
- `tests/sync/moova_widget_bridge_contract_test.php`
- `tools/audit_write_paths.php`
- `tests/sync/write_surface_inventory_test.php`
- `classes/Sync/SchemaManager.php`
- `tests/sync/table_order_counter_smoke_test.php`
- `tests/sync/uuid_backfill_smoke_test.php`

That matched B001, B003, and B004, but it was narrower than the documented B002 candidate scope in both:

- `current-blocker-manifest-2026-05-14.md`
- `current-approval-handoff-2026-05-14.md`

Those B002 artifacts list these additional candidate files if the owner requires endpoint/service alignment rather than only test-contract reclassification:

- `ajax/moova_confirm_order.php`
- `ajax/moova_change_order.php`
- `classes/Pos/Service/PosOrderMutationService.php`

## Board Repair

`T045.allowed_files` was updated to include the three B002 candidate files above.

This does not activate T045 and does not approve edits. It only makes the blocked future task internally consistent with the manifest and handoff, so an owner-approved Option B or D can proceed without an artificial board mismatch if the owner chooses endpoint/service alignment.

## Guardrails Preserved

- `T045.status` remains `blocked`.
- `T045.stop_if` still blocks work unless Option B or Option D is explicitly approved.
- `T045.stop_if` still stops if changing Moova endpoint behavior would affect idempotency, accounting, stock, table state, or auth beyond the approved contract decision.
- Runtime files remain outside T045 and stay in T046.
- The goal remains blocked.

## Boundary

- No implementation files or tests were edited.
- No runtime configuration was changed.
- No services were stopped, started, or restarted.
- No database mutation was performed.
- `update_goal(status=complete)` was not called.
