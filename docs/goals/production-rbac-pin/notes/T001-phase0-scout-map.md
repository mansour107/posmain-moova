# T001 Phase 0 Scout Map — Production RBAC / PIN / Escalation

## Existing infrastructure (extend, do not duplicate)

| Area | Files | Status |
|------|-------|--------|
| Session / CSRF | `includes/session_bootstrap.php`, `includes/csrf.php` | Production-ready; DB sessions in prod |
| Auth / permissions | `includes/auth_guard.php` | Named permission map → legacy `usr_pwrs` flags; user overrides via `UserPermissionGrantService`; session capabilities cache |
| Page / route guards | `includes/page_guard.php`, `includes/rbac_route_guard.php`, `config/rbac_*_manifest.php` | Broad RBAC wiring in dirty worktree |
| Role sync | `classes/Security/RolePermissionSyncService.php` | Maps permissions ↔ legacy columns; `permissionGroups()` for UI |
| User overrides | `classes/Security/UserPermissionGrantService.php` | `user_permission_grants` table; no `limit_value`/`is_unlimited` yet |
| Audit / throttle | `SecurityAuditLogger.php`, `LoginThrottleService.php` | `security_audit_log`, `failed_login_attempts` exist |
| Manager approval | `ManagerApprovalService.php` | Basic CRUD + `requireApprovedIfNeeded`; missing `expires_at`, `consumed_at`, `permission_key`, `performed_by` |
| POS shift | `ShiftSessionService.php`, `DrawerSessionService.php`, `auth_guard` POS session keys | Barcode/password unlock; no PIN acting user |
| Schema | `classes/Sync/SchemaManager.php` | `user_permission_grants`, `manager_approvals` tables exist; PIN/RBAC columns missing |
| Password | `classes/PasswordService.php` | bcrypt + legacy MD5; no PIN helpers |
| POS login | `pos_barcode.php`, `includes/pos_login_screen.php` | Verifies **account password** as barcode; no PIN pad |
| Waiter | `includes/waiter_auth.php`, `waiter_barcode.php` | Session flags `user_logged_in`, `is_waiter`; no PIN |
| Lock UI | `includes/pos_lock_system.php` | Client-side sessionStorage lock only |
| Tests | `tests/e2e/helpers/rbac.ts`, `playwright.config.ts` rbac project | RBAC e2e exists; `tests/security/` and `cli/seed_security_fixtures.php` **missing** |
| Docs | `docs/production/permission_matrix.md` | 9 logical roles documented; 5 personas in e2e |

## Gaps vs spec (Phases 1–9)

### Phase 1 — Schema
- `usr_pwrs.role_key`, `is_system`
- `role_capabilities` table (new)
- `user_permission_grants.limit_value`, `is_unlimited`
- `users` PIN columns (`display_name`, `phone`, `pin_hash`, `pin_lookup`, lockout fields)
- `manager_approvals` escalation columns
- `app_settings` (`permissions_version`, `pos_autolock_seconds` default 90)
- Idempotent seed of 5 preset roles (`owner`, `manager`, `cashier`, `waiter`, `kitchen`)
- `POSMAIN_PIN_SECRET` helper — fail closed `PIN_SECRET_MISSING`

### Phase 2
- `classes/Security/PermissionService.php` **does not exist**
- `auth_guard_capabilities_version()` uses `hash(userId:roleId)` only — **not** `permissions_version` from DB (cache bug)

### Phase 3–4
- No `pos_acting_user_id()`, `ajax/pos_pin_login.php`, `ajax/pos_override_auth.php`
- No auto-lock from `pos_autolock_seconds`

### Phase 5–8
- Lifecycle audit hooks partial; UI lacks PIN fields; security PHPUnit suite absent

## Compatibility risks

1. POS unlock today uses login password — PIN rollout must keep password fallback until any user has PIN set.
2. `auth_guard_is_admin_session()` treats `usr_pwrs.id=1` as superuser — preset `owner` must align with id 1 or document bridge.
3. Capabilities session cache can go stale after role edits until `permissions_version` fix ships.
4. Large dirty worktree — Worker tasks must stay scoped; no unrelated reverts.

## Recommended Worker sequence

| Task | Scope |
|------|-------|
| T003 | SchemaManager + RolePermissionSyncService seed + PIN secret + DECISIONS.md |
| T004 | PermissionService + cache fix + role edit rules |
| T005 | PIN terminal + ajax endpoints + auto-lock |
| T006 | Manager override auth + enforcement |
| T007 | Lifecycle guards (user/role/PIN) |
| T008 | Arabic UI (users, roles, PIN) |
| T009 | Remaining route/page manifest endpoints |
| T010 | PHPUnit + Playwright + fixtures + README-roles.md |
| T999 | Final audit |

## Verification commands

```bash
node ~/.codex/skills/goal-maker/scripts/check-goal-state.mjs docs/goals/production-rbac-pin/state.yaml
php -l classes/Sync/SchemaManager.php
POSMAIN_DB_PORT=3307 php tools/run_migrations.php --dry-run
composer test
scripts/run_rbac_e2e.sh
```

## Blockers / owner input

- `role_capabilities` column layout not in repo — infer from spec (permission_key + limit_value + is_unlimited per role).
- Exact numeric defaults for discount limits — record in DECISIONS.md.
