#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

STAGE2_DB_HOST="${POSMAIN_STAGE2_TEST_DB_HOST:-127.0.0.1}"
case "$STAGE2_DB_HOST" in
  127.0.0.1|localhost|mysql) ;;
  *)
    echo "STAGE2_PACK_LOCAL_DATABASE_REQUIRED" >&2
    exit 1
    ;;
esac

STAGE2_DB_PORT="${POSMAIN_STAGE2_TEST_DB_PORT:-}"
if [[ -z "$STAGE2_DB_PORT" ]]; then
  if [[ "$STAGE2_DB_HOST" == "mysql" ]]; then
    STAGE2_DB_PORT=3306
  else
    STAGE2_DB_PORT=3307
  fi
fi

export POSMAIN_TEST_MYSQL_HOST="$STAGE2_DB_HOST"
export POSMAIN_TEST_MYSQL_PORT="$STAGE2_DB_PORT"
export POSMAIN_TEST_MYSQL_USER="${POSMAIN_STAGE2_TEST_DB_USER:-root}"
export POSMAIN_TEST_MYSQL_PASS="${POSMAIN_STAGE2_TEST_DB_PASS:-}"
export POSMAIN_TEST_MYSQL_DB="posmain_stage2_pack_forbidden_default"
export POSMAIN_DB_HOST="$POSMAIN_TEST_MYSQL_HOST"
export POSMAIN_DB_PORT="$POSMAIN_TEST_MYSQL_PORT"
export POSMAIN_DB_USER="$POSMAIN_TEST_MYSQL_USER"
export POSMAIN_DB_PASS="$POSMAIN_TEST_MYSQL_PASS"
export POSMAIN_DB_NAME="posmain_stage2_pack_forbidden_default"
export POSMAIN_ENV=test
export POSMAIN_PRODUCTION_MODE=0

run_stage2_test() {
  local test_file="$1"
  local output
  local status

  if [[ ! -f "$test_file" ]]; then
    echo "STAGE2_PACK_TEST_MISSING: $test_file" >&2
    return 1
  fi

  set +e
  output="$(php "$test_file" 2>&1)"
  status=$?
  set -e
  printf '%s\n' "$output"

  if [[ $status -ne 0 ]]; then
    echo "STAGE2_PACK_TEST_FAILED: $test_file status=$status" >&2
    return "$status"
  fi
  if [[ "$output" =~ [Ss][Kk][Ii][Pp] \
    || "$output" =~ [Uu][Nn][Aa][Vv][Aa][Ii][Ll][Aa][Bb][Ll][Ee] ]]; then
    echo "STAGE2_PACK_COVERAGE_SKIPPED: $test_file" >&2
    return 1
  fi
}

PACK=(
  tests/sync/inventory_phase1_noop_contract_test.php
  tests/sync/inventory_quantity_only_operator_surface_contract_test.php
  tests/sync/recipe_quantity_tracking_dependency_test.php
  tests/sync/recipe_inventory_kernel_contract_test.php
  tests/sync/inventory_runtime_preflight_contract_test.php
  tests/sync/recipe_runtime_preflight_contract_test.php
  tests/sync/inventory_reconciliation_check_contract_test.php
  tests/sync/inventory_phase10_production_surface_contract_test.php
  tests/sync/inventory_phase14_migration_tools_contract_test.php
  tests/sync/inventory_phase15_cutover_contract_test.php
  tests/sync/inventory_phase16_legacy_retirement_contract_test.php
  tests/sync/inventory_phase17_hardening_contract_test.php
  tests/sync/inventory_quantity_only_runtime_test.php
  tests/sync/inventory_phase3_ledger_service_test.php
  tests/sync/inventory_phase4_invoice_bridge_test.php
  tests/sync/inventory_phase6_purchase_bridge_test.php
  tests/sync/inventory_phase6_receiving_service_test.php
  tests/sync/inventory_phase8_transfer_service_test.php
  tests/sync/inventory_phase9_adjustment_service_test.php
  tests/sync/inventory_phase12_accounting_service_test.php
  tests/sync/inventory_sync_atomicity_test.php
  tests/sync/inventory_accounting_sync_atomicity_test.php
  tests/sync/recipe_accounting_sync_atomicity_test.php
  tests/sync/inventory_moving_average_concurrency_runtime_test.php
  tests/sync/recipe_reservation_lifecycle_runtime_test.php
  tests/sync/recipe_production_endpoint_runtime_test.php
  tests/sync/pos_table_payment_recipe_endpoint_runtime_test.php
  tests/sync/pos_split_payment_recipe_endpoint_runtime_test.php
  tests/sync/recipe_sale_refund_reversal_truth_table_runtime_test.php
  tests/sync/recipe_paid_reversal_endpoint_runtime_test.php
  tests/sync/pos_paid_reversal_service_test.php
  tests/sync/recipe_moova_replay_runtime_test.php
  tests/sync/inventory_phase14_migration_service_test.php
  tests/sync/inventory_phase15_cutover_service_test.php
  tests/sync/inventory_phase15_cutover_readiness_service_test.php
  tests/sync/inventory_valuation_cutover_service_test.php
  tests/sync/inventory_reconciliation_repair_service_test.php
  tests/sync/inventory_balance_rebuild_acceptance_service_test.php
  tests/sync/inventory_accounting_reconciliation_acceptance_service_test.php
)

echo "Running POSMAIN Stage 2 pre-certification pack (${#PACK[@]} files)..."
for test_file in "${PACK[@]}"; do
  echo "==> $test_file"
  run_stage2_test "$test_file"
done

echo "stage2-pre-certification-pack-ok tests=${#PACK[@]}"
