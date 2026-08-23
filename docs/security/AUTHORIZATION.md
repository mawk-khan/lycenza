# School OS — Identity and Authorization Design

## Principle: capability-based, not role-hard-coded

**No application code may branch on a role name** (e.g.
`if ($user->role === 'teacher')`). Every protected operation checks a
**capability** (a specific, named permission, e.g. `attendance.mark`,
`invoice.read`, `student.transfer`), resolved through Laravel's policy/
gate system from whatever roles or grants the user actually has. This
mirrors the AI agent authorization model in ADR 0014 — the same
"narrow, explicit, reviewable permission" principle applies to both
human and AI actors, deliberately, so "what can this actor do" always
has the same kind of answer regardless of who or what the actor is.

Roles remain useful as a **convenience bundle of capabilities** assigned
to a user (e.g. a "Teacher" role grants a default capability set), but
the enforcement point is always the capability check, never the role
name — so a school that needs a custom variant (e.g. a "Teacher" who
should not have `attendance.mark`) is a configuration change, not a
code change.

## Actor categories

None of these are implemented yet in Phase 0A (no Identity & Access
module exists) — this is the design reference for whichever module
builds it (`docs/architecture/DOMAIN-MAP.md` Layer 0).

| Actor | Nature | Typical scope | Notes |
|---|---|---|---|
| Platform Super Admin | Human, platform staff | Cross-tenant | Operates through a dedicated, audited administrative path (`docs/architecture/TENANCY.md`) — not a permanent "bypass tenant scoping" flag in normal application code. |
| School Group Admin | Human | One School Group's member schools | Cross-school access within a group is an explicit, granted, audited elevation (ADR 0004) — not automatic from group membership alone. |
| School Admin | Human | One School (all campuses) | |
| Principal / Vice Principal | Human | One School (or one Campus, per school configuration) | |
| Academic Coordinator | Human | One School/Campus, academic-domain capabilities | |
| Teacher | Human | One School/Campus, own classes/sections by default | |
| Accountant | Human | One School, financial-domain capabilities | |
| Admissions Staff | Human | One School, admissions-domain capabilities | |
| HR Staff | Human | One School, HR-domain capabilities | |
| Transport Staff | Human | One School, transport-domain capabilities | |
| Librarian | Human | One School/Campus, library-domain capabilities | |
| Receptionist | Human | One School/Campus, narrow front-office capabilities | |
| Counsellor | Human | One School, student-welfare-domain capabilities (often intersects with Highly Sensitive data — `docs/security/DATA-CLASSIFICATION.md`) | |
| Parent/Guardian | Human, external-facing | Own linked student(s) only | Never a default "see all students" capability — always resolved through the specific Guardian↔Student link. |
| Student | Human, external-facing | Own record only | Age-appropriate capability sets are a **[LEGAL REVIEW REQUIRED]** design question once the Students/SIS module is built (see `docs/security/DATA-CLASSIFICATION.md`'s children's-data flag). |
| Driver | Human | Own assigned route/vehicle only | |
| External Integration | Non-human (API key holder) | Whatever the integration's granted API-key scope covers | Same capability model as a human actor — an API key's capabilities are just as narrow and reviewable. |
| AI Agent | Non-human | Whatever its granted capability set covers, always within one tenant per request | Governed specifically by ADR 0014's stricter chain (capability → tool → authorization → policy → approval → domain service → audit) — an AI agent's capability grant is reviewed with at least as much scrutiny as a human role's, arguably more, given the prompt-injection threat model in `docs/ai/AI-SECURITY.md`. |

## Authorization must be enforced for every protected operation

- Every controller action, Application-layer service method, queued
  job, and AI tool that touches Sensitive/Highly Sensitive data or any
  state change must have an explicit authorization check — "it's only
  reachable from a page the UI hides for unauthorized users" is not
  authorization (root `CLAUDE.md`).
- Authorization checks are always tenant-scoped
  (`docs/architecture/TENANCY.md`) — a capability grant never
  implicitly spans tenants.
- Denied-access attempts are worth auditing (ADR 0017), not just
  granted ones, once the Identity & Access module is built — this
  matters for detecting probing/misconfiguration, not just for
  after-the-fact investigation of a successful breach.
