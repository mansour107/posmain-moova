#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/docker-compose.posmain-test.yml"
MYSQL_BIN="${MYSQL_BIN:-/opt/homebrew/opt/mysql-client/bin/mysql}"

case "${1:-up}" in
  up)
    docker compose -f "$COMPOSE_FILE" up -d --build
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
      set -- classes/MoovaPosIntegration.php classes/PosOrderService.php ajax/moova_confirm_order.php elements/pos/cofe_widget.php moova_pos_widget.php moova_pos_proxy.php
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
    docker exec posmain-php php -l elements/pos/cofe_widget.php
    docker exec posmain-php php -l moova_pos_widget.php
    docker exec posmain-php php -l moova_pos_proxy.php
    "$MYSQL_BIN" -h127.0.0.1 -P3307 -uroot -e "USE kody2; SHOW TABLES LIKE 'moova_pos_shop_links'; SHOW TABLES LIKE 'moova_pos_order_links';"
    curl -fsS -I http://127.0.0.1:8010/index.php >/dev/null
    echo "posmain test environment is ready"
    ;;
  *)
    echo "Usage: $0 {up|down|logs|php-lint|mysql|smoke}" >&2
    exit 2
    ;;
esac
