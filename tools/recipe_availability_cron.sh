#!/usr/bin/env bash
# Cron-friendly wrapper that refreshes the recipe availability cache for all active
# recipes. Schedule on the shop host, e.g. every 5 minutes:
#
#   */5 * * * * /var/www/html/tools/recipe_availability_cron.sh >> /var/www/html/logs/recipe_availability_cron.log 2>&1
#
# Requires the shop env (POSMAIN_DB_*, POSMAIN_RECIPE_MODE=full, POSMAIN_RECIPE_AVAILABILITY=1)
# to be available to the PHP process (via the shop .env loaded by app_config.php).

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${PROJECT_ROOT}"

php "${PROJECT_ROOT}/tools/recipe_refresh_availability.php" \
    --apply \
    --all-active \
    --order-type=takeaway \
    --channel=pos \
    --limit=1000 \
    --json

exit $?
