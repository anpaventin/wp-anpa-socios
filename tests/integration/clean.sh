#!/usr/bin/env bash
# Removes ONLY this project's container resources (wp_anpa_integration_*).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
COMPOSE="docker compose"; command -v docker >/dev/null 2>&1 || COMPOSE="podman compose"
( cd "$HERE" && $COMPOSE -f docker-compose.integration.yml down -v --remove-orphans )
rm -f "$HERE/.env.integration" 2>/dev/null || true
echo "clean done (project resources only)"
