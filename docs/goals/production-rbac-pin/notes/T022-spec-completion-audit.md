# T022 — Production RBAC/PIN Spec Completion Audit

**Date:** 2026-07-05  
**Auditor:** Codebase + test run (read-only)  
**Board claim:** `state.yaml` → `status: done` (T999 Judge)  
**Verdict vs original MUST spec:** **substantially_complete (~72%)** — **not** Phase 9 done

---

## 1. Overall verdict

| Verdict | Estimate |
|---------|----------|
| **substantially_complete** | **~72%** |

**What is genuinely solid:** SchemaManager extensions, `PermissionService` facade, PIN terminal pad, acting-user plumbing, lifecycle handlers, manifest routes, security contract pack, and 43 PHPUnit tests that pass locally.

**Why not "complete":** Spec sections **8.1** (25 behavioral PHPUnit cases) and **8.2** (19 Playwright scenarios) are largely unimplemented as specified; several explicit MUSTs diverge (override UI, 90s TTL, `pin_available` shape, preset permission matrix, delegation guards, receipt dual-name line). Goal board `done` is **premature** — same pattern as T011.

**Tests run this audit:**

| Command | Result |
|---------|--------|
| `bash scripts/run_security_contract_pack.sh` | **PASS** (18 contract files) |
| `ProductionRbacPinTest.php` (43 tests) | **PASS** 43/43 |
| `bash scripts/run_rbac_e2e.sh` | **BLOCKED** in read-only sandbox (`EPERM` on `test-results/`); prior board receipt: 70 passed, **1 skipped** |
| `composer test` | **NOT RUN** — `vendor/bin/phpunit` missing |

---

## 2. Phase-by-phase

### Phase 0 — Read / conventions

**DONE**

- Scout map (`T001`), DECISIONS.md, README-roles.md exist; conventions followed.

### Phase 1 — Schema

**PARTIAL**

- `role_key`, `role_capabilities`, PIN columns, `manager_approvals` escalation cols, `app_settings` present in `SchemaManager.php`.
- **Gap:** Spec seeds `role_key='admin'`; implementation uses **`owner`** (documented in DECISIONS, not spec literal).
- **Gap:** Table uses `is_enabled` not spec's `is_allowed`.
- **Gap:** Preset capability defaults diverge from spec §2.3 matrix (e.g. cashier gets `pos.shift.open/close`; spec says off; discount limits differ).

### Phase 2 — Permission model

**PARTIAL**

- `PermissionService::check/limit/checkAmount` exist; `permissions_version` in `auth_guard_capabilities_version()` fixed.
- **Gap:** Missing keys: `pos.order.modify_others`, `pos.void.item_after_send`, `pos.drawer.payin`, `pos.shift.force_close_others`, `reports.own_shift`, `reports.branch_daily`, `reports.costs`; naming drift (`pos.credit.sale` vs `pos.credit.sell`, `pos.payout.over_limit` vs `pos.drawer.payout.limit`).
- **Gap:** Preset seed matrix incomplete vs spec §2.3 (manager missing refund/void/reprint limits, etc.).
- **Gap:** `bumpPermissionsVersion()` not on all grant paths (e.g. `do/doadd_user.php`).

### Phase 3 — Staff PIN identity

**PARTIAL**

- RTL PIN pad ≥64px, explicit **دخول** (no auto-submit at 6 digits) in `includes/pos_login_screen.php`.
- `pos_acting_user_id()`, `ajax/pos_pin_login.php`, cart parking, `#posHeaderLockBtn`, `session_regenerate_id` on login.
- **Gap:** Per-user lockout base is **900s** (`PinService::LOCK_SECONDS`), not spec **60s** doubling from 60.
- **Gap:** Terminal freeze **900s**, not 60s; login returns distinct codes (`PIN_USER_LOCKED`, `PIN_TERMINAL_FROZEN`) vs spec "byte-identical generic response".
- **Gap:** No role-based 6-digit admin/manager rule; no phone-suffix blacklist; `PIN_TOO_WEAK` not implemented.

### Phase 4 — Escalation

**PARTIAL**

- `ManagerApprovalService`, `ajax/pos_override_auth.php`, client `ensureEscalationForAmount` / `ensurePermissionOrOverride`.
- **MUST violation:** Override modal uses **SweetAlert `input: 'password'`** / `window.prompt`, **not** the PIN pad component (§4.1).
- **MUST violation:** Approval TTL **`DEFAULT_TTL_SECONDS = 300`**, not **90s** (§4.1).
- **Gap:** No `APPROVER_LIMIT_EXCEEDED`; override auth checks bool permission only, not `checkAmount` on limit keys.
- **Gap:** Receipt line **"بواسطة X — بموافقة Y"** not found in print/receipt paths.

### Phase 5 — Lifecycle guard rails

**PARTIAL**

