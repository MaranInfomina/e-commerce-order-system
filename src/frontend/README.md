# Frontend — Nuxt SSR

Nuxt 3.21.11 running as a Nitro SSR server on internal port 3000, reached
through the root nginx edge.

Nothing here runs on the host — no `npm install`, no local Node. Every
command below goes through the container.

## Running commands

```bash
docker compose exec nuxt npm run test
docker compose exec nuxt npm install <package>
docker compose logs -f nuxt
```

## Structure

| Path | Responsibility |
|---|---|
| `utils/api.ts` | Pure functions and types. No Nuxt imports, so directly unit-testable |
| `composables/useProductsApi.ts` | The only module that performs a request |
| `components/` | Presentational components — props in, events out, no Nuxt composables |
| `pages/products/index.vue` | List page. Reads and writes list state via the URL query |
| `pages/products/new.vue` | Create page |

Components take props and emit events only. That is deliberate: it keeps them
testable with plain Vue Test Utils, with no Nuxt test runtime to configure.

## The SSR base URL rule

The API is reached through two different URLs, and using the wrong one is the
most common failure in this setup:

| Context | Base URL | Why |
|---|---|---|
| Browser | `/api/v1` | Same origin through nginx, so no CORS |
| Server-side render | `http://nginx/api/v1` | A relative URL has no host inside the container |

`resolveApiBase()` in `utils/api.ts` picks between them from
`import.meta.server`. Both values are set in `nuxt.config.ts` under
`runtimeConfig`.

## List state lives in the URL

Search, category, sort, and page are query parameters, not component state.
URLs are shareable, browser back and forward work, and no state library is
needed. Changing a filter resets to page 1.

## HMR through the proxy

The browser reaches the app on port 8080, but the dev server listens on 3000.
`vite.server.hmr.clientPort` in `nuxt.config.ts` tells the HMR client where to
connect. If hot reload stops working, that value and `APP_PORT` have diverged.

`CHOKIDAR_USEPOLLING=true` is set in `docker-compose.yml` because file change
events do not propagate reliably across a Windows bind mount without it.

## Cold-boot console noise

A fresh `docker compose up` prints five `#app-manifest` pre-transform
`ERROR` blocks in this container's log during the first startup. This is an
upstream Nuxt dev-server issue, not a defect in this project — it is
cosmetic, clears itself on a warm restart, and never shows up in an actual
HTTP response. Expect it on the very first boot.

## Testing

```bash
docker compose exec nuxt npm run test
```

Vitest with happy-dom and Vue Test Utils. Browser-level end-to-end tests are
deliberately deferred to Milestone 4, alongside the CI pipeline.
