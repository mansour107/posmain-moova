#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PACK=(
  tests/sync/phase3_security_helpers_test.php
  tests/sync/phase3_permission_matrix_test.php
  tests/sync/admin_write_security_test.php
  tests/sync/pos_browser_write_csrf_test.php
  tests/sync/pos_form_write_security_test.php
  tests/sync/write_surface_audit_contract_test.php
  tests/sync/permission_resolver_test.php
  tests/sync/user_permission_grants_schema_test.php
  tests/sync/capabilities_endpoint_contract_test.php
  tests/sync/permission_denial_audit_contract_test.php
  tests/sync/rbac_critical_surfaces_contract_test.php
  tests/sync/rbac_write_surface_contract_test.php
  tests/sync/rbac_manifest_coverage_test.php
  tests/sync/rbac_manifest_completeness_contract_test.php
  tests/sync/rbac_fail_closed_unlisted_guard_contract_test.php
  tests/sync/sidebar_permission_contract_test.php
  tests/sync/role_permission_sync_test.php
  tests/sync/rbac_runtime_denial_matrix_test.php
  tests/sync/rbac_critical_runtime_test.php
  tests/sync/rbac_page_coverage_contract_test.php
  tests/sync/user_permission_grants_integration_test.php
  tests/sync/security_contract_pack_isolation_test.php
  tests/sync/user_override_deny_matrix_test.php
  tests/sync/changed_php_syntax_contract_test.php
  tests/sync/team_hub_pin_reveal_contract_test.php
  tests/sync/local_pin_auth_contract_test.php
  tests/sync/heartbeat_gap_expiry_contract_test.php
  tests/sync/shift_entry_state_machine_contract_test.php
  tests/sync/user_lifecycle_drawer_guard_test.php
  tests/sync/user_lifecycle_session_guard_contract_test.php
  tests/sync/team_hub_panel_url_contract_test.php
  tests/sync/role_capabilities_backfill_contract_test.php
  tests/sync/pos_item_void_override_contract_test.php
  tests/sync/pos_table_visible_locked_contract_test.php
  tests/sync/pos_override_lockout_contract_test.php
  tests/sync/pos_item_void_override_runtime_test.php
)

echo "Running POSMAIN security / RBAC contract pack (${#PACK[@]} files)..."

for test_file in "${PACK[@]}"; do
  if [[ ! -f "$test_file" ]]; then
    echo "SKIP missing $test_file"
    continue
  fi
  echo "==> $test_file"
  if [[ "$test_file" == "tests/sync/user_override_deny_matrix_test.php" \
    || "$test_file" == "tests/sync/user_lifecycle_drawer_guard_test.php" \
    || "$test_file" == "tests/sync/role_capabilities_backfill_contract_test.php" \
    || "$test_file" == "tests/sync/pos_item_void_override_runtime_test.php" ]]; then
    POSMAIN_SECURITY_TEST_DISPOSABLE=1 \
    POSMAIN_SECURITY_TEST_DB_HOST="${POSMAIN_SECURITY_TEST_DB_HOST:-127.0.0.1}" \
    POSMAIN_SECURITY_TEST_DB_PORT="${POSMAIN_SECURITY_TEST_DB_PORT:-3307}" \
    POSMAIN_SECURITY_TEST_DB_USER="${POSMAIN_SECURITY_TEST_DB_USER:-root}" \
    POSMAIN_SECURITY_TEST_DB_PASS="${POSMAIN_SECURITY_TEST_DB_PASS:-}" \
      php "$test_file"
  else
    php "$test_file"
  fi
done

echo "security-contract-pack-ok"
