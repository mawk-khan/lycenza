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

This table is a design reference, not a list of implemented roles.
(Historical, Phase 0A: none were implemented then.) As built
(2026-09-30):
- the system roles are `platform_super_admin`, `platform_auditor`
  (platform), `group_admin` (Group), and `school_admin` and `principal`
  (School);
- every other row here is a design-reference actor, with no production
  role;
- the Teacher row is contracted by ADR 0063 (TCH) — see "Ownership-based
  authorization" below. It is **not** implemented yet: there is no
  production Teacher role and no teacher ownership.

| Actor | Nature | Typical scope | Notes |
|---|---|---|---|
| Platform Auditor | Human, platform staff | Platform audit ledger only | ADR 0046, built in Phase 0N.7: `platform_auditor` holds only `platform.audit.view`; granted and revoked by a Platform Super Admin; no School, Group, elevation or governance power. |
| Platform Super Admin | Human, platform staff | Cross-tenant | Operates through a dedicated, audited administrative path (`docs/architecture/TENANCY.md`) — not a permanent "bypass tenant scoping" flag in normal application code. Entering one School is a temporary, explicit elevation that grants no School capability (ADR 0044; substrate built in Phase 0N.3, no School page opens under it yet). |
| School Group Admin | Human | One School Group's member schools | Cross-school access within a group is an explicit, granted, audited elevation (ADR 0004) — not automatic from group membership alone. ADR 0045, foundation built in Phase 0N.5: a distinct Group scope with its own grant (`group_role_assignments`) and `group.*` capabilities, granting no School capability; School entry only through ADR 0044 elevation; Group membership and grants are platform-governed. |
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
- **Roles**: `roles` table (central, global catalog; `scope` is
  `platform`, `school` or `group` since Phase 0N.5). Only system-defined
  roles exist:
  - `platform_super_admin` and `platform_auditor` (platform);
  - `group_admin` (Group);
  - `school_admin` and `principal` (School).

  The DDEV demo adds non-system `demo.*` roles only behind its local-demo
  guard. Tenant-custom roles are future work; ADR 0063 adds no custom roles,
  only a planned minimal system Teacher role.
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
- **Revocation**:
  - **A disabled user** loses access on the next capability check: `is_disabled`
    is checked before the capability cache is read.
  - **A suspended membership** is refused on every School request
    immediately, because `RequireSchoolContext`/`ResolveSchoolContext`
    re-check membership status without the cache.
  - **The School capability set itself is cached for 60 s**, with membership
    status read *inside* the cached value (`CapabilityResolver`).
    `StaffAccessService` calls `forgetCache` on suspension and role changes,
    but a non-HTTP caller relying on the resolver alone after a path that
    skips `forgetCache` may see stale capabilities for up to 60 s.
  - For that reason ADR 0063 requires state-changing teacher operations to
    re-read membership and ownership under lock inside their transaction.

  See `tests/Feature/Authorization/CapabilityResolverTest.php`'s disabled-
  user and suspended-membership cases (section 31).

## Ownership-based authorization (ADR 0063 — TCH contract; not implemented)

Roles stay capability bundles. ADR 0063 (TCH.0, 2026-09-30) contracts the
platform's first ownership-based authorization, for teachers. **None of it
is built yet**: every teaching module is still admin-only.

- **Two capability tiers:**

  ```text
  Administrative School-wide resource access:
      active actor + required School-wide capability

  Owned teacher resource access:
      active actor
      + verified ActingEmployee
      + required owned-scope capability
      + authoritative TeachingAssignment ownership
  ```

  - Existing School-wide capabilities (e.g. `attendance.manage`,
    `curriculum.delivery.manage`, `lms.content.manage`) keep their current
    meaning and grants.
  - Owned-scope capabilities (anticipated names such as
    `curriculum.delivery.teacher`) require capability **AND** ownership,
    never either alone.
- **ActingEmployee** (HR): User → active SchoolMembership → linked Employee
  → active Employee record → eligible current EmploymentRecord (dated
  current, status `active` or `notice_period`).
  - Resolved server-side and fails closed.
  - Never inferred from an email, employee number, name or any request
    field.
  - It identifies; it authorizes nothing by itself.
