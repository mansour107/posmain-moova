# Production RBAC, PIN Identity, and Manager Escalation

## Objective

Deliver production-grade role presets, permission limits, staff PIN identity on POS terminals, manager approval escalation, lifecycle guard rails, Arabic RTL admin UI, and full test coverage — extending existing security infrastructure only (no parallel duplicate tables/guards/services).

## Goal Kind

`specific`

## Current Tranche

**Substantially complete (~72% per T022 audit)** — infrastructure and contract pack are solid; original MUST list and §8.1/§8.2 test contract are not satisfied. Active queue: **T023** (override PIN pad, 90s TTL, `APPROVER_LIMIT_EXCEEDED`, `pin_available` shape), **T024** (`PRIVILEGE_ESCALATION_BLOCKED`, receipt dual-name, permission keys), **T025** (25 PHPUnit + 19 Playwright behavioral cases, zero skips). Re-run **T999** only after T023–T025 receipts green.

## Non-Negotiable Constraints

- PHP 8.2, mysqli, prepared statements, `if (!function_exists)` guards.
- Arabic-first UI; error codes like `RuntimeException('APPROVAL_NOT_FOUND')`.
- No standalone SQL migrations — only `classes/Sync/SchemaManager.php`.
- Extend existing infrastructure: `auth_guard`, `page_guard`, `UserPermissionGrantService`, `RolePermissionSyncService`, `ManagerApprovalService`, `ShiftSessionService`, etc.
- `POSMAIN_PIN_SECRET` from env or `includes/config.php`; fail closed with `PIN_SECRET_MISSING`.
- Grep gate: no PIN/password in logs/audit.
- No commits unless owner explicitly requests.
- Minimize scope per Worker task; complete full spec across board tasks sequentially.

## Stop Rule

Stop when Phase 9 definition of done passes (all tests green, SchemaManager idempotent, DECISIONS.md complete), all safe local work is blocked, or continuing requires owner input, credentials, or destructive operations outside the board.

## Canonical Board

Machine truth lives at:

`docs/goals/production-rbac-pin/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/production-rbac-pin/goal.md
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
