#!/bin/sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
  echo "[entrypoint] vendor/ is missing or stale relative to composer.lock, installing"
  composer install --no-interaction --no-progress
fi

# bootstrap/cache is a named volume (Task 5b), and Docker seeds a named volume
# only when it is empty. Without this, the first deploy fills it and every later
# release boots against the PREVIOUS release's config.php, routes.php,
# packages.php and services.php, silently running on stale configuration.
# packages.php in particular is written by package:discover during composer
# install, so a skipped install leaves the discovery manifest stale too.
rm -f bootstrap/cache/*.php

if [ -z "${APP_KEY}" ]; then
  APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
  export APP_KEY
  echo "[entrypoint] WARNING: no APP_KEY set; generated an ephemeral one."
  echo "[entrypoint] Run ./setup.sh to write a stable key into .env."
fi

echo "[entrypoint] waiting for database ${DB_HOST}:${DB_PORT}"
attempts=0
until php -r 'new PDO("pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' 2>/dev/null; do
  attempts=$((attempts + 1))
  if [ "$attempts" -ge 30 ]; then
    echo "[entrypoint] database unreachable after 60s, giving up"
    exit 1
  fi
  sleep 2
done
echo "[entrypoint] database reachable"

if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
  echo "[entrypoint] running migrations"
  php artisan migrate --force
fi

if [ "${AUTO_SEED:-true}" = "true" ]; then
  php artisan app:seed-if-empty
fi

echo "[entrypoint] starting: $*"
exec "$@"