- **TeachingAssignment** is the ownership fact: a dated Employee × Section ×
  required SubjectOffering assignment, administered under
  `teaching.assignments.*`.
  - `TimetableEntry.teacher_id` is scheduling evidence and never grants
    access.
- **No role-name check, ever.**
  - A Teacher role is a capability bundle, never authorization: a Teacher
    without qualifying ownership reaches no owned resource.
  - The `.teacher` suffix in a capability key is a permission name, not a
    role check.
- **Out of scope for TCH:**
  - generic staff roles (accountant, HR, librarian, reception, transport,
    admissions);
  - tenant-custom roles;
  - HRX self-service;
  - RES, POR and Lesson Planning.

## Source-record access vs. derived/aggregate-view access are separate concepts (ADR 0040)

A capability that authorizes reading a module's own transactional
records does not, by itself, authorize viewing a dashboard/report
derived from that data, and the reverse does not hold either — these
are two separate authorization decisions, never assumed to imply one
another:

- **`source-record access = analytics/reporting access` is FALSE.**
  Holding a module's own `.view` capability (e.g. `attendance.view`)
  does not grant access to an aggregate/dashboard built from that data
  — a School may reasonably want a Principal to see aggregate trends
  without seeing every individual record, or the reverse.
- **`analytics/reporting access = source-record access` is FALSE.**
  Holding a reporting/analytics capability never lets an actor drill
  through to an individual underlying record — a read surface offering
  row-level drill-down must additionally check that row's own
  source-module capability, never substitute a reporting-only check for
  it.

This principle governs any future Layer 5 (Oversight — Compliance,
Analytics, Automation) or Layer 6 module that reads across module
boundaries, not Analytics alone; see ADR 0040 and
`docs/modules/ANALYTICS.md` §5 for the concrete Analytics-specific
capability namespace this principle was first applied to.

As built in Phase 0L.2-1 (2026-09-23): `analytics.view` is held by the
`school_admin` and `principal` system roles only, and the Curriculum
Coverage report neither requires nor grants `curriculum.delivery.view`
(tested both ways in `Tests\Feature\Analytics\AnalyticsReadGateTest`
and `Tests\Feature\App\CurriculumCoverageAnalyticsUiTest`).
`analytics.export` is seeded but granted to no role; there is no export.

Compliance (ADR 0042) applies the same principle:
`compliance.view`/`compliance.export`/`compliance.platform.view` are
reserved and unseeded, and no Compliance surface may show more, or
require less (capability, MFA, access audit), than the source module does
for the same data. As built in Phase 0L.4 (2026-09-24): the School
audit-log review (`/app/compliance/audit-log`) requires
`school.audit.view`, held by the `school_admin` and `principal` system
roles only (no desk, teacher, student, guardian or platform role), shows
envelope fields only, and audits every review
(`Tests\Feature\Compliance\AuditLogReviewTest`).

Automation (ADR 0043) uses `automation.view` and `automation.manage`
(`automation.platform.view` reserved, unseeded; no `automation.execute`).
As built in Phase 0L.6: `school_admin` holds both, `principal` holds
`automation.view` only, no other role holds either, and the School
opt-in flag `automation.rules` grants nothing. **Configuring a rule grants
nothing.** An execution acts as the rule instance's accountable owner —
a School member who held `automation.manage` when enabling it — and
before every action Automation re-verifies the owner's active account,
active membership, `automation.manage`, and the action's own declared
capability; the effective authority is that intersection, never the
owner's full capability set. A failed check skips the execution and
suspends the rule. No service identity or other non-User principal acts
inside a School.

## Multi-factor authentication (Phase 0H.4D-P1, ADR 0037)

