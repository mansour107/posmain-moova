#!/bin/bash
set -euo pipefail

if [[ $# -ne 0 ]]; then
  echo "Usage: posmain-update-runtime-reload.sh" >&2
  exit 64
fi

mapfile -t PHP_FPM_SERVICES < <(
  /usr/bin/systemctl list-units --type=service --state=running --no-legend --plain 'php*-fpm.service' \
    | /usr/bin/awk '{print $1}'
)
if [[ ${#PHP_FPM_SERVICES[@]} -ne 1 ]]; then
  echo "Expected exactly one running PHP-FPM service; found ${#PHP_FPM_SERVICES[@]}." >&2
  exit 69
fi

exec /usr/bin/systemctl reload "${PHP_FPM_SERVICES[0]}"
