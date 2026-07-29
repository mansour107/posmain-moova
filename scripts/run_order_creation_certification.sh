#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

TESTS=(
  tests/sync/frontend_cutover_contract_test.php
  tests/sync/pos_order_creation_matrix_contract_test.php
  tests/sync/order_creation_write_surface_contract_test.php
  tests/sync/pos_api_router_contract_test.php
  tests/sync/pos_order_controller_contract_test.php
  tests/sync/pos_cashier_empty_table_option_contract_test.php
  tests/sync/kds_foundation_contract_test.php
  tests/sync/user_id_fallback_contract_test.php
  tests/sync/pos_order_api_http_matrix_test.php
)

run_required_php_test() {
  local test_file="$1"
  local output
  local status

  set +e
  output="$(php "$test_file" 2>&1)"
  status=$?
  set -e
  printf '%s\n' "$output"

  if [[ $status -ne 0 ]]; then
    echo "ORDER_CREATION_TEST_FAILED: $test_file status=$status" >&2
    return "$status"
  fi
  if [[ "$output" =~ [Ss][Kk][Ii][Pp][Pp][Ee][Dd] \
    || "$output" =~ [Dd][Bb]-[Uu][Nn][Aa][Vv][Aa][Ii][Ll][Aa][Bb][Ll][Ee] \
    || "$output" =~ [Dd][Aa][Tt][Aa][Bb][Aa][Ss][Ee]-[Uu][Nn][Aa][Vv][Aa][Ii][Ll][Aa][Bb][Ll][Ee] ]]; then
    echo "ORDER_CREATION_TEST_COVERAGE_SKIPPED: $test_file" >&2
    return 1
  fi
}

for test in "${TESTS[@]}"; do
  echo "==> php $test"
  run_required_php_test "$test"
done

if [[ "${POSMAIN_ORDER_CERT_RUN_PLAYWRIGHT:-0}" == "1" ]]; then
  if [[ ! -x node_modules/.bin/playwright ]]; then
    echo "ORDER_CREATION_PLAYWRIGHT_RUNNER_MISSING: install pinned dependencies before requesting browser certification" >&2
    exit 1
  fi
  if [[ -z "${POSMAIN_TEST_HTTP_BASE:-}" ]]; then
    echo "ORDER_CREATION_PLAYWRIGHT_BASE_REQUIRED: set POSMAIN_TEST_HTTP_BASE to an isolated local runtime" >&2
    exit 1
  fi
  echo "==> node_modules/.bin/playwright test tests/e2e/cashier/pos-save-no-reload.spec.ts"
  node_modules/.bin/playwright test tests/e2e/cashier/pos-save-no-reload.spec.ts
else
  echo "playwright-e2e-not-requested (set POSMAIN_ORDER_CERT_RUN_PLAYWRIGHT=1 with pinned dependencies and an isolated POSMAIN_TEST_HTTP_BASE)"
fi

echo "order-creation-certification-pack-ok"