This is the "documented future path" `App\Http\Controllers\Auth\LoginController`'s
docblock has referenced since Phase 0B. TOTP-only (RFC 6238) v1, no
SMS/email/WebAuthn yet; one active factor per User, database-enforced
(`user_mfa_factors_one_active_per_user` partial unique index); recovery
codes hashed and single-use. MFA is User-global identity data (no
`school_id`, no RLS — a User's MFA state is identical across every
School they belong to), enforced per-route via the opt-in `mfa`
middleware alongside `capability:` — never replacing it, never applied
globally. Session-scoped assurance (`session('mfa_verified_at')`)
expires after `config('mfa.assurance_window_minutes')` (default 60),
independent of the underlying session lifetime. Administrative reset
is a platform-scoped capability (`platform.users.mfa.reset`), never a
School-admin action — User identity's cross-School scope means a
School admin resetting another User's MFA would exceed what that admin
actually owns. Full detail, including the actual (not aspirational)
TOTP replay-prevention behavior, is in ADR 0037. This exists as a
prerequisite for future Highly Sensitive capabilities (StudentMark
chief among them) to require MFA assurance in addition to a capability
check. Phase 0H.4D-P2 (below) is the first real capability this
composes with in production.

## Student processing-authorization registry (Phase 0H.4D-P2, ADR 0038)

`students.processing_authorizations.view`/`.manage` (School-scoped,
`school_admin`/`principal` only, deliberately not implied by
`students.manage`) are each composed with the `mfa` middleware on every
route — the first genuine production route pairing a capability with
MFA, not `App\Http\Controllers\Internal\MfaDemoController`'s
demonstration-only route. Recording, withdrawing, or revoking a
`StudentProcessingAuthorization` is a Highly Sensitive, privacy-facing
action: a compromised password-only administrative session must not be
able to falsely establish or destroy the basis that gates a future
StudentMark checkpoint's processing. Full detail in ADR 0038 and
`docs/security/STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md`.

## After logout: no signed-in page is redrawn from the browser

`POST /logout` (`LoginController::destroy()`) destroys the server
session (audit `auth.logout`, logout, session invalidation, CSRF token
regeneration, redirect to `/login`). That alone does not stop the
browser from redrawing a page it already holds, so three separate
browser mechanisms are closed as well:

| Mechanism | What kept the old page | Control |
|---|---|---|
| Inertia history state | Inertia stores each visited page's props in `history.state`; Back within the same document redraws them without a request. | Every Inertia response for a signed-in user sets `encryptHistory` (`HandleInertiaRequests::handle()`, Inertia's documented history encryption; the key lives in the tab's `sessionStorage`). `destroy()` calls `Inertia::clearHistory()` **after** `session()->invalidate()` so the flag survives into the new session; the `/login` response carries `clearHistory`, the client deletes the key, and Back/Forward to an encrypted entry fails to decrypt and re-requests the URL, which the server redirects to `/login`. Guest pages are not encrypted. |
| Back/forward cache | Chrome restored earlier full documents (`pageshow` with `persisted`) with the account bar still rendered -- including a document that *started* as the guest login page and became the signed-in app through Inertia's in-page sign-in. | `App\Http\Middleware\PreventAuthenticatedPageCaching` (web group) sends `Cache-Control: no-store, private` on HTML and Inertia page responses for a signed-in user; the three guest pages a sign-in happens on (`/login`, `/login/mfa`, `/invitations/{school}/{token}`) use the existing `private-no-store` route middleware. Chrome then refuses the restore (`MainResourceHasCacheControlNoStore`, `CacheControlNoStoreHTTPOnlyCookieModified`). Inertia's own `pageshow` re-validation is a second line. |
| HTTP cache | Laravel's default `no-cache, private` still allows a browser to store the document (with its embedded page props) on disk. | Same `no-store, private` header. Every other guest page, redirects, file downloads and plain JSON keep the framework default. |

Web 403 pages are Blade, not Inertia, and show the account's email: they
are covered by the same `no-store` header, and Chrome does not
back/forward-cache a non-2xx document. The history encryption requires a
secure context (HTTPS; `window.crypto.subtle`), which every non-local
deployment has.

**The signed-in document itself is replaced.** A normal redirect would
let Inertia swap `/login` into the *same* document, which then kept the
first page's JSON in its `<script data-page>` element and the page
objects in JavaScript memory -- invisible, but readable in developer
tools (verified: user id, name, student numbers/names, employee numbers
after logging out from `/app/students` and `/app/hr/employees`). So
`destroy()` returns `Inertia::location(redirect('/login'))`: an Inertia
logout gets `409` + `X-Inertia-Location`, the client sets
`window.location` and the browser does a real top-level load of a fresh
guest `/login` document. A non-Inertia form logout (the Blade 403 page,
no JavaScript) still gets the same plain `302` to `/login`, which is
already a navigation.