- **Idempotent replay is not an authorization bypass** (Phase 0C.2,
  `docs/architecture/RELIABILITY.md`): authentication, tenant/
  membership validation, and authorization are re-evaluated on *every*
  request against an idempotency-protected endpoint, including a
  would-be replay — never short-circuited by "this key already
  succeeded once." An actor who has since been disabled, had their
  membership suspended, or lost the required capability cannot fetch a
  previously-stored successful response merely by knowing an old
  `Idempotency-Key`.
- **Feature flags are never authorization** (Phase 0C): a feature flag
  gates rollout/visibility, never access control — checking a flag is
  never a substitute for `Gate::authorize('capability', ...)`.

## Relationship to the AI agent model

`docs/ai/AI-SECURITY.md` and ADR 0014 define the AI-specific version of
this same principle. The two systems are deliberately analogous
(capability-based, narrow, reviewable, auditable) but are **not the same
mechanism** — a human's Laravel-side capability grant and an AI agent's
`services/ai` capability grant are checked independently, and an AI tool
call into Laravel still goes through Laravel's own authorization on top
of the AI Gateway's capability check (ADR 0014's "Domain service" step)
— defense in depth, the same principle applied to tenancy in ADR 0004.

## Implementation (Phase 0B)

- **Capability catalog**: `capabilities` table (central; `key` is the
  primary key — the one documented exception to UUIDv7, ADR 0019),
  seeded by `database/seeders/CapabilityAndRoleSeeder.php` with the
  minimal `platform.*`/`school.*` namespace needed to prove the
  architecture (section 17) — future modules reserve their own
  namespace (`students.*`, `fees.*`, ...).
- **Roles**: `roles` table (central catalog; `scope` is `platform` or
  `school`). Only system-defined roles exist (`platform_super_admin`,
  `school_admin`, `principal`) — tenant-custom roles are future work.
- **Assignment, kept structurally separate by scope**:
  `platform_role_assignments` (central) for platform roles,
  `membership_role_assignments` (tenant-owned, RLS-protected) for
  school roles via a `school_memberships` row. A **database trigger** on
  each table (not just application code) rejects an assignment whose
  role has the wrong `scope` — see the migrations and
  `tests/Feature/Authorization/RoleScopeTriggerTest.php`. This is the
  concrete mechanism behind "a School administrator must not be capable
  of assigning `platform.*` capabilities."
- **Resolution**: `App\Support\Authorization\CapabilityResolver` is the
  one authoritative service. `platformCapabilities()`/
  `schoolCapabilities()` are cached independently, with cache keys that
  always include both user id and (for School capabilities) School id
  — see `docs/architecture/TENANCY.md` ("tenant-aware caches"). A
  disabled user (`users.is_disabled`) or a non-`active` membership
  yields an empty set regardless of role assignments
  (`tests/Feature/Authorization/CapabilityResolverTest.php`). Platform
  and School capabilities are never merged: `CapabilityResolverTest`
  proves a Platform Super Admin has zero School capabilities without an
  explicit membership — no invisible cross-tenant bypass exists.
- **Enforcement**: a `Gate::define('capability', ...)` (registered in
  `AppServiceProvider`) backs two equivalent patterns future modules
  may use — the `capability:` route middleware
  (`App\Http\Middleware\EnsureCapability`) or the
  `App\Support\Authorization\AuthorizesCapability` controller trait
  (`SchoolSettingsController` demonstrates both, one per action, in
  real tested code).
- **Revocation**: a disabled user or a suspended/revoked membership
  loses access on the *next* capability check — there is no session- or
  cache-level grace period, since `CapabilityResolver` re-derives from
  the database each time its cache entry expires (60s TTL) and the
  check itself queries current `is_disabled`/membership `status`. See
  `tests/Feature/Authorization/CapabilityResolverTest.php`'s disabled-
  user and suspended-membership cases (section 31).

## What is NOT yet implemented

Tenant-custom roles, a UI for managing role assignments (only the data
model + a seeded system catalog exist), a real "platform admin enters a
specific School's context" elevation workflow (the brief in section 12
deliberately asked for only the *foundation*, proven by denial — see
`CapabilityResolverTest::platform_capability_grant_does_not_imply_school_capability`
— not the workflow itself), and any actor-category-specific UI beyond
the minimal login/dashboard/settings pages proving the architecture
(`docs/architecture/ARCHITECTURE.md`).
