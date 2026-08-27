#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

# docker compose exec (unlike run) bypasses ENTRYPOINT and requires the
# target service to already be running, so fail loudly here instead of
# letting `exec` produce a cryptic "service is not running" error.
echo "==> Checking that the dev stack is running"
running="$(docker compose ps --status running --services 2>/dev/null || true)"
for svc in php-fpm nuxt; do
  if ! grep -qx "$svc" <<<"$running"; then
    echo "The '$svc' service is not running. Start the stack first: ./scripts/setup.sh or docker compose up -d"
    exit 1
  fi
done

echo "==> Backend (Pest)"
docker compose exec -T php-fpm ./vendor/bin/pest

echo "==> Frontend (Vitest)"
docker compose exec -T nuxt npm run test

echo "==> All suites passed"