Known limits, deliberately not addressed by these controls:

- **A session that ends without logout** is covered separately, below
  ("When a session ends without logout"): until the page's next server
  interaction, the browser has no way to know.
- **Browser-internal retention.** With Chrome's back/forward cache
  enabled, Chrome may keep the previous signed-in document in memory
  after the logout navigation even though it is `no-store`. It is never
  the active document, page JavaScript cannot reach it, and Chrome
  refuses to restore it (`CacheControlNoStoreHTTPOnlyCookieModified`),
  evicting it at the first Back/Forward attempt (or on its own timeout).
  Until then its strings are visible only in a developer-tools heap
  snapshot of the renderer. `Clear-Site-Data: "cache"` on the post-logout
  `/login` load was tried and does not evict it; there is no
  server-side control for this.

Tests: `tests/Feature/Auth/PostLogoutHistoryPrivacyTest.php`; the real
browser check is in `docs/development/DDEV-DEMO-REVIEW.md`.

## Custom School domains: host-local sessions and the sign-in handoff (ADR 0054, Phase 0O.8A)

- **Capabilities.** `school.domains.view` and `school.domains.manage`
  (`school_admin` by default); add, new record value, primary and removal also
  need a fresh MFA code. Platform and Group authority grant neither.
- **Host is intent, not authority.** On a School's active domain the Host
  names the School; `TenantContext` is set only for a signed-in user with an
  active membership in it. Anyone else (platform-only and Group-only accounts
  included) gets a fixed 403 page there. Elevation never applies on a School
  host.
- **Sessions are host-local.** Cookies stay host-only (`SESSION_DOMAIN`
  empty, production-guarded). Logout ends the session of the host it runs on;
  there is no cross-domain global logout in v1.
- **Cross-host switching.** A School switch whose target origin differs
  issues a one-time, 60 s, server-side ticket bound to the user, the source
  session, the School and the exact target host. The target redeems it once
  and re-checks the Host, the source session (still signed in), the user,
  the School and the membership before signing in with a fresh session id;
  it refuses when a different user is signed in there. The ticket is
  continuity, never authority, and is never logged.

## When a session ends without logout

Explicit logout and a session that ends on its own are different events:

| | Explicit logout | Session ended without logout |
|---|---|---|
| Cause | The user presses **Log out** (`POST /logout`). | The session expired in the session store (`SESSION_LIFETIME`, 120 minutes of inactivity by default; the Redis entry's TTL and the session cookie's lifetime), or it was ended from somewhere else (signed out in another tab, session removed server-side). |
| When the server acts | Immediately, in the logout request. | Only when the open page next makes a request -- the server cannot push anything to a page it no longer has a session for. |
| What the browser shows before that | -- | The page that was already on screen stays on screen, and Back/Forward inside that tab can still redraw pages the user saw there (Inertia decrypts them locally with the key the tab still holds). Nothing on the server can retract what the browser has already been given; leaving a shared device means signing out. |
| What happens next | Fresh `/login` document, Inertia history key discarded. | The same: fresh `/login` document, Inertia history key discarded, plus a one-time "Your session has ended. Please sign in again." when the request came from an open signed-in page. |

**How the server handles it** (`App\Support\Auth\SessionEndedResponder`,
registered once in `bootstrap/app.php`'s exception configuration -- no
controller changes). The next request from the open page reaches a route
that requires sign-in with no signed-in user:

- The `auth` middleware throws `AuthenticationException`. For a web
  (non-JSON) request the responder sets Inertia's clear-history flag in
  the new session and answers with `Inertia::location()` to `/login`
  (intended URL remembered): an Inertia visit, filter or form gets
  `409` + `X-Inertia-Location` and the client does a real top-level load
  of a fresh guest `/login` document; a plain browser request gets the
  same `302` to `/login` as before. The `/login` page then carries
  `clearHistory`, the client deletes the history key, and Back/Forward to
  an encrypted signed-in entry can no longer decrypt it -- Inertia
  re-requests the URL and the server sends the guest to `/login`.
