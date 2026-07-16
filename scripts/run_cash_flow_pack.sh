#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

php tests/sync/cash_flow_period_contract_test.php
php tests/sync/cash_flow_accountability_contract_test.php
php tests/sync/cash_flow_accountability_integration_test.php
php tests/sync/close_shift_modal_full_blind_contract_test.php
php tests/sync/drawer_cash_flow_contract_test.php
php tests/sync/cash_flow_unassigned_integration_test.php
php tests/sync/cash_flow_full_day_integration_test.php
php tests/sync/cash_flow_legacy_shop_integration_test.php
php tests/sync/cash_flow_multi_session_integration_test.php
php tests/sync/cash_flow_business_day_integration_test.php
php tests/sync/business_day_service_unit_test.php
php tests/sync/business_day_contract_test.php
php tests/sync/business_day_system_integration_test.php
php tests/sync/cash_flow_report_endpoint_runtime_test.php
php tests/sync/drawer_cash_flow_integration_test.php
php tests/sync/shift_preview_sales_drawer_integration_test.php
php tests/sync/phase4_drawer_session_service_test.php
php tests/sync/shift_expense_record_integration_test.php
php tests/sync/shift_payin_record_integration_test.php

if [[ -n "${POSMAIN_TEST_MYSQL_DB:-}" ]]; then
  php tools/cash_ledger_consistency_check.php "$(date +%Y-%m-%d)" "$(date +%Y-%m-%d)" || true
fi

echo "cash-flow-pack-ok"
