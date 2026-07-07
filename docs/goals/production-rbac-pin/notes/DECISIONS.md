# DECISIONS — Production RBAC / PIN / Escalation

Recorded choices where the implementation spec left details implicit.

## Schema

| Decision | Choice | Rationale |
|----------|--------|-----------|
| `role_capabilities` shape | `role_id`, `permission_key`, `is_enabled`, `limit_value`, `is_unlimited`, `tenant`, `branch` | Mirrors `user_permission_grants` limit model at role level |
| `app_settings` shape | Key/value table with `setting_key` PK | Simple idempotent upserts; no parallel settings service |
| `permissions_version` | Stored in `app_settings`; incremented on preset seed and (Phase 2) role edits | Fixes stale session capabilities cache |
| `pos_autolock_seconds` default | `90` | Per spec default |
| Preset `owner` on existing DB | Bind `role_key=owner`, `is_system=1` on `usr_pwrs.id=1` when present | Preserves admin superuser compatibility |
| Five preset `role_key` values | `owner`, `manager`, `cashier`, `waiter`, `kitchen` | Aligns with e2e personas and permission matrix |

## Permission limits (preset defaults)

| Role | Permission | Limit |
|------|------------|-------|
| cashier | `pos.discount.apply` | 10% (`limit_value=10`, `is_unlimited=0`) |
| manager | `pos.discount.apply` | 25% |
| manager | `pos.discount.manager_override` | unlimited |

## PIN secret

| Decision | Choice |
|----------|--------|
| Source order | `POSMAIN_PIN_SECRET` env → `POSMAIN_PIN_SECRET` constant in `includes/config.php` |
| Missing secret | Throw `RuntimeException('PIN_SECRET_MISSING')` via `posmain_pin_secret()` |

## PIN rollout (Phase 3)

| Decision | Choice |
|----------|--------|
| Legacy fallback | Keep password/barcode unlock until at least one active user has `pin_set_at` set |
| `pin_lookup` | HMAC-SHA256 of normalized PIN + secret; **globally unique** (one PIN → one user) |

## Escalation (Phase 4)

| Decision | Choice |
|----------|--------|
| Approval TTL | **90 seconds** default from `expires_at` when not supplied (T023) |
| Consumption | Set `consumed_at` + `performed_by` on successful override use |
| Override UI | Reusable RTL PIN pad modal (`POSMAIN.showPinPadModal`) — not SweetAlert/password prompt |
| Approver limit | `authenticateManagerOverride` enforces `checkAmount` on limit keys → `APPROVER_LIMIT_EXCEEDED` |
| `pin_available` check | `?pin=` returns **only** `{available: bool}`; requires `users.manage`; metadata (autolock) without `pin` param |
| Override CSRF scope | `pos_override` (separate from `pos_pin` login/lock) |

## Permission facade (Phase 2)

| Decision | Choice |
|----------|--------|
| Unknown permission key | `InvalidArgumentException('PERMISSION_KEY_UNKNOWN')` |
| Resolution order | admin → user grants → role_capabilities → legacy flags → deny |
| Owner role immutability | `PermissionService::ADMIN_ROLE_IMMUTABLE`; `owner` / `id=1` throws `ADMIN_ROLE_IMMUTABLE` on edit |
| Escalation permission keys | `pos.discount.manual_pct.limit`, `pos.price.override`, `pos.drawer.no_sale`, `pos.payout.over_limit`, `pos.credit.sale`, `pos.reprint`, `pos.shift.force_close`, `pos.void.post_send`, `pos.refund.limit` |
| Refund limit key | `pos.refund.limit` — amount ceiling before manager override (`pos.refund`) |
| Discount escalation | Compare discount **percentage** against `pos.discount.apply` limit; escalate with `pos.discount.manual_pct.limit` |
| PIN lockout doubling | `pin_lockout_count` doubles base 900s lock duration (capped at 4 doublings) |
| PIN lockout base duration (accepted deviation) | **900s** base lockout in `PinService` (spec §8.1 cited 60s); doubling cap unchanged — accepted for production safety (T025 follow-up) |
| Cart parking | `localStorage` key `pos_parked_cart_{userId}`; restored on PIN re-login via `POSMAIN.restoreParkedCartForActingUser` |
| Payout default limit (cashier) | `pos.payout.over_limit` limit_value `100` EGP before manager PIN |
| Acting user on POS | `POSMAIN_CAPABILITIES` + `POSMAIN_LIMITS` injected from `posmain_render_acting_pos_context_script()` for `pos_acting_user_id()` |
| Force-close drawer | `do/do_force_close_drawer.php` + `ShiftSessionService::forceCloseDrawerForUser()`; open sessions listed on `closed_sessions.php` |
| No-sale drawer | `ajax/pos_drawer_no_sale.php` records audit movement after `pos.drawer.no_sale` or manager override |

## Lifecycle (Phase 5)

| Decision | Choice |
|----------|--------|
| Deactivate | Soft delete via `isdeleted=1`; `do/do_user_deactivate.php` |
| PIN reset | `do/do_user_reset_pin.php` or Team Hub AJAX; admin/owner can view stored PINs in Team Hub; one-time session reveal still supported after legacy reset redirect |
| Role change | Clears `user_permission_grants`, resets `permission_mode=role_only`, bumps `permissions_version` |
| Delegation guard | Non-admin `users.manage` holders cannot create/edit admin users, promote to admin role, or edit another `users.manage` holder → `PRIVILEGE_ESCALATION_BLOCKED` |
| Receipt escalation line | Consumed `manager_approvals` on order print → `بواسطة X — بموافقة Y` in receipt payload/footer |

## Permission key aliases (T024)

| Spec name | Canonical / alias in code |
|-----------|---------------------------|
| `pos.credit.sell` | Alias of `pos.credit.sale` (same legacy flags) |
| `pos.drawer.payout.limit` | Alias of `pos.payout.over_limit` (same legacy flags + limit row) |
| `pos.order.modify_others` | New key → `edit_sales`, `delete_sales` |
| `pos.void.item_after_send` | New key → `edit_sales`, `delete_sales` |
| `pos.drawer.payin` | New key → `edit_payment`, `add_payment` |
| `pos.shift.force_close_others` | New key → `edit_sales`, `sid_sales` |
| `reports.own_shift`, `reports.branch_daily`, `reports.costs` | New report-scoped keys |

