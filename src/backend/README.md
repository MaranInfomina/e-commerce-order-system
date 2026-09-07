# Backend — Laravel API

Laravel 13.29.0 on PHP 8.4, served by php-fpm behind the root nginx edge.

## Running commands

Nothing here runs on the host. Every command goes through the container:

```bash
docker compose exec php-fpm php artisan route:list --path=api
docker compose exec php-fpm ./vendor/bin/pest
docker compose exec php-fpm composer require <package>
```

## Structure

All paths below are relative to `src/backend/`:

| Path | Responsibility |
|---|---|
| `routes/api.php` | The complete API surface |
| `app/Exceptions/ApiExceptionRenderer.php` | The only place error shape is decided |
| `app/Queries/ProductListQuery.php` | Search, filter, sort. `SORTS` is the single source of truth for allowed sort keys |
| `app/Http/Requests/` | Input validation |
| `app/Http/Resources/` | Response serialization |
| `app/Models/` | Eloquent models |

## Configuration

Config comes from container environment variables injected by Compose from the
root `.env`. **Do not create a `.env` file here** — it would shadow those
values and drift out of sync.

`LOG_CHANNEL` must stay `stderr`. Only `storage/framework` and
`bootstrap/cache` are mounted as named volumes writable by `www-data`;
`storage/logs` is not writable in the container, and never has been.
Setting `LOG_CHANNEL=daily` writes there and reproduces a 500 — the same
*class* of failure (an unwritable storage path) that an earlier fix cured
for `storage/framework/views`, but this exact path was never made
writable.

`AUTH_GUARD` (`config/auth.php`) is a code-default-only setting: it exists in
neither `.env.example` nor either compose file, and defaults to `api`. Every
route that needs authentication names `auth:api` explicitly, so overriding
this variable does not change what is protected — it only changes what
`Auth::user()` resolves to outside an explicit guard, which nothing in this
milestone relies on. Documented here so it is not mistaken for a switch
worth setting.

## Testing

Pest, running against a separate database created by
`infra/postgres/init/01-create-test-db.sh`, whose name is resolved from the
`DB_TEST_DATABASE` environment variable at test bootstrap time (see
`tests/bootstrap.php`) — `coe_orders_test` is only the default, not a fixed
name. Tests use real PostgreSQL rather than SQLite so that check constraints
and `ILIKE` behave exactly as in production.

```bash
docker compose exec php-fpm ./vendor/bin/pest
docker compose exec php-fpm ./vendor/bin/pest --filter=health
```

## Authentication

`app/Services/TokenService.php` is the only place `firebase/php-jwt` is
touched: it issues HS256 tokens (`sub`, `role`, `jti`, `iat`, `exp`) from
`JWT_SECRET`/`JWT_TTL` (`config/jwt.php`), and refuses to construct with a
key under 32 bytes so a misconfigured secret fails at boot rather than
presenting as "every token is invalid" at request time. `app/Auth/JwtGuard.php`
is the `api` guard registered in `config/auth.php`: it reads the
`Authorization: Bearer` header, verifies the signature via `TokenService`,
then checks `app/Services/TokenDenylist.php` for the token's `jti` before
resolving the `User` row from the database. Authorization is a separate
step — `app/Policies/ProductPolicy.php` reads `$user->isAdmin()` off that
database row, never the token's `role` claim, so a demotion takes effect on
the user's very next request instead of waiting for the token to expire.

`TokenDenylist` and `app/Repositories/CartRepository.php` (all cart Redis
access; controllers never touch Redis directly) both live on the `default`
Redis connection — logical database 1, `REDIS_DB` — which is never flushed
wholesale, unlike the `cache` connection. Both classes expose a static
`connectionName(bool $testing)` specifically so the production arm (`default`)
is assertable in a test and cannot silently drift onto `cache` while the
suite stays green.

## Redis layout

Six logical databases, defined in `config/database.php`: three the running
app uses, and three the test suite mirrors them onto so no test can ever
touch a developer's live data.

| DB | `.env` variable | Holds | Test mirror |
|---|---|---|---|
| 0 | `REDIS_CACHE_DB` | Cached `product:{id}` and `categories:all` reads. Safe to flush. | 14 |
| 1 | `REDIS_DB` (`default` connection) | Carts (`cart:user:{id}`) and the JWT denylist (`denylist:{jti}`). Never flushed wholesale. | 15 (`REDIS_TEST_DB`) |
| 2 | `REDIS_SESSION_DB` | Sessions (`session` connection). | 13 |

`tests/Pest.php` flushes all three test indexes between every test.

**Carts have no TTL.** `CartRepository` never calls `expire` on `cart:user:{id}`
— a cart lives in Redis forever until the user clears it or removes every
line. Acceptable for this milestone's scale; a real deployment would want a
sliding expiry.