- A mutation from a client that relies on the CSRF token (no
  `Sec-Fetch-Site: same-origin` header -- plain HTTP, older browsers)
  fails CSRF first, because the token lived in the ended session. That
  `419` is treated the same way **only** when the matched route requires
  sign-in (`auth`) and the request has no signed-in user -- the `auth`
  middleware would reject it anyway once CSRF passed. A token mismatch
  for a signed-in user, or on a guest route (`/login`, `/login/mfa`,
  invitation acceptance), keeps its `419`. Current HTTPS browsers send
  `Sec-Fetch-Site`, which Laravel's `PreventRequestForgery` accepts in
  place of the token, so for them the ended session always surfaces as
  the `auth` case above.

Before this, the next Inertia request followed the `302` inside the
same document: the signed-in page JSON stayed in `<script data-page>`,
the history key stayed in `sessionStorage`, and Back redrew the
signed-in page (reproduced in Chromium for School Admin, Student,
Guardian, HR & Payroll, a no-School session and Platform Admin; the HR
employee list JSON stayed in the document after a filter).

**Unchanged:** JSON and `/api/*` requests keep `401` (and `419`) with
their existing bodies; `403` authorization denials, `422`, `429` and
`5xx` are untouched; guests still get a `302` to `/login`. The server
cannot tell an ended session from no session, so every unauthenticated
web request carries the clear-history flag -- for a real guest there is
no key to clear. A plain page load (`<a href>`, a bookmark) gets no
"session ended" notice, since it may not come from an open page.

Remaining limits: the page stays as it was until its next request (see
the table); in-page `fetch()` helpers that call JSON endpoints show
their own error on `401`/`419`, and the next Inertia navigation then
performs the transition; the "Browser-internal retention" note above
applies equally here.

Tests: `tests/Feature/Auth/SessionEndedHistoryPrivacyTest.php`; the
real browser check is in `docs/development/DDEV-DEMO-REVIEW.md`.

## Platform elevation into a School (ADR 0044; substrate built in Phase 0N.3)

Platform elevation (contract Phase 0N.2, substrate Phase 0N.3) keeps two
questions separate:

1. **May this actor start and keep an elevation?** The platform
   capability `platform.schools.elevate` (seeded to `platform_super_admin`
   only), an exact target, one of four reason codes, explicit
   confirmation and a **fresh MFA re-verification on every start**
   (`App\Support\Auth\Mfa\MfaReverificationService`, the same factor and
   code paths as login, ADR 0037) — the capability, account state, School
   status and non-membership re-checked on every elevated request, for at
   most 30 minutes. `platform.schools.manage` is deliberately not reused.
2. **May an elevated actor perform this operation?** Only if the
   operation has opted in through its own ADR and an explicit check
   requiring a valid elevation for this School **and** a narrow
   `platform.*` capability for that operation, with the module's own
   validation, MFA, legal gates and audit unchanged.

Elevation establishes tenant context and **grants no School
capability**: `CapabilityResolver::schoolCapabilities()` stays
membership-only, no membership or role row is created, and platform
roles never map to School roles. School routes refuse elevated context
(403, `school_elevation_not_permitted`) unless they declare
`school-context:elevated` — none does (CLAUDE.md rule 83) — because some
School pages are gated by membership or context rather than by a
capability. Start, end, expiry, forced termination and denied attempts
are audited in the platform ledger. Web session only; never `/api/v1`.
See ADR 0044 for the full contract.

## Group/Trust scope (ADR 0045 — foundation built in Phase 0N.5)

A third authorization scope beside platform and School: `group`-scoped
roles held through `group_role_assignments` (one Group per grant, never
self-granted, revocation kept as history) and `group.*` capabilities —
v1 has only `group.schools.view` and `group.schools.elevate`. A Group
grant never satisfies a platform or School capability check, and
`CapabilityResolver`'s platform and School sides never read it. Changing
which Schools belong to a Group, and who holds Group authority, is
platform-governed (`platform.school_groups.view`/`.manage`,
`platform.school_group_grants.manage`, held by `platform_super_admin`); a
Group Admin can change neither. Group capabilities are resolved uncached
through `CapabilityResolver::canInGroup()` only — `can()` never answers a
`group.*` key — and the database lets a role hold only its own scope's
capabilities (`trg_role_capabilities_scope`). A Group-derived elevation
records its Group and grant, is re-verified by the database at start, and
ends the moment that authority goes (no fallback to platform authority). Platform Super Admin is not implicitly a Group Admin. An
ordinary member of several Schools holds no Group authority. See
ADR 0045.

