#!/usr/bin/env bash
# Archive the current MySQL database with checksum, then optionally rebuild a clean certification DB.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT_DIR="${POSMAIN_FINANCIAL_ARCHIVE_DIR:-$ROOT/var/financial_archives}"
mkdir -p "$OUT_DIR"

HOST="${POSMAIN_TEST_MYSQL_HOST:-127.0.0.1}"
PORT="${POSMAIN_TEST_MYSQL_PORT:-3307}"
USER="${POSMAIN_TEST_MYSQL_USER:-root}"
PASS="${POSMAIN_TEST_MYSQL_PASS:-}"
DB="${POSMAIN_MYSQL_DATABASE:-posmain}"

ARCHIVE="$OUT_DIR/${DB}_${STAMP}.sql"
CHECKSUM="$OUT_DIR/${DB}_${STAMP}.sha256"

echo "Archiving $DB from $HOST:$PORT → $ARCHIVE"
if [[ -n "$PASS" ]]; then
  mysqldump -h "$HOST" -P "$PORT" -u "$USER" -p"$PASS" --single-transaction --routines --triggers "$DB" > "$ARCHIVE"
else
  mysqldump -h "$HOST" -P "$PORT" -u "$USER" --single-transaction --routines --triggers "$DB" > "$ARCHIVE"
fi
shasum -a 256 "$ARCHIVE" | awk '{print $1}' > "$CHECKSUM"
echo "checksum=$(cat "$CHECKSUM")"

if [[ "${1:-}" == "--verify" ]]; then
  ACTUAL="$(shasum -a 256 "$ARCHIVE" | awk '{print $1}')"
  EXPECTED="$(cat "$CHECKSUM")"
  [[ "$ACTUAL" == "$EXPECTED" ]] || { echo "checksum mismatch"; exit 1; }
  echo "archive-verify-ok"
fi

if [[ "${1:-}" == "--rebuild-clean" ]]; then
  CLEAN_DB="${POSMAIN_FINANCIAL_CERT_DB:-posmain_financial_cert}"
  echo "Building clean certification database $CLEAN_DB"
  MYSQL=(mysql -h "$HOST" -P "$PORT" -u "$USER")
  if [[ -n "$PASS" ]]; then MYSQL+=(-p"$PASS"); fi
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$CLEAN_DB\`; CREATE DATABASE \`$CLEAN_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  POSMAIN_MYSQL_DATABASE="$CLEAN_DB" php "$ROOT/tools/financial_certification_seed.php"
  echo "clean-rebuild-ok db=$CLEAN_DB"
fi

echo "financial-archive-ok"
