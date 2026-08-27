#!/bin/sh
set -e

cd /app

# node_modules is a named volume (frontend_node_modules) with the same
# seed-only-when-empty behaviour as backend_vendor, and the dev image bakes
# no node_modules to seed it from. The directory-empty checks below cover a
# cold volume; the -nt check covers a package-lock.json that changed after
# the volume was already populated. The empty-directory checks must stay
# first in this || chain: busybox `[ a -nt b ]` is false when b does not
# exist, so on a truly empty volume the -nt clause alone would never fire.
if [ ! -d node_modules ] || [ -z "$(ls -A node_modules 2>/dev/null)" ] || [ package-lock.json -nt node_modules/.package-lock.json ]; then
  echo "[entrypoint] node_modules is missing or stale relative to package-lock.json, installing" >&2
  # >&2: same reasoning as the echo above — npm's own console output goes to
  # stdout by default and must not leak into a caller's command substitution.
  if [ -f package-lock.json ]; then
    npm ci >&2
  else
    npm install >&2
  fi
fi

echo "[entrypoint] starting: $*" >&2
exec "$@"
