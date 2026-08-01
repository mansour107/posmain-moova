#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
  echo "شغّل أداة التثبيت بصلاحية مسؤول الجهاز." >&2
  exit 2
fi
app_root="${1:-/opt/posmain}"
service_user="${POSMAIN_SERVICE_USER:-www-data}"
php_binary="${POSMAIN_PHP_BINARY:-$(command -v php)}"
app_env="${POSMAIN_APP_ENV_FILE:-${app_root}/.env}"
runtime_dir="/var/lib/posmain/printing"
secret_file="/etc/posmain/printing.secret"
bridge_listen="${POSMAIN_PRINT_BRIDGE_LISTEN:-127.0.0.1:17981}"
bridge_app_url="${POSMAIN_PRINT_BRIDGE_APP_URL:-http://127.0.0.1:17981}"
bridge_worker_url="${POSMAIN_PRINT_BRIDGE_WORKER_URL:-http://127.0.0.1:17981}"
mkdir -p /etc/posmain "${runtime_dir}/delivery-state" /var/log/posmain
"${php_binary}" "${app_root}/tools/configure_print_runtime.php" --apply --env-file="${app_env}" --secret-file="${secret_file}" --bridge-url="${bridge_app_url}"
chmod 600 "${secret_file}" "${app_env}"
chown -R "${service_user}" "${runtime_dir}" /var/log/posmain

sed -e "s|__POSMAIN_ROOT__|${app_root}|g" -e "s|__PHP_BINARY__|${php_binary}|g" -e "s|__PRINT_ENV_FILE__|${app_env}|g" -e "s|__BRIDGE_LISTEN__|${bridge_listen}|g" -e "s|__BRIDGE_SECRET_FILE__|${secret_file}|g" -e "s|__BRIDGE_STATE_DIR__|${runtime_dir}/delivery-state|g" -e "s|__SERVICE_USER__|${service_user}|g" "${app_root}/deploy/printing/systemd/posmain-print-bridge.service" > /etc/systemd/system/posmain-print-bridge.service
sed -e "s|__POSMAIN_ROOT__|${app_root}|g" -e "s|__PHP_BINARY__|${php_binary}|g" -e "s|__APP_ENV_FILE__|${app_env}|g" -e "s|__BRIDGE_WORKER_URL__|${bridge_worker_url}|g" -e "s|__WORKER_PID_FILE__|${runtime_dir}/worker.pid|g" -e "s|__WORKER_STATUS_FILE__|${runtime_dir}/worker-status.json|g" -e "s|__SERVICE_USER__|${service_user}|g" "${app_root}/deploy/printing/systemd/posmain-print-worker.service" > /etc/systemd/system/posmain-print-worker.service
systemctl daemon-reload
systemctl enable --now posmain-print-bridge.service posmain-print-worker.service
echo "تم تثبيت خدمتي الطباعة وتشغيلهما تلقائياً."
