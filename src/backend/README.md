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

| Path | Responsibility |
|---|---|
| `routes/api.php` | The complete API surface |
| `Exceptions/ApiExceptionRenderer.php` | The only place error shape is decided |
| `Queries/ProductListQuery.php` | Search, filter, sort. `SORTS` is the single source of truth for allowed sort keys |
| `Http/Requests/` | Input validation |
| `Http/Resources/` | Response serialization |
| `Models/` | Eloquent models |

## Configuration

Config comes from container environment variables injected by Compose from the
root `.env`. **Do not create a `.env` file here** — it would shadow those
values and drift out of sync.

`LOG_CHANNEL` must stay `stderr`. Only `storage/framework` and
`bootstrap/cache` are mounted as named volumes writable by `www-data`;
`storage/logs` is not writable in the container. Setting `LOG_CHANNEL=daily`
reproduces a 500 error that Task 5b already fixed once — writes to
`storage/logs` fail.

## Testing

Pest, running against the separate `coe_orders_test` database created by
`infra/postgres/init/01-create-test-db.sh`. Tests use real PostgreSQL rather
than SQLite so that check constraints and `ILIKE` behave exactly as in
production.

```bash
docker compose exec php-fpm ./vendor/bin/pest
docker compose exec php-fpm ./vendor/bin/pest --filter=health
```

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

- `is_active` as a query parameter takes `1` or `0`, not `true`/`false`
  (Laravel's `boolean` validation rule).
- An unrecognized `category` slug returns **422**, not an empty list.
