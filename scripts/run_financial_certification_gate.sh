#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

GATE_DB_HOST="${POSMAIN_FINANCIAL_TEST_DB_HOST:-127.0.0.1}"
case "$GATE_DB_HOST" in
  127.0.0.1|localhost|mysql) ;;
  *)
    echo "FINANCIAL_CERTIFICATION_GATE_LOCAL_DATABASE_REQUIRED" >&2
    exit 1
    ;;
esac

GATE_DB_PORT="${POSMAIN_FINANCIAL_TEST_DB_PORT:-}"
if [[ -z "$GATE_DB_PORT" ]]; then
  if [[ "$GATE_DB_HOST" == "mysql" ]]; then
    GATE_DB_PORT=3306
  else
    GATE_DB_PORT=3307
  fi
fi

GATE_DB="posmain_financial_gate_$$_${RANDOM}"
GATE_OUTPUT="$(mktemp "${TMPDIR:-/tmp}/posmain-financial-gate.XXXXXX")"

export POSMAIN_FINANCIAL_GATE_DISPOSABLE=1
export POSMAIN_TEST_MYSQL_HOST="$GATE_DB_HOST"
export POSMAIN_TEST_MYSQL_PORT="$GATE_DB_PORT"
export POSMAIN_TEST_MYSQL_USER="${POSMAIN_FINANCIAL_TEST_DB_USER:-root}"
export POSMAIN_TEST_MYSQL_PASS="${POSMAIN_FINANCIAL_TEST_DB_PASS:-}"
export POSMAIN_MYSQL_DATABASE="$GATE_DB"
export POSMAIN_DB_HOST="$POSMAIN_TEST_MYSQL_HOST"
export POSMAIN_DB_PORT="$POSMAIN_TEST_MYSQL_PORT"
export POSMAIN_DB_USER="$POSMAIN_TEST_MYSQL_USER"
export POSMAIN_DB_PASS="$POSMAIN_TEST_MYSQL_PASS"
export POSMAIN_DB_NAME="$GATE_DB"
export POSMAIN_ENV=local
export POSMAIN_PRODUCTION_MODE=0
export POSMAIN_TAX_ENABLED=0

cleanup_gate() {
  POSMAIN_MYSQL_DATABASE="$GATE_DB" php tools/financial_certification_seed.php --drop-disposable >/dev/null 2>&1 || true
  rm -f "$GATE_OUTPUT"
}
trap cleanup_gate EXIT

php tools/financial_certification_seed.php
php tools/financial_certification_preflight.php --json >"$GATE_OUTPUT"
cat "$GATE_OUTPUT"

php -r '
$result = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($result)
    || ($result["production_ready"] ?? false) !== true
    || ($result["blockers"] ?? null) !== []
    || !is_array($result["checks"] ?? null)
    || ($result["checks"]["financial_reconciliations"]["ok"] ?? false) !== true
    || ($result["checks"]["financial_reconciliations"]["blockers"] ?? null) !== []) {
    fwrite(STDERR, "FINANCIAL_CERTIFICATION_GATE_NOT_GREEN\n");
    exit(1);
}
foreach ($result["checks"] as $name => $check) {
    if (is_array($check) && (($check["skipped"] ?? false) === true || ($check["ok"] ?? false) !== true)) {
        fwrite(STDERR, "FINANCIAL_CERTIFICATION_GATE_CHECK_NOT_GREEN: " . $name . PHP_EOL);
        exit(1);
    }
}
' "$GATE_OUTPUT"

echo "financial-certification-gate-ok db=$GATE_DB"
