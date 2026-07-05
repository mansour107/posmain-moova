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
  tests/sync/sidebar_permission_contract_test.php
  tests/sync/role_permission_sync_test.php
  tests/sync/rbac_runtime_denial_matrix_test.php
  tests/sync/rbac_critical_runtime_test.php
  tests/sync/rbac_page_coverage_contract_test.php
  tests/sync/user_permission_grants_integration_test.php
)

echo "Running POSMAIN security / RBAC contract pack (${#PACK[@]} files)..."

for test_file in "${PACK[@]}"; do
  if [[ ! -f "$test_file" ]]; then
    echo "SKIP missing $test_file"
    continue
  fi
  echo "==> $test_file"
  php "$test_file"
done

echo "security-contract-pack-ok"