**Every key is written with a `coe_` prefix**, applied at the phpredis client
level (`config/database.php`'s `options.prefix`), not by Laravel's cache
store. So a key you write as `product:1` lands in Redis as `coe_product:1`,
and every `redis-cli` command in this document needs that prefix or it finds
nothing:

```bash
docker compose exec redis redis-cli -n 0 --scan --pattern 'coe_product:*'
docker compose exec redis redis-cli -n 1 --scan --pattern 'coe_cart:*'
```

**`CACHE_PREFIX` is deliberately empty** (`.env.example`, `docker-compose.yml`).
Laravel's own cache store would otherwise prepend a second prefix — by
default `Str::slug(config('app.name')).'_cache_'` — on top of the `coe_` one
above, so `product:1` would become `coe_laravel_cache_product:1` and every
documented `redis-cli` command would silently return nothing.

## Cache invalidation

Only a single product (`product:{id}`) and the category list
(`categories:all`) are cached — never list queries, which vary by search,
filter, sort and page. `app/Observers/ProductObserver.php` busts
`product:{id}` on `saved`, `deleted`, and `restored`; `app/Observers/CategoryObserver.php`
busts `categories:all` on the same events, and additionally busts
`product:{id}` for every product in a category whose `name` or `slug`
actually changed — `ProductResource` embeds the category's name and slug
inside every cached product, so a category rename has two invalidation
triggers, not one. Both observers set `$afterCommit = true` so a bust can
never race a still-in-flight transaction, even though nothing in this
milestone currently wraps a write in one.

**`config/cache.php`'s `serializable_classes` is an explicit allow-list, not
`true`.** Laravel refuses by default to unserialize any class out of the
cache (a gadget-chain hardening measure), which silently breaks caching a
model or a collection: the value comes back as `__PHP_Incomplete_Class` —
not `null` — so a naive `Cache::has()` check reports a hit while every real
read of the object fails. Only the three classes this milestone actually
caches are listed: `Product`, `Category`, and Eloquent's `Collection`. Add a
class here before caching a fourth one, or it will fail the same way.

## Storage disk

`config/filesystems.php`'s `s3` disk points at Garage (`AWS_ENDPOINT`,
path-style addressing) rather than real AWS. `AWS_URL` is a *second*,
separate address — the one a browser uses to fetch the image back — proxied
through nginx to Garage's web endpoint; it is never the same value as
`AWS_ENDPOINT`, which only resolves inside the compose network. The bucket
and access key are provisioned by `scripts/setup.sh` / `scripts/setup.ps1`
(this container has no `garage` CLI to do it itself); the entrypoint only
logs whether object storage looks configured.

## Schema notes

- **Money is `price_cents`, an integer.** Milestone 3 stores a pricing snapshot on each order, and integers make rounding error impossible. Formatting is the frontend's job.
- **`stock_quantity` has a database check constraint** (`products_stock_quantity_non_negative`) as well as request validation. Milestone 3's concurrency-safe decrement will rely on it.
- **Products are soft-deleted.** Milestone 3 adds orders that reference products; a hard delete would break order history.

## Known limitation: search

`search` uses `ILIKE '%term%'` across name and description. No btree index can
serve a leading-wildcard match, so this is a sequential scan and will degrade
as the catalog grows. Acceptable at Milestone 1 volumes.

The upgrade path when it matters: enable the `pg_trgm` extension and add a GIN
trigram index on the searched columns, or move to a `tsvector` column with a
GIN index for full-text search.

## Seeding

`DatabaseSeeder` is not idempotent — it's built for `migrate:fresh --seed`
and creates rows via factories every time it runs. Running
`php artisan db:seed` on its own appends a second 6 categories and 50
products on top of whatever already exists. The cold-start entrypoint uses
the guarded `php artisan app:seed-if-empty` instead, which only seeds when
the `products` table is empty; prefer that command over `db:seed` when
you need a safe, repeatable seed from the shell.

## Environment variable pitfall

`POSTGRES_DB` (read by the `postgres` container) and `DB_DATABASE` (read by
Laravel) both default to `coe_orders` independently — they are not the same
variable. Overriding only one in `.env` silently points Laravel at a
database Postgres was never told to create.

## Two API behaviours that look like bugs and are not

- `is_active` as a query parameter takes `1` or `0`. The literal strings
  `true`/`false` are rejected with 422 (Laravel's `boolean` validation rule
  accepts `1`, `0`, `"1"`, `"0"`, and actual booleans, but not the words
  `"true"`/`"false"`).
- An unrecognized `category` slug returns **422**, not an empty list.
