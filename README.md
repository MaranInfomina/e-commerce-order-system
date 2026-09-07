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
| `redis` | Redis 7. Cart, cache, sessions, and the JWT denylist | internal only |
| `garage` | Garage v1, S3-compatible object storage for product images | internal only |

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

| Method | Path | Purpose | Auth |
|---|---|---|---|
| GET | `/api/health` | Service and database reachability | Public |
| GET | `/api/v1/categories` | All categories | Public |
| GET | `/api/v1/products` | List. Supports `page`, `per_page` (max 100), `search`, `category`, `min_price`, `max_price`, `is_active`, `sort` | Public |
| GET | `/api/v1/products/{id}` | One product | Public |
| POST | `/api/v1/products` | Create | Admin |
| PATCH | `/api/v1/products/{id}` | Partial update | Admin |
| DELETE | `/api/v1/products/{id}` | Soft delete | Admin |
| POST | `/api/v1/products/{id}/image` | Upload a product image | Admin |
| DELETE | `/api/v1/products/{id}/image` | Remove a product's image | Admin |
| POST | `/api/v1/auth/register` | Create an account | Public, throttled 5/min/IP |
| POST | `/api/v1/auth/login` | Log in; returns a JWT | Public, throttled 5/min/IP |
| POST | `/api/v1/auth/logout` | Revoke the caller's current token | Authenticated |
| GET | `/api/v1/auth/me` | The authenticated user | Authenticated |
| PATCH | `/api/v1/auth/me` | Update the authenticated user's profile | Authenticated |
| GET | `/api/v1/cart` | The caller's own cart | Authenticated |
| POST | `/api/v1/cart/items` | Add an item to the cart | Authenticated |
| PATCH | `/api/v1/cart/items/{productId}` | Change a line's quantity | Authenticated |
| DELETE | `/api/v1/cart/items/{productId}` | Remove a line | Authenticated |
| DELETE | `/api/v1/cart` | Clear the cart | Authenticated |

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
| `UNAUTHENTICATED` | 401 | No token, or the token does not verify (missing, malformed, expired, wrong signature, or revoked) |
| `FORBIDDEN` | 403 | A verified token belonging to a `customer` hit an admin-only endpoint |
| `TOO_MANY_REQUESTS` | 429 | `POST /api/v1/auth/register` or `POST /api/v1/auth/login` exceeded 5 requests/minute for that IP on that endpoint |

Prices are integer **cents** in `price_cents`, everywhere. No floats touch money.

Two behaviours in the list endpoint look like bugs and are not:

- `is_active` as a **query parameter** takes `1` or `0`. The literal strings
  `true`/`false` are **rejected with 422** — Laravel's `boolean` validation
  rule accepts `1`, `0`, `"1"`, `"0"`, and actual booleans, but not the
  words `"true"`/`"false"`.
- An unrecognized `category` slug returns **422**, not an empty list — the
  slug is validated against the category table, not silently filtered.

## Authentication

Two roles: `customer` and `admin`. `POST /api/v1/auth/register` and
`POST /api/v1/auth/login` are the only public write endpoints; `login`
returns a signed JWT (`Bearer <token>`) with a one-hour default lifetime
(`JWT_TTL`). There is no refresh — once a token expires, the user logs in
again.

`POST /api/v1/auth/logout` revokes the caller's token immediately by adding
its `jti` to a Redis denylist, rather than waiting for it to expire on its
own. Every authenticated request checks that denylist, so a logged-out
token stops working right away even though its signature is still valid.

Reads (`GET` on `/api/v1/products*` and `/api/v1/categories`) are public.
Writes — creating, updating, or deleting a product, and uploading or
removing its image — require a valid token belonging to an `admin` user:
no token gets `401 UNAUTHENTICATED`, a valid `customer` token gets
`403 FORBIDDEN`. The cart endpoints require only a valid token of either
role — every user manages their own cart, identified by the token's
subject, never by a request parameter.

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

`JWT_SECRET` signs and verifies every token; leave it empty for a clean
clone and the entrypoint generates an ephemeral one and warns (every restart
then invalidates all issued tokens). `./setup.sh` / `./setup.ps1` writes a
stable one on first run, and `docker-compose.prod.yml` refuses to start
without a real value. `JWT_TTL` (default `3600`) is the token lifetime in
seconds.

