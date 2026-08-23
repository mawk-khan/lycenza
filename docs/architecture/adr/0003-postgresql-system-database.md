# ADR 0003: PostgreSQL as the System-of-Record Database

- Status: Accepted
- Date: 2026-08-22

## Context

The ERP core needs one relational database technology, chosen before
any schema work begins, since migrating a production school's financial
and academic data between database engines later would be high-risk and
expensive.

## Decision

**PostgreSQL (16+)** is the only supported system-of-record database
for `apps/platform`. No module may depend on MySQL/MariaDB-specific or
SQLite-specific behavior. Local `.env.example` and
`infrastructure/docker/docker-compose.yml` default to Postgres so
engineers never develop against a different engine than production will
use.

## Rationale

- **Row-level security and schema-based isolation** are first-class in
  Postgres and are directly relevant to the tenancy model (ADR 0004) —
  we want that option genuinely available, not theoretical.
- **Native `JSONB`** with indexing supports semi-structured data
  (custom fields per school, AI-related metadata, integration payloads)
  without contorting the relational schema for every school-specific
  variation — common in Indian school ERP requirements (state-board
  variance, custom fee structures, etc.).
- **Strong transactional and constraint guarantees** (check constraints,
  exclusion constraints, deferred constraints) matter for financial
  correctness rules (see `docs/architecture/adr/0009`'s sibling
  financial-correctness documentation and future Finance module ADRs).
- **`NUMERIC`/`DECIMAL` types** give exact decimal arithmetic for money,
  required by the financial-correctness rules in
  `docs/architecture/ARCHITECTURE.md`.
- Mature, well-supported by Laravel's query builder/Eloquent, widely
  available on every major cloud and India-based hosting provider.

## Alternatives considered

1. **MySQL/MariaDB.** Rejected: weaker JSON/constraint ergonomics,
   historically weaker point-in-time recovery tooling, and no material
   advantage over Postgres for this workload.
2. **SQLite for production.** Rejected outright for production
   (no real concurrent-write story for a multi-user ERP); it remains
   useful only as a fast option for isolated unit tests that don't
   exercise Postgres-specific behavior, which we generally avoid — see
   `phpunit.xml`'s testing config, which developers should be aware
   defaults to SQLite in-memory and must be validated against real
   Postgres in CI (see `.github/workflows/ci.yml`) before trusting it
   for anything Postgres-specific.
3. **A managed multi-model / NoSQL primary store.** Rejected: an ERP's
   core data is inherently relational with strong consistency needs;
   introducing a non-relational primary store here would fight the
   domain rather than help it.

## Consequences

- Every developer and CI environment must run real Postgres for
  anything beyond the most trivial database-touching test — SQLite is
  not an acceptable substitute once module-specific behavior (JSONB,
  constraints, extensions) is exercised.
- Schema migrations must be written with Postgres semantics in mind and
  reviewed for rollback safety (root `CLAUDE.md`).
- Extensions we may adopt later (e.g. `pg_trgm` for search,
  `pgcrypto`) are Postgres-specific by design — this is accepted, not
  accidental.

## Future extraction/evolution path

If a future extracted service (ADR 0001) needs a different storage
engine suited to its own workload (e.g. a search index, a time-series
store for IoT/transport telemetry), that is a decision local to that
service's own ADR — it does not change Postgres's role as the ERP
core's system of record, and that service still may not become a
second writer to ERP-owned tables (ADR 0002).