## Group cross-School reporting (ADR 0048 — built in Phase 0N.11)

- A Group report needs the Group capability **`group.reporting.view`** on
  an unrevoked grant in the one named, active Group (v1: held by
  `group_admin`, alongside `group.schools.view` and
  `group.schools.elevate`; seeded in Phase 0N.11), plus current MFA
  assurance. One Group per request; grants in several Groups never pool.
- It is **never** implied by `platform_super_admin` or any `platform.*`
  capability (a root needs a real Group grant, from another root), by
  `platform.audit.view`, by School roles or `analytics.view` in any number
  of Schools, by multi-School membership, or by an active elevation.
- Per School, the Group layer re-checks the grant, the Group and the
  membership FOR SHARE, and Analytics re-checks the capability and the
  School's `active` status; the actor needs **no** School membership or
  School capability — this is the Group-safe path, not
  `AnalyticsReadGate::read()`.
- Only Analytics-registered Group-safe reports (v1 `curriculum.coverage`)
  exist on it; no Compliance, Automation or AI cross-School access; no
  export. See ADR 0048.

## Platform authority and platform audit review (ADR 0046 — built in Phase 0N.7)

- `platform_super_admin` is the **root / bootstrap** role: provisioned
  only by trusted, out-of-application means, never granted or revoked
  through the ERP application; losing every root account is recovered
  the same way. **Production provisioning path (Phase 0O.1):** the
  operator console command `php artisan platform:provision-root` —
  console only, on the migration/admin connection, one existing enabled
  account by exact email or id, typed confirmation (or `--force` for
  trusted automation), idempotent, audited as
  `platform.role_grant.provisioned` (actor null, `method: console`). It
  identifies the root role structurally (the non-runtime-assignable system
  platform role holding `platform.role_grants.manage`), never by name. See
  `docs/architecture/PRODUCTION-RELEASE.md` §5. **Phase 0O.1A:** the
  database itself refuses a grantor-less (out-of-band) platform grant from
  any role that does not hold the table owner's privileges — the runtime
  role cannot mint root by any path — and a fresh installation's first
  platform account is created by `php artisan platform:bootstrap-root`
  (interactive, first boot only, hidden password prompts, no School or
  Group authority).
- Runtime platform-role governance covers only **code-approved,
  `runtime_assignable`, non-root** roles (v1: `platform_auditor`), through
  the root-reserved `platform.role_grants.manage` — no role builder, no
  self-grant or self-revoke, no delegation chain. Root-reserved
  capabilities (`platform.role_grants.manage`, `platform.schools.manage` —
  School creation, root-only) are never held by a runtime-assignable role.
  Grants keep history; the database refuses a runtime grant or revocation
  of the root role (`roles.runtime_assignable`,
  `trg_platform_role_assignments_governance`). Every refused grant or
  revoke is audited as `platform.role_grant.denied`.
- **Platform audit review** needs `platform.audit.view` (root and
  `platform_auditor`; no School or Group role) **and current MFA
  assurance** (an enrolled factor and a sign-in code within the assurance
  window — no fresh code per page). It reads only
  `platform_audit_events`, establishes no School context, shows seven
  envelope fields and never metadata, IP or user agent, pages by keyset,
  and writes one `platform.audit_log.viewed` event per review. It is
  separate from the School audit-log review (`school.audit.view`); Schools
  see no elevation events in v1. See ADR 0046.
- **Service identities (Phase 0O.1):** issued and disabled by operators
  only, through `php artisan platform:service-identity-issue` (capabilities
  limited to `ai.tools.invoke`, `ai.audit.write`; the credential is shown
  once and stored only as a hash) and `platform:service-identity-disable`.
  `ServiceIdentitySeeder` (the development token) refuses outside
  `local`/`testing`. No HTTP or UI, no rotation or expiry yet (decision
  O5).

## School lifecycle and bootstrap administration (ADR 0047 — built in Phase 0N.9)

