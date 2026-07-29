#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SYNC_DB_HOST="${POSMAIN_SYNC_TEST_DB_HOST:-127.0.0.1}"
case "$SYNC_DB_HOST" in
  127.0.0.1|localhost|mysql) ;;
  *)
    echo "SYNC_RELIABILITY_PACK_LOCAL_DATABASE_REQUIRED" >&2
    exit 1
    ;;
esac

SYNC_DB_PORT="${POSMAIN_SYNC_TEST_DB_PORT:-}"
if [[ -z "$SYNC_DB_PORT" ]]; then
  if [[ "$SYNC_DB_HOST" == "mysql" ]]; then
    SYNC_DB_PORT=3306
  else
    SYNC_DB_PORT=3307
  fi
fi

export POSMAIN_TEST_MYSQL_HOST="$SYNC_DB_HOST"
export POSMAIN_TEST_MYSQL_PORT="$SYNC_DB_PORT"
export POSMAIN_TEST_MYSQL_USER="${POSMAIN_SYNC_TEST_DB_USER:-root}"
export POSMAIN_TEST_MYSQL_PASS="${POSMAIN_SYNC_TEST_DB_PASS:-}"
export POSMAIN_TEST_MYSQL_DB="posmain_sync_pack_forbidden_default"
export POSMAIN_DB_HOST="$POSMAIN_TEST_MYSQL_HOST"
export POSMAIN_DB_PORT="$POSMAIN_TEST_MYSQL_PORT"
export POSMAIN_DB_USER="$POSMAIN_TEST_MYSQL_USER"
export POSMAIN_DB_PASS="$POSMAIN_TEST_MYSQL_PASS"
export POSMAIN_DB_NAME="posmain_sync_pack_forbidden_default"
export POSMAIN_ENV=test
export POSMAIN_PRODUCTION_MODE=0

run_sync_test() {
  local test_file="$1"
  local output
  local status

  if [[ ! -f "$test_file" ]]; then
    echo "SYNC_RELIABILITY_PACK_TEST_MISSING: $test_file" >&2
    return 1
  fi

  set +e
  output="$(php "$test_file" 2>&1)"
  status=$?
  set -e
  printf '%s\n' "$output"

  if [[ $status -ne 0 ]]; then
    echo "SYNC_RELIABILITY_PACK_TEST_FAILED: $test_file status=$status" >&2
    return "$status"
  fi
  if [[ "$output" =~ [Ss][Kk][Ii][Pp] \
    || "$output" =~ [Uu][Nn][Aa][Vv][Aa][Ii][Ll][Aa][Bb][Ll][Ee] ]]; then
    echo "SYNC_RELIABILITY_PACK_COVERAGE_SKIPPED: $test_file" >&2
    return 1
  fi
}

PACK=(
  tests/sync/operational_sync_contract_test.php
  tests/sync/production_sync_profile_test.php
  tests/sync/sync_outbox_invariants_contract_test.php
  tests/sync/pos_customer_sync_atomicity_test.php
  tests/sync/pos_order_fulfillment_sync_atomicity_test.php
  tests/sync/cloud_branch_sync_publisher_router_multishop_test.php
  tests/sync/e2e_mock_online_offline_sync_contract_test.php
  tests/sync/e2e_bidirectional_operational_sync_contract_test.php
)

echo "Running POSMAIN sync reliability pack (${#PACK[@]} contract/integration files + outage harness)..."
for test_file in "${PACK[@]}"; do
  echo "==> $test_file"
  run_sync_test "$test_file"
done

SYNC_OUTPUT="$(mktemp "${TMPDIR:-/tmp}/posmain-sync-reliability.XXXXXX")"
trap 'rm -f "$SYNC_OUTPUT"' EXIT
php tools/e2e_mock_online_offline_sync.php >"$SYNC_OUTPUT"
cat "$SYNC_OUTPUT"

php -r '
$result = json_decode((string) file_get_contents($argv[1]), true);
$required = [
    "cloud_receive_only",
    "cloud_shadow_apply",
    "cloud_live_apply",
    "online_cloud_back_retries_failed_event",
    "branch_worker_crash_lock_expires_and_reclaims",
    "offline_branch_back_cloud_event_delivered_and_acked",
];
if (!is_array($result)
    || !preg_match("#/posmain_sync_e2e_[0-9]+_[0-9]+$#", (string) ($result["db"] ?? ""))
    || !is_array($result["results"] ?? null)) {
    fwrite(STDERR, "SYNC_RELIABILITY_HARNESS_RESULT_INVALID\n");
    exit(1);
}
$byName = [];
foreach ($result["results"] as $scenario) {
    if (is_array($scenario)) {
        $byName[(string) ($scenario["name"] ?? "")] = $scenario;
    }
}
foreach ($required as $name) {
    if (($byName[$name]["pass"] ?? false) !== true) {
        fwrite(STDERR, "SYNC_RELIABILITY_SCENARIO_NOT_GREEN: " . $name . PHP_EOL);
        exit(1);
    }
}
' "$SYNC_OUTPUT"

echo "sync-reliability-pack-ok"
