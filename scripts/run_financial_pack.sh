#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

FINANCIAL_DB_HOST="${POSMAIN_FINANCIAL_TEST_DB_HOST:-127.0.0.1}"
case "$FINANCIAL_DB_HOST" in
  127.0.0.1|localhost|mysql) ;;
  *)
    echo "FINANCIAL_PACK_LOCAL_DATABASE_REQUIRED" >&2
    exit 1
    ;;
esac

export POSMAIN_TEST_MYSQL_HOST="$FINANCIAL_DB_HOST"
FINANCIAL_DB_PORT="${POSMAIN_FINANCIAL_TEST_DB_PORT:-}"
if [[ -z "$FINANCIAL_DB_PORT" ]]; then
  if [[ "$FINANCIAL_DB_HOST" == "mysql" ]]; then
    FINANCIAL_DB_PORT=3306
  else
    FINANCIAL_DB_PORT=3307
  fi
fi
export POSMAIN_TEST_MYSQL_PORT="$FINANCIAL_DB_PORT"
export POSMAIN_TEST_MYSQL_USER="${POSMAIN_FINANCIAL_TEST_DB_USER:-root}"
export POSMAIN_TEST_MYSQL_PASS="${POSMAIN_FINANCIAL_TEST_DB_PASS:-}"

# Poison the normal application connection so a test cannot silently fall back
# to kody2 or another standing shop. Financial integration tests must create
# their own uniquely named schema through POSMAIN_TEST_MYSQL_*.
export POSMAIN_DB_HOST="$POSMAIN_TEST_MYSQL_HOST"
export POSMAIN_DB_PORT="$POSMAIN_TEST_MYSQL_PORT"
export POSMAIN_DB_USER="$POSMAIN_TEST_MYSQL_USER"
export POSMAIN_DB_PASS="$POSMAIN_TEST_MYSQL_PASS"
export POSMAIN_DB_NAME="posmain_financial_pack_forbidden_default"

run_financial_test() {
  local test_file="$1"
  local output
  local status

  if [[ ! -f "$test_file" ]]; then
    echo "FINANCIAL_PACK_TEST_MISSING: $test_file" >&2
    return 1
  fi

  set +e
  output="$(php "$test_file" 2>&1)"
  status=$?
  set -e
  printf '%s\n' "$output"

  if [[ $status -ne 0 ]]; then
    echo "FINANCIAL_PACK_TEST_FAILED: $test_file status=$status" >&2
    return "$status"
  fi
  if [[ "$output" =~ [Ss][Kk][Ii][Pp][Pp][Ee][Dd] \
    || "$output" =~ [Dd][Bb]-[Uu][Nn][Aa][Vv][Aa][Ii][Ll][Aa][Bb][Ll][Ee] \
    || "$output" =~ [Dd][Aa][Tt][Aa][Bb][Aa][Ss][Ee]-[Uu][Nn][Aa][Vv][Aa][Ii][Ll][Aa][Bb][Ll][Ee] ]]; then
    echo "FINANCIAL_PACK_TEST_COVERAGE_SKIPPED: $test_file" >&2
    return 1
  fi
}

PACK=(
  tests/sync/financial_float_journal_contract_test.php
  tests/sync/financial_pricing_reconciliation_test.php
  tests/sync/financial_refund_service_test.php
  tests/sync/order_pricing_service_test.php
  tests/sync/financial_e2e_flow_test.php
  tests/sync/financial_posted_reports_test.php
  tests/sync/cash_flow_period_contract_test.php
  tests/sync/drawer_cash_flow_contract_test.php
  tests/sync/cash_flow_unassigned_integration_test.php
  tests/sync/cash_flow_full_day_integration_test.php
  tests/sync/cash_flow_legacy_shop_integration_test.php
  tests/sync/cash_flow_multi_session_integration_test.php
  tests/sync/cash_flow_business_day_integration_test.php
  tests/sync/drawer_cash_flow_integration_test.php
  tests/sync/phase4_drawer_session_service_test.php
  tests/sync/phase4_shift_drawer_reconciliation_service_test.php
  tests/sync/shift_financial_integrity_service_test.php
  tests/sync/business_day_service_unit_test.php
  tests/sync/business_day_contract_test.php
  tests/sync/business_day_system_integration_test.php
)

echo "Running POSMAIN financial certification pack (${#PACK[@]} files)..."
for test_file in "${PACK[@]}"; do
  echo "==> $test_file"
  run_financial_test "$test_file"
done

echo "financial-pack-ok"
