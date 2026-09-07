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

## Authentication

The JWT is kept in a cookie (`coe_token`, via `useCookie` in
`composables/useAuth.ts`), not `localStorage`. This is an SSR requirement,
not a stylistic choice: the server needs to read the token to render an
authenticated page (e.g. deciding whether to show the "create product"
control) on its very first response, and `localStorage` does not exist on
the server. The cookie is `sameSite: 'lax'` and deliberately **not**
`httpOnly` — the client-side code that builds the `Authorization` header has
to be able to read it — so an XSS on this app can steal it exactly as it
could from `localStorage`; this cookie is not being used as a CSRF-style
authenticator (`JwtGuard` only ever reads the `Authorization` header, never
a cookie), so there is no cookie-CSRF surface to protect, but there is also
no additional protection against token theft via XSS. `secure` is hardcoded
`false` for local HTTP development and must change before any non-localhost
deployment.

`useAuth()` returns `{ token, isAuthenticated, isAdmin, login, logout }`.
`isAuthenticated` and `isAdmin` are computed from the cookie:
`isAdmin` decodes the token's `role` claim client-side
(`decodeJwtRole` in `utils/api.ts`) purely to decide **what to render** —
`login`/`logout` call `POST /auth/login` and `POST /auth/logout`. Gating a
control on `isAdmin` (e.g. `pages/products/index.vue`'s create button, or
`pages/products/new.vue` redirecting an unauthenticated visitor to
`/login`) is a rendering decision only: every write endpoint is
independently authorized server-side against the database row via
`ProductPolicy`, so a forged or stale claim never grants real access — it
can only mis-render a button that a 401/403 from the API would immediately
correct.

## The SSR base URL rule

The API is reached through two different URLs, and using the wrong one is the
most common failure in this setup:

| Context | Base URL | Why |
|---|---|---|
| Browser | `/api/v1` | Same origin through nginx, so no CORS |
| Server-side render | `http://nginx/api/v1` | A relative URL has no host inside the container |

`resolveApiBase(isServer, config)` in `utils/api.ts` is a pure function that
picks between them given a boolean — it has no Nuxt imports itself.
`composables/useProductsApi.ts` is the only caller, and passes
`import.meta.server` as `isServer`. Both base-URL values are set in
`nuxt.config.ts` under `runtimeConfig`.

## List state lives in the URL

Search, category, sort, and page are query parameters, not component state.
URLs are shareable, browser back and forward work, and no state library is
needed. Changing a filter resets to page 1.

## HMR through the proxy

The browser reaches the app on port 8080, but the dev server listens on 3000.
`vite.server.hmr.clientPort` in `nuxt.config.ts` reads `process.env.APP_PORT`
(falling back to 8080), so it tracks `APP_PORT` automatically — there is
nothing to keep in sync by hand. The actual failure mode is `APP_PORT` not
reaching the `nuxt` container at all: `docker-compose.override.yml` forwards
it via `environment: APP_PORT: ${APP_PORT:-8080}`, so if `.env` was edited
but the `nuxt` container was never restarted, the running container still
has the old value baked into its environment and the browser tries to
connect to the wrong port. `docker compose up -d` after changing `.env` is
enough to fix it.

`CHOKIDAR_USEPOLLING=true` is set in `docker-compose.override.yml` (not the
base `docker-compose.yml`) because file change events do not propagate
reliably across a Windows bind mount without it.

## Cold-boot console noise

A fresh `docker compose up` prints five `#app-manifest` pre-transform
`ERROR` blocks in this container's log during the first startup. This is an
upstream Nuxt dev-server issue (a boot-order race between Nitro writing the
`#app-manifest` file and Vite's client warmup pre-transforming the import
that references it), not a defect in this project — it is cosmetic, clears
itself on a warm restart, and never shows up in an actual HTTP response.
One mitigation (`vite.server.warmup.clientFiles: []`) was tried and had no
effect; it was reverted rather than kept as an unproven fix, and the issue
is accepted as upstream. Expect it on the very first boot.

## Testing

```bash
docker compose exec nuxt npm run test
```

Vitest with happy-dom and Vue Test Utils. Browser-level end-to-end tests are
deliberately deferred to Milestone 4, alongside the CI pipeline.
