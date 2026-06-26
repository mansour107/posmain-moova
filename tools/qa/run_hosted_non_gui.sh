#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CONFIG="${POSMAIN_QA_CAMPAIGN_CONFIG:-$ROOT/tests/qa/campaign_config.local.json}"

if [[ ! -f "$CONFIG" ]]; then
  echo "Campaign config not found: $CONFIG" >&2
  exit 1
fi

SSH_HOST="$(php -r '$c=json_decode(file_get_contents($argv[1]), true); echo $c["hosted"]["ssh_host"] ?? "";' "$CONFIG")"
SSH_USER="$(php -r '$c=json_decode(file_get_contents($argv[1]), true); echo $c["hosted"]["ssh_user"] ?? "";' "$CONFIG")"
REMOTE_PATH="$(php -r '$c=json_decode(file_get_contents($argv[1]), true); echo $c["hosted"]["remote_app_path"] ?? "";' "$CONFIG")"
BASE_URL="$(php -r '$c=json_decode(file_get_contents($argv[1]), true); echo $c["hosted"]["base_url"] ?? "";' "$CONFIG")"
RUN_ID="$(php -r '$c=json_decode(file_get_contents($argv[1]), true); echo $c["run_id"] ?? "qa-run";' "$CONFIG")"
OUT_DIR="$ROOT/var/qa/$RUN_ID/hosted"
mkdir -p "$OUT_DIR"

if [[ -z "$SSH_HOST" ]]; then
  echo '{"ok":true,"skipped":true,"message":"No ssh_host in campaign config"}' | tee "$OUT_DIR/non_gui.json"
  exit 0
fi

SSH_TARGET="$SSH_HOST"
if [[ -n "$SSH_USER" ]]; then
  SSH_TARGET="${SSH_USER}@${SSH_HOST}"
fi

SSH_IDENTITY="${POSMAIN_QA_SSH_IDENTITY_FILE:-$HOME/.ssh/hetzner_pos_server}"
SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=30)
if [[ -f "$SSH_IDENTITY" ]]; then
  SSH_OPTS+=(-i "$SSH_IDENTITY")
fi

HOSTED_DB="$(php -r '$c=json_decode(file_get_contents($argv[1]), true); echo $c["hosted"]["db_name"] ?? "";' "$CONFIG")"
HOSTED_SLUG="$(php -r '$c=json_decode(file_get_contents($argv[1]), true); echo $c["hosted"]["shop_slug"] ?? "";' "$CONFIG")"

E2E_ENV=()
if [[ -n "$HOSTED_SLUG" ]]; then
  E2E_ENV+=("POSMAIN_E2E_USER_ADMIN=p6_admin@${HOSTED_SLUG}")
  E2E_ENV+=("POSMAIN_E2E_USER_MANAGER=p6_manager@${HOSTED_SLUG}")
  E2E_ENV+=("POSMAIN_E2E_USER_CASHIER=p6_cashier@${HOSTED_SLUG}")
  E2E_ENV+=("POSMAIN_E2E_USER_WAITER=p6_waiter@${HOSTED_SLUG}")
fi
if [[ -n "$HOSTED_DB" ]]; then
  E2E_ENV+=("POSMAIN_DB_NAME=${HOSTED_DB}")
  # MariaDB on Debian: root auth via unix socket requires host=localhost (not 127.0.0.1).
  E2E_ENV+=("POSMAIN_TEST_MYSQL_HOST=localhost")
  E2E_ENV+=("POSMAIN_TEST_MYSQL_PORT=3306")
  E2E_ENV+=("POSMAIN_TEST_MYSQL_USER=root")
  E2E_ENV+=("POSMAIN_TEST_MYSQL_PASS=")
fi
E2E_ENV+=("POSMAIN_E2E_DEMO_PASSWORD=P6demo123!")

REMOTE_CMD="cd $(printf %q "$REMOTE_PATH") && env POSMAIN_QA_CAMPAIGN=1 ${E2E_ENV[*]} php tools/run_persona_tests.php --all --non-gui --json --continue-on-failure"

set +e
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "$REMOTE_CMD" > "$OUT_DIR/non_gui.json" 2> "$OUT_DIR/non_gui.stderr"
CODE=$?
set -e

if [[ ! -s "$OUT_DIR/non_gui.json" ]]; then
  echo '{"ok":false,"error":"empty remote output","stderr_file":"'"$OUT_DIR/non_gui.stderr"'"}' > "$OUT_DIR/non_gui.json"
  exit 2
fi

echo "Hosted non-GUI complete (exit $CODE). Artifacts: $OUT_DIR/non_gui.json"
exit "$CODE"
