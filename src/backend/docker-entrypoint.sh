#!/bin/sh
set -e

cd /var/www/html

# bootstrap/cache is a named volume (Task 5b), and Docker seeds a named volume
# only when it is empty. Without this, the first deploy fills it and every later
# release boots against the PREVIOUS release's config.php, routes.php,
# packages.php and services.php, silently running on stale configuration.
# packages.php in particular is written by package:discover during composer
# install below, so this must run BEFORE that install to actually clear what
# it's meant to clear rather than deleting the manifest install just wrote.
echo "[entrypoint] clearing bootstrap/cache" >&2
rm -f bootstrap/cache/*.php

if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
  echo "[entrypoint] vendor/ is missing or stale relative to composer.lock, installing" >&2
  # >&2: this is boot diagnostics, not the wrapped command's output — the same
  # reason every echo above is redirected. Composer's own console output goes
  # to stdout by default and would otherwise land in the same trap that
  # corrupted APP_KEY: a caller doing $(docker compose run ... <cmd>) to
  # capture only <cmd>'s stdout.
  composer install --no-interaction --no-progress >&2
fi

if [ -z "${APP_KEY}" ]; then
  APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
  export APP_KEY
  echo "[entrypoint] WARNING: no APP_KEY set; generated an ephemeral one." >&2
  echo "[entrypoint] Run ./setup.sh to write a stable key into .env." >&2
fi

echo "[entrypoint] waiting for database ${DB_HOST}:${DB_PORT}" >&2
attempts=0
until php -r 'new PDO("pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' 2>/dev/null; do
  attempts=$((attempts + 1))
  if [ "$attempts" -ge 30 ]; then
    echo "[entrypoint] database unreachable after 60s, giving up" >&2
    # Re-run without suppressing stderr so the actual PDO error (e.g. wrong
    # credentials) is printed instead of leaving every timeout looking like
    # a network problem.
    php -r 'new PDO("pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' || true
    exit 1
  fi
  sleep 2
done
echo "[entrypoint] database reachable" >&2

if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
  echo "[entrypoint] running migrations" >&2
  # >&2: same reasoning as the composer install above — artisan's own console
  # output goes to stdout by default and must not leak into a caller's
  # command substitution.
  php artisan migrate --force >&2
fi

if [ "${AUTO_SEED:-true}" = "true" ]; then
  php artisan app:seed-if-empty >&2
fi

echo "[entrypoint] starting: $*" >&2
exec "$@"
