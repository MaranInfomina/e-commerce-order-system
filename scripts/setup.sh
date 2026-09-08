#!/usr/bin/env bash
set -euo pipefail

# Git Bash on Windows (MSYS) rewrites a bare leading "/" argument as if it
# were a Windows path before handing it to a native .exe — so every
# `docker compose exec ... /garage ...` call below would otherwise resolve
# to something like "C:/Program Files/Git/garage" and fail with "no such
# file or directory", well before Docker ever sees the argument. This is a
# no-op on Linux and macOS, where the variable is simply unused.
export MSYS_NO_PATHCONV=1

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
  # sed silently no-ops (exit 0) if .env has no APP_KEY= line to match at all --
  # e.g. a hand-edited or truncated .env. Don't sail into migrate/seed and a
  # "Setup complete" banner while the app is actually running on the
  # entrypoint's ephemeral, restart-losing key.
  grep -qE '^APP_KEY=base64:' .env || { echo "ERROR: failed to write APP_KEY into .env" >&2; exit 1; }
fi

if ! grep -qE '^JWT_SECRET=.+' .env; then
  echo "==> Generating JWT_SECRET"
  # Generated locally rather than through `docker compose run`, which would
  # execute the entrypoint and mix its output into the value — the trap that
  # corrupted APP_KEY on this project once already.
  secret="$(docker compose run --rm -T --entrypoint php php-fpm \
    -r 'echo bin2hex(random_bytes(32));' | tr -d '\r' | grep -oE '^[0-9a-f]{64}$' | head -n 1)"

  if [ -z "${secret}" ]; then
    echo "ERROR: could not generate a JWT_SECRET" >&2
    exit 1
  fi

  sed -i.bak "s|^JWT_SECRET=.*|JWT_SECRET=${secret}|" .env
  rm -f .env.bak

  grep -qE '^JWT_SECRET=[0-9a-f]{64}$' .env || {
    echo "ERROR: .env JWT_SECRET is malformed after write" >&2; exit 1; }
fi

if ! grep -qE '^GARAGE_RPC_SECRET=.+' .env; then
  echo "==> Generating GARAGE_RPC_SECRET"
  # docker-compose.yml carries a published throwaway default so a clean clone
  # boots (CR-3). Anyone who can reach Garage's RPC port authenticates with
  # it, so every real environment must replace it — including this developer
  # machine, which is what this block is for.
  rpc="$(docker compose run --rm -T --entrypoint php php-fpm \
    -r 'echo bin2hex(random_bytes(32));' | tr -d '\r' | grep -oE '^[0-9a-f]{64}$' | head -n 1)"

  if [ -z "${rpc}" ]; then
    echo "ERROR: could not generate a GARAGE_RPC_SECRET" >&2
    exit 1
  fi

  sed -i.bak "s|^GARAGE_RPC_SECRET=.*|GARAGE_RPC_SECRET=${rpc}|" .env
  rm -f .env.bak

  # The same post-write check the JWT_SECRET block has. `sed` exits 0 when its
  # anchor line is absent — a hand-edited .env, or one copied from a
  # pre-Task-9 .env.example — so without this the script prints
  # "Setup complete." while Garage still runs on the published throwaway.
  grep -qE '^GARAGE_RPC_SECRET=[0-9a-f]{64}$' .env || {
    echo "ERROR: .env GARAGE_RPC_SECRET is malformed after write" >&2; exit 1; }
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

echo "==> Configuring object storage"
docker compose up -d garage >/dev/null

# `up -d` returns when the container STARTS, not when the RPC layer answers.
# Without this wait `garage status` fails, and the negated layout test below
# read that failure as "already laid out" — after which every bucket command
# fails and setup exits on the credential check with an error pointing nowhere
# near the cause.
ready=0
for _ in $(seq 1 30); do
  if docker compose exec -T garage /garage status >/dev/null 2>&1; then ready=1; break; fi
  sleep 2
done
if [ "$ready" != 1 ]; then
  echo "ERROR: garage did not become reachable. Check: docker compose logs garage" >&2
  exit 1
fi

