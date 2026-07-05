# T011 Gap Audit — Production RBAC / PIN / Escalation (2026-07-05)

Premature `goal.status: done` corrected. Audit vs T001 scout map + user gap list + current code.

## Phase 2 — PermissionService facade (GAPS)

| Item | Status |
|------|--------|
| `check()`, `limit()`, `checkAmount()` | **Missing** — only helper methods exist |
| Unknown key → `PERMISSION_KEY_UNKNOWN` | **Missing** |
| Resolution: admin → grants → role_capabilities → legacy → deny | **Partial** — grants wired; **role_capabilities not in** `auth_guard_session_has_permission` |
| `permissions_version` in capabilities cache | **Done** |
| Bump version on all role/grant/userrole writes | **Partial** — only `doedit_role_permissions.php` |
| `ADMIN_ROLE_IMMUTABLE` / restore preset defaults | **Partial** — `assertRoleEditable` exists; no restore API |
| Escalation permission keys in map | **Missing** — no `pos.price.override`, `pos.reprint`, etc. |

## Phase 3 — PIN terminal (GAPS)

| Item | Status |
|------|--------|
| PinService, pos_pin_login, auto-lock | **Done** |
| `pos_acting_user_id()` | **Done** |
| Cart parking on user switch | **Missing** |
| PIN pad ≥64px, explicit OK (no auto-submit at 6 digits) | **Gap** — auto-submits at maxLen |
| Full lockout rules | **Partial** — PinService has constants; verify audit hooks |

## Phase 4 — Escalation (GAPS)

| Item | Status |
|------|--------|
| ManagerApprovalService TTL/consume | **Done** |
| `ajax/pos_override_auth.php` | **Done** |
| Wired into refund/void/cancel/discount/price handlers | **Partial** — `PosOrderMutationService` has gates; `refund_order.php` uses session userid not acting |
| `pos_acting_user_id` on POS mutations | **Gap** in `refund_order.php`, `posmain_resolve_pos_user_id` |
| Terminal locked buttons + override modal | **Missing** in `pos_barcode.js` (recipe override uses old endpoint) |
| CSRF `pos_override` for override auth | **Wrong** — manifest + endpoint use `pos_pin` |

## Phase 5 — Lifecycle (GAPS)

| Item | Status |
|------|--------|
| UserLifecycleGuardService | **Done** (last admin, drawer, display_name) |
| `do_user_deactivate/reactivate/reset_pin/unlock_pin` | **Missing** |
| Override clear on role change | **Gap** |
| SecurityAuditLogger for lifecycle events | **Partial** |

## Phase 6 — UI (GAPS)

| Item | Status |
|------|--------|
| users.php columns/badges/PIN status/actions | **Partial** — styled list; missing PIN badges, deactivate toggle |
| add_user role cards, pin_available, one-time reveal | **Partial** — basic PIN field only |
| role_permissions preset matrix + limits + restore | **Partial** — checkboxes only |
| POS header lock button | **Gap** — acting name shown; no lock button in header |

## Phase 7 — Endpoints

Manifest covers pin/override/capabilities; lifecycle `do/do_user_*` routes **missing** from manifest.

## Phase 8 — Tests

| Item | Status |
|------|--------|
| `ProductionRbacPinTest.php` | ~25 tests but many are existence checks, not behavioral |
| Security contract pack | Present; not run in premature completion |
| Playwright RBAC e2e | Exists; status unknown |

## Phase 9

SchemaManager idempotent test exists in PHPUnit; DECISIONS.md incomplete for new keys; grep gate partial.

## Recommended worker sequence (Judge T012)

1. T013 — Phase 2 facade + auth_guard role_capabilities + permission keys + version bumps
2. T014 — Phase 3 PIN UX + cart parking + acting user resolution
3. T015 — Phase 4 escalation wiring + override modal + CSRF fix
4. T016 — Phase 5 lifecycle handlers + audit
5. T017 — Phase 6 UI upgrades (users, roles, POS lock)
6. T018 — Phase 7 manifest routes for lifecycle
7. T019 — Phase 8 expand tests + run packs
8. T999 — Final audit (only if tests green)
