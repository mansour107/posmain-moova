# Step 1 receipt notes

Date: 2026-07-27

## Done

- Replaced web password-reset/debug/setup utilities with hard `404` gone stubs (`includes/http_gone.php`).
- Removed junk zero-byte root artifacts and password-crack leftovers.
- Added private CLI password workflow:
  - `tools/invalidate_legacy_password_hashes.php`
  - `tools/issue_password_reset.php`
  - `tools/complete_password_reset.php`
- Legacy MD5 auth is denied when `POSMAIN_DENY_LEGACY_PASSWORD_AUTH=1` or production mode is on.
- Added release packaging policy + builder:
  - `config/prohibited_web_routes.php`
  - `config/release_artifact_policy.php`
  - `tools/build_release_artifact.php`
- Added Step 1 gate: `tools/commercial_v1_step1_gate.php`
- Edge deny rules added to `.htaccess` (Apache). PHP built-in server relies on gone stubs / CLI guards.

## Proof

- `php tests/sync/commercial_v1_step1_security_contract_test.php`
- `php tests/sync/commercial_v1_step1_password_reset_runtime_test.php`
- `php tools/commercial_v1_step1_gate.php` → 82/82
- Live HTTP against `:8010` returned 404 for `fix_passwords.php?key=...` and `tools/issue_password_reset.php`

## Not claimed

- Full AppSec review
- Complete write-surface migration of every legacy A/B path
- Steps 2–6
