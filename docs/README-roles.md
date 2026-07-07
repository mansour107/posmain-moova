# POSMAIN Production Roles & PIN

Arabic-first RBAC with five system preset roles, staff PIN identity on POS terminals, and manager escalation.

## Preset roles (`role_key`)

| Key | Arabic label | Notes |
|-----|--------------|-------|
| `owner` | مالك | Bound to `usr_pwrs.id=1` when present; system-locked |
| `manager` | مدير | Discount limit 25%; manager override |
| `cashier` | كاشير | Discount limit 10% |
| `waiter` | ويتر | Table/order flows |
| `kitchen` | مطبخ | KDS view/complete |

Permissions are stored in `role_capabilities` (normalized) with `user_permission_grants` for per-user overrides. Legacy `usr_pwrs` boolean columns are deprecated; prune with `php tools/prune_usr_pwrs_legacy_columns.php --dry-run` after `php tools/permission_parity_check.php` passes.

## Staff PIN (terminal)

1. Set `POSMAIN_PIN_SECRET` in environment (required in production).
2. Assign 4–6 digit PIN on user create/edit (blacklisted codes rejected).
3. Terminal ERP login stays separate; staff enter PIN on `pos_barcode.php` unlock screen.
4. Admin/owner sessions may view staff PINs in Team Hub (`team.php`); other `users.manage` holders see masked PINs only.
5. Until any user has `pin_set_at`, legacy password unlock remains available.
6. Auto-lock after `app_settings.pos_autolock_seconds` (default 90).

### Acting user

- `pos_terminal_user_id()` — device ERP session
- `pos_acting_user_id()` — staff who unlocked POS (orders, drawer, shift)

## Manager escalation

`ajax/pos_override_auth.php` — manager PIN + permission → short-lived `manager_approvals` row (5 min TTL).

Client helpers on POS: `POSMAIN.ensureEscalationForAmount`, `POSMAIN.ensurePermissionOrOverride`, acting-user `POSMAIN_LIMITS`.

| Action | Permission / escalation key |
|--------|----------------------------|
| Discount over role % | `pos.discount.manual_pct.limit` |
| Price override | `pos.price.override` |
| Credit sale (أجل) | `pos.credit.sale` |
| Payout over limit | `pos.payout.over_limit` |
| No-sale drawer | `pos.drawer.no_sale` |
| Force-close drawer | `pos.shift.force_close` |
| Reprint receipt | `pos.reprint.receipt` |

## Admin UI

- Users: `team.php` — PIN badges, role column, deactivated toggle, admin PIN reveal on cards, lifecycle actions
- Add user: `add_user.php` — preset role cards, PIN generate + `pin_available` live check
- Roles: `role_permissions.php` — limit matrix, restore preset defaults, owner read-only
- User overrides: `edit_user.php` / `ajax/user_permissions.php`
- POS: acting name via `#posActingUserId`, lock via `#posHeaderLockBtn` (topbar) / auto-lock script

## Verification

```bash
export POSMAIN_PIN_SECRET=posmain-test-pin-secret
export POSMAIN_TEST_MYSQL_PORT=3307
php cli/seed_security_fixtures.php
composer test -- tests/security/ProductionRbacPinTest.php
scripts/run_security_contract_pack.sh
scripts/run_rbac_e2e.sh
```

## Security notes

- PINs stored as `password_hash` + HMAC `pin_lookup` (never log PIN/password).
- Failed PIN → per-user lockout + terminal freeze via `LoginThrottleService`.
- User delete is soft (`isdeleted`); blocked for last admin or open drawer.

See `docs/goals/production-rbac-pin/notes/DECISIONS.md` for implementation choices.
