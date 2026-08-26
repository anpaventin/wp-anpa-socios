#!/usr/bin/env bash
# Removes the ephemeral integration environment. Only ever touches this
# project's own /tmp/wp_anpa_integration_* resources. Never touches system
# services, other containers, other databases or any real installation.
set -euo pipefail

PREFIX="wp_anpa_integration"
BASE="/tmp/${PREFIX}"

if [[ -f "$BASE/mysqld.pid" ]]; then
  PID="$(cat "$BASE/mysqld.pid" 2>/dev/null || true)"
  if [[ -n "${PID:-}" ]] && kill -0 "$PID" 2>/dev/null; then
    echo "stopping ephemeral server (pid $PID)"
    kill "$PID" 2>/dev/null || true
    sleep 2
  fi
fi

# Kill only server processes whose datadir is OUR ephemeral path.
if command -v pgrep >/dev/null 2>&1; then
  for pid in $(pgrep -f "datadir=${BASE}/data" 2>/dev/null || true); do
    echo "stopping stray server pid $pid"
    kill "$pid" 2>/dev/null || true
  done
fi

if [[ -d "$BASE" ]]; then
  rm -rf "$BASE"
  echo "removed $BASE (datadir, credentials, WP test library, evidence copy)"
else
  echo "nothing to remove at $BASE"
fi

# Optional docker/podman path (only this project's compose project).
if command -v docker >/dev/null 2>&1 || command -v podman >/dev/null 2>&1; then
  COMPOSE="docker compose"; command -v docker >/dev/null 2>&1 || COMPOSE="podman compose"
  HERE="$(cd "$(dirname "$0")" && pwd)"
  if [[ -f "$HERE/docker-compose.integration.yml" ]]; then
    ( cd "$HERE" && $COMPOSE -f docker-compose.integration.yml down -v --remove-orphans 2>/dev/null || true )
  fi
fi

echo "clean done"
