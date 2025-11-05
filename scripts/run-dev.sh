#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

echo "Starting docker-compose (detached)..."
docker compose up -d --build

echo "Waiting for web to respond at http://127.0.0.1:8000 ..."
for i in {1..40}; do
  if curl -sSf http://127.0.0.1:8000 >/dev/null 2>&1; then
    echo "Web is up: http://127.0.0.1:8000"
    if which xdg-open >/dev/null 2>&1; then xdg-open http://127.0.0.1:8000; fi
    exit 0
  fi
  sleep 1
done

echo "Timed out waiting for web; check 'docker compose ps' and 'docker compose logs'"
