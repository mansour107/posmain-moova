#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/docker-compose.posmain-test.yml"
MYSQL_BIN="${MYSQL_BIN:-/opt/homebrew/opt/mysql-client/bin/mysql}"
POSMAIN_TEST_URL="${POSMAIN_TEST_URL:-http://127.0.0.1:8010/index.php}"

container_exists() {
  docker container inspect "$1" >/dev/null 2>&1
}

container_running() {
  [ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null || true)" = "true" ]
}

start_mysql_container() {
  if container_exists posmain-mysql; then
    docker start posmain-mysql >/dev/null
  else
    docker compose -f "$COMPOSE_FILE" up -d mysql
  fi
}

wait_for_mysql() {
  local attempt
  for attempt in $(seq 1 40); do
    if docker exec posmain-mysql mariadb-admin ping -uroot --silent >/dev/null 2>&1 ||
       docker exec posmain-mysql mysqladmin ping -uroot --silent >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done

  echo "Timed out waiting for posmain-mysql to accept connections" >&2
  return 1
}

restart_php_container() {
  if container_exists posmain-php; then
    if container_running posmain-php; then
      docker restart posmain-php >/dev/null
    else
      docker start posmain-php >/dev/null
    fi
  else
    docker compose -f "$COMPOSE_FILE" up -d --build php
  fi
}

wait_for_pos_url() {
  local attempt
  for attempt in $(seq 1 40); do
    if curl -fsS -I "$POSMAIN_TEST_URL" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done

  echo "Timed out waiting for POS URL: $POSMAIN_TEST_URL" >&2
  return 1
}

recover_stack() {
  start_mysql_container
  wait_for_mysql
  restart_php_container
  wait_for_pos_url
  echo "posmain test environment recovered: posmain-mysql running, posmain-php restarted, $POSMAIN_TEST_URL reachable"
}

case "${1:-up}" in
  up)
    docker compose -f "$COMPOSE_FILE" up -d --build
    ;;
  recover|restart)
    recover_stack
    ;;
  down)
    docker compose -f "$COMPOSE_FILE" down
    ;;
  logs)
    docker compose -f "$COMPOSE_FILE" logs -f "${2:-}"
    ;;
  php-lint)
    shift || true
    if [ "$#" -eq 0 ]; then
      set -- classes/MoovaPosIntegration.php classes/PosOrderService.php ajax/moova_confirm_order.php ajax/moova_change_order.php elements/pos/cofe_widget.php moova_pos_widget.php moova_pos_proxy.php
    fi
    for file in "$@"; do
      docker exec posmain-php php -l "$file"
    done
    ;;
  mysql)
    shift || true
    "$MYSQL_BIN" -h127.0.0.1 -P3307 -uroot "$@"
    ;;
  smoke)
    docker exec posmain-php php -l classes/MoovaPosIntegration.php
    docker exec posmain-php php -l classes/PosOrderService.php
    docker exec posmain-php php -l ajax/moova_confirm_order.php
    docker exec posmain-php php -l ajax/moova_change_order.php
    docker exec posmain-php php -l elements/pos/cofe_widget.php
    docker exec posmain-php php -l moova_pos_widget.php
    docker exec posmain-php php -l moova_pos_proxy.php
    "$MYSQL_BIN" -h127.0.0.1 -P3307 -uroot -e "USE kody2; SHOW TABLES LIKE 'moova_pos_shop_links'; SHOW TABLES LIKE 'moova_pos_order_links';"
    curl -fsS -I http://127.0.0.1:8010/index.php >/dev/null
    echo "posmain test environment is ready"
    ;;
  *)
    echo "Usage: $0 {up|recover|restart|down|logs|php-lint|mysql|smoke}" >&2
    exit 2
    ;;
esac
