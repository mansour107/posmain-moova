#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

export PLAYWRIGHT_BROWSERS_PATH="${PLAYWRIGHT_BROWSERS_PATH:-$HOME/Library/Caches/ms-playwright}"
export POSMAIN_TEST_HTTP_BASE="${POSMAIN_TEST_HTTP_BASE:-http://127.0.0.1:8010}"
export POSMAIN_PIN_SECRET="${POSMAIN_PIN_SECRET:-posmain-test-pin-secret-do-not-use-in-prod}"
export POSMAIN_DB_HOST="${POSMAIN_DB_HOST:-127.0.0.1}"
export POSMAIN_DB_PORT="${POSMAIN_DB_PORT:-3307}"
export POSMAIN_DB_NAME="${POSMAIN_DB_NAME:-kody2}"
export POSMAIN_DB_USER="${POSMAIN_DB_USER:-root}"
export POSMAIN_DB_PASS="${POSMAIN_DB_PASS:-}"
export POSMAIN_TEST_MYSQL_DB="${POSMAIN_TEST_MYSQL_DB:-kody2}"

export POSMAIN_DB_NAME="${POSMAIN_DB_NAME:-kody2}"
export POSMAIN_TEST_MYSQL_DB="${POSMAIN_TEST_MYSQL_DB:-kody2}"

echo "==> Team Hub + RBAC full verification"

echo "==> seed security fixtures"
php cli/seed_security_fixtures.php

echo "==> changed PHP syntax lint"
bash scripts/lint_changed_php.sh

echo "==> security contract pack"
bash scripts/run_security_contract_pack.sh

echo "==> permission parity"
php tools/permission_parity_check.php

echo "==> migration dry-run"
php tools/run_migrations.php --dry-run

echo "==> usr_pwrs prune dry-run"
php tools/prune_usr_pwrs_legacy_columns.php --dry-run

if [[ -x vendor/bin/phpunit ]]; then
  PHPUNIT=(vendor/bin/phpunit)
elif [[ -f tools/phpunit.phar ]]; then
  PHPUNIT=(php tools/phpunit.phar)
else
  echo "==> downloading PHPUnit PHAR"
  curl -fsSL -o tools/phpunit.phar https://phar.phpunit.de/phpunit-11.phar
  PHPUNIT=(php tools/phpunit.phar)
fi

echo "==> ProductionRbacPinTest"
"${PHPUNIT[@]}" tests/security/ProductionRbacPinTest.php

if curl -fsS -o /dev/null "${POSMAIN_TEST_HTTP_BASE}/index.php"; then
  echo "==> Playwright team-hub"
  npx playwright test tests/e2e/shared/team-hub.spec.ts --project=shared
  echo "==> Playwright RBAC suite"
  bash scripts/run_rbac_e2e.sh
else
  echo "SKIP Playwright (server not reachable at ${POSMAIN_TEST_HTTP_BASE})"
fi

echo "team-hub-verification-ok"
