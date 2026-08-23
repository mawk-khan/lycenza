# ADR 0004: Tenant Isolation Model

- Status: Accepted
- Date: 2026-08-22

## Context

School OS must support: a single independent school, a school with
multiple campuses, and (in the future) a group/trust owning multiple
schools — plus central platform administration across all of them. The
Indian K-12 market is dominated by a very large number of small and
mid-size schools, so the isolation model must remain operationally cheap
per tenant at meaningfully large tenant counts, while still giving each
school a hard guarantee that no other school can ever see its data by
default.

This ADR fixes the isolation *model*. The mechanics (query scoping,
queues, cache, files, logs, AI requests) are detailed in
`docs/architecture/TENANCY.md`; this ADR is the decision record for why
that model was chosen.

## Decision

**Tenant unit:** the **School** is the primary tenant/isolation
boundary. A **Campus** is a sub-tenant dimension *within* a School
(a `campus_id` on campus-scoped rows, not a separate isolation
boundary — school staff may need cross-campus visibility inside their
own school). A **School Group / Trust** is a grouping entity *above*
tenants for cross-school reporting and administration; it does not
merge the underlying tenant boundary — a group admin's cross-school
access is an explicit, granted, audited elevation, never a default.

**Isolation mechanism:** **shared database, shared schema**, with a
mandatory `tenant_id` (school id) discriminator column on every
tenant-scoped table, enforced at three independent layers so a bug in
any one layer does not by itself cause a cross-tenant leak:

1. **Application layer:** every tenant-scoped Eloquent model uses a
   global scope that requires an explicitly resolved tenant context for
   the current request/job — there is no "unscoped by default" query
   path in normal application code.
2. **Database layer (defense in depth):** PostgreSQL **Row-Level
   Security (RLS)** policies on tenant-scoped tables, keyed off a
   session-local `app.tenant_id` setting the connection must set at the
   start of every request/job. Even a bug that forgets the application
   scope cannot return another tenant's rows.
3. **Infrastructure layer:** queue jobs, cache keys, file storage paths,
   and log context all carry the tenant id explicitly (see
   `docs/architecture/TENANCY.md`) rather than relying on ambient
   request state that a background job doesn't have.

**Platform Super Admin** access is a separate, explicitly audited path
(not a "bypass RLS" flag left permanently available to application
code) — see `docs/security/AUTHORIZATION.md`.

## Rationale

- A large fleet of small-to-mid tenants (the expected shape of the
  Indian K-12 market) makes **database-per-tenant** and
  **schema-per-tenant** operationally expensive: N databases/schemas
  means N-way migration fan-out, connection-pool pressure, and
  materially harder cross-tenant platform reporting — cost the product
  cannot pass on to price-sensitive schools.
- Shared schema + RLS gives strong, DB-enforced isolation (not just
  "the application promises to filter correctly") while keeping a
  single migration set and a single connection pool — the cheapest
  operational model that still meets the "no implicit cross-tenant
  access" requirement.
- Modeling Campus as a dimension rather than a separate tenant matches
  how school staff actually work (a vice principal often needs
  cross-campus visibility within their own school) while still letting
  campus-level filtering exist wherever it's needed.
- Modeling Group/Trust as an elevation *above* the tenant boundary
  (rather than redefining the tenant unit to be "the group") keeps the
  common case simple (most schools have no group) and keeps a
  compromised or over-permissioned group-admin credential's blast
  radius auditable and explicit rather than an ambient default.

## Alternatives considered

1. **Database-per-tenant.** Strongest physical isolation; rejected as
   the default because of migration fan-out and infra cost at the
   expected tenant count. Retained as a future escape hatch (see below)
   for a customer whose contract or compliance posture requires it.
2. **Schema-per-tenant (one Postgres schema per school).** A middle
   ground; rejected as the default for the same operational reasons,
   one notch cheaper than database-per-tenant but still N-way schema
   migrations to manage and coordinate.
3. **Tenant = School Group** (a trust is the isolation boundary, schools
   within it share access). Rejected: most schools have no group at
   all, and even within a group, a school's staff generally should
   *not* default to seeing another school's students/finances just
   because they share a trust.
4. **No RLS, application-scoping only.** Rejected: a single missed
   `->where('tenant_id', ...)` in a query, a raw query, or a queued job
   without request context becomes a cross-tenant data leak with no
   second line of defense. RLS is cheap insurance given Postgres
   provides it natively (ADR 0003).

## Consequences

- Every tenant-scoped migration must include and index `tenant_id`, and
  every tenant-scoped table needs an RLS policy — this is a mandatory
  checklist item for any future module, documented in root `CLAUDE.md`
  and `docs/architecture/TENANCY.md`.
- Background jobs, scheduled commands, and the AI Gateway callback path
  must all explicitly carry and set tenant context — there is no
  "current tenant" ambient global outside an active HTTP request.
- Platform-wide analytics/reporting across tenants is possible (it's
  one database) but must go through code paths that are intentionally
  tenant-unscoped and are treated as privileged, audited operations —
  never the default query path.
- No business module or the AI Platform may implement its own separate
  tenant-scoping mechanism; they use the shared convention.

## Future extraction/evolution path

If a specific school group's contract, regulatory posture, or scale
genuinely requires physical isolation, that tenant (or group of
tenants) can be migrated to a dedicated database later: the `tenant_id`
discriminator and RLS-policy discipline mean the schema is already
"shaped" for that migration (extract by `tenant_id`, replay into a
fresh database), rather than requiring a redesign. This is an
intentionally deferred, not-yet-needed capability, not a plan being
executed now.