- `UserLifecycleGuardService`, `do/do_user_{deactivate,reactivate,reset_pin,unlock_pin}.php`, soft-delete preserved.
- **Gap:** **`PRIVILEGE_ESCALATION_BLOCKED`** not implemented in `do/` handlers (grep: zero matches).
- **Gap:** Audit event names don't match spec (`perm.escalation_*`, `pin.login_*`, `user.created`, etc.) — only generic events like `manager_override_granted`.

### Phase 6 — UI

**PARTIAL**

- `users.php`: display name, role, PIN badge, deactivate toggle, unlock PIN — **partial** spec table.
- `add_user.php`: role cards, PIN reveal banner, `pin_available` XHR — **partial**.
- `role_permissions.php`: per-role limit matrix + restore defaults — **not** §6.3 five-column preset matrix on `myroles.php`.
- **Gaps:** No color-coded role badges, overrides indicator, last PIN login, reset-PIN action on list, dismissible "missing PIN" banner; add-user Step 2 accordion deferred ("بعد الإنشاء"); `myroles.php` is legacy table only.

### Phase 7 — Endpoints

**PARTIAL**

- PIN/override/lock/capabilities/lifecycle routes in `config/rbac_route_manifest.php`.
- **MUST violation:** `ajax/pin_available.php` returns `{success, pin_mode, any_user_has_pin, …, pin_available}` — spec requires **`{available: bool}` only**.
- **Gap:** `pin_available` uses `require_login()` not `require_permission('users.manage')`.

### Phase 8 — Tests

**PARTIAL / MISSING**

- 43 PHPUnit methods exist; **~10/25** spec §8.1 cases have real behavioral coverage; rest are existence/smoke.
- RBAC Playwright project has ~42 `test()` blocks across 5 files — **not** the 19 §8.2 scenarios; **1 skipped** per board.
- `cli/seed_security_fixtures.php` + README exist.

### Phase 9 — Definition of done

**NOT MET**

- §8.1 + §8.2 not satisfied; skipped e2e; full `composer test` not verified; grep gate not proven on all new paths.

---

## 3. Test matrix — PHPUnit §8.1 (25 cases)

| # | Spec case | Implemented test | Status |
|---|-----------|------------------|--------|
| 1 | Role default bool/limit/unlimited (each type) | `test_role_capability_limits_for_cashier`, `test_permission_service_check_amount_respects_cashier_discount_limit` | **PARTIAL** |
| 2 | User override wins over role | — | **MISSING** |
| 3 | Role edit affects non-overridden only | — | **MISSING** |
| 4 | Role change clears overrides + audit | — | **MISSING** |
| 5 | Unknown key → exception | `test_permission_service_check_unknown_key_throws` | **DONE** |
| 6 | Limit boundary (equal, +0.01, 0, unlimited) | partial in #1, `test_payout_limit_blocks_amount_over_ceiling` | **PARTIAL** |
| 7 | Legacy flag fallback | — | **MISSING** |
| 8 | Capabilities cache rebuild (2 sessions) | `test_capabilities_version_includes_permissions_version` | **PARTIAL** |
| 9 | PIN uniqueness + errno 1062 race | `test_pin_set_find_and_clear_roundtrip` | **PARTIAL** |
| 10 | Blacklist + role-length matrix | `test_pin_blacklist_rejects_1234` | **PARTIAL** |
| 11 | Deactivate frees PIN; reactivate needs new | — | **MISSING** |
| 12 | HMAC deterministic; no plaintext PIN in DB; PIN_SECRET_MISSING | `test_pin_lookup_is_deterministic`, `test_pin_secret_available` | **PARTIAL** |
| 13 | Correct PIN → acting user; generic wrong response | — | **MISSING** |
| 14 | 5 failures → 60s lock; doubling; hourly reset | `test_pin_lockout_count_increments_on_lock` | **PARTIAL** (wrong base duration) |
| 15 | Terminal freeze 10/min | — | **MISSING** |
| 16 | Deactivated acting user rejected | — | **MISSING** |
| 17 | Last admin protected | `test_last_admin_guard_blocks_deactivate` | **DONE** |
| 18 | Delegation escalation blocked (4 vectors) | — | **MISSING** (feature also missing) |
| 19 | Role change blocked with open drawer | — | **MISSING** |
| 20 | display_name uniqueness trim/case | `test_display_name_unique_guard` | **DONE** |
| 21 | No hard-delete users | — | **MISSING** |
| 22 | Approval single-use; 90s expiry; scope/target/amount/approver limit | `test_manager_approval_consume_twice_fails`, `test_manager_approval_expired_is_rejected` | **PARTIAL** (TTL 300s; no approver-limit) |
| 23 | Audit: performed_by + authorized_by | — | **MISSING** |
| 24 | Parametrized audit events Phases 3–5 | — | **MISSING** |
| 25 | No PIN/password in audit metadata | `test_audit_logger_does_not_reference_pin_literal_in_source` | **PARTIAL** (source grep only) |

**Count:** ~4 DONE, ~11 PARTIAL, ~10 MISSING → **~40% of §8.1 truly met**

---

## 4. Playwright §8.2 (19 scenarios)

