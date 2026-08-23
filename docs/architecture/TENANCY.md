# School OS — Multi-Tenancy Architecture

For the decision record and alternatives, see ADR 0004, ADR 0020
(terminology), ADR 0021 (database roles), ADR 0022 (context
propagation), ADR 0024 (real-Postgres test infrastructure). This
document is the operational reference for implementing tenant-aware
code, updated in Phase 0B to describe what is **actually implemented**,
not only the target design.

## Tenant model

- **School** = the tenant/isolation boundary. Every tenant-scoped table
  carries a `school_id` (the School's id) -- see ADR 0020 for why the
  column is literally named `school_id`, not `tenant_id`.
- **Campus** = a sub-tenant dimension. A `campus_id` column exists on
  campus-scoped tables, but Campus is **not** a separate isolation
  boundary — staff scoped to a School may legitimately need
  cross-campus visibility within that one School.
- **School Group / Trust** = a grouping entity *above* the tenant
  boundary. A group admin's cross-school access is an explicit,
  granted, audited elevation (see `docs/security/AUTHORIZATION.md`),
  never a default consequence of a school belonging to a group.
- **Platform Super Admin** = operates outside normal tenant scoping
  through a dedicated, audited administrative path — not a flag that
  application code can casually flip.

## Isolation layers (defense in depth)

No single layer is trusted alone. All three must independently prevent
cross-tenant access:

### 1. Application layer — implemented

Every tenant-scoped Eloquent model uses the `App\Support\Tenancy\
BelongsToSchool` trait, which applies the `SchoolScope` global scope
(`App\Support\Tenancy\SchoolScope`). The scope resolves the current
School from `App\Support\Tenancy\TenantContext` (ADR 0022) — never from
a static/global. With no School context set, the scope adds
`whereRaw('1 = 0')`, so the query returns zero rows rather than every
tenant's rows (fail-closed, proven in
`tests/Feature/Tenancy/SchoolScopeTest.php`).

To intentionally cross Schools, call
`Model::withoutGlobalScope(SchoolScope::class)` explicitly — there is
no other unscoped path. `SchoolScopeTest` also proves that removing
this scope alone still cannot leak another School's row, because Layer
2 (RLS) independently blocks it on the same connection.

### 2. Database layer — PostgreSQL Row-Level Security — implemented

Every tenant-scoped table has RLS **enabled and forced**
(`App\Support\Tenancy\TenantRls::enable()`, called from that table's
migration), with a policy keyed on the session-local Postgres setting
`app.current_school_id`:

```sql
USING (school_id = NULLIF(current_setting('app.current_school_id', true), '')::uuid)
```

`TenantContext::set()` assigns this via `set_config('app.current_school_id',
?, false)` (session-level, not `SET LOCAL` — see ADR 0022 for why);
`TenantContext::clear()`/`clearAll()` issue `RESET app.current_school_id`.
Missing/empty context makes the cast operand `NULL`, so the policy
matches nothing — fail-closed, identical in spirit to Layer 1, and
proven independently against real Postgres in
`tests/Feature/Postgres/RawIsolationTest.php` (raw SQL, bypassing
Eloquent entirely) and `tests/Feature/Tenancy/SmokeTest.php`.

Critically, this is only meaningful because the runtime connection
(`pgsql`, using the `school_os_app` role) is **not** a superuser and
does **not** have `BYPASSRLS` — see ADR 0021. `RawIsolationTest`
asserts this directly against `pg_roles`, not just by convention.

### 3. Infrastructure layer

- **Tenant-aware queues — implemented.** The `App\Support\Tenancy\
  TenantScoped` job trait captures `school_id`/`campus_id`/`actor_id`/
  `request_id` into the job's own payload at dispatch time; its
  `middleware()` returns `SetTenantContextForJob`, which sets
  `TenantContext` before `handle()` and clears it in a `finally` block
  after — proven safe for sequential jobs targeting different Schools
  in `tests/Feature/Tenancy/QueueContextPropagationTest.php`
  (`RecordSchoolAuditPingJob` is the Phase 0B proof job).
- **Tenant-aware caches — implemented.** `App\Support\Tenancy\TenantCache`
  namespaces every key `school:{id}:{key}` (vs. `TenantCache::platformKey()`'s
  `platform:{key}`) and throws `TenantContextRequiredException` if
  called with no School context — see `TenantCacheTest`.
  `CapabilityResolver`'s own permission cache uses this same
  discipline: cache keys always include both user id and School id, so
  permissions for one School are never served from another's entry.
- **Tenant-aware files — primitive implemented, no Documents module
  yet.** `App\Support\Tenancy\TenantStoragePath::for($school, $path)`
  builds `schools/{school_id}/...` and rejects traversal/absolute/
  malformed fragments (`tests/Unit/Tenancy/TenantStoragePathTest.php`).
  ADR 0012's Documents module itself remains unbuilt — out of scope per
  this checkpoint's brief.
- **Tenant-aware logs — implemented.** `TenantContext::set()`/`setActor()`/
  `setRequestId()` push `school_id`, `campus_id`, `actor_id`, and
  `request_id` into Laravel's `Context` facade, which every log entry
  picks up automatically; `clear()`/`clearAll()` remove them.
- **Tenant-aware AI requests — implemented.** Every Laravel → AI
  Gateway call carries `school_id` explicitly (renamed from Phase 0A's
  `tenant_id` per ADR 0020); the outbound half additionally binds
  School + actor + capability into a signed context token before any
  network call (ADR 0023, `docs/ai/AI-SECURITY.md`) — a stronger
  guarantee than "carries school_id in the payload" alone, since the AI
  Gateway cannot alter or widen what that token grants.

## What "no implicit cross-tenant access" means in practice

- A missing `school_id` filter in a raw/manual query is a bug caught by
  RLS (Layer 2), not a silent data leak — but it should still never
  ship; RLS is the backstop, not the intended primary control.
- A background job that forgets to set tenant context fails loudly:
  `TenantContext::requireSchool()` throws `TenantContextRequiredException`
  rather than silently no-op-ing or querying the wrong School.
- Cross-tenant reporting (Layer 5 in `docs/architecture/DOMAIN-MAP.md`)
  is possible because it's one shared database, but every such code
  path is explicit and treated as privileged — never the default query
  behavior any module reaches for. No such reporting path is
  implemented yet.

## What is NOT yet implemented (Phase 0B honesty note)

Real School/Campus/membership/role data model, RLS, application-layer
scoping, and tenant-aware queue/cache/storage/log/AI plumbing are now
real (this section previously said none of it existed — that was
Phase 0A). What remains for a future phase: School Group/Trust
cross-school elevation workflows (the *structural* tables exist —
`school_groups`, `school_group_members` — but no "enter a member
School's context as a group admin" action is built, deliberately, per
section 12 of the Phase 0B brief: no invisible see-every-tenant-row
mode), tenant-custom roles (only system-defined roles exist), and a
production-grade credential-issuance story for the `school_os`/
`school_os_app` role split (ADR 0021's "Future extraction path").
