# E-Commerce Order System

Centre of Excellence reference project, **Track A** (Nuxt + Laravel).
Milestone 1 — Foundation.

## Requirements

Docker with Compose v2. Nothing else — no PHP, Composer, or Node needed on
the host.

## Quick start

```bash
git clone <this-repo>
cd e-commerce-order-system
docker compose up
```

That is genuinely all of it. The containers install their own dependencies,
run migrations, and seed the catalog on first boot, so there is no prior
setup step.

Then open <http://localhost:8080/products>.

### Recommended: run setup once

`docker compose up` generates a throwaway `APP_KEY` each time it starts
without one. To pin a stable key into `.env` and get explicit progress
output:

```bash
./setup.sh          # macOS, Linux, or Git Bash on Windows
# .\setup.ps1       # Windows PowerShell
```

## What runs

| Service | Role | Exposed |
|---|---|---|
| `nginx` | Edge. Routes `/api/*` to Laravel and everything else to Nuxt | `localhost:8080` |
| `php-fpm` | Laravel API | internal only |
| `nuxt` | Nuxt SSR frontend | internal only |
| `postgres` | PostgreSQL 16 | internal only |

Only nginx publishes a port, so the whole app is one origin and needs no CORS.

## Compose files

Three files, not two:

| File | Loaded when | What it does |
|---|---|---|
| `docker-compose.yml` | Always | Base stack. Builds `nuxt` at `target: prod`, no frontend bind mount |
| `docker-compose.override.yml` | Automatically, by plain `docker compose ...` (Compose's own convention) | Dev overlay: retargets `nuxt` to `target: dev`, adds the `./src/frontend` bind mount and `frontend_node_modules` volume, sets `CHOKIDAR_USEPOLLING=true` and `APP_PORT` |
| `docker-compose.prod.yml` | Only when named explicitly with `-f` | Production overlay: `APP_ENV=production`, `NODE_ENV=production` |

Plain `docker compose up` therefore already means base **plus** override —
that combination is "dev." The production command in "Common commands"
below passes an explicit `-f docker-compose.yml -f docker-compose.prod.yml`,
which does **not** include the override file, so it builds `nuxt` from the
base file's `target: prod` instead of the override's `target: dev`. Same
service name, same image tag — see the image-tag note under "Known
limitations and gotchas."

## API

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/health` | Service and database reachability |
| GET | `/api/v1/categories` | All categories |
| GET | `/api/v1/products` | List. Supports `page`, `per_page` (max 100), `search`, `category`, `min_price`, `max_price`, `is_active`, `sort` |
| GET | `/api/v1/products/{id}` | One product |
| POST | `/api/v1/products` | Create |
| PATCH | `/api/v1/products/{id}` | Partial update |
| DELETE | `/api/v1/products/{id}` | Soft delete |

`sort` accepts `name`, `-name`, `price`, `-price`, `created_at`, `-created_at`.
A leading `-` means descending. An unrecognized value is rejected with 422
rather than ignored.

Every error, at every status code, has the same `{ "error": { "code", "message" } }`
envelope. `details` is only present on validation failures (422):

```json
{ "error": { "code": "VALIDATION_FAILED", "message": "…", "details": { "field": ["…"] } } }
```

`code` is one of:

| Code | Status | When |
|---|---|---|
| `VALIDATION_FAILED` | 422 | Request data failed validation |
| `NOT_FOUND` | 404 | Route-model binding found no matching record |
| `ROUTE_NOT_FOUND` | 404 | No route matches the URL |
| `METHOD_NOT_ALLOWED` | 405 | The route exists but not for this HTTP method |
| `HTTP_ERROR` | varies | Any other HTTP exception (its own status code, generic message) |
| `INTERNAL_ERROR` | 500 | Anything else — the message never discloses internal detail |

Prices are integer **cents** in `price_cents`, everywhere. No floats touch money.

Two behaviours in the list endpoint look like bugs and are not:

- `is_active` as a **query parameter** takes `1` or `0`. The literal strings
  `true`/`false` are **rejected with 422** — Laravel's `boolean` validation
  rule accepts `1`, `0`, `"1"`, `"0"`, and actual booleans, but not the
  words `"true"`/`"false"`.
- An unrecognized `category` slug returns **422**, not an empty list — the
  slug is validated against the category table, not silently filtered.

## Common commands

```bash
./scripts/test.sh                                     # both suites (scripts/test.ps1 on Windows PowerShell)
docker compose logs -f                                # follow all logs
docker compose exec php-fpm php artisan migrate:fresh --seed --force  # drops and re-seeds the catalog — destructive
docker compose exec php-fpm ./vendor/bin/pest         # backend only
docker compose exec nuxt npm run test                 # frontend only
docker compose down                                   # stop
docker compose down -v                                # stop and delete data
```

Production build (Nitro output instead of the dev server):

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

### After pulling changes

If a pulled change touches either Dockerfile, rebuild before starting:

```bash
docker compose up -d --build
```

Plain `docker compose up -d` does not rebuild. `storage/framework` and
`bootstrap/cache` live on named volumes layered over the image; if the image
is stale, those volumes seed from it once and then keep hiding whatever the
correct files should be — an empty, root-owned tree that is harder to
diagnose than the original stale-image problem.

## Configuration

All configuration lives in the root `.env`, created from `.env.example` by the
setup script, and reaches containers as environment variables. There is
deliberately **no** `src/backend/.env` — one source of truth avoids the two
files drifting apart.

If port 8080 is already taken on your machine, set `APP_PORT` to a free port
in `.env` before running `docker compose up` (or `./setup.sh`) — the nginx
port mapping, the frontend's HMR client port, and the setup script's own
"Application"/"API health" URLs all read this one variable, so changing it
in `.env` is enough. Overriding it as a shell-exported environment variable
instead of editing `.env` also works for Compose itself, but
`scripts/setup.sh`'s completion banner reads the port back out of the `.env`
file, not the effective variable, so the banner can print the wrong URL in
that case even though the container is actually listening on the right
port.

`AUTO_MIGRATE` and `AUTO_SEED` (both default `true`) control what the
`php-fpm` entrypoint does on container start: run `php artisan migrate
--force`, and run the guarded `php artisan app:seed-if-empty`. Set either to
`false` in `.env` to skip it. `docker-compose.prod.yml` already sets both to
`false`, so the production overlay never migrates or seeds automatically —
run those commands by hand instead:

```bash
docker compose exec php-fpm php artisan migrate --force
```

## Known limitations and gotchas

- **A cold `docker compose up` prints five `#app-manifest` pre-transform
  `ERROR` blocks in the `nuxt` container log.** This is an upstream Nuxt
  dev-server startup issue in the `nuxt@3.21.11` + `vite@7.3.6` +
  `nitro@2.13.4` combination: the `#app-manifest` alias resolves to a file
  Nitro writes asynchronously during boot, and Vite's client-entry warmup
  can pre-transform that import before the file exists. It is cosmetic,
  self-clears on a warm restart, and never affects an HTTP response — the
  app is reachable and correct while it's happening. One targeted
  mitigation (`vite.server.warmup.clientFiles: []`) was tried against a
  reproducing cold-boot procedure; it had no effect and was reverted rather
  than left in as an unproven fix. It is accepted as an upstream issue, not
  chased further. Expect it on the very first boot; it is not a sign
  anything is broken.

- **`dev` and `prod` share the same image tag, `<project>-nuxt`.** Compose
  names images from the project directory and service name, not the compose
  file, and `docker-compose.override.yml` is auto-loaded by plain
  `docker compose` commands (Compose's own convention — see "Compose files"
  below), so a bare `docker compose up` actually builds `nuxt` from
  `docker-compose.yml` **plus** the override's `target: dev` and bind mount.
  The production command below passes an explicit `-f` list that does not
  include the override, so it builds `nuxt` from `target: prod` in the base
  file instead — same service name, same resulting image tag, different
  target. Building the production overlay (`docker compose -f
  docker-compose.yml -f docker-compose.prod.yml up -d --build`) overwrites
  the cached dev image with that prod build. The next plain
  `docker compose up` will then crash-loop with `Cannot find module
  '/app/.output/server/index.mjs'` — no file is wrong, the tag just points
  at the prod target now. Fix: `docker compose build nuxt` (with no `-f`
  flags, so the override applies again) to rebuild the dev image back onto
  the tag.

- **Production is not a finished deployment.** The production overlay
  inherits the `./src/backend` source bind mount from the base compose
  file, and `src/backend/Dockerfile` does not bake application code into
  the image: remove the mount and `/var/www/html` is empty, and every
  route returns "File not found." The overlay proves the Nitro production
  build works; it is a known limitation of this milestone, not
  production-ready packaging.

- **The production image carries dev dependencies.** `docker-entrypoint.sh`
  runs `composer install` without `--no-dev`, so Pest, PHPUnit, Faker, and
  Pint ship in the production container too. This is intentional for this
  milestone, not an oversight: `DatabaseSeeder` depends on `fakerphp/faker`,
  which is a `require-dev` package, and `AUTO_SEED` needs to work even
  against the production overlay if enabled by hand. A real production
  packaging pass would split seeding out of the deployed image and add
  `--no-dev` to the install.

- **`LOG_CHANNEL` must stay `stderr`.** Only `storage/framework` and
  `bootstrap/cache` are on named volumes writable by `www-data`;
  `storage/logs` is not and never has been. An earlier fix made
  `storage/framework/views` (and its siblings) writable, curing a 500 from
  Blade being unable to compile views — `LOG_CHANNEL=daily` writes
  somewhere that fix didn't touch, so it reproduces the same *class* of
  500 (an unwritable storage path) for a path that was never made
  writable.

- **`DatabaseSeeder` is not idempotent.** It's designed to run under
  `migrate:fresh --seed`. Running `php artisan db:seed` standalone appends
  a second 6 categories and 50 products on top of whatever is already
  there. Use the entrypoint's guarded `php artisan app:seed-if-empty`
  (what `scripts/setup.sh` uses) when you want a safe, repeatable seed.

- **`POSTGRES_DB` and `DB_DATABASE` carry the same default independently.**
  They are two separate environment variables that happen to default to
  the same value. Overriding only one in `.env` silently points Laravel at
  a database Postgres never created.

- **`MSYS_NO_PATHCONV=1`** is needed on Git Bash (Windows) before any
  `docker`/`docker compose` command that passes an absolute container path,
  e.g. `-v "$(pwd)/src:/app"` — otherwise Git Bash rewrites the container
  side of the path as if it were a Windows path.

- **Run `./scripts/test.sh` (or `./setup.sh`) at least once before trusting
  a bare `docker compose up` for testing.** With no `APP_KEY` in `.env`, the
  entrypoint generates an ephemeral key for the running `php-fpm` process,
  but a separate `docker compose exec` session (which is how both
  `scripts/test.sh` and `scripts/test.ps1` reach the container) does not
  inherit it — it sees the empty `APP_KEY` from `.env`/Compose instead. The
  API itself is unaffected (`/api/*` routes are stateless and never touch
  Laravel's session encryption, and `/` and `/products` are served by
  Nuxt), but `Tests\Feature\ExampleTest` — Laravel's own scaffolded test
  for `GET /`, which does go through the session-encrypting `web`
  middleware — fails with `MissingAppKeyException` in that state. Running
  `./setup.sh` first writes a real key into `.env` before the stack
  (re)starts, so `docker compose exec` sessions see it too, and this does
  not happen.

## Layout

```
docker-compose.yml           base stack (see "Compose files")
docker-compose.override.yml  dev overlay, auto-loaded with the base file
docker-compose.prod.yml      production overlay, loaded explicitly with -f
infra/nginx/                 edge routing
infra/postgres/init/         test database creation
scripts/                     setup and test entry points (.sh and .ps1)
src/backend/                 Laravel API
src/frontend/                Nuxt frontend
```

`docs/adr/` (architecture decision records) exists locally during
development but is gitignored and deliberately not part of the delivered
repo — a fresh clone will not have it.
