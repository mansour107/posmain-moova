#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

php tests/sync/business_day_service_unit_test.php
php tests/sync/business_day_contract_test.php
php tests/sync/business_day_system_integration_test.php
php tests/sync/cash_flow_business_day_integration_test.php
php tests/sync/cash_flow_period_contract_test.php

echo "business-day-pack-ok"
