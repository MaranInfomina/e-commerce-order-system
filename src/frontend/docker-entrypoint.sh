#!/bin/sh
set -e

cd /app

if [ ! -d node_modules ] || [ -z "$(ls -A node_modules 2>/dev/null)" ]; then
  echo "[entrypoint] node_modules is empty, installing dependencies"
  if [ -f package-lock.json ]; then
    npm ci
  else
    npm install
  fi
fi

echo "[entrypoint] starting: $*"
exec "$@"
