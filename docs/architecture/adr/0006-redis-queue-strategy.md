# ADR 0006: Redis/Valkey for Cache, Session, and Queue

- Status: Accepted
- Date: 2026-08-22

## Context

The ERP core needs a fast cache, a session store, and a background job
queue (for notifications, report generation, future integrations, and
AI Gateway callbacks). These three needs are commonly served by the
same technology in a Laravel application, and the choice affects both
local developer environment complexity and tenant-isolation mechanics
(ADR 0004) since cache keys, session keys, and queue payloads all need
tenant-awareness.

## Decision

**Redis-protocol-compatible key-value store** — deployed locally and
by default configured as **Valkey** (the open-source Redis fork) — is
used for Laravel's cache, session, and queue drivers. The PHP client is
**Predis** (a pure-PHP client), not the `phpredis` C extension, so the
application has no native-extension dependency for this.

All tenant-scoped cache keys, session data, and queue jobs **must**
carry an explicit tenant identifier as part of the key/payload — see
`docs/architecture/TENANCY.md` ("tenant-aware caches", "tenant-aware
queues"). This is an application-layer discipline; Redis itself has no
built-in tenant concept.

## Rationale

- One technology for three needs (cache/session/queue) is one less
  moving part to operate, monitor, and secure for a small team.
- Valkey is a drop-in Redis-protocol-compatible fork with an open
  license, avoiding Redis Inc.'s licensing changes as a future concern
  without giving up ecosystem compatibility (Laravel's Redis client
  works against it unmodified).
- Predis avoids requiring the `phpredis` PHP extension to be compiled
  into every environment (local dev, CI, and eventually production
  images) — fewer environment-specific build steps, one fewer thing
  that can silently differ between a developer's machine and CI/prod.
- Laravel's queue worker model (`php artisan queue:work`) fits a
  Redis-backed queue well and is a well-understood operational pattern.

## Alternatives considered

1. **Database-backed queue/cache/session (Postgres).** Rejected as the
   default: works, but adds load to the primary transactional database
   for high-churn, low-value-per-row data (cache entries, session
   pings, queue polling) that a purpose-built store handles far more
   cheaply. Kept in mind only as a possible *local* fallback, not used
   here since Redis/Valkey is already required infrastructure.
2. **A managed distributed job-queue system (e.g. SQS, a Kafka-backed
   queue).** Rejected for Phase 0A: no throughput or delivery-semantics
   requirement yet justifies it, and it adds real operational and cost
   overhead compared to Redis-backed queues for a team and product at
   this stage. Revisit only if a specific module's measured needs
   demand it (see ADR 0010's stance on avoiding Kafka prematurely).
3. **`phpredis` extension instead of Predis.** Marginally faster;
   rejected because of the extra native-extension dependency across
   every environment for a performance difference that doesn't matter
   at this stage. Can be revisited later purely as a performance
   optimization without changing the architecture.

## Consequences

- Local development and CI both require a running Redis-protocol
  server (`infrastructure/docker/docker-compose.yml`'s `redis` service,
  `.github/workflows/ci.yml`'s `redis` service container).
- Every module that enqueues a job or caches tenant data must follow
  the tenant-aware key/payload convention — a job or cache entry
  without an explicit tenant id is a bug, not a shortcut, per root
  `CLAUDE.md`.
- Session data lives in Redis, so Redis availability is on the critical
  path for any logged-in user — this is an accepted operational
  dependency, matching most Laravel deployments.

## Future extraction/evolution path

If a specific queue workload later needs different delivery guarantees
(exactly-once, ordered-per-tenant, very high throughput), that workload
can move to a purpose-built queue technology without affecting cache or
session, since those three responsibilities, though co-located on Redis
today, are already accessed through Laravel's standard driver
abstractions rather than bespoke Redis calls.
