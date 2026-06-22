#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
export POSMAIN_TEST_MYSQL_HOST="${POSMAIN_TEST_MYSQL_HOST:-127.0.0.1}"
export POSMAIN_TEST_MYSQL_PORT="${POSMAIN_TEST_MYSQL_PORT:-3307}"
export POSMAIN_TEST_MYSQL_DB="${POSMAIN_TEST_MYSQL_DB:-kody2}"
export POSMAIN_TEST_HTTP_BASE="${POSMAIN_TEST_HTTP_BASE:-http://127.0.0.1:8010}"

echo "=== Delivery production test suite ==="
echo "MySQL: ${POSMAIN_TEST_MYSQL_HOST}:${POSMAIN_TEST_MYSQL_PORT}/${POSMAIN_TEST_MYSQL_DB}"
echo "HTTP:  ${POSMAIN_TEST_HTTP_BASE}"

run_php() {
  echo "--- $1 ---"
  php "$1"
}

run_node() {
  echo "--- $1 ---"
  node "$1"
}

# Contract tests (fast)
for t in \
  tests/sync/delivery_validation_contract_test.php \
  tests/sync/delivery_client_upsert_test.php \
  tests/sync/delivery_order_create_service_test.php \
  tests/sync/delivery_status_transition_test.php \
  tests/sync/delivery_zones_contract_test.php \
  tests/sync/moova_delivery_order_type_test.php
do
  run_php "$t"
done

# Production integration + runtime
run_php tests/sync/delivery_production_integration_test.php
run_php tests/sync/delivery_kody2_moova_runtime_test.php
run_php tests/sync/delivery_http_smoke_test.php
run_node tests/sync/delivery_pos_js_runtime_test.js
run_node tests/sync/delivery_browser_gui_test.js

echo "=== ALL DELIVERY PRODUCTION TESTS PASSED ==="