- CREATE, bootstrap School Admin assign/replace, ACTIVATE, SUSPEND and
  RESUME all require `platform.schools.manage` (root-reserved, so only
  the Platform Super Admin holds it; checked as a capability, never by
  role name), a **fresh in-session MFA re-verification**
  (`MfaReverificationService`) immediately before the action, and an
  explicit confirmation step. No narrower lifecycle capability is seeded.
- The **bootstrap exception** is the only platform path that writes School
  memberships: while a School is `provisioning`, the root may give an
  exact, existing, enabled user (never themselves) a real, ordinary
  membership and `school_admin` assignment, or replace that user. The
  path closes permanently at first activation. Activation needs a
  non-disabled user whose active membership grants
  `school.members.manage` and `school.roles.manage` in that School.
- **No ongoing platform membership administration in v1**: after
  activation, adding/removing members and granting/revoking School roles
  stay with the School (`school.members.*`, `school.roles.*`). Creating a
  School never makes its creator a School member; a School that later
  loses every admin awaits a future break-glass decision.
- **Staff and School-admin account provisioning (ADR 0059, contract
  Phase 0O.12A; built in 0O.12B).** Two flows:
  - **Bootstrap accounts.** An interactive operator console command creates
    a *credential-less* User. The one-time activation link is displayed
    once. The command writes no membership, role or Employee, and School
    authority still comes only from the bootstrap exception above.
    Activation additionally needs the administrator to have an established
    credential (`admin_not_activated`).
  - **Staff invitations.** Inside an active School, issuing needs
    `school.members.manage` **and** `school.roles.manage` plus fresh MFA.
    Resend and revoke need `school.members.manage`, listing needs
    `school.members.view`.
  - **Roles.** Only School-scope roles, and only ones whose capabilities
    the issuer already holds.
  - **Acceptance** creates an `active` membership and the roles. An
    existing User must be signed in; disabled Users, platform-role holders
    and existing members of that School are refused generically.
  - **Never:** elevation (rule 83), Group authority (rule 84), or a
    platform HTTP endpoint that creates Users.
  - **Off-boarding and roles (built in 0O.12B, owner amendment):**
    - Suspend and reactivate need `school.members.manage` +
      `school.roles.manage`; granting or revoking one role needs
      `school.roles.manage`; every change needs fresh MFA.
    - Suspension revokes every active role grant, kept as history.
      Reactivation grants newly chosen roles only.
    - Nobody changes their own access here.
    - No change may leave the School without a qualifying administrator
      (`App\Support\Authorization\SchoolAdministrators`, serialized per
      School).
    - Revoked grants never authorize: `CapabilityResolver` reads active
      grants only, and the runtime role cannot DELETE one.
- A suspended or provisioning School must grant nothing through any
  path: membership-derived capabilities still resolve, so every entry
  point refuses a non-`active` School — web, API and elevation (per
  request), and since Phase 0N.9 AI token minting and the internal AI
  endpoints, Guardian invitation acceptance and background business work
  (`SchoolOperationalGuard`, at execution time). See ADR 0047.
- As built: `SchoolLifecycleService` / `SchoolBootstrapAdministrationService`
  (`App\Domain\Platform\Application\Schools`), `/app/platform/schools`,
  limiter `platform-school-lifecycle`; refusals audited as
  `platform.school.lifecycle_denied` (operation + outcome code).

## External API credentials (ADR 0049 — contract Phase 0O.2, built Phase 0O.3)

- **Human API tokens** represent one User and never freeze authority: every
  request re-checks the enabled account, active membership, active School
  and the route's capability, then the token's closed scope (`api.read`,
  `api.write`). Tokens always expire, are issued only with a fresh MFA code
  from the Account/Security area and never exercise `platform.*` or
  `group.*` capabilities.
- **Partner API clients** are non-human and bound to exactly one School
  (immutable). They reach only explicitly registered `/api/v1/partner`
  routes through their approved scopes — never the human routes, never a
  client-supplied School. Managed under `integrations.api_clients.view` /
  `.manage` (fresh MFA for issue, rotate, revoke); Platform Super Admin has
  no partner-secret access.
