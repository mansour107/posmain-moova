#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

php tests/sync/dashboard_overview_service_unit_test.php
php tests/sync/dashboard_overview_service_test.php
php tests/sync/dashboard_redesign_contract_test.php
php tests/sync/dashboard_ot_head_filter_contract_test.php
php tests/sync/team_hub_login_activity_contract_test.php
php tests/sync/team_hub_login_activity_service_test.php

echo "dashboard-pack-ok"