`REDIS_HOST` and `REDIS_PORT` point at the `redis` container. Three logical
databases split what lives in Redis so that flushing one can never destroy
another: `REDIS_CACHE_DB` (default `0`, product/category cache, safe to
flush), `REDIS_DB` (default `1`, carts and the JWT denylist, never flushed
wholesale), and `REDIS_SESSION_DB` (default `2`, sessions). `REDIS_TEST_DB`
(default `15`) is the carts/denylist index the test suite uses instead —
`src/backend/tests/Pest.php` flushes it, and phpunit.xml forces the
cache/session tests onto their own separate indexes, so the suite can never
touch a developer's live cart or cache data.

`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`,
`AWS_BUCKET`, `AWS_ENDPOINT`, and `AWS_USE_PATH_STYLE_ENDPOINT` configure the
`s3` filesystem disk against Garage. `AWS_ENDPOINT` is the compose-internal
address Laravel uses to talk to Garage; `AWS_URL` is the address a
**browser** uses to fetch an image and must be reachable from outside the
compose network (the default, `http://localhost:8080/storage`, is proxied
to Garage's web endpoint by `infra/nginx/default.conf`) — leaving it unset
would publish the internal `http://garage:3900/...` address in every
product response instead. `./setup.sh` / `./setup.ps1` provisions the
bucket and an access key on first run and writes the two `AWS_*` credential
variables into `.env`; the entrypoint only reports whether object storage
is configured; it does not provision it.

`GARAGE_RPC_SECRET` is the Garage cluster's own RPC credential, unrelated to
the `AWS_*` application credentials above. `docker-compose.yml` carries an
obvious published throwaway default so a clean clone still boots (CR-3);
`./setup.sh` / `./setup.ps1` replaces it with a random value on first run,
and `docker-compose.prod.yml` refuses to start without a real one. Changing
it after Garage has initialized requires `docker compose down -v` for the
`garage` service, because the node identity is derived from it.

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

- **The auth guard fails closed when Redis is down.** A denylist that failed
  open would silently re-validate every logged-out token during an outage,
  so the guard lets the Redis error propagate rather than treating an
  unreachable denylist as "not revoked". Redis is a hard dependency for
  authenticated routes. Note the failure surfaces as a **5xx, not a 401** —
  the exception is not caught and mapped, so clients should treat it as
  "retry later", not "log in again". Nothing in the suite stops Redis and
  asserts this; it is reasoned from the code, not observed.
- **Restarting php-fpm without a `JWT_SECRET` in `.env` invalidates every
  issued token.** The entrypoint generates an ephemeral secret and warns.
  `./setup.sh` writes a stable one, and `docker-compose.prod.yml` refuses to
  start without one — an ephemeral key would also differ per replica,
  401ing roughly half of all requests behind a load balancer.
- **A password change does not invalidate existing tokens, and does not ask
  for the current password.** `PATCH /auth/me` accepts a new password from
  anyone holding a valid token. Revocation is per-`jti` only: there is no
  `token_version` column and no "log out everywhere". So a stolen token
  survives the victim changing their password, for up to the remaining hour
  of its lifetime, and an attacker holding one can change the password
  themselves. Closing this needs a `current_password` rule plus user-level
  revocation, and is deliberately deferred — it is a change to the user
  model and the denylist design, not a bug in what this milestone built.
- **There is no token refresh.** Tokens last one hour and then stop working
  mid-session; the user logs in again. Adding refresh without also adding
  the revocation above would widen the stolen-token window, so the two are
  deferred together.
- **The bearer token is stored in a JavaScript-readable cookie.** It cannot
  be `httpOnly`, because the frontend reads it to build the `Authorization`
  header, so any XSS yields a usable token. `secure` is also hardcoded
  `false` for local HTTP development and must be switched before any
  deployment that is not localhost.
- **The cart holds no prices.** Lines store only a product and a quantity;
  pricing is resolved on read. A cart is not a quote, and Milestone 3 takes
  the pricing snapshot at order time.
- **Only single products and the category list are cached**, not list
  queries. List responses vary by search, filter, sort and page, so caching
  them would mean unbounded keys and an invalidation path that degrades to
  flushing everything.
- **Garage credentials are per-volume.** `docker compose down -v` destroys
  them, and `./setup.sh` must run again to recreate the bucket and key.

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
