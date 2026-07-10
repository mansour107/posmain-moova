#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "== financial float/journal contract =="
php tests/sync/financial_float_journal_contract_test.php

echo "== financial pricing reconciliation =="
php tests/sync/financial_pricing_reconciliation_test.php

echo "== financial refund service =="
php tests/sync/financial_refund_service_test.php

echo "== order pricing service =="
php tests/sync/order_pricing_service_test.php

echo "== financial e2e flow =="
php tests/sync/financial_e2e_flow_test.php

echo "== financial posted reports =="
php tests/sync/financial_posted_reports_test.php

echo "== cash flow pack (local DB suite) =="
php tests/sync/cash_flow_period_contract_test.php
php tests/sync/drawer_cash_flow_contract_test.php
php tests/sync/cash_flow_unassigned_integration_test.php
php tests/sync/cash_flow_full_day_integration_test.php
php tests/sync/cash_flow_legacy_shop_integration_test.php
php tests/sync/cash_flow_multi_session_integration_test.php
php tests/sync/cash_flow_business_day_integration_test.php
php tests/sync/drawer_cash_flow_integration_test.php
php tests/sync/phase4_drawer_session_service_test.php

if [[ -f scripts/run_business_day_pack.sh ]]; then
  echo "== business day pack =="
  bash scripts/run_business_day_pack.sh
fi

echo "financial-pack-ok"
