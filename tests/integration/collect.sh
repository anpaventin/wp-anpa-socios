#!/usr/bin/env bash
# Collects schema/timezone evidence from the container-based ephemeral DB.
# Writes plain-text evidence (no credentials, no personal data).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
OUT="${EVIDENCE_OUT:-$HERE/../../../.integration-evidence}"

[[ -f "$HERE/.env.integration" ]] || { echo "Missing .env.integration" >&2; exit 1; }
set -a; . "$HERE/.env.integration"; set +a
mkdir -p "$OUT"

CID="wp_anpa_integration_mysql"
DOCKER="docker"; command -v docker >/dev/null 2>&1 || DOCKER="podman"

run_sql() { $DOCKER exec -i "$CID" sh -lc "exec mariadb -uroot -p\"\$MYSQL_ROOT_PASSWORD\" \"$WP_TESTS_DB_NAME\" -e \"$1\" 2>/dev/null || exec mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" \"$WP_TESTS_DB_NAME\" -e \"$1\""; }

run_sql "SELECT VERSION() AS version, @@global.time_zone AS global_tz, @@session.time_zone AS session_tz, NOW() AS now_local, UTC_TIMESTAMP() AS now_utc\\G" > "$OUT/engine-and-timezones.txt"
: > "$OUT/show-create-table.txt"; : > "$OUT/show-index.txt"
for t in "${WP_TESTS_TABLE_PREFIX}anpa_email_campaigns" "${WP_TESTS_TABLE_PREFIX}anpa_email_recipients" "${WP_TESTS_TABLE_PREFIX}anpa_email_attempts"; do
  run_sql "SHOW CREATE TABLE \\\`$t\\\`\\G" >> "$OUT/show-create-table.txt" 2>&1 || true
  run_sql "SHOW INDEX FROM \\\`$t\\\`\\G"  >> "$OUT/show-index.txt" 2>&1 || true
done
echo "evidence in $OUT"
