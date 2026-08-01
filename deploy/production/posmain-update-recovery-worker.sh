#!/bin/bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: posmain-update-recovery-worker.sh <job-id>" >&2
  exit 64
fi

APP_ROOT="${POSMAIN_APP_ROOT:-/var/www/posmain/current}"
JOB_ID="$1"
if [[ ! "$JOB_ID" =~ ^upd_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$ ]]; then
  echo "Invalid update job ID." >&2
  exit 64
fi

cd "$APP_ROOT"
exec /usr/bin/php "$APP_ROOT/cli/update_recovery_worker.php" --job-id="$JOB_ID"
