#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

LIFECYCLE_DB_HOST="${POSMAIN_LIFECYCLE_TEST_DB_HOST:-127.0.0.1}"
case "$LIFECYCLE_DB_HOST" in
  127.0.0.1|localhost|mysql) ;;
  *)
    echo "COMMERCIAL_LIFECYCLE_PACK_LOCAL_DATABASE_REQUIRED" >&2
    exit 1
    ;;
esac

LIFECYCLE_DB_PORT="${POSMAIN_LIFECYCLE_TEST_DB_PORT:-}"
if [[ -z "$LIFECYCLE_DB_PORT" ]]; then
  if [[ "$LIFECYCLE_DB_HOST" == "mysql" ]]; then
    LIFECYCLE_DB_PORT=3306
  else
    LIFECYCLE_DB_PORT=3307
  fi
fi

export POSMAIN_TEST_MYSQL_HOST="$LIFECYCLE_DB_HOST"
export POSMAIN_TEST_MYSQL_PORT="$LIFECYCLE_DB_PORT"
export POSMAIN_TEST_MYSQL_USER="${POSMAIN_LIFECYCLE_TEST_DB_USER:-root}"
export POSMAIN_TEST_MYSQL_PASS="${POSMAIN_LIFECYCLE_TEST_DB_PASS:-}"
export POSMAIN_TEST_MYSQL_DB="posmain_commercial_lifecycle_forbidden_default"
export POSMAIN_DB_HOST="$POSMAIN_TEST_MYSQL_HOST"
export POSMAIN_DB_PORT="$POSMAIN_TEST_MYSQL_PORT"
export POSMAIN_DB_USER="$POSMAIN_TEST_MYSQL_USER"
export POSMAIN_DB_PASS="$POSMAIN_TEST_MYSQL_PASS"
export POSMAIN_DB_NAME="posmain_commercial_lifecycle_forbidden_default"
export POSMAIN_ENV=test
export POSMAIN_PRODUCTION_MODE=0
export POSMAIN_TAX_ENABLED=0

run_lifecycle_test() {
  local test_file="$1"
  local output
  local status

  if [[ ! -f "$test_file" ]]; then
    echo "COMMERCIAL_LIFECYCLE_PACK_TEST_MISSING: $test_file" >&2
    return 1
  fi

  set +e
  output="$(php "$test_file" 2>&1)"
  status=$?
  set -e
  printf '%s\n' "$output"

  if [[ $status -ne 0 ]]; then
    echo "COMMERCIAL_LIFECYCLE_PACK_TEST_FAILED: $test_file status=$status" >&2
    return "$status"
  fi
  if [[ "$output" =~ [Ss][Kk][Ii][Pp] \
    || "$output" =~ [Uu][Nn][Aa][Vv][Aa][Ii][Ll][Aa][Bb][Ll][Ee] ]]; then
    echo "COMMERCIAL_LIFECYCLE_PACK_COVERAGE_SKIPPED: $test_file" >&2
    return 1
  fi
}

PACK=(
  tests/sync/pos_takeaway_invoice_handler_test.php
  tests/sync/pos_table_pay_without_save_test.php
  tests/sync/pos_table_save_service_test.php
  tests/sync/pos_table_takeaway_parity_test.php
  tests/sync/delivery_validation_contract_test.php
  tests/sync/delivery_order_create_service_test.php
  tests/sync/delivery_production_integration_test.php
  tests/sync/delivery_operations_service_test.php
  tests/sync/financial_refund_reconciliation_test.php
  tests/sync/refund_reversal_read_service_test.php
  tests/sync/refund_shift_surfaces_contract_test.php
  tests/sync/refund_admin_drilldown_contract_test.php
  tests/sync/refund_legacy_reports_contract_test.php
  tests/sync/pos_paid_reversal_service_test.php
  tests/sync/shift_sales_receipt_scope_runtime_test.php
)

echo "Running POSMAIN commercial lifecycle pack (${#PACK[@]} files)..."
for test_file in "${PACK[@]}"; do
  echo "==> $test_file"
  run_lifecycle_test "$test_file"
done

echo "commercial-lifecycle-pack-ok"
