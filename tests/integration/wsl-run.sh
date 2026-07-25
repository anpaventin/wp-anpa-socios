#!/usr/bin/env bash
# Runs the fase35 integration matrix against an EPHEMERAL, user-owned DB started
# in a throwaway /tmp datadir. No root, no system service, no persistence, no
# production access. The plugin source is COPIED into /tmp so the Windows-side
# vendor/ and working tree are never modified.
#
#   bash wsl-run.sh
#
# Env overrides: DB_PORT, DB_TZ_SQL, PHP_TZ, WP_TZ, KEEP=1 (keep env for debug).
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SRC_PLUGIN="$(cd "$HERE/../.." && pwd)"

PREFIX="wp_anpa_integration"
BASE="/tmp/${PREFIX}"
DATADIR="$BASE/data"
SOCKET="$BASE/mysqld.sock"
WORK_PLUGIN="$BASE/plugin"
PORT="${DB_PORT:-33061}"
DB_NAME="${WP_TESTS_DB_NAME:-wp_anpa_integration_test}"
DB_USER="${WP_TESTS_DB_USER:-wp_anpa_it}"

# Three DIFFERENT timezones on purpose: DB session, PHP and WordPress.
DB_TZ_SQL="${DB_TZ_SQL:--03:00}"
PHP_TZ="${PHP_TZ:-Europe/Madrid}"
WP_TZ="${WP_TZ:-Pacific/Auckland}"

# ── Hard guards ─────────────────────────────────────────────────────────────
case "$DB_NAME" in
  *test*|*integration*) : ;;
  *) echo "REFUSING: DB name must contain 'test' or 'integration' (got '$DB_NAME')." >&2; exit 1 ;;
esac

SERVER_BIN=""; for c in mariadbd mysqld; do command -v "$c" >/dev/null 2>&1 && { SERVER_BIN="$c"; break; }; done
[[ -n "$SERVER_BIN" ]] || { echo "No mariadbd/mysqld. Run: sudo bash wsl-setup.sh [mariadb|mysql]" >&2; exit 1; }
CLIENT_BIN=""; for c in mariadb mysql; do command -v "$c" >/dev/null 2>&1 && { CLIENT_BIN="$c"; break; }; done
[[ -n "$CLIENT_BIN" ]] || { echo "No mariadb/mysql client found." >&2; exit 1; }
command -v php >/dev/null 2>&1 || { echo "php missing. Run: sudo bash wsl-setup.sh" >&2; exit 1; }
# A Windows composer shim on PATH cannot run here; require a Linux one.
COMPOSER_BIN=""
for c in /usr/bin/composer /usr/local/bin/composer; do [[ -x "$c" ]] && { COMPOSER_BIN="$c"; break; }; done
[[ -n "$COMPOSER_BIN" ]] || { echo "Linux composer missing. Run: sudo bash wsl-setup.sh" >&2; exit 1; }
command -v svn >/dev/null 2>&1 || { echo "svn missing. Run: sudo bash wsl-setup.sh" >&2; exit 1; }

ENGINE_DESC="$($SERVER_BIN --version 2>&1 | head -1)"
echo "== engine: $ENGINE_DESC"

# ── Fresh ephemeral environment ─────────────────────────────────────────────
bash "$HERE/wsl-clean.sh" >/dev/null 2>&1 || true
mkdir -p "$DATADIR" "$BASE/evidence" "$WORK_PLUGIN"
chmod 700 "$BASE"
EV="$BASE/evidence"

# Locally generated throwaway credentials: never printed in full, never committed.
DB_PASS="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 20)"
umask 077
printf 'DB_PASS=%s\n' "$DB_PASS" > "$BASE/.creds"
echo "== generated ephemeral DB password (${#DB_PASS} chars; value not shown)"

echo "== copying plugin source into the ephemeral workspace =="
tar -C "$SRC_PLUGIN" \
    --exclude='./vendor' --exclude='./.git' --exclude='./node_modules' \
    --exclude='./bak' --exclude='./dist' --exclude='./.sync' \
    --exclude='./openspec' --exclude='./.integration-evidence' \
    -cf - . | tar -C "$WORK_PLUGIN" -xf -

echo "== initialising datadir =="
if command -v mariadb-install-db >/dev/null 2>&1; then
  mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal >"$BASE/initdb.log" 2>&1 \
    || mariadb-install-db --datadir="$DATADIR" >>"$BASE/initdb.log" 2>&1
else
  "$SERVER_BIN" --initialize-insecure --datadir="$DATADIR" >"$BASE/initdb.log" 2>&1
fi

echo "== starting ephemeral server (port $PORT, session tz $DB_TZ_SQL) =="
"$SERVER_BIN" \
  --datadir="$DATADIR" \
  --socket="$SOCKET" \
  --port="$PORT" \
  --pid-file="$BASE/mysqld.pid" \
  --default-time-zone="$DB_TZ_SQL" \
  --skip-name-resolve \
  --log-error="$BASE/mysqld.err" \
  > "$BASE/mysqld.out" 2>&1 &
