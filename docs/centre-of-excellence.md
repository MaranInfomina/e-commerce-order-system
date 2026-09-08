# Centre of Excellence

This is the Centre of Excellence learning track. You'll build a full-stack e-commerce order system - frontend, backend API, and the infrastructure around it - picking up the tools and habits that make you well-rounded rather than just feature-complete. The more developers who can work across this stack, the more people we can staff on projects that need it, so completing this track directly expands who's available for that kind of work.

The track is milestone-based and self-paced. It assumes you're already comfortable with the fundamentals (containers, REST APIs, basic SQL); working solo or with a partner, you'll hit four required milestones at your own pace, picking up frontend UI, backend API, PostgreSQL, Redis, object storage, a message queue, JWT auth and RBAC, Docker, CI/CD, logs/metrics, tests, and API docs along the way.

**Reference project:** a simple e-commerce order system - product catalog, cart, checkout, order status. You'll register/log in, browse the catalog, add items to a cart, place an order, have it processed asynchronously, get a simulated payment confirmed, and watch the order status update through its lifecycle. It's small enough to build solo but touches every deliverable above, including a natural "async order handling" use case for the message queue.

Some of what you'll build here is deliberately simplified for training and isn't meant to carry over into client work: the mock payment step (Milestone 3), dev-grade TLS used to satisfy the Milestone 4 HTTPS requirement, and throwaway credentials pointed at Garage, the lightweight self-hosted S3-compatible object store you'll use for object storage (Milestone 2). On regulated client work, each of those needs the real, production-grade equivalent.

## Track Options

Pick one. You'll build the identical reference project against the identical milestones - only the framework-specific "how" differs, so you can still compare notes with someone on the other track at each checkpoint.

- **Track A: Nuxt.js + Laravel** - a Vue-based frontend paired with a PHP backend.
- **Track B: Angular + Spring Boot** - a TypeScript-based frontend paired with a Java backend.

Both tracks converge on the same underlying capabilities: PostgreSQL, Redis, object storage, a message queue, JWT auth, Docker, CI/CD, logging/metrics, tests, and OpenAPI docs.

## Core Functional Features

The reference project breaks down into these features, each landing at a specific milestone:

1. **Product Catalog** (Milestone 1) - list/view, search/filter/pagination, inventory tracking
2. **User Management** (Milestone 2) - registration & login, profile management, role-based access
3. **Shopping Cart** (Milestone 2) - add/remove items, update quantity, persist cart
4. **File Upload / Object Storage** (Milestone 2) - product images stored in object storage
5. **Order Creation** (Milestone 3) - convert cart to order, unique order ID, pricing snapshot
6. **Payment Simulation** (Milestone 3) - mock processing, record status
7. **Order Management** (Milestone 3) - order history, detail view, status lifecycle

## Repository Structure

You'll build everything in a single repo, not three - frontend, backend, and infra are components of one product and live together:

- `src/frontend/` - the Nuxt or Angular application.
- `src/backend/` - the Laravel or Spring Boot application.
- Infra config at the repo root - `docker-compose.yml`, CI configuration, and (from Milestone 5 onward) the gateway/load-balancer config. It builds the frontend and backend from their sibling `src/` folders and wires them together with the infra services below.
- `docs/adr/` - architecture decision records, including the Milestone 5 ADR.

PostgreSQL, Redis, and object storage (Garage) aren't codebases you write - each runs as its own standalone service container (official images), configured but not customized, defined in the repo's `docker-compose.yml`.

You'll run this whole stack locally on your own laptop/workstation. By Milestone 4 you're running Postgres, Redis, RabbitMQ, ELK/Loki, Grafana, and Prometheus alongside Garage and your frontend/backend containers, so make sure you've got enough RAM and disk headroom (16GB+ RAM recommended) before you get there.

## Getting Started

1. Tell your team lead or supervisor you're starting the track and which track (A or B) you've picked - they're also your reviewer, so they should know from day one.
2. Create one repo on the team's GitLab, named `coe-<yourname>` (or your pair's shared name if you're working with a partner), laid out with `src/frontend/`, `src/backend/`, and infra config at the root as described above.
3. Start Milestone 1. If you're pairing with someone on the other track, agree on your first checkpoint now.

