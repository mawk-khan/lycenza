# ADR 0021: Separate PostgreSQL Roles for Migrations vs. Runtime

- Status: Accepted. **Amended 2026-10-04 (E21-RH.1):** a third,
  dedicated retention identity is the target for destructive retention;
  see "Amendment — retention execution identity" below and ADR 0066.
- Date: 2026-08-22 (Phase 0B)

## Context

PostgreSQL Row-Level Security (ADR 0004, `docs/architecture/TENANCY.md`)
is only meaningful if the role the application actually connects as is
subject to it. PostgreSQL superusers and any role granted `BYPASSRLS`
ignore RLS policies unconditionally, and a table's **owner** is exempt
from its own policies unless the table additionally has `FORCE ROW
LEVEL SECURITY` set. If Laravel's normal web/queue/schedule connection
used the same role that owns the tables (the natural default — the role
that ran the migrations), RLS would silently do nothing for that
connection, and the whole Layer 2 defense-in-depth guarantee in
`docs/architecture/TENANCY.md` would be theater.

## Decision

Two distinct PostgreSQL roles, two distinct Laravel connections:

| Role | Properties | Laravel connection | Used by |
|---|---|---|---|
| `school_os` | Superuser (Docker Postgres's bootstrap `POSTGRES_USER`), owns every table | `pgsql_admin` | **Migrations and maintenance only.** `php artisan migrate --database=pgsql_admin`. |
| `school_os_app` | `NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE`, granted `SELECT/INSERT/UPDATE/DELETE` on tables via `ALTER DEFAULT PRIVILEGES` (never DDL) | `pgsql` (Laravel's `default` connection) | **Every runtime code path**: HTTP requests, queue workers, scheduled commands. There is no config value or environment flag that lets runtime code select `pgsql_admin`. |

Role creation and default-privilege grants live in
`infrastructure/docker/postgres/init/01-roles.sql`, mounted into the
Postgres container's `/docker-entrypoint-initdb.d/` (runs automatically
on a fresh volume) and documented for manual application to an
already-initialized database.

Every tenant-owned table additionally sets `FORCE ROW LEVEL SECURITY`
(ADR 0022) — belt-and-suspenders in case table ownership ever changes,
even though `school_os_app` not being the owner already means RLS
applies to it without `FORCE`.

This is proven, not just asserted: `SELECT rolname, rolsuper,
rolbypassrls FROM pg_roles WHERE rolname = 'school_os_app'` returns
`f, f` — verified in this checkpoint (see the Final Report's
PostgreSQL Isolation Proof section) and asserted directly in the
integration test suite.

## Rationale

- This is the single most important control making RLS real rather
  than decorative. Every other Phase 0B guarantee (fail-closed no-context
  behavior, cross-school write rejection) depends on the runtime
  connection actually being subject to policy evaluation.
- Using Docker Postgres's own bootstrap superuser as the migration role
  (rather than creating a third role) avoids inventing extra roles for
  no benefit — the bootstrap role's superuser status is already
  necessary for a migration role that needs to `CREATE POLICY`,
  `ALTER TABLE ... ENABLE ROW LEVEL SECURITY`, and manage grants for
  other roles.
- `ALTER DEFAULT PRIVILEGES` (rather than an explicit `GRANT` after
  every migration) means every future Phase 0C+ migration automatically
  extends the correct, minimal grant to `school_os_app` without a
  developer needing to remember a manual step — a class of "forgot to
  grant, so the app 500s in a way that looks like a bug" mistake is
  designed out.

## Alternatives considered

1. **One role for everything (the common default).** Rejected outright
   — this is exactly the setup that makes RLS a no-op for the owning
   connection, defeating the entire purpose of ADR 0004's Layer 2.
2. **A `BYPASSRLS`-free but still schema-owning app role** (own the
   tables directly, without `FORCE ROW LEVEL SECURITY`). Rejected:
   relies on remembering `FORCE` correctly on every single tenant table
   forever, with silent, non-obvious failure (RLS simply not applying)
   if ever missed. Cleaner to make the app role structurally unable to
   own the tables in the first place.
3. **Managed via a cloud provider's IAM-integrated database roles
   instead of plain Postgres roles/passwords.** Rejected for Phase 0B —
   no cloud infrastructure exists yet (stop gates); this ADR fixes the
   *role-separation model*, which a future managed-Postgres deployment
   should replicate using whatever credential-issuance mechanism that
   platform provides, not a specific IAM implementation decided now.

## Consequences

- Every migration must run with `--database=pgsql_admin` explicitly —
  documented in `CLAUDE.md`'s command reference. Running `php artisan
  migrate` without the flag targets the default `pgsql` connection,
  which (correctly) lacks the privileges to alter RLS/create policies,
  and will fail loudly rather than silently succeeding as the wrong
  role.
- Local `.env`/`.env.example` now carries both `DB_USERNAME`/
  `DB_PASSWORD` (app role) and `DB_ADMIN_USERNAME`/`DB_ADMIN_PASSWORD`
  (migration role) — see ADR 0016's secrets-strategy conventions, both
  remain inert local-only defaults.
- CI (`.github/workflows/ci.yml`) must provision both roles the same
  way local dev does, or migrations will fail in CI — the CI Postgres
  service now runs the same init script.

## Future extraction/evolution path

A production deployment must replicate this exact separation using
whatever credential/role-provisioning mechanism that environment uses
(a managed Postgres service's role management, a secrets manager
issuing scoped credentials, etc.) — the two-role model, not the literal
`school_os`/`school_os_app` names or the plaintext local passwords, is
the durable decision this ADR records.

## Amendment — retention execution identity (2026-10-04, E21-RH.1)

The two-role model stays. It gains one bounded exception, decided in
ADR 0066: destructive E21 retention functions will be executed by a
**dedicated retention identity**, neither the runtime role nor this
migration/owner role.

- **Runtime role (`school_os_app`):** unchanged for every request, queue
  worker and ordinary scheduled command. It must hold **no** EXECUTE on
  a destructive retention function and **no** DELETE/UPDATE privilege
  that exists only for retention.
- **Migration/owner role (`pgsql_admin`):** migrations and operator-run
  privileged maintenance only. **No scheduled retention code path may
  select it**, and neither the application nor the retention scheduler
  may require its credentials.
- **Dedicated retention identity** (E21-RH.2): NOSUPERUSER, NOBYPASSRLS,
  not an owner and not a member of either role above. Its own
  credential is provisioned only to the process that runs scheduled
  retention. Without it, destructive retention fails safe and never
  falls back to another identity.
- **Known temporary deviation.** Since `dc8b50a` (ADR 0065 §27.10) the
  HRX retention participants of the scheduled
  `platform:employee-retention-prune` run on `pgsql_admin`, so that
  scheduler process needs the migration credentials. This contradicts
  the "Used by" column above. It is recorded here as temporary and is
  removed in E21-RH.2, once the dedicated identity exists.
- E21-RH.1 also revoked the runtime role's unused UPDATE/DELETE on
  `payroll_lwf_annual_charges`. The default privileges above remain the
  baseline, and individual tables are narrowed by verifier-guarded
  REVOKEs (`DatabaseRoleVerifier::NO_RUNTIME_DELETE` /
  `NO_RUNTIME_UPDATE`).
