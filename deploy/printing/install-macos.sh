#!/bin/zsh
set -eu

app_root="${1:-$(cd "$(dirname "$0")/../.." && pwd)}"
php_binary="${POSMAIN_PHP_BINARY:-$(command -v php)}"
app_env="${POSMAIN_APP_ENV_FILE:-${app_root}/.env}"
runtime_dir="${HOME}/Library/Application Support/POSMAIN/printing"
agent_dir="${HOME}/Library/LaunchAgents"
log_dir="${HOME}/Library/Logs/POSMAIN"
secret_file="${runtime_dir}/bridge.secret"
state_dir="${runtime_dir}/delivery-state"
bridge_listen="${POSMAIN_PRINT_BRIDGE_LISTEN:-127.0.0.1:17981}"
bridge_app_url="${POSMAIN_PRINT_BRIDGE_APP_URL:-http://127.0.0.1:17981}"
bridge_worker_url="${POSMAIN_PRINT_BRIDGE_WORKER_URL:-http://127.0.0.1:17981}"

if [[ -z "${php_binary}" || ! -x "${php_binary}" ]]; then
  print -u2 "PHP غير مثبت. ثبّت حزمة POSMAIN المحلية أولاً ثم أعد المحاولة."
  exit 2
fi
mkdir -p "${runtime_dir}" "${state_dir}" "${agent_dir}" "${log_dir}"
chmod 700 "${runtime_dir}" "${state_dir}"
"${php_binary}" "${app_root}/tools/configure_print_runtime.php" --apply --env-file="${app_env}" --secret-file="${secret_file}" --bridge-url="${bridge_app_url}"
secret="$(tr -d '\r\n' < "${secret_file}")"

bridge_plist="${agent_dir}/com.posmain.print-bridge.plist"
worker_plist="${agent_dir}/com.posmain.print-worker.plist"
sed -e "s|__POSMAIN_ROOT__|${app_root}|g" -e "s|__PHP_BINARY__|${php_binary}|g" -e "s|__BRIDGE_LISTEN__|${bridge_listen}|g" -e "s|__BRIDGE_SECRET_FILE__|${secret_file}|g" -e "s|__BRIDGE_STATE_DIR__|${state_dir}|g" -e "s|__LOG_DIR__|${log_dir}|g" "${app_root}/deploy/printing/launchd/com.posmain.print-bridge.plist" > "${bridge_plist}"
sed -e "s|__POSMAIN_ROOT__|${app_root}|g" -e "s|__PHP_BINARY__|${php_binary}|g" -e "s|__BRIDGE_WORKER_URL__|${bridge_worker_url}|g" -e "s|__BRIDGE_SECRET__|${secret}|g" -e "s|__WORKER_PID_FILE__|${runtime_dir}/worker.pid|g" -e "s|__WORKER_STATUS_FILE__|${runtime_dir}/worker-status.json|g" -e "s|__LOG_DIR__|${log_dir}|g" "${app_root}/deploy/printing/launchd/com.posmain.print-worker.plist" > "${worker_plist}"
chmod 600 "${bridge_plist}" "${worker_plist}"
launchctl bootout "gui/$(id -u)" "${bridge_plist}" >/dev/null 2>&1 || true
launchctl bootout "gui/$(id -u)" "${worker_plist}" >/dev/null 2>&1 || true
launchctl bootstrap "gui/$(id -u)" "${bridge_plist}"
launchctl bootstrap "gui/$(id -u)" "${worker_plist}"
launchctl kickstart -k "gui/$(id -u)/com.posmain.print-bridge"
launchctl kickstart -k "gui/$(id -u)/com.posmain.print-worker"
print "تم تثبيت خدمة الطباعة وتشغيلها تلقائياً لهذا المستخدم."