SERVER_PID=$!

cleanup() {
  echo "== stopping ephemeral server =="
  kill "$SERVER_PID" 2>/dev/null || true
  wait "$SERVER_PID" 2>/dev/null || true
  rm -f "$BASE/.creds"
  if [[ "${KEEP:-0}" != "1" ]]; then rm -rf "$DATADIR"; fi
}
trap cleanup EXIT

echo "== waiting for the server =="
for i in $(seq 1 90); do
  if "$CLIENT_BIN" --socket="$SOCKET" -uroot -e 'SELECT 1' >/dev/null 2>&1; then echo "DB up"; break; fi
  sleep 1
  if [[ $i -eq 90 ]]; then echo "DB failed to start; last log lines:" >&2; tail -30 "$BASE/mysqld.err" >&2 || true; exit 1; fi
done

echo "== creating dedicated test database + user limited to it =="
"$CLIENT_BIN" --socket="$SOCKET" -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# ── WordPress test library ─────────────────────────────────────────────────
export WP_TESTS_DIR="$BASE/wordpress-tests-lib"
export WP_CORE_DIR="$BASE/wordpress"
export WP_TESTS_DB_NAME="$DB_NAME"
export WP_TESTS_DB_USER="$DB_USER"
export WP_TESTS_DB_PASS="$DB_PASS"
export WP_TESTS_DB_HOST="127.0.0.1:${PORT}"
export WP_TESTS_TABLE_PREFIX="wpint_"
export WP_TESTS_DOMAIN="example.org"

echo "== installing WordPress test library =="
bash "$HERE/install-wp-tests.sh" "$DB_NAME" "$DB_USER" "$DB_PASS" "127.0.0.1:${PORT}" latest

# Harden the throwaway WordPress: no external calls, no real mail, no cron.
cat >> "$WP_TESTS_DIR/wp-tests-config.php" <<'PHPEOF'
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'DISABLE_WP_CRON', true );
PHPEOF

echo "== composer (dev deps) in the ephemeral copy =="
( cd "$WORK_PLUGIN" && "$COMPOSER_BIN" install --no-interaction --no-progress >"$BASE/composer.log" 2>&1 )

# ── Evidence: environment / timezones ──────────────────────────────────────
{
  echo "engine:            $ENGINE_DESC"
  echo "system tz:         $(cat /etc/timezone 2>/dev/null || date +%Z)"
  echo "php tz (forced):   $PHP_TZ"
  echo "wp tz (intended):  $WP_TZ"
  echo "db session tz:     $DB_TZ_SQL"
  echo "php version:       $(php -r 'echo PHP_VERSION;')"
} > "$EV/environment.txt"

"$CLIENT_BIN" --socket="$SOCKET" -uroot -e \
  "SELECT VERSION() AS version, @@global.time_zone AS global_tz, @@session.time_zone AS session_tz, NOW() AS now_session, UTC_TIMESTAMP() AS now_utc\G" \
  > "$EV/engine-and-timezones.txt"

# ── Run the integration suite (skips are failures) ─────────────────────────
echo "== running integration suite (no skips allowed) =="
set +e
( cd "$WORK_PLUGIN" && WP_TZ="$WP_TZ" php -d date.timezone="$PHP_TZ" vendor/bin/phpunit -c phpunit-integration.xml --no-coverage ) \
  > "$EV/phpunit.txt" 2>&1
RC=$?
set -e
tail -40 "$EV/phpunit.txt"

# ── Evidence: schema ───────────────────────────────────────────────────────
echo "== collecting schema evidence =="
: > "$EV/show-create-table.txt"; : > "$EV/show-index.txt"
for t in wpint_anpa_email_campaigns wpint_anpa_email_recipients wpint_anpa_email_attempts; do
  "$CLIENT_BIN" --socket="$SOCKET" -uroot "$DB_NAME" -e "SHOW CREATE TABLE \`$t\`\G" >> "$EV/show-create-table.txt" 2>&1 || true
  "$CLIENT_BIN" --socket="$SOCKET" -uroot "$DB_NAME" -e "SHOW INDEX FROM \`$t\`\G"  >> "$EV/show-index.txt" 2>&1 || true
done
"$CLIENT_BIN" --socket="$SOCKET" -uroot "$DB_NAME" -e \
  "SELECT option_value AS db_version FROM wpint_options WHERE option_name='anpa_socios_db_version'\G" \
  > "$EV/db-version.txt" 2>&1 || true

# Copy evidence OUT of /tmp so it survives cleanup (git-ignored location).
OUTDIR="${EVIDENCE_OUT:-$SRC_PLUGIN/.integration-evidence}"
mkdir -p "$OUTDIR"
cp -r "$EV/." "$OUTDIR/" 2>/dev/null || true
echo "== evidence copied to $OUTDIR =="
echo "== phpunit exit code: $RC =="
exit $RC
