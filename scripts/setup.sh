#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> Checking prerequisites"
command -v docker >/dev/null 2>&1 || { echo "Docker is required and was not found."; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "Docker Compose v2+ is required."; exit 1; }

if [ ! -f .env ]; then
  cp .env.example .env
  echo "==> Created .env from .env.example"
fi

echo "==> Building images"
docker compose build

echo "==> Starting postgres"
docker compose up -d postgres

printf '==> Waiting for postgres to become healthy'
for _ in $(seq 1 60); do
  status="$(docker inspect --format '{{.State.Health.Status}}' "$(docker compose ps -q postgres)" 2>/dev/null || echo starting)"
  if [ "$status" = "healthy" ]; then
    echo ' ok'
    break
  fi
  printf '.'
  sleep 2
done

if [ "${status:-}" != "healthy" ]; then
  echo ' failed'
  echo "postgres did not become healthy. Check: docker compose logs postgres"
  exit 1
fi

# NOTE: docker compose run executes the php-fpm image's ENTRYPOINT before the
# given command. That entrypoint (Task 17) already waits for postgres, clears
# bootstrap/cache, installs composer deps when vendor/ is missing or stale,
# runs `migrate --force`, and conditionally seeds via `app:seed-if-empty`. So
# by the time the explicit "composer install" below finishes, the database is
# already migrated and seeded. `run` (not `exec`) is still correct here: the
# php-fpm service is not started as a long-running container yet at this
# point in the script.
echo "==> Installing backend dependencies"
docker compose run --rm php-fpm composer install --no-interaction

if ! grep -qE '^APP_KEY=base64:' .env; then
  echo "==> Generating APP_KEY"
  # Extract ONLY the key. `docker compose run` executes Task 17's entrypoint first,
  # and anything it prints lands in this command substitution too. The entrypoint
  # logs to stderr precisely so this stays clean -- but do not rely on that alone:
  # grep the one line that actually is a key. Piping the whole output through a
  # newline-stripping tr instead concatenates every log line into the value, which
  # has already corrupted a .env once on this project.
  key="$(docker compose run --rm -T php-fpm \
    php artisan key:generate --show | tr -d '\r' | grep -oE '^base64:[A-Za-z0-9+/=]+$' | head -n 1)"
  if [ -z "${key}" ]; then
    echo "ERROR: could not extract an APP_KEY from key:generate output" >&2
    exit 1
  fi
  sed -i.bak "s|^APP_KEY=.*|APP_KEY=${key}|" .env
  rm -f .env.bak
fi

# Deliberately NOT `migrate --force --seed`: the entrypoint invoked above
# already seeded an empty catalog via app:seed-if-empty. DatabaseSeeder
# creates rows via factories and is not idempotent, so `--seed` here would
# unconditionally append a second batch of categories/products on every run,
# breaking the idempotency this script promises. `migrate --force` plus the
# same seed-if-empty command the entrypoint uses keeps re-runs safe.
echo "==> Running migrations and seeding"
docker compose run --rm php-fpm php artisan migrate --force
docker compose run --rm php-fpm php artisan app:seed-if-empty

echo "==> Installing frontend dependencies"
docker compose run --rm nuxt npm ci

echo "==> Starting the full stack"
docker compose up -d

cat <<'DONE'

Setup complete.

  Application   http://localhost:8080/products
  API health    http://localhost:8080/api/health

  Run tests     ./scripts/test.sh
  Stop          docker compose down
  Logs          docker compose logs -f

DONE
