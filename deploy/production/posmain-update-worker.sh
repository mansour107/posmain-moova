#!/bin/bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: posmain-update-worker.sh <job-id>" >&2
  exit 64
fi

APP_ROOT="${POSMAIN_APP_ROOT:-/var/www/posmain/current}"
JOB_ID="$1"

cd "$APP_ROOT"
exec /usr/bin/php "$APP_ROOT/cli/update_worker.php" --job-id="$JOB_ID"
