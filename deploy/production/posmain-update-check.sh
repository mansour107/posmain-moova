#!/bin/bash
set -euo pipefail

if [[ $# -ne 0 ]]; then
  echo "Usage: posmain-update-check.sh" >&2
  exit 64
fi

APP_ROOT="${POSMAIN_APP_ROOT:-/var/www/posmain/current}"

cd "$APP_ROOT"
exec /usr/bin/timeout --kill-after=5s 30s /usr/bin/php "$APP_ROOT/cli/update_check.php"
