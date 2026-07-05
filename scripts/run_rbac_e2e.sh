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

echo "RBAC E2E against ${POSMAIN_TEST_HTTP_BASE}"

if ! curl -fsS -o /dev/null "${POSMAIN_TEST_HTTP_BASE}/index.php"; then
  echo "POS server not reachable. Start it first (e.g. scripts/posmain-test-env.sh)."
  exit 1
fi

php cli/seed_security_fixtures.php

# HTTP server reads POSMAIN_PIN_SECRET from project .env when not set in container env.
if [ ! -f .env ] || ! grep -q '^POSMAIN_PIN_SECRET=' .env 2>/dev/null; then
  echo "POSMAIN_PIN_SECRET=${POSMAIN_PIN_SECRET}" >> .env
fi

npx playwright test --project=rbac "$@"
