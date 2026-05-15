# Approval-Gated Task Backlog - 2026-05-14

## Purpose

Add explicit blocked future task cards for the next possible owner-approved paths, without changing app code, tests, runtime configuration, services, or database data.

## Added Blocked Tasks

### T045 - Source/Test Fix Tranche

Approval path: Option B from `owner-decision-menu-2026-05-14.md`.

Scope:

```text
js/pos_auto_lock.js
tests/sync/moova_widget_bridge_contract_test.php
ajax/moova_confirm_order.php
ajax/moova_change_order.php
classes/Pos/Service/PosOrderMutationService.php
tools/audit_write_paths.php
tests/sync/write_surface_inventory_test.php
classes/Sync/SchemaManager.php
tests/sync/table_order_counter_smoke_test.php
tests/sync/uuid_backfill_smoke_test.php
```

Note: the three endpoint/service files were added to this blocked future scope by the later approval-scope consistency repair because they are the documented B002 candidate files in the current blocker manifest and approval handoff. This does not approve edits; it keeps the blocked task internally consistent if the owner chooses endpoint/service alignment.

Status: blocked pending owner approval.

### T046 - Runtime/Widget Tranche

Approval path: Option C from `owner-decision-menu-2026-05-14.md`.

Scope:

```text
/Users/ab.mansour1agmail.com/Library/LaunchAgents/com.codex.cofe-order-3001.plist
/Users/Shared/cofe_order_runtime/.env
/Users/Shared/cofe_order_runtime/.env.local
assets/moova-pos-widget/pos-widget.js
moova_pos_proxy.php
moova_pos_widget.php
```

Status: blocked pending owner approval.

### T047 - Full Strict-Pass Rerun

Approval path: Option D from `owner-decision-menu-2026-05-14.md`.

Scope:

```text
post-fix-rerun-checklist-2026-05-14.md
fresh completion audit after fixes
```

Status: blocked until either T045 and T046 pass, or the owner/Judge explicitly reclassifies all remaining blockers.

## Decision

The board now has concrete future targets, but none are active. The current state remains audit-only and blocked.