# Positive test, not a negated one. The single node must be assigned a zone
# before Garage serves anything.
if docker compose exec -T garage /garage status | grep -q 'NO ROLE ASSIGNED'; then
  node="$(docker compose exec -T garage /garage node id -q | cut -d@ -f1 | tr -d '\r')"
  docker compose exec -T garage /garage layout assign -z dc1 -c 1G "$node" >/dev/null
  docker compose exec -T garage /garage layout apply --version 1 >/dev/null
fi

key_created=0
if ! docker compose exec -T garage /garage bucket info coe-products >/dev/null 2>&1; then
  docker compose exec -T garage /garage bucket create coe-products >/dev/null
  docker compose exec -T garage /garage key create coe-app >/dev/null
  docker compose exec -T garage /garage bucket allow --read --write coe-products --key coe-app >/dev/null
  # Anonymous read through Garage's web endpoint, which is what actually
  # serves image_url. Without it every published image URL is a dead link.
  docker compose exec -T garage /garage bucket website --allow coe-products >/dev/null
  key_created=1
fi

# Write whenever the key was just minted, NOT only when .env is blank.
# `docker compose down -v` destroys Garage's metadata volume, so the next run
# creates a brand new key while .env still holds the old id. Guarding on .env
# alone leaves those stale credentials in place — and wrong credentials fail
# harder than absent ones: uploads 403, and StorageConnectivityTest FAILS
# rather than skipping, because credentials are present, just wrong.
if [ "$key_created" = 1 ] || ! grep -qE '^AWS_ACCESS_KEY_ID=.+' .env; then
  key_info="$(docker compose exec -T garage /garage key info coe-app --show-secret)"
  key_id="$(echo "$key_info" | grep -oE 'GK[0-9a-f]{20,}' | head -n 1)"
  # Strip the key-id line before hunting for the secret: `key info` also
  # prints authorized-bucket ids, which are also 64 hex, and whichever run
  # appears first would otherwise land a bucket id in AWS_SECRET_ACCESS_KEY —
  # a 403 that reads like a configuration bug.
  key_secret="$(echo "$key_info" | grep -v 'GK[0-9a-f]' | grep -oE '[0-9a-f]{64}' | head -n 1)"

  if [ -z "$key_id" ] || [ -z "$key_secret" ]; then
    echo "ERROR: could not read Garage credentials" >&2
    exit 1
  fi

  sed -i.bak "s|^AWS_ACCESS_KEY_ID=.*|AWS_ACCESS_KEY_ID=${key_id}|" .env
  sed -i.bak "s|^AWS_SECRET_ACCESS_KEY=.*|AWS_SECRET_ACCESS_KEY=${key_secret}|" .env
  rm -f .env.bak

  grep -qE '^AWS_ACCESS_KEY_ID=GK[0-9a-f]+$' .env || {
    echo "ERROR: .env AWS_ACCESS_KEY_ID is malformed after write" >&2; exit 1; }
fi

if [ ! -f infra/nginx/certs/localhost.crt ] || [ ! -f infra/nginx/certs/localhost.key ]; then
  echo "==> Generating a self-signed TLS certificate"
  mkdir -p infra/nginx/certs
  openssl req -x509 -nodes -newkey rsa:2048 \
    -keyout infra/nginx/certs/localhost.key \
    -out infra/nginx/certs/localhost.crt \
    -days 825 \
    -subj "/CN=localhost" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"
fi

echo "==> Starting the full stack"
docker compose up -d

# docker-compose.yml maps "${APP_PORT:-8080}:80" -- read the actual value out of
# .env rather than hardcoding 8080, so the summary doesn't print a URL that
# refuses connections when the host has overridden the port.
port="$(grep -E '^APP_PORT=' .env | tail -n 1 | cut -d= -f2-)"
port="${port:-8080}"
https_port="$(grep -E '^APP_HTTPS_PORT=' .env | tail -n 1 | cut -d= -f2-)"
https_port="${https_port:-8443}"

cat <<EOF

Setup complete.

  Application   http://localhost:${port}/products
  Application   https://localhost:${https_port}/products (self-signed cert; accept the browser warning)
  API health    http://localhost:${port}/api/health
  RabbitMQ UI   http://localhost:15672 (guest/guest)
  Mailpit UI    http://localhost:8025

  Run tests     ./scripts/test.sh
  Stop          docker compose down
  Logs          docker compose logs -f

EOF
