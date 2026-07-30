#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MASTER_DB_HOST="${POSMAIN_MASTER_SYNC_TEST_DB_HOST:-127.0.0.1}"
case "$MASTER_DB_HOST" in
  127.0.0.1|localhost|mysql) ;;
  *)
    echo "MASTER_DATA_PACK_LOCAL_DATABASE_REQUIRED" >&2
    exit 1
    ;;
esac

MASTER_DB_NAME="${POSMAIN_MASTER_SYNC_TEST_DB:-}"
if [[ ! "$MASTER_DB_NAME" =~ ^posmain_master_sync_[a-z0-9_]+$ ]]; then
  echo "MASTER_DATA_PACK_DISPOSABLE_DATABASE_REQUIRED" >&2
  exit 1
fi

export POSMAIN_TEST_MYSQL_HOST="$MASTER_DB_HOST"
export POSMAIN_TEST_MYSQL_PORT="${POSMAIN_MASTER_SYNC_TEST_DB_PORT:-3307}"
export POSMAIN_TEST_MYSQL_USER="${POSMAIN_MASTER_SYNC_TEST_DB_USER:-root}"
export POSMAIN_TEST_MYSQL_PASS="${POSMAIN_MASTER_SYNC_TEST_DB_PASS:-}"
export POSMAIN_TEST_MYSQL_DB="$MASTER_DB_NAME"
export POSMAIN_ENV=test
export POSMAIN_PRODUCTION_MODE=0

run_master_test() {
  local test_file="$1"
  local output
  local status

  if [[ ! -f "$test_file" ]]; then
    echo "MASTER_DATA_PACK_TEST_MISSING: $test_file" >&2
    return 1
  fi

  set +e
  output="$(php "$test_file" 2>&1)"
  status=$?
  set -e
  printf '%s\n' "$output"

  if [[ $status -ne 0 ]]; then
    echo "MASTER_DATA_PACK_TEST_FAILED: $test_file status=$status" >&2
    return "$status"
  fi
  if [[ "$output" =~ [Ss][Kk][Ii][Pp] \
    || "$output" =~ [Uu][Nn][Aa][Vv][Aa][Ii][Ll][Aa][Bb][Ll][Ee] ]]; then
    echo "MASTER_DATA_PACK_COVERAGE_SKIPPED: $test_file" >&2
    return 1
  fi
}

PACK=(
  tests/sync/item_form_input_test.php
  tests/sync/item_unit_profile_builder_test.php
  tests/sync/branch_cloud_master_boundary_contract_test.php
  tests/sync/master_clock_drift_runtime_test.php
  tests/sync/recipe_editor_atomic_outbox_contract_test.php
  tests/sync/master_data_convergence_runtime_test.php
  tests/sync/recipe_editor_atomic_outbox_runtime_test.php
)

echo "Running POSMAIN master-data convergence pack (${#PACK[@]} files)..."
for test_file in "${PACK[@]}"; do
  echo "==> $test_file"
  run_master_test "$test_file"
done

echo "master-data-convergence-pack-ok tests=${#PACK[@]}"
