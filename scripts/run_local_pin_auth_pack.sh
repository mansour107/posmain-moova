#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# Contract/source checks first (no MySQL). Disposable-DB integrations last.
PACK=(
  tests/sync/local_pin_auth_contract_test.php
  tests/sync/main_auth_mode_matrix_test.php
  tests/sync/health_auth_readiness_contract_test.php
  tests/sync/shift_entry_state_machine_contract_test.php
  tests/sync/team_hub_pin_reveal_contract_test.php
  tests/sync/rbac_page_coverage_contract_test.php
  tests/sync/pos_pin_entry_contract_test.php
  tests/sync/drawer_takeover_pin_required_contract_test.php
  tests/sync/auth_session_revocation_integration_test.php
  tests/sync/login_throttle_escalation_integration_test.php
  tests/sync/recover_owner_pin_cli_test.php
  tests/sync/shift_count_handover_integration_test.php
)

echo "Running POSMAIN local PIN auth pack (${#PACK[@]} files)..."

for test_file in "${PACK[@]}"; do
  if [[ ! -f "$test_file" ]]; then
    echo "MISSING $test_file" >&2
    exit 1
  fi
  echo "==> $test_file"
  php "$test_file"
done

echo "local-pin-auth-pack-ok"
