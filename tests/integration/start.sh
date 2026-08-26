#!/usr/bin/env bash
# Starts the ephemeral integration DB and installs the WP test library.
# Only touches this project's own compose resources. Never production.
set -euo pipefail
cd "$(dirname "$0")"

if [[ ! -f .env.integration ]]; then
  echo "Missing .env.integration (copy .env.integration.example and edit)." >&2
  exit 1
fi
set -a; . ./.env.integration; set +a

case "$WP_TESTS_DB_NAME" in
  *test*|*integration*) : ;;
  *) echo "REFUSING: WP_TESTS_DB_NAME must contain 'test' or 'integration'." >&2; exit 1 ;;
esac

COMPOSE="docker compose"
command -v docker >/dev/null 2>&1 || COMPOSE="podman compose"

$COMPOSE -f docker-compose.integration.yml up -d
echo "Waiting for DB healthcheck..."
for i in $(seq 1 40); do
  if $COMPOSE -f docker-compose.integration.yml ps | grep -q "healthy"; then echo "DB healthy"; break; fi
  sleep 2
done

WP_TESTS_DB_HOST="127.0.0.1:${DB_PORT}" \
  ./install-wp-tests.sh "$WP_TESTS_DB_NAME" "$WP_TESTS_DB_USER" "$WP_TESTS_DB_PASS" "127.0.0.1:${DB_PORT}" latest
echo "Ready. Run ./run.sh"
