#!/usr/bin/env bash
# One-time (per engine) WSL/Ubuntu setup for the EPHEMERAL integration harness.
#
# Usage — run BY HAND with sudo (it installs packages):
#     sudo bash wsl-setup.sh mariadb     # Ubuntu 22.04 ships MariaDB 10.6
#     sudo bash wsl-setup.sh mysql       # Ubuntu 22.04 ships MySQL 8.0
#
# MariaDB and MySQL server packages CONFLICT on Debian/Ubuntu (both provide
# mysqld), so the engines are installed one at a time; re-run with the other
# engine for the second half of the matrix.
#
# Installs only what the harness needs:
#   - PHP CLI + extensions used by the WordPress test suite
#   - the chosen DB server + client BINARIES (started later by a normal user in
#     a throwaway /tmp datadir — no system service, no root, no persistence)
#   - subversion + curl (WordPress test library installer)
#   - composer (Linux one; a Windows composer shim on PATH is not usable here)
#
# It does NOT create databases, start services, touch any existing WordPress,
# read production credentials, or keep any secret.
set -euo pipefail

ENGINE="${1:-mariadb}"
case "$ENGINE" in
  mariadb|mysql) : ;;
  *) echo "usage: sudo bash wsl-setup.sh [mariadb|mysql]" >&2; exit 1 ;;
esac

if [[ "${EUID}" -ne 0 ]]; then
  echo "This script installs packages and must run with sudo:" >&2
  echo "    sudo bash $0 $ENGINE" >&2
  exit 1
fi

echo "== apt update =="
apt-get update -y

echo "== PHP + extensions =="
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
  php-cli php-mysql php-mbstring php-xml php-curl php-zip unzip

echo "== subversion + curl (WP test library installer) =="
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
  subversion curl ca-certificates

echo "== composer (Linux) =="
if [[ ! -x /usr/bin/composer && ! -x /usr/local/bin/composer ]]; then
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends composer || {
    php -r "copy('https://getcomposer.org/installer','/tmp/composer-setup.php');"
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
  }
fi

echo "== DB engine: $ENGINE =="
if [[ "$ENGINE" == "mariadb" ]]; then
  # Remove the conflicting engine if present (packages only; our data lives in /tmp).
  DEBIAN_FRONTEND=noninteractive apt-get remove -y mysql-server mysql-server-8.0 mysql-client 2>/dev/null || true
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends mariadb-server mariadb-client
else
  DEBIAN_FRONTEND=noninteractive apt-get remove -y mariadb-server mariadb-client 2>/dev/null || true
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends mysql-server mysql-client
fi

echo "== keeping the machine clean: stop/disable any auto-started system service =="
service mariadb stop 2>/dev/null || true
service mysql stop 2>/dev/null || true
systemctl disable mariadb 2>/dev/null || true
systemctl disable mysql 2>/dev/null || true

echo
echo "Installed:"
php -v | head -1 || true
(mariadbd --version 2>/dev/null || mysqld --version 2>/dev/null) || true
(/usr/bin/composer --version 2>/dev/null || /usr/local/bin/composer --version 2>/dev/null) | head -1 || true
svn --version --quiet 2>/dev/null || true
echo
echo "DONE ($ENGINE). Now run (WITHOUT sudo):  bash wsl-run.sh"