- **As built (0O.3):** human tokens at `/app/account/api-tokens` (30/90
  days); `CapabilityResolver::isBearerAuthenticated()` empties platform and
  Group capabilities for bearer actors; `integrations.api_clients.view` /
  `.manage` are held by `school_admin` only; the partner surface has **no
  production route** (the production scope catalog is empty, O15).

## Account recovery (ADR 0056 — contract Phase 0O.10; built in Phase 0O.10A)

Password recovery is **identity-level**. It grants no School, membership,
capability, Group, platform authority or elevation, and never sets
`TenantContext`. It runs only on the canonical platform host.

- **Eligibility.** Active, non-root human Users with a local password.
  **Root** (`platform_super_admin`) has no public email path: its password
  is reset only by `platform:user-password-reset` on the operator console,
  and its MFA only by another root (`platform.users.mfa.reset`, ADR 0046).
- **What a reset does.** It preserves MFA, never auto-logs in, and bumps
  `users.credential_version`, which ends every browser session on every
  host (the cross-host handoff included). It also revokes the User's human
  personal access tokens and ends elevations. Partner API clients and
  service identities are separate principals and are never touched.
- **What it never accepts as identity proof:** a Host, custom-domain
  ownership, knowledge-based questions or helpdesk disclosure.
- **As built (0O.10A).**
  - The recovery routes are public by design (a person who lost a password
    has no session) and carry no capability. Their protection is the
    closed eligibility rule (checked at issuance AND at reset), the
    generic response, the limits and the possession of the emailed secret.
  - `platform:user-password-reset` and `platform:account-recovery-status`
    are operator-console only (no HTTP route) and platform-audited
    (`auth.password_reset_by_operator`, `auth.account_recovery_status_viewed`).
  - `EnforceCredentialVersion` runs before School, elevation and page
    logic, so a signed-out stale session never reaches an authorization
    check as its old User.
  - Every browser sign-in path stamps the credential version
    (architecture-tested); a pending MFA challenge started before a reset
    cannot complete.

## Manual / offline payment recording (ADR 0031 amendment — built in Phase 0O.11A)

`finance.payments.record` (School namespace) is the one human Payment
write. It records a cash, bank transfer or cheque payment the School has
already received.

- **Grant.** School Admin only, by default. A School may grant it to a
  cashier-style role of its own; the demo's `demo.finance_officer` holds it.
- **What does not imply it:** `finance.payments.view`, `finance.charges.*`
  and `finance.ledger.*`. `finance.payments.manage` does not exist.
- **Double check.** The Inertia controller checks it, and
  `ManualPaymentRecordingService::record()` checks it again with the actor
  it is given.
- **Order:** membership/TenantContext → capability → operational School
  (inside the transaction) → idempotency key. A replay never skips
  authorization (rule 32).
- **Other authority.**
  - Group authority has no School capability (rule 84).
  - An elevated platform session is refused with 403 on the route
    (rule 83; no `school-context:elevated`).
  - A platform role without a membership has no School context (409).
- **Payment-owned journal entries.** `finance.ledger.reverse` no longer
  reaches them. The Payments-owned
  `journal_entries_payment_reversal_guard` refuses the reversal for every
  role, and the API answers 409 `JOURNAL_ENTRY_NOT_REVERSIBLE`.
- **Tests.** `Tests\Feature\App\ManualPaymentUiTest` covers allow and
  deny over the real role catalog: principal, role-less members (Guardian
  accounts and ordinary staff), payments viewer, charges/ledger manager,
  non-member, platform root (plain and elevated), Group admin (plain and
  Group-derived elevation), and a recorder of another School.

## What is NOT yet implemented

Tenant-custom roles (role *assignment* exists: Settings → Staff accounts
grants and revokes School roles from the closed catalog, ADR 0059; role
*creation* does not), teacher ownership-based authorization (contracted by
ADR 0063, not built), a real "platform admin enters a
specific School's context" elevation workflow (the brief in section 12
deliberately asked for only the *foundation*, proven by denial — see
`CapabilityResolverTest::platform_capability_grant_does_not_imply_school_capability`
— not the workflow itself; ADR 0044's elevation substrate now exists
(Phase 0N.3) but no School operation accepts it yet), and any actor-category-specific UI beyond
the minimal login/dashboard/settings pages proving the architecture
(`docs/architecture/ARCHITECTURE.md`).
