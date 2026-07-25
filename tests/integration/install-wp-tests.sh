#!/usr/bin/env bash
# Installs a throwaway WordPress + the WordPress PHPUnit test library for
# integration tests. Adapted from the canonical wp-cli scaffold installer.
# NEVER point this at a real database. The DB name MUST contain "test".
set -euo pipefail

DB_NAME="${1:-}"
DB_USER="${2:-}"
DB_PASS="${3:-}"
DB_HOST="${4:-127.0.0.1}"
WP_VERSION="${5:-latest}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

if [[ -z "$DB_NAME" || -z "$DB_USER" ]]; then
  echo "usage: install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]" >&2
  exit 1
fi
case "$DB_NAME" in
  *test*|*integration*) : ;;
  *) echo "REFUSING: DB name must contain 'test' or 'integration' (got '$DB_NAME')." >&2; exit 1 ;;
esac

download() { curl -fsSL --retry 3 --retry-delay 2 "$1" -o "$2"; }

# Downloads a .tar.gz and verifies it really is a valid gzip archive, retrying a
# few times (a truncated download otherwise fails later with a confusing
# "invalid compressed data" from tar).
download_targz() {
  local url="$1" dest="$2" i
  for i in 1 2 3; do
    rm -f "$dest"
    if download "$url" "$dest" && gzip -t "$dest" 2>/dev/null; then
      return 0
    fi
    echo "   download/verify attempt $i failed for $url; retrying" >&2
    sleep 3
  done
  echo "FAILED to download a valid archive from $url" >&2
  return 1
}

mkdir -p "$WP_CORE_DIR" "$WP_TESTS_DIR"

# Resolve the latest WP version tag when requested.
if [[ "$WP_VERSION" == "latest" ]]; then
  WP_VERSION="$(curl -fsSL https://api.wordpress.org/core/version-check/1.7/ | grep -o '"version":"[0-9.]*"' | head -1 | cut -d'"' -f4)"
fi
echo "Using WordPress ${WP_VERSION}"

# WordPress core (for the test library's ABSPATH). Cached across runs in
# /tmp/wp-anpa-cache so repeated runs do not re-download.
CACHE_DIR="${WP_DL_CACHE:-/tmp/wp-anpa-cache}"
mkdir -p "$CACHE_DIR"
if [[ ! -f "$WP_CORE_DIR/wp-load.php" ]]; then
  TARBALL="$CACHE_DIR/wordpress-${WP_VERSION}.tar.gz"
  if [[ ! -f "$TARBALL" ]] || ! gzip -t "$TARBALL" 2>/dev/null; then
    download_targz "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$TARBALL"
  fi
  tar --strip-components=1 -zxmf "$TARBALL" -C "$WP_CORE_DIR"
fi

# Test library (includes + data) matching the WP version.
# Export the test library, retrying (WSL DNS can fail transiently) and falling
# back from the version tag to trunk.
svn_export_retry() {
  local path="$1" dest="$2" i
  for i in 1 2 3; do
    if svn export --quiet --force "https://develop.svn.wordpress.org/tags/${WP_VERSION}/${path}" "$dest" 2>/dev/null; then return 0; fi
    if svn export --quiet --force "https://develop.svn.wordpress.org/trunk/${path}" "$dest" 2>/dev/null; then return 0; fi
    echo "   svn export attempt $i failed for ${path}; retrying" >&2
    sleep 3
  done
  echo "FAILED to export ${path} from develop.svn.wordpress.org" >&2
  return 1
}
svn_export_retry 'tests/phpunit/includes/' "$WP_TESTS_DIR/includes"
svn_export_retry 'tests/phpunit/data/'     "$WP_TESTS_DIR/data"

# wp-tests-config.php — dedicated test DB, integration table prefix.
CONF="$WP_TESTS_DIR/wp-tests-config.php"
download "https://develop.svn.wordpress.org/tags/${WP_VERSION}/wp-tests-config-sample.php" "$CONF" \
  || download "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" "$CONF"

sed -i "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}/'|" "$CONF"
sed -i "s/youremptytestdbnamehere/${DB_NAME}/" "$CONF"
sed -i "s/yourusernamehere/${DB_USER}/" "$CONF"
sed -i "s/yourpasswordhere/${DB_PASS}/" "$CONF"
sed -i "s|localhost|${DB_HOST}|" "$CONF"
sed -i "s/wptests_/wpint_/" "$CONF"

echo "WP test library ready at ${WP_TESTS_DIR} (prefix wpint_, DB ${DB_NAME})."
