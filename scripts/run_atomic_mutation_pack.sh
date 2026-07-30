#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

ATOMIC_DB_HOST="${POSMAIN_ATOMIC_TEST_DB_HOST:-127.0.0.1}"
case "$ATOMIC_DB_HOST" in
  127.0.0.1|localhost|mysql) ;;
  *)
    echo "ATOMIC_PACK_LOCAL_DATABASE_REQUIRED" >&2
    exit 1
    ;;
esac

ATOMIC_DB_PORT="${POSMAIN_ATOMIC_TEST_DB_PORT:-}"
if [[ -z "$ATOMIC_DB_PORT" ]]; then
  if [[ "$ATOMIC_DB_HOST" == "mysql" ]]; then
    ATOMIC_DB_PORT=3306
  else
    ATOMIC_DB_PORT=3307
  fi
fi

export POSMAIN_TEST_MYSQL_HOST="$ATOMIC_DB_HOST"
export POSMAIN_TEST_MYSQL_PORT="$ATOMIC_DB_PORT"
export POSMAIN_TEST_MYSQL_USER="${POSMAIN_ATOMIC_TEST_DB_USER:-root}"
export POSMAIN_TEST_MYSQL_PASS="${POSMAIN_ATOMIC_TEST_DB_PASS:-}"
export POSMAIN_TEST_MYSQL_DB="posmain_atomic_pack_forbidden_default"
export POSMAIN_DB_HOST="$POSMAIN_TEST_MYSQL_HOST"
export POSMAIN_DB_PORT="$POSMAIN_TEST_MYSQL_PORT"
export POSMAIN_DB_USER="$POSMAIN_TEST_MYSQL_USER"
export POSMAIN_DB_PASS="$POSMAIN_TEST_MYSQL_PASS"
export POSMAIN_DB_NAME="posmain_atomic_pack_forbidden_default"

run_atomic_test() {
  local test_file="$1"
  local output
  local status

  if [[ ! -f "$test_file" ]]; then
    echo "ATOMIC_PACK_TEST_MISSING: $test_file" >&2
    return 1
  fi

  set +e
  output="$(php "$test_file" 2>&1)"
  status=$?
  set -e
  printf '%s\n' "$output"

  if [[ $status -ne 0 ]]; then
    echo "ATOMIC_PACK_TEST_FAILED: $test_file status=$status" >&2
    return "$status"
  fi
  if [[ "$output" =~ [Ss][Kk][Ii][Pp] \
    || "$output" =~ [Uu][Nn][Aa][Vv][Aa][Ii][Ll][Aa][Bb][Ll][Ee] ]]; then
    echo "ATOMIC_PACK_TEST_COVERAGE_SKIPPED: $test_file" >&2
    return 1
  fi
}

PACK=(
  tests/sync/pos_request_keys_idempotency_service_test.php
  tests/sync/pos_table_endpoint_idempotency_test.php
  tests/sync/pos_table_save_service_test.php
  tests/sync/pos_payment_split_service_idempotency_test.php
  tests/sync/pos_takeaway_order_service_test.php
  tests/sync/pos_takeaway_order_idempotency_test.php
  tests/sync/phase4_table_transfer_service_test.php
  tests/sync/phase4_table_merge_service_test.php
  tests/sync/pos_paid_reversal_service_test.php
  tests/sync/shift_count_idempotency_test.php
  tests/sync/shift_midshift_cash_idempotency_test.php
  tests/sync/shift_handover_tx_atomicity_test.php
  tests/sync/phase6_load_concurrency_check_test.php
)

echo "Running POSMAIN atomic mutation pack (${#PACK[@]} files)..."
for test_file in "${PACK[@]}"; do
  echo "==> $test_file"
  run_atomic_test "$test_file"
done

echo "atomic-mutation-pack-ok"
