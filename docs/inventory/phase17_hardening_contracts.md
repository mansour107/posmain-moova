# Phase 17 Hardening Contracts

Phase 17 adds production-readiness checks without changing inventory write behavior.

## Added Controls

- `InventoryOperationalHardeningService` provides bounded pagination, retryable database error detection, a small retry wrapper, and operator-safe stock messages.
- `InventoryStockReadService` keeps item movement history paginated with a server-side limit and offset.
- `tools/inventory_operational_health_check.php` verifies important inventory indexes and hardening controls.
- The health check reports the planned required-index count even when the database is unreachable, and exposes `summary.index_check_status` as `checked`, `database_unreachable`, or `not_checked`. This prevents a blocked local runtime from looking like there are zero required indexes.
- The health check scans real `ajax/inventory_*.php` endpoints and blocks cutover if any endpoint misses both required request controls: `require_csrf(...)` and `require_permission(...)`. Shared helper includes are skipped because they are not callable endpoints, and the skipped helper list is printed in the health output so the exception stays visible.
- The waste/adjustment UI and endpoint guard cost-sensitive fields: users without accounting/report cost access see hidden cost UI, cost values must not be embedded in option attributes or JSON balance payloads, and crafted `unit_cost` / `total_cost` fields are ignored server-side.
- The item movement history hides raw technical IDs from the normal operator table, shows an Arabic stock-source badge, sanitizes date filters before the legacy history query, and gates price/cost/profit columns behind accounting/admin access.

## Retry Policy

Only retry database failures that MySQL identifies as lock wait timeout or deadlock:

- `1205`
- `1213`
- SQLSTATE `40001`
- SQLSTATE `41000`

The retry helper does not make non-idempotent work magically safe. Services should still use deterministic idempotency keys and transaction boundaries.

## Operator Messages

Inventory errors shown to staff should be specific and actionable:

- Good: `Milk stock is 0 in Kitchen Store.`
- Bad: `movement failed`

## Cost Visibility

Inventory screens may need cost data for accounting users, but normal inventory
operators should not receive hidden costs in page source, JavaScript payloads,
or AJAX responses. If a user cannot view cost, write endpoints should calculate
movement cost server-side from inventory balances instead of trusting a posted
cost field.

## Health Check

Run:

`php tools/inventory_operational_health_check.php --json`

The tool is read-only and should be part of the cutover checklist with preflight, reconciliation, accounting, reports, and browser smoke tests.
