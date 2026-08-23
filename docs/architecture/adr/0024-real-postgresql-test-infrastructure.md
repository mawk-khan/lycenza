# ADR 0024: Tests Run Against Real PostgreSQL, Not SQLite

- Status: Accepted
- Date: 2026-08-22 (Phase 0B)

## Context

Phase 0A's `phpunit.xml` defaulted the test environment to SQLite
in-memory, which was fine when zero tables existed. Phase 0B's schema
uses PostgreSQL-specific features throughout: `uuid`/`jsonb` column
types, `current_setting`/`set_config` in Row-Level Security policies,
`FORCE ROW LEVEL SECURITY`, and PL/pgSQL trigger functions. None of
this exists in SQLite. More fundamentally, this checkpoint's entire
purpose is to prove tenant isolation is real -- and RLS is a PostgreSQL
server-side feature that cannot be exercised, let alone meaningfully
tested, against a different database engine. A mocked or SQLite-backed
"RLS test" would test nothing real.

## Decision

**The test environment runs against real PostgreSQL**, using a
dedicated `school_os_test` database (never the local dev `school_os`
database), reachable through the same two-role setup as local
dev/production (ADR 0021): `pgsql` (the `school_os_app` runtime role)
for everything queries normally run through, `pgsql_admin` (the
`school_os` superuser) for one-time migration of the test schema.

Consequences of this choice, addressed explicitly:

- **No `RefreshDatabase`/`DatabaseMigrations` trait.** Both re-migrate
  using the test's *default* connection, which is deliberately the
  unprivileged `school_os_app` role that cannot run RLS/trigger DDL
  (ADR 0021) -- exactly the role that must NOT have that power. The
  test database is migrated **once** (via `pgsql_admin`, same as any
  environment) before the suite runs, not per-test.
- **`DatabaseTransactions`, not schema rebuilding**, isolates tests from
  each other by wrapping each test in a transaction and rolling back --
  compatible with a pre-migrated, privilege-locked-down database.
- **Postgres session-level GUCs (`app.current_school_id`) are NOT
  transactional** (`set_config(..., is_local=false)`), so they survive
  a `DatabaseTransactions` rollback on a reused connection between
  tests in the same PHPUnit process -- this is a deliberate, useful
  property: it makes the test suite itself a realistic analogue of the
  "long-running worker" leak risk (section 25/32 of this checkpoint's
  brief), which is exactly why `Tests\PostgresTestCase::tearDown()`
  unconditionally calls `TenantContext::clearAll()` and why that
  behaviour is itself asserted by tests, not just assumed.
- **Fixture creation must respect the same rules production code
  does**: creating a tenant-owned row in a test requires
  `TenantContext::set($school)` first, exactly like any other code path
  -- there is no test-only bypass of RLS or the application scope.

## Rationale

- Testing RLS against SQLite (or an in-memory fake) would validate
  nothing about PostgreSQL's actual policy engine, the exact failure
  mode this checkpoint exists to prove doesn't happen. "Real Postgres or
  it doesn't count" is stated explicitly in this checkpoint's brief and
  is the only credible standard for this class of guarantee.
- Using `school_os_app` (not `school_os`) as the test suite's query
  connection means every test implicitly re-verifies ADR 0021's role
  separation on every run -- if a future change accidentally granted
  the app role more privilege, tests would start silently succeeding at
  things they should fail at, which is a useful tripwire even without a
  dedicated "assert the role's privileges" test (which this checkpoint
  also adds, see the Final Report's PostgreSQL Isolation Proof).

## Alternatives considered

1. **SQLite for fast unit tests, Postgres only for a slow, separate
   integration suite.** Considered and partially adopted: `tests/Unit`
   remains for pure-PHP logic with no database at all (e.g.
   `AiContextTokenService`'s signing logic), but any test touching an
   Eloquent model or RLS runs against real Postgres -- there is no
   middle tier pretending SQLite is an adequate stand-in for
   Postgres-specific schema.
2. **A separate, ORM-agnostic pure-SQL RLS test suite, with Laravel-level
   tests staying on SQLite.** Rejected: Phase 0B's schema (UUID PKs,
   JSONB, composite FKs) isn't meaningfully expressible in SQLite either,
   so "Laravel-level tests on SQLite" would mean rewriting the schema
   twice or skipping most Feature-level coverage. Running everything on
   Postgres is simpler and more honest about what's actually verified.
3. **Testcontainers-style ephemeral Postgres per test run.** A
   reasonable future CI hardening; not adopted now because the local
   dev Postgres container (`docker-compose.yml`) already exists and a
   dedicated `school_os_test` database on it is sufficient for this
   checkpoint -- CI's own Postgres service container (already used for
   Phase 0A's `platform` CI job) plays the same role there.

## Consequences

- `phpunit.xml` sets `DB_CONNECTION=pgsql`, `DB_DATABASE=school_os_test`,
  and the `school_os_app` credentials -- no more SQLite testing config.
- Whoever runs the test suite must have migrated `school_os_test` via
  `pgsql_admin` first (documented in `CLAUDE.md`); this is a one-time
  step per fresh database, not per test run.
- CI (`.github/workflows/ci.yml`) must provision `school_os_test` the
  same way (its Postgres service now uses the same init scripts) and
  migrate it via the admin connection before running PHPUnit.

## Future extraction/evolution path

If test suite runtime becomes a bottleneck, parallelizing PHPUnit
against multiple `school_os_test_{n}` databases (Postgres supports this
natively; Laravel's parallel testing tooling has first-class support
for it) is the natural next step -- it doesn't change this ADR's core
decision, only how many real Postgres databases the suite uses at once.
