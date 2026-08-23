# ADR 0020: `school_id` Is the Canonical Tenant-Column Name

- Status: Accepted
- Date: 2026-08-22 (Phase 0B)
- Amends: ADR 0004, `docs/architecture/TENANCY.md` (wording only — no
  change to the isolation model those documents established)

## Context

ADR 0004 correctly decided the tenant/isolation *unit* is the School.
However, `docs/architecture/TENANCY.md` (written the same day)
describes the mechanism using the generic word **`tenant_id`** ("every
tenant-scoped table carries a `tenant_id`"), while ADR 0004's own prose
already calls the concept "School." Phase 0A shipped no code, so this
was harmless at the time, but Phase 0B is about to write the first real
migrations, and starting them under a mixture of `tenant_id`,
`school_id`, `organisation_id`, etc. for the same concept is exactly the
kind of avoidable confusion this checkpoint's brief calls out —
different engineers (or a future AI-Native codebase) would otherwise
guess differently per module.

## Decision

**`school_id` is the one and only code-level column/property name** for
the tenant-boundary foreign key, across every Laravel migration,
Eloquent model, PHP variable, Postgres RLS policy, session GUC, queue
job payload key, cache-key segment, log-context key, and the
Laravel↔AI Gateway request payload.

- **Business/architecture prose** continues to say "tenant" when
  talking about the *concept* (multi-tenancy, tenant isolation, tenant
  context) — this is normal SaaS vocabulary and stays in ADR 0004 and
  `docs/architecture/TENANCY.md`'s title/headings.
- **Code, schema, config keys, and anything a developer greps for**
  says `school_id` — never `tenant_id`, `organisation_id`,
  `organization_id`, or `institution_id` for this same concept.
- The Postgres session variable RLS policies key off is
  `app.current_school_id` (not `app.tenant_id` as TENANCY.md's original
  illustrative example wrote it) — see ADR 0021, ADR 0022.
- `docs/architecture/TENANCY.md` is updated in this same checkpoint to
  replace its `tenant_id` column-name references with `school_id`,
  keeping "tenant" only where it's describing the architectural concept.

## Rationale

- `school_id` is immediately meaningful to every engineer working on
  this specific product (an India-focused School ERP) without requiring
  the mental translation "tenant means school here." Generic SaaS
  terminology ("tenant") is the right word in architecture prose aimed
  at explaining *why* a pattern exists, but a needless indirection as an
  actual column name in a codebase this domain-specific.
- A single canonical name is a mechanical, greppable invariant: any PR
  introducing `tenant_id`, `organisation_id`, or similar for this
  concept is trivially wrong on sight, which is exactly the kind of rule
  that scales across many future contributors and modules.
- This does not change ADR 0004's decision in any way — School is still
  the tenant boundary, Campus is still a sub-tenant dimension, Group/
  Trust is still an explicit elevation above it. Only the column-name
  wording in the supporting document was inconsistent with the ADR it
  was supporting.

## Alternatives considered

1. **Keep `tenant_id` as the column name**, treating "School" as purely
   a business-layer synonym. Rejected: this is precisely the "confusing
   mixture" this checkpoint's brief warns against, and it was already
   inconsistent with ADR 0004's own prose.
2. **`organization_id` / `organisation_id`.** Rejected: more generic
   than this product will ever need (there is no non-school tenant
   concept in this domain), and adds a spelling-variant risk
   (British vs. American English) for no benefit.
3. **Different column names per module** (e.g. `owning_school_id` in
   some tables, `school_id` in others) to disambiguate multiple
   school-referencing columns on the same table. Rejected as a blanket
   rule — where a table has more than one school reference for a
   genuine reason (rare), the *tenant-boundary* one is always literally
   `school_id`; any other school reference on that table must use a
   different, more specific name (e.g. `home_school_id` for a
   hypothetical transferred-student record in a future module) so
   `school_id` unambiguously means "the tenant this row belongs to"
   everywhere it appears.

## Consequences

- Every Phase 0B (and later) migration for a tenant-owned table
  includes a non-null `school_id` `uuid` column, indexed, referencing
  `schools.id`.
- `docs/architecture/TENANCY.md` is corrected in this checkpoint to
  match this ADR; no other Phase 0A document required a substantive
  change since none of them shipped code that used `tenant_id`
  literally.
- RLS policies, cache keys, queue payloads, log context, and the AI
  Gateway request/response contracts (`docs/ai/AI-PLATFORM.md`) all use
  `school_id` consistently — see ADR 0021–0023.

## Future extraction/evolution path

If a future School Group/Trust or platform-level feature needs to refer
to "which school(s) a group administers" as a *list*, that's a
different relationship (group ↔ many schools) than the tenant-boundary
`school_id` column this ADR governs, and should be named for what it
is (e.g. a `school_group_members` join table, not a `school_id` on the
group itself) rather than overloading this convention.