If you get stuck, ask - the point of a shared track is that someone else has probably hit the same wall. Raise it with your track partner, the team channel, or your reviewer, in that order, and don't sit on a blocker for more than a day or two.

## Milestone 1: Foundation

**Capability:** a single repo with frontend, backend, and infra components that come together into a dockerized skeleton with a basic product CRUD API your frontend can list and create against, backed by a properly modeled schema and a clean API surface.

**Covers:** Frontend UI, Backend API, PostgreSQL, Dockerized services, Data Modeling & Schema Design, API Design Quality, Developer Experience.

- **Track A:** `src/frontend` with a Nuxt 3 scaffold; `src/backend` with Laravel + Eloquent models/migrations; root-level `docker-compose.yml` running php-fpm/nginx (built from `src/frontend` and `src/backend`) + a standalone postgres service.
- **Track B:** `src/frontend` with an Angular CLI scaffold; `src/backend` with Spring Boot + Spring Data JPA entities/repositories; root-level `docker-compose.yml` running an nginx container serving the Angular build (built from `src/frontend`), the Spring Boot jar (built from `src/backend`), + a standalone postgres service.

**Done when:**

- [ ] You can clone the repo and start everything with a single `docker-compose up` from the repo root.
- [ ] CRUD endpoints for products work end-to-end.
- [ ] Your frontend can list and create products against the API.
- [ ] Your schema is normalized with sensible indexes and relationships (e.g. products, categories, orders), including a stock/inventory quantity on each product.
- [ ] Your list endpoints support pagination, filtering, search, and sorting, and errors return a consistent shape (not framework-default stack traces). (REST is the default here; GraphQL is a valid alternative if you want to explore it instead.)
- [ ] The repo has a README (with per-component notes for `src/frontend` and `src/backend`), and a setup script at the root brings up the whole stack without tribal knowledge.

**References:**

- Docker: https://www.freecodecamp.org/news/docker-full-course/
- Track A frontend: https://www.freecodecamp.org/news/learn-vuejs-in-this-beginners-course/ , https://www.freecodecamp.org/news/nuxt-3-course-for-beginners/ , https://learn.nuxt.com/en
- Track A backend: https://www.freecodecamp.org/news/learn-laravel-by-building-a-medium-clone/ , https://laravel.com/learn/getting-started-with-laravel
- Track B frontend: https://www.freecodecamp.org/news/learn-angular-full-course/ , https://tutorials.angular.courses/
- Track B backend: https://www.freecodecamp.org/news/learn-app-development-with-spring-boot-3/

## Milestone 2: Identity & State

**Capability:** JWT auth with role-based access control (registration/login/profile/protected routes), a Redis-backed shopping cart, cache/session with a real invalidation strategy, and object storage for product image uploads.

**Covers:** Auth (JWT login), Role-Based Access Control, Redis (cache/session), Caching Strategy, Shopping Cart, Object storage (file uploads).

- **Track A:** Laravel Sanctum/JWT package for auth plus policy/gate-based RBAC (customer vs admin); cart stored in Redis keyed by user/session; Laravel's cache facade backed by Redis; `flysystem-aws-s3-v3` pointed at Garage.
- **Track B:** Spring Security + JJWT for auth plus role-based `@PreAuthorize` checks (customer vs admin); cart stored in Redis keyed by user/session; Spring Data Redis; AWS S3 SDK or a Minio client pointed at Garage.

**Done when:**

- [ ] You can register, log in, and update your own profile; login issues a JWT and protected routes reject requests without a valid one.
- [ ] Admin-only endpoints (e.g. deleting a product) are rejected for a customer-role token.
- [ ] Your cart supports adding/removing items and updating quantity, and persists in Redis across requests.
- [ ] A cache or session hit is visible in Redis, with a TTL set and a documented invalidation path (e.g. product update busts its cache entry).
- [ ] Product image upload and retrieval works against object storage.

**Optional/stretch:** swap JWT-only login for a real OAuth2/OpenID Connect provider (e.g. Keycloak) instead of hand-rolled JWT issuance.

**References:**

- Redis: https://www.freecodecamp.org/news/how-to-learn-redis/
- JWT: https://jwt.io/introduction
- Garage: https://garagehq.deuxfleurs.fr/documentation/quick-start/

