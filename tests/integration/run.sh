#!/usr/bin/env bash
# Runs the integration suite against the container-based ephemeral DB.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/../.." && pwd)"

[[ -f "$HERE/.env.integration" ]] || { echo "Missing .env.integration" >&2; exit 1; }
set -a; . "$HERE/.env.integration"; set +a

case "$WP_TESTS_DB_NAME" in
  *test*|*integration*) : ;;
  *) echo "REFUSING: WP_TESTS_DB_NAME must contain 'test' or 'integration'." >&2; exit 1 ;;
esac
[[ "$WP_TESTS_TABLE_PREFIX" == wpint_* ]] || { echo "REFUSING: prefix must start with wpint_" >&2; exit 1; }

export WP_TESTS_DB_HOST="127.0.0.1:${DB_PORT}"
( cd "$PLUGIN_DIR" && composer install --no-interaction --no-progress >/dev/null )
( cd "$PLUGIN_DIR" && vendor/bin/phpunit -c phpunit-integration.xml --no-coverage )