| # | Scenario | Spec file / coverage | Status |
|---|----------|----------------------|--------|
| 1 | Owner creates cashier via cards; PIN once; terminal login | — | **MISSING** |
| 2 | Each preset lands on correct home (kitchen→KDS, etc.) | — | **MISSING** |
| 3 | Edit cashier discount limit → immediate UI effect | — | **MISSING** |
| 4 | "يفتح الشيفت" shortcut override | — | **MISSING** |
| 5 | PIN reset old fails / new works | — | **MISSING** |
| 6 | Deactivate / reactivate + new PIN dialog | — | **MISSING** |
| 7 | Cashier direct URL to admin pages → 403 | `rbac-full-suite.spec.ts` (page denials) | **PARTIAL** |
| 8 | Cashier POST `doadd_user` → 403 | `rbac-full-suite.spec.ts` (write denials) | **PARTIAL** |
| 9 | Waiter payment endpoint → 403 | `rbac-full-suite.spec.ts` | **PARTIAL** |
| 10 | IDOR: manager PATCH admin user → 403 | — | **MISSING** |
| 11 | Locked void → override → success; dual audit; session intact | — | **MISSING** |
| 12 | 5 wrong manager PINs in override → lockout countdown | — | **MISSING** |
| 13 | 5 wrong PINs on pad; no identity leak | — | **MISSING** |
| 14 | Auto-lock; same user cart resume; different user parks | — | **MISSING** |
| 15 | CSRF replay rejected | `rbac-escalation-ui.spec.ts` (override CSRF) | **PARTIAL** |
| 16 | Session fixation (id changes after PIN login) | — | **MISSING** |
| 17 | Duplicate PIN UI + `{available}` only body | `rbac-escalation-ui.spec.ts` (checks `pin_available` bool, not body-only) | **PARTIAL** |
| 18 | Last-admin deactivate button disabled + tooltip | — | **MISSING** |
| 19 | RTL staff page + PIN pad @ 1024×768 & 1366×768 | `rbac-escalation-ui.spec.ts` (POS RTL only) | **PARTIAL** |

**Count:** ~0 DONE, ~5 PARTIAL, ~14 MISSING → **~15% of §8.2 met**

---

## 5. Critical MUST violations

| MUST | Spec | Actual |
|------|------|--------|
| Override modal | Same PIN pad component | SweetAlert / `prompt` in `js/pos_barcode.js` |
| Approval TTL | 90 seconds | `ManagerApprovalService::DEFAULT_TTL_SECONDS = 300` |
| `pin_available` body | `{available: bool}` only | 7+ fields in `ajax/pin_available.php` |
| `role_key` admin preset | `admin` | `owner` (documented deviation) |
| Per-user lockout | 60s base, double hourly | 900s base (`PinService`) |
| Delegation limits | `PRIVILEGE_ESCALATION_BLOCKED` | Not implemented |
| Receipt escalation | "بواسطة X — بموافقة Y" | Not implemented |
| Phase 8 | All 25 + 19 tests, none skipped | ~43 smoke-heavy PHPUnit; ~4/19 e2e; 1 skipped |
| Phase 9 | `composer test` green | `vendor/bin/phpunit` absent |

---

## 6. Recommendation — true Phase 9 done

**P0 — MUST alignment (blocks "done")**

1. Replace SweetAlert override with reusable PIN pad modal; wire visible-but-locked buttons per §4.1.
2. Set escalation approval TTL to **90s**; enforce approver `checkAmount` → `APPROVER_LIMIT_EXCEEDED`.
3. Shrink `pin_available` to `{available: bool}`; guard with `users.manage`.
4. Implement `PRIVILEGE_ESCALATION_BLOCKED` in user create/edit/deactivate paths.
5. Add receipt/void dual-name line when `manager_approval_id` consumed.

**P1 — Spec fidelity**

6. Complete permission key map + preset matrix per §2.2–2.3 (or update spec/DECISIONS with explicit deltas).
7. Fix lockout timings (60s base, 60s terminal freeze) or document accepted deviation.
8. Finish §6 UI: `users.php` full columns, `myroles.php` 5-column preset matrix, add-user inline Step 2 overrides.

**P2 — Tests (definition of done)**

9. Add missing §8.1 cases (target: 25 behavioral tests, not existence checks).
10. Add dedicated `rbac-pin-spec.spec.ts` (or expand escalation spec) for all 19 §8.2 scenarios.
11. Run `composer test` + full Playwright with **zero skips**; record in `state.yaml`.

**Estimated remaining effort:** ~2–3 focused worker tranches (tests alone ≈1 tranche).

---

## Board vs truth

| Source | Claims |
|--------|--------|
| `state.yaml` T999 (prior) | `decision: complete` |
| This audit | **substantially_complete (~72%)** — infrastructure strong; spec MUST list and §8.1/§8.2 test contract not satisfied |

Recommend `goal.status: active` until §8.1+§8.2+§9 pass with Judge receipt tied to the matrices above. Worker queue: **T023** (P0 escalation UI/TTL/pin_available), **T024** (P0 delegation + receipt + keys), **T025** (P2 test matrices).