## Milestone 3: Async Order Flow

**Capability:** converting a cart into an order enqueues a job (email/notification/inventory update); a worker processes it, with retry/failure handling visible, a simulated payment is recorded, and the order moves through a status lifecycle.

**Covers:** Message queue (async order handling), Messaging & Event-Driven Architecture, Order Creation, Payment Simulation, Order Management.

Both tracks are RabbitMQ-flavored below, but the same milestone works equally well with Kafka or Redis Streams if you want to swap the transport - the point is decoupling the request path from the side effects, not the specific broker.

- **Track A:** Laravel Queues + Horizon, using a Redis or RabbitMQ driver; failed jobs land in the `failed_jobs` table and can be retried.
- **Track B:** Spring AMQP or Spring Cloud Stream with RabbitMQ, including retry/dead-letter queue configuration.

**Done when:**

- [ ] Checkout converts the cart into an order with a unique order ID and a pricing snapshot (the price stored on the order stays fixed even if the product's price changes later).
- [ ] Order creation returns immediately without waiting on side effects.
- [ ] The queued job visibly processes asynchronously (e.g. a log line or notification appears after a short delay).
- [ ] A forced failure demonstrates retry/dead-letter behavior instead of silently dropping the job.
- [ ] A mock payment step runs against the order and records a success or failure status.
- [ ] The order moves through a status lifecycle (Pending -> Paid -> Shipped -> Delivered), and you can view your order history and an individual order's detail.

**Optional/stretch:** an idempotency key on order/payment submission so a retried request can't create a duplicate order or double-charge; a concurrency-safe inventory decrement so two simultaneous orders for the last unit of stock can't both succeed (no overselling); a simple reporting endpoint (sales summary, orders per day).

**References:**

- RabbitMQ tutorials: https://www.rabbitmq.com/tutorials
- Track A: https://laravel.com/docs/queues
- Track B: https://spring.io/guides/gs/messaging-rabbitmq/

## Milestone 4: Production Hardening

**Capability:** a CI/CD pipeline, structured logs and metrics, automated tests, hardened security basics, externalized configuration, and OpenAPI/Swagger docs.

**Covers:** CI/CD pipeline, Logs + metrics, Tests, API documentation, Security Best Practices, Configuration & Secrets Management.

- **Track A:** a GitLab CI pipeline in the repo, with jobs path-filtered so changes under `src/frontend/` only run frontend lint/test/build and changes under `src/backend/` only run backend lint/test/build (matching the team's existing GitLab infrastructure); Laravel logging channels shipped to ELK or Loki, metrics via a Prometheus exporter visualized in Grafana; Pest/PHPUnit tests covering unit, integration, and API layers; L5-Swagger for API docs.
- **Track B:** a GitLab CI pipeline in the repo, with jobs path-filtered so changes under `src/frontend/` only run frontend lint/test/build and changes under `src/backend/` only run backend lint/test/build; Spring Boot Actuator with Micrometer/Prometheus visualized in Grafana, logs shipped to ELK or Loki; JUnit/Mockito tests covering unit, integration, and API layers; springdoc-openapi for API docs.

**Done when:**

- [ ] Your pipeline runs automatically on push, with path-filtered jobs keeping frontend and backend changes independent, and it builds/deploys the full stack.
- [ ] Logs are centralized (ELK/Loki) and a metrics dashboard (Grafana) shows live data from your app.
- [ ] A metrics or actuator endpoint is exposed and scrapeable.
- [ ] Your test suite (unit + integration + API) passes in CI.
- [ ] An OpenAPI spec renders at a docs endpoint.
- [ ] Inputs are validated, SQL injection is prevented via parameterized queries/ORM usage, sensitive endpoints are rate-limited, and the app is served over HTTPS.
- [ ] Config (DB credentials, JWT secret, queue/broker URL, storage keys) comes from environment variables, not hardcoded values.

**Optional/stretch:** distributed tracing (Jaeger) added alongside the logs/metrics; secrets pulled from Vault instead of plain env vars.

**References:**

- GitLab CI/CD: https://docs.gitlab.com/ee/ci/
- Prometheus: https://prometheus.io/docs/introduction/overview/
- Grafana: https://grafana.com/docs/grafana/latest/getting-started/

## Milestone 5: System Design & Scale (Optional/Stretch)

**Capability:** thinking beyond the single-instance app - routing through a gateway, handling load, and being able to justify architectural decisions rather than just implement them.

**Covers:** API Gateway & Routing, Scalability & Load Handling, Architecture Decisions, Deployment & Infrastructure.

- Put your frontend and backend behind a reverse proxy/API gateway (NGINX, or Kong/Traefik if you want gateway-specific features like rate limiting or API versioning at the edge), configured in the repo alongside the existing `docker-compose.yml`.
- Run two replicas of your backend behind a load balancer and confirm the API is stateless enough that either replica can serve any request (session/cache already lives in Redis from Milestone 2, not in-process).
- Write a short ADR (Architecture Decision Record) arguing monolith vs microservices for this reference app, saved to `docs/adr/` - there's no single right answer, the point is showing the trade-off analysis.

The gateway and second backend replica run on top of everything you're already running from Milestone 4 - budget 24GB+ RAM if you're keeping the full ELK/Grafana/Prometheus stack running at the same time, or stop the observability containers you're not actively using while you work through this milestone.

**Done when:**

- [ ] Requests to the app go through a gateway/reverse proxy rather than hitting a service directly.
- [ ] Two backend replicas run simultaneously behind a load balancer and both correctly serve authenticated requests.
- [ ] A one-page ADR exists comparing monolith vs microservices for this app, with a stated recommendation.

**References:**

- NGINX reverse proxy: https://docs.nginx.com/nginx/admin-guide/web-server/reverse-proxy/
- ADR overview and templates: https://adr.github.io/

## Evaluation Criteria

Whatever milestone you're on, this is the quality bar your work should hold to, on top of that milestone's own done-when checklist:

- **Code quality & maintainability** - consistent style, small focused functions, no dead code, a codebase a teammate could pick up cold
- **API design** - predictable resource naming, correct status codes, consistent request/response and error shapes
- **Database schema design** - normalized where it should be, indexed where it matters, constraints doing real work
- **Error handling** - failures produce useful messages and logs, not swallowed exceptions or default stack traces
- **System scalability** - state lives in Redis/Postgres rather than in-process, so nothing breaks the moment a second instance appears
- **DevOps maturity** - the pipeline, containers, and configs are things you understand and can debug, not copy-pasted incantations
- **Documentation quality** - READMEs and API docs that stay true to what the code actually does

## Use of AI Coding Tools

Use them - Claude Code, Copilot, Cursor, and the like are part of the job now, and working effectively with them is itself a skill this track should build. Two ground rules:

- You own every line, however it was produced. At the milestone walkthrough your reviewer can point at any part of the system and ask you to explain what it does and why it's there. "The AI wrote that part" is not an answer; if you can't explain it, the milestone isn't done.
- Don't let the tool skip the learning. If an LLM one-shots your queue setup and you sign off without understanding retries and dead-lettering, you've completed the checklist but not the track - and it will show the first time something breaks on a client project.

## Review & Sign-off

Each milestone's "Done when" checklist is dual-purpose: it's what you build against, and it's what a reviewer checks off before the milestone counts as complete. Self-marking a milestone done doesn't count - a milestone is only "really done" once a reviewer (your team lead or supervisor) has walked through the checklist with you and confirmed each item. Leave the checkboxes unchecked until that review happens.

Once confirmed, the reviewer logs the sign-off (developer, track, milestone, reviewer, date) to the team's shared tracker. The walkthrough itself is verbal, but the sign-off it produces is not - if it isn't in the tracker, it didn't happen, and staffing decisions can't be made against it. The track counts as complete once Milestones 1-4 all have logged sign-offs; a Milestone 5 sign-off is a bonus, not a requirement.

## Notes on Pacing

Milestones 1-4 are sequential and required - each builds on the container/API you set up in the previous one - but the pace within and between milestones is up to you. As a rough guide, aim to clear each required milestone within about six weeks of part-time effort - faster is fine, but if you're stalled well past that, flag it to your reviewer rather than letting it drift indefinitely. Milestone 5 is optional/stretch: skip it, do part of it, or come back later - it isn't a prerequisite for calling the reference project complete. Consider pairing up with someone on the other track so you can compare approaches at each milestone checkpoint.
