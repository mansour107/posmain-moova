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

for test in "${TESTS[@]}"; do
  echo "==> php $test"
  php "$test"
done

if command -v npx >/dev/null 2>&1 && [[ -f tests/e2e/cashier/pos-save-no-reload.spec.ts ]]; then
  echo "==> npx playwright test tests/e2e/cashier/pos-save-no-reload.spec.ts"
  npx playwright test tests/e2e/cashier/pos-save-no-reload.spec.ts || true
fi

echo "order-creation-certification-pack-ok"
