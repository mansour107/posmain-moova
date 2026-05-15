# Phase 3 Security Focused Tests - 2026-05-14

## Purpose

Refresh focused Phase 3/security hardening evidence without editing code, applying migrations to current `kody2`, or running destructive database actions.

## Test Set

Standalone/source-contract and disposable-schema tests:

```sh
php tests/sync/phase3_security_helpers_test.php
php tests/sync/admin_write_security_test.php
php tests/sync/pos_browser_write_csrf_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/security_audit_logger_test.php
php tests/sync/moova_token_visibility_security_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/security_schema_migration_test.php
php tests/sync/moova_admin_security_test.php
php tests/sync/upload_guard_test.php
php tests/sync/phase3_permission_matrix_test.php
php tests/sync/upload_route_contract_test.php
POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/login_throttle_service_test.php
php tests/sync/login_security_integration_test.php
php tests/sync/password_change_security_test.php
php tests/sync/pos_form_write_security_test.php
```

All passed:

```text
phase3-security-helpers-ok
admin-write-security-ok
pos-browser-write-csrf-ok
security-audit-logger-ok db=posmain_security_audit_36099
moova-token-visibility-security-ok
security-schema-migration-ok db=posmain_security_schema_36103
moova-admin-security-ok
upload-guard-ok
phase3-permission-matrix-ok
upload-route-contract-ok
login-throttle-service-ok db=posmain_login_throttle_37405
login-security-integration-ok
password-change-security-ok
pos-form-write-security-ok
```

PHPUnit-style auth service test:

```sh
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/cloud_auth_service_test.php
```

Result:

```text
OK (3 tests, 7 assertions)
```

Note: running `cloud_auth_service_test.php` directly with `php` is a runner error because it extends `PHPUnit\Framework\TestCase`; it is not an application failure. The PHPUnit rerun passed.

## Cleanup And Baseline

Temporary schema cleanup check:

```sh
docker exec posmain-mysql mariadb -uroot -N -e "SHOW DATABASES LIKE 'posmain_security_%'; SHOW DATABASES LIKE 'posmain_login_throttle_%';"
```

Output: no rows.

POS baseline after the tests:

```sh
curl -s -o /tmp/posmain-after-phase3-tests-http.txt -w '%{http_code}\n' http://127.0.0.1:8010/index.php
```

Result:

```text
200
```

## What This Covers

- Auth guard and POS session helper behavior.
- CSRF helper behavior and POS write CSRF contracts.
- Admin write-route permission and CSRF contracts.
- Security audit logger behavior.
- Security schema planning/application/idempotency on a disposable schema.
- Login throttling service behavior.
- Login integration source contract.
- Password change security contract.
- Upload guard and upload route security contracts.
- Permission matrix contract.
- Moova admin security and token visibility security contracts.
- Cloud auth service PHPUnit coverage.

## Verdict

This focused Phase 3/security slice passed.

It does not clear the full P0-P6 strict certification because the remaining blockers are outside this green slice:

- `js/pos_auto_lock.js` syntax failure.
- Pending `order_fulfillment` migration on current `kody2`.
- Persistent Moova `/readyz` is degraded with `redis=false`.
- Moova widget contract test failure.
- Write-surface inventory test failure.
- Minimal schema fixture smoke failures.
- Cashier-facing Moova degraded-state UX still presents empty-queue copy while runtime health is red.
