# Phase 0N — Multi-School Management: Readiness Audit

**Status: BLOCKED — ARCHITECTURE / PRODUCT / SECURITY DECISIONS REQUIRED
(recorded 2026-09-24, baseline `0d4fc9a`).** No elevation into a School,
group/trust administration, cross-School reporting or platform School
management may be built until the decisions in section 15 are recorded.
This document states what exists and what must be decided; the audit
itself decided nothing and changed no code. **Update (Phase 0N.1,
2026-09-24):** the owner approved **D9(a)** and **D10(a)** and the
section 17 checkpoint implemented them (section 11, "Resolution"). That
removes the no-School 500 and gives `/app` a neutral state; it is a
tenancy-safety prerequisite only and does **not** unblock Phase 0N —
D1–D8 and D11–D18 remain open and the status above is unchanged.
**Update (Phase 0N.2, 2026-09-24):** the owner approved **D2–D8, D14 and
D17**; **ADR 0044 (Cross-Tenant Elevation Contract)** records them as an
implementation contract for temporary platform elevation into one School
(nothing built). D1, D11, D12, D13, D15, D16 and D18 remain open and the
status above is unchanged. **Update (Phase 0N.3, 2026-09-24):** the
elevation **substrate** is implemented (ADR 0044 "Implementation
amendment"): `platform.schools.elevate`, the `school_elevations` record,
exact-target start with a fresh MFA re-verification, a fixed 30-minute
lifetime, the banner, Exit, expiry and forced termination, and platform
audit — with **zero** School routes accepting elevated context. It
unblocks nothing else; the status above is unchanged. **Update (Phase
0N.4, 2026-09-24):** the owner approved **D1** and **D18**; **ADR 0045
(Group/Trust Governance Contract)** records a distinct Group scope,
platform-governed Group membership and grants, and Group-derived entry
through the same ADR 0044 elevation (nothing built). Still open: D11,
D12, D13, D15, D16 — the status above is unchanged. **Update (Phase 0N.5,
2026-09-24):** the Group authority foundation is implemented (ADR 0045
"Implementation amendment"): the `group` scope, `group_admin`, Group
grants, platform Group governance, the Group view, and Group-derived
elevation with recorded provenance — still with **zero** School routes
accepting elevated context, and with the owner's v1 classifications for
Group records. It unblocks nothing else. **Update (Phase 0N.6,
2026-09-24):** the owner approved **D12** and **D16**; **ADR 0046
(Platform Authority & Audit Governance Contract)** records the root role,
runtime-assignable non-root platform roles, `platform_auditor`, and the
platform audit review contract (nothing built). Still open: D11, D13,
D15. **Update (Phase 0N.7, 2026-09-25):** the platform authority and audit
foundation is implemented (ADR 0046 "Implementation amendment"):
`platform_auditor`, history-keeping platform grants with database
anti-escalation, and the MFA-protected platform audit log. It unblocks
nothing else. **Update (Phase 0N.8, 2026-09-25):** the owner decided
**D11** (create/activate/suspend/resume; archive and delete excluded
behind a legal gate) and **D13** for v1 (bootstrap School Admin before
first activation only; no ongoing platform membership administration);
**ADR 0047 (School Lifecycle & Bootstrap Administration Contract)**
records them (nothing built; proposed Phase 0N.9). **The only remaining
Phase 0N decision is D15** (Group / cross-School reporting). **Update
(Phase 0N.9, 2026-09-25):** the School lifecycle foundation is implemented
(ADR 0047 "Implementation amendment"): database-enforced lifecycle on
`schools.status` (`provisioning` default), root-only creation with the
bootstrap School Administrator, activation, suspension (eager elevation
termination, execution-time enforcement in every business substrate) and
resume, fresh MFA for every change, no runtime `DELETE` on `schools`. No
archive, delete, break-glass or platform membership administration. It
unblocks nothing else; D15 remains. **Update (Phase 0N.10, 2026-09-25):**
the owner decided **D15**; **ADR 0048 (Group Cross-School Reporting
Contract)** authorizes exactly one narrow cross-School read — the
`curriculum.coverage` report, under a new Group capability
`group.reporting.view`, executed one School at a time through an
Analytics-owned Group-safe path with no RLS change (nothing built; proposed
Phase 0N.11). **All Phase 0N decisions (D1–D18) are now recorded:
architecture decisions complete — the first cross-School report
implementation remains**, and Phase 0N stays in progress until it is built
(the roadmap scopes 0N as "administration and reporting"). Phase 0M
(`AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md`)
is independent and remains BLOCKED.

Sources: `docs/roadmap/MASTER-ROADMAP.md` ("Phase 0B", "Phase 0N — Multi-School
Management"), `docs/architecture/DOMAIN-MAP.md` (Layer 6 "Multi-School
Management"), ADR 0004 (tenant isolation), ADR 0017 (audit), ADR 0020,
ADR 0021 (runtime vs migration roles), ADR 0022 (context propagation),
ADR 0037 (MFA), ADR 0040/0042/0043 (Layer 5 cross-School deferrals),
`docs/architecture/TENANCY.md`, `docs/security/AUTHORIZATION.md`,
`docs/security/DATA-CLASSIFICATION.md`, `docs/modules/ORGANIZATION.md`,
`docs/modules/HR.md` ("Platform / privileged actor"),
`docs/architecture/PHASE-0L-CLOSEOUT.md`, `docs/development/DDEV-DEMO-REVIEW.md`.
Every "current state" claim below was checked against code, the schema
and a running DDEV instance (section 18).

## 1. Title and repository-defined scope

**Exact title** (roadmap): *Phase 0N — Multi-School Management.*

**Exact scope** (roadmap, verbatim): *"Group/Trust cross-school
administration and reporting, building on Phase 0B's explicit-elevation
model (ADR 0004) and its structural foundation (`school_groups`,
`school_group_members`) — the actual 'enter a member School's context as
a group/platform admin' workflow is still unbuilt after Phase 0B,
deliberately."*

DOMAIN-MAP (Layer 6): owns *"Group/Trust entities, cross-school
administration, group-level reporting"*; depends on Schools and Identity
& Access *"for the explicit elevated-access grants ADR 0004 describes"*;
*"Sits 'above' the tenant boundary, not inside it."*

| Requirement | Repository source | Current state | Missing work / blocker |
|---|---|---|---|
| Group/Trust entities | ADR 0004; DOMAIN-MAP; Phase 0B | `school_groups`, `school_group_members` tables and `App\Models\SchoolGroup` exist; no service, route, capability, UI or seed data (0 rows in DDEV) | Group administration model (D1, D18) |
| Cross-school administration by a group admin | ADR 0004 ("explicit, granted, audited elevation, never a default"); AUTHORIZATION.md actor table ("School Group Admin") | No group-scoped principal exists. `roles.scope` is `platform` or `school` only, enforced by database triggers on both assignment tables | Principal and grant model (D1); elevation design (D2–D8) |
| "Enter a member School's context as a group/platform admin" | Roadmap Phase 0B and 0N; TENANCY.md "What is NOT yet implemented"; AUTHORIZATION.md "What is NOT yet implemented" | Deliberately unbuilt. `CapabilityResolverTest::platform_capability_grant_does_not_imply_school_capability` proves the absence | Elevation controls are not specified anywhere (section 5) |
| Group-level reporting | Roadmap; DOMAIN-MAP | None. ADR 0040 §4, ADR 0042 and ADR 0043 each defer cross-School reads to their own future ADR | A cross-School read ADR (D15) |
| Platform Super Admin "dedicated, audited administrative path" | ADR 0004; TENANCY.md; AUTHORIZATION.md | Capabilities only; two platform actions exist (section 4) | Landing and scope of platform administration (D9, D11–D13) |
| School tenant lifecycle as a platform action | ORGANIZATION.md (`schools.status` is "a platform-administration concern … not built") | `active`/`suspended`/`archived` column; no code writes it | Lifecycle semantics (D11) — not named in the 0N roadmap entry, but it is the only home ORGANIZATION.md gives it |

The roadmap does not put School creation, a platform School directory,
platform membership administration or School deletion in Phase 0N by
name. Section 13–14 record where they would belong if the owner adds them.

## 2. Four different things

The repository treats these as separate; they must not be merged.

| | What it is | Exists today | In Phase 0N? |
|---|---|---|---|
| **A. Multi-School membership** | A person is a real member of several Schools and selects one | Yes (section 3) | No — already built in Phase 0B. Its no-School failure (section 11) is the prerequisite fix. |
| **B. Platform administration** | A platform operator manages Schools and platform settings without being a School member | Capabilities and two actions only | Partly: the roadmap says the "dedicated, audited administrative path" is the one elevation builds on. Which platform operations belong in 0N is D9/D11–D13. |
| **C. Cross-School reporting** | Reading data from more than one School at once | No (only operational counters, section 6) | Named by the roadmap ("group-level reporting"), but every Layer 5 ADR requires its own cross-School ADR first (D15). |
| **D. Elevation / entering a School** | Temporarily acting inside a School's context under explicit privileged authority | No | Yes — the roadmap's core item. Fully unspecified (section 5). |

## 3. Existing multi-School behaviour

| Capability / behaviour | Exists? | Production-ready? | Evidence | Limitation |
|---|---|---|---|---|
| A User in several Schools | Yes | Yes | `school_memberships` (central, `unique(user_id, school_id)`, status `invited`/`active`/`suspended`); DDEV `multi.school@example.test` is `principal` at Demo School and `school_admin` at Annexe | No staff membership administration exists (section 14) |
| School roles per membership | Yes | Yes | `membership_role_assignments` (RLS, composite FK to the membership, trigger rejects non-`school` roles) | System roles only; no role UI |
| Selecting the active School (web) | Yes | Yes | `POST /app/schools/{school}/activate` (`app.schools.activate`, `SchoolSwitchController`): requires an active membership and an active School, regenerates the session, audits `school_context.activated` in `platform_audit_events` | A refused selection is not audited (validation error only) |
| Session School context | Yes | Yes | `ResolveSchoolContext` re-validates `session('active_school_id')` against an active membership and `School::isActive()` on every request; a stale value resolves to no School | Nothing selects a School at login (every user starts with none) |
| Verified-domain School resolution | Yes | Yes | `school_domains` with `verified_at` | No domains in DDEV |
| API School context | Yes | Yes | `/api/v1/schools/{school}/…` with `school-membership` (`EnsureSchoolMembershipContext`): non-member or inactive School → 404 | — |
| School selection dashboard | Yes | Minimal | `/app` (`DashboardController`, `App/Dashboard.vue`) lists active memberships and activates one | Deliberately a Phase 0B primitive |
| `TenantContext` | Yes | Yes | One School (+ optional Campus) per unit of work; `set()` issues `set_config('app.current_school_id', …)`; `requireSchool()` throws `TenantContextRequiredException` | Single School by design (section 6) |
| RLS | Yes | Yes | 144 tables with RLS enabled and forced; runtime role `school_os_app` is `NOSUPERUSER NOBYPASSRLS` (checked in DDEV and by `RawIsolationTest`) | — |
| Cross-School record isolation | Yes | Yes | DDEV: multi.school with Annexe selected — own student 200, Demo School student 404; annexe.admin selecting Demo School — refused | — |
| Behaviour before a School is selected | Yes (Phase 0N.1) | Yes | Was: 135 of 141 School page routes returned 500 (section 11). Now: `school-context` (`RequireSchoolContext`) on every School web route returns to `/app`; mutations/JSON get 409 `school_context_required` | — |
| Platform Super Admin | Partly | Foundation only | Section 4 | Neutral `/app` landing (D9(a)); temporary elevation into one School (Phase 0N.3, ADR 0044) that opens no School page yet; no platform administration UI |
| School Groups | Yes (Phase 0N.5) | Foundation | ADR 0045 and its implementation amendment | Platform-governed Groups, grants and membership; Group Admin view and Group-derived elevation; no Group reporting (D15), no School lifecycle (D11) |

## 4. Platform Super Admin today

- **Meaning.** A `platform`-scoped role (`platform_super_admin`) in
  `platform_role_assignments` holding the eight `platform.*` (nine since
  Phase 0N.3, which added `platform.schools.elevate`)
  capabilities: `platform.schools.view`, `platform.schools.manage`,
  `platform.feature_flags.view`/`.manage`,
  `platform.service_identities.view`/`.manage`,
  `platform.operations.view`, `platform.users.mfa.reset`.
- **What uses them.** Only two: `platform.operations.view`
  (`GET /api/internal/operations/status`, plus the local/testing-only
  `/internal/mfa-demo/ping`) and `platform.users.mfa.reset`
  (`POST /app/account/admin/users/{targetUser}/mfa/reset`, no page).
  `platform.schools.manage` feeds only an unused dashboard nav flag
  (`canManagePlatformSchools`, never rendered). The other five are held
  by no route.
- **UI.** None. `/app` shows "None selected" and no School list;
  `/app/account/security` works. *(Phase 0N.1: `/app` now states it is a
  platform account with no School access; still no platform UI.)*
- **School membership.** None (DDEV `platform.admin@example.test`).
  `CapabilityResolver` resolves platform and School capabilities
  separately and never merges them; `can()` returns false for any
  non-`platform.*` capability without a School.
- **Entering a School.** Not possible. `SchoolSwitchController` requires
  a membership; DDEV: platform admin posting activate for Demo School →
  refused, still no School. There is no elevation mechanism.
- **Database privilege.** None. The runtime role has no `BYPASSRLS` and
  no superuser (ADR 0021, CLAUDE.md rule 26); platform admin is an
  application-layer capability only.
- **Ordinary School routes.** 135 of 141 return **500**, 3 return 403
  (section 11) — the known behaviour recorded in `DDEV-DEMO-REVIEW.md`.
  *(Phase 0N.1: every one now returns to `/app`; the account still never
  enters a School.)*
- **MFA.** No platform action requires MFA assurance (only the
  local-only demo route composes `mfa`).
- **Belongs in 0N?** Yes, as its first step: the roadmap builds
  elevation on the Platform Super Admin's "dedicated, audited
  administrative path", which does not exist yet. A platform-scope
  landing is part of making platform and School contexts explicit
  (section 17). The 500 itself is not platform-specific (section 11).

## 5. The cross-School elevation prerequisite

**Where it is defined.** ADR 0004 (group admin's cross-school access is
"an explicit, granted, audited elevation, never a default"; Platform
Super Admin is "a separate, explicitly audited path (not a 'bypass RLS'
flag …)"); TENANCY.md tenant model and "What is NOT yet implemented";
AUTHORIZATION.md actor table and "What is NOT yet implemented"; roadmap
Phase 0B ("no invisible cross-tenant bypass") and Phase 0N; the
`school_group_members` migration ("grants no access by itself …
platform_role_assignments / a future group-scoped role"); HR.md,
ADR 0042 §3 and ADR 0043 (each confirms no module invented its own
bypass and "entering a School's context remains the unbuilt, audited
elevation path").

**Why School switching is not enough.** Switching requires a real, active
membership with School roles and gives exactly that membership's
capabilities. A platform or group operator has no membership, so there is
nothing to switch to — and giving them one silently would be the
"invisible cross-tenant bypass" the Phase 0B brief forbade.

**What the repository specifies vs leaves open.**

| Property | Repository says | Status |
|---|---|---|
| Explicit (never implicit, never a default) | ADR 0004, TENANCY.md, AUTHORIZATION.md | **Required** |
| Granted (a real grant, not inferred from group membership or platform role) | ADR 0004; `school_group_members` migration | **Required**; the grant model is open (D1) |
| Audited | ADR 0004; ADR 0017 (permission grants always audited) | **Required**; event set open (section 8) |
| No RLS bypass, no superuser, no `pgsql_admin` use | ADR 0021; CLAUDE.md rule 26; ADR 0040 §4 | **Required** |
| Capability-gated | AUTHORIZATION.md (every protected operation) | **Required**; capability names open (section 7) |
| Whether platform admins (not only group admins) may elevate | ADR 0004/TENANCY.md imply a platform path exists; nothing states it may reach School data | **Decided** (D2, ADR 0044) |
| Reason / reason code | — | **Decided**: required closed reason code; catalog values still to choose (D4, ADR 0044 §5) |
| Explicit confirmation step | — | **Decided** (D4, ADR 0044 §6) |
| Time limit and automatic exit | — | **Decided**: bounded, absolute, no extension; duration value still to choose (D4, ADR 0044 §5) |
| MFA | ADR 0037 built MFA as a prerequisite for Highly Sensitive capabilities; no rule for platform actions | **Decided**: required to start (D5, ADR 0044 §9) |
| Second-person approval | — | **Decided**: not for establishing context in v1 (D6) |
| Banner / visible indication | — | **Decided** (D4, ADR 0044 §12) |
| Prohibited or restricted domains | AUTHORIZATION.md: derived access never implies source access; each module's own capability rules | **Decided**: zero modules by elevation alone (D7, ADR 0044 §§7–8) |
| Effective capabilities while elevated | — | **Decided**: none (D8, ADR 0044 §7) |

No privileged impersonation design exists in the repository, so none is
proposed here.

## 6. RLS and tenancy boundary

- **School-scoped tables.** 149 tables carry `school_id`; 144 have RLS
  enabled and forced (`TenantRls::enable()`, policy on
  `app.current_school_id`). The 5 with `school_id` and no RLS are
  deliberate central tables: `school_memberships` (must be readable
  before a School is chosen), `school_domains` (resolved before any
  context), `school_group_members` (structural), `domain_event_outbox`
  and `event_consumer_receipts` (ADR 0025 exception).
- **Platform tables (no RLS).** `schools`, `school_groups`,
  `school_group_members`, `school_domains`, `school_memberships`,
  `users`, `roles`, `capabilities`, `role_capabilities`,
  `platform_role_assignments`, `platform_audit_events` (append-only),
  `service_identities`, `feature_flags`, `user_mfa_factors`,
  `education_boards`, statutory Payroll rule tables, framework tables.
- **Runtime role.** `school_os_app`: `NOSUPERUSER NOBYPASSRLS`.
  `pgsql_admin` is migration-only.
- **How context reaches PostgreSQL.** `TenantContext::set()` →
  session-level `set_config('app.current_school_id', …, false)`;
  cleared with `RESET` at the end of the request or job. Missing
  context → the policy matches nothing.
- **More than one School per request.** Not possible: the GUC holds one
  id. Code may run School after School sequentially with
  `TenantContext::withSchool()`; the existing privileged example is
  `OperationalStatusService::webhooks()` (non-personal counters under
  `platform.operations.view`), and the scheduled commands that walk every
  School.
- **Cross-School aggregation.** Not prohibited outright (ADR 0004: "must
  go through code paths that are intentionally tenant-unscoped and are
  treated as privileged, audited operations"), but ADR 0040 §4, ADR 0042
  and ADR 0043 each require a new ADR before any cross-School business
  read.

**Cannot be built under the current RLS model** (and must not be made
possible by weakening it):

1. A single query returning rows from several Schools. A group view
   must iterate Schools one context at a time, or read only
   non-RLS platform data.
2. A "see every tenant" mode for a platform or group operator.
3. Elevation that does not pass through `TenantContext::set()` for
   exactly one School — i.e. any implementation granting database
   privilege rather than an application-layer capability.

## 7. Authorization model

- **Existing capabilities relevant to 0N:** the eight `platform.*`
  above; School-side `school.members.view`/`.manage`,
  `school.roles.view`/`.manage`, `school.audit.view`,
  `school.settings.*`, `school.profile.*`. `school.members.view` and
  `school.roles.*` are held by roles but gate no route;
  `school.members.manage` gates only Guardian account invitations.
- **Separation.** Platform and School roles stay strictly separate:
  database triggers on `platform_role_assignments` and
  `membership_role_assignments` reject a wrong-scope role (CLAUDE.md
  rule 25, `RoleScopeTriggerTest`). There is no third scope.
- **Likely needs** (names not proposed or seeded; following the
  existing dotted `namespace.resource.action` convention):

| Need | Existing capability? | Note |
|---|---|---|
| School directory / list | `platform.schools.view` exists, unused | Classification of School metadata first (D14) |
| School create / configure | `platform.schools.manage` exists, unused | In scope only if the owner adds it (D11). *Decided, ADR 0047: create, bootstrap admin, activate, suspend and resume all use `platform.schools.manage` (root-reserved); nothing new seeded* |
| School activate / suspend | Could reuse `platform.schools.manage` or need its own | Suspension semantics open (D11). *Decided, ADR 0047 §7–8* |
| Membership administration | School-side `school.members.manage` exists | Platform-side need open (D13). *Decided, ADR 0047 §6: bootstrap exception only* |
| Platform role grants | None | Who may grant `platform_super_admin` (D12) |
| Elevation into a School | None | Separate capability; never implied by `platform_super_admin` alone (D2) |
| Group administration | None; no group scope | D1 |
| Platform audit view | None (`compliance.platform.view` reserved) | ADR 0042 §13 item 2 (D16) |

## 8. Audit requirements

ADR 0017 makes permission grants and significant state changes always
audited, append-only, with actor, tenant, time, entity and before/after;
denied access to Sensitive data is "worth auditing in later phases". The
existing pattern: platform-level events go to `platform_audit_events`
via `AuditRecorder::platform()` (e.g. `school_context.activated`,
`auth.*`, MFA reset); School events go to `school_audit_events` via
`AuditRecorder::school()`.

Events any Phase 0N implementation would need (names and ledger
placement to be decided with the design):

| Event | Minimum content | Open question |
|---|---|---|
| School created / profile or lifecycle changed | actor, School, before/after status | Both ledgers? (a suspended School's own ledger records its suspension) |
| Group created / School added or removed | actor, group, School | — |
| Membership or role changed by a platform actor | actor, School, target user, role | Also in the School's ledger? |
| Platform role granted / revoked | actor, target user, role | Two-person? (D12) |
| Elevation requested / approved / started / ended / expired | human actor, target School, reason code, duration, approver | Both ledgers (the School should see who entered) — D4/D6 |
| Action performed while elevated | normal module audit plus an elevation reference | How the module's own audit carries it |
| Denied elevation or School selection | actor, target School, reason code | Today a refused selection is not audited (D17) |

No source-domain content (student, employee, financial data) belongs in
these records; identifiers and codes only, the same rule the School
audit-log review applies (metadata allowlist empty).

## 9. Data classification

`DATA-CLASSIFICATION.md` has **no row** for any 0N record. Proposed
starting points for the review (engineering view, not a decision):

| Record | Contents | Starting point | Why |
|---|---|---|---|
| School metadata (`schools`) | name, slug, status, legal name, contact email/phone, address, board | Confidential at least | Organisational, not personal; across Schools a directory is commercially sensitive |
| School configuration / settings / feature flags | settings values, flags | Confidential | Operational; may reveal security posture |
| Membership information | which user belongs to which School, status | Sensitive | Links an identifiable person to a School; for Student/Guardian members it reveals a child's School (children's-data gate) |
| Platform role information | who holds platform roles | Sensitive; security-relevant | Identifies privileged staff |
| Elevation records | who entered which School, when, why | Highly Sensitive (fail-safe, as audit records) | They are audit records |
| Platform audit events | sign-in, IP, user agent, MFA events | Highly Sensitive (existing v1 audit treatment) | Contains IP and user-agent personal data; no review surface exists |

Decision D14 records the actual tiers.

## 10. Sensitive-domain boundaries

Repository policy, per module: every Documents, HR, Payroll, Finance,
Students, Guardians, Compliance, Analytics, Automation and LMS action
checks its own School capability (and, for Student processing
authorizations, the `mfa` middleware). HR.md states it explicitly: *"No
HR-specific superadmin bypass exists … A Platform Super Admin who needs
HR access gets it the same way anyone does: a real membership and an
HR-capable role."* ADR 0042 and ADR 0043 say the same for Compliance and
Automation; AUTHORIZATION.md's Layer 5 principle says derived access never
implies source access.

Therefore an elevated actor must **not** gain any module's access
implicitly. Whether elevation carries any School capability at all, a
fixed limited set, or read-only access — and which domains are excluded
entirely (Highly Sensitive: HR sensitive records, Payroll, Finance,
Student processing authorizations, Documents, the audit log) — is D7/D8.
Any access granted must still pass each module's own check and MFA rule.

## 11. The no-School failure mode

**Observed (DDEV, 2026-09-24).** Signed in with no School selected,
across all 141 parameterless `GET /app…` routes:

| Result | Count | Routes |
|---|---|---|
| 200 | 2 | `/app`, `/app/account/security` |
| 403 | 3 | `/app/settings`, `/app/automation`, `/app/compliance/audit-log` (the capability check runs before any `requireSchool()` — `capability:` route middleware for settings, the controller's own check for the other two — and `can()` is false without a School) |
| 500 | 135 | every other School page: `TenantContextRequiredException` ("No School tenant context is set for this operation.") |

The result is **identical** for `platform.admin@example.test` and for
`multi.school@example.test` before choosing a School. Every user reaches
this state after every sign-in, because login never selects a School
(`LoginController::store()` → `/app`) and `DashboardController` does
not either.

**Why.** Most School controllers call `$context->requireSchool()` (359
calls in 83 controllers) before or while authorizing; nothing in the web
pipeline requires a School before a School-scoped controller runs.
`requireSchool()` failing closed is correct (no data is exposed); the
defect is that a normal, expected user state surfaces as a server error.

**Is this expected?** The fail-closed part is; the 500 is not — it is a
Phase 0B pipeline gap, not something a Phase 0B document intended. The
Platform Admin's 500 is one manifestation; any member hits the same path
before choosing a School. The `/api/v1` surface is unaffected (the School
is in the path and verified by `school-membership`).

**Architectural home.** The tenancy web pipeline (Layer 0, alongside
`ResolveSchoolContext`): a single "School context required" step on
School-scoped web routes that sends a no-School request to School
selection (and an explicit refusal for JSON) before any controller runs,
plus a platform-scope landing on `/app` for actors with platform
capabilities and no School. It should not be a per-controller fix, and
it should come with a guard test that every School-scoped route carries
the step, so future modules cannot regress it. It belongs at the start
of Phase 0N because making platform and School contexts explicit is the
precondition for elevation; it needs none of the elevation decisions.
Any new middleware should use the `try`/`catch` restore pattern from
TENANCY.md ("TenantContext cleanup"), not a bare `finally`.

**Resolution (Phase 0N.1, D9(a) + D10(a) approved).**

- **Route model.** `routes/web.php` has two signed-in groups. The
  context-neutral group (`auth` only) is exactly: `/logout`, `/app`,
  `POST /app/schools/{school}/activate`, the six
  `/app/account/security…` routes, the platform-scoped MFA reset
  (`/app/account/admin/users/{targetUser}/mfa/reset`) and the
  local/testing-only `/internal/mfa-demo/ping`. Every other signed-in web
  route is in the School group (`auth` + `school-context`).
- **The step.** `App\Http\Middleware\RequireSchoolContext` (alias
  `school-context`) does not resolve a School — `ResolveSchoolContext`
  still does, from the verified domain or the session selection
  re-validated against an active membership and an active School. It
  requires that a School was resolved, that it is active, that the
  account is not disabled and that the account holds an active membership
  in it; otherwise the controller never runs. It establishes nothing
  itself, so no context cleanup is needed there. It is pinned in the
  priority list after `StartSession`, `ResolveSchoolContext`,
  `DevOnlySchoolHeaderResolver` and `Authenticate`, and before
  `ThrottleRequests` and `SubstituteBindings` — so no School-scoped route
  model is bound (or 404s) before the check — and therefore before every
  `capability:`/`mfa` route middleware.
- **GET/HEAD without a valid School:** 302 to `/app`, which shows
  "Select a School to continue" once (session flash
  `school_context.required`). Inertia visits follow the same redirect.
- **Mutations and JSON without a valid School:** never a success-looking
  redirect. JSON (any method): `409` with
  `{"error": {"message", "status": 409, "code": "school_context_required", "requestId", "errors": null}}`.
  An Inertia mutation: `409` + `X-Inertia-Location: /app` (a hard visit
  to the landing, which says nothing was saved). A plain form post:
  `409` text. No repository convention existed for this; 409 was chosen
  because 403 must keep meaning an authorization denial and 404 resource
  isolation.
- **Stale context.** A session School that no longer resolves
  (membership suspended or removed, School suspended/archived or gone, a
  non-UUID value, a disabled account) is removed from the session and
  Inertia's history key is rotated; the User must select again. There is
  no fallback to another membership. `/app` clears a stale selection
  too, so a reactivated membership never re-selects its School on its
  own. `ResolveSchoolContext` now treats a non-UUID session value as no
  School (it previously reached PostgreSQL as an invalid-uuid error).
- **`/app`.** Renders with no School: memberships for explicit selection
  (never auto-selected), or — with none, including the Platform Super
  Admin — a neutral "no School access" state (plus "platform account" when
  the account holds any platform capability). No School data, no School
  list beyond the User's own memberships, no counts.
- **Unchanged.** `SchoolSwitchController` (membership + active School,
  session regeneration, `school_context.activated`); `/api/v1`
  (`school-membership`, School in the URL, non-member 404);
  `TenantContextRequiredException` stays the fail-closed invariant — on a
  School-group route it now indicates a bug; RLS, the runtime role, and
  audit (D17 stays open: denials are not audited). No migration, no new
  capability.
- **Guard.** `Tests\Feature\Tenancy\SchoolContextRouteGuardTest`
  fails if any signed-in web route (or any `app*` URI) lacks
  `school-context` without being on the explicit allowlist, if the
  allowlist names a missing route, if the step is ordered after binding,
  throttling, `capability:` or `mfa`, or if any School GET route (all of
  them, parameterised ones with a random id) does not return to `/app`
  without a School — for a member and for a Platform Super Admin.
  Behaviour: `Tests\Feature\Tenancy\SchoolContextRequiredTest`.
- **Re-probe (DDEV, 2026-09-24, after the change).** The same 141
  parameterless `GET /app…` routes, signed in with no School, for
  platform.admin, multi.school, school.admin, principal, teacher,
  student, guardian01, finance.officer, hr.payroll and reception: every
  account **200 × 2** (`/app`, `/app/account/security`) and **302 → `/app`
  × 139**; **0 × 500** (was 135) and **0 × 403** (the three former 403s
  now return to `/app` before their capability check). After selecting a
  School, genuine denials are still 403 (teacher/student/guardian on
  `/app/students`) and cross-School records still 404 (multi.school, both
  directions).

## 12. Cross-School features that stay outside Phase 0N

Phase 0N must not create a generic cross-School query path. These keep
their own gates:

- **Cross-School Analytics** — ADR 0040 §4 (own ADR; how the read path
  works without `BYPASSRLS`, which capability, cross-tenant
  classification; `analytics.platform.view` unseeded).
- **Cross-School / platform Compliance and platform audit review** —
  ADR 0042 §3 and §13; `compliance.platform.view` unseeded.
- **Cross-School / platform Automation** — ADR 0043;
  `automation.platform.view` unseeded.
- **AI across Schools** — ADR 0023 binds one School per context token;
  Phase 0M is BLOCKED and excludes cross-School AI.

"Group-level reporting" in the 0N roadmap entry is therefore blocked on
the same kind of ADR (D15); elevation into one School at a time does not
satisfy it and must not be stretched to.

**Resolution (Phase 0N.10, ADR 0048).** D15 is that ADR, and it doubles as
ADR 0040 §4's required cross-School Analytics ADR for exactly one report,
`curriculum.coverage`, under Group authority. Compliance, Automation and AI
cross-School access remain **not authorized**; `analytics.platform.view`,
`compliance.platform.view` and `automation.platform.view` remain unseeded.

## 13. School lifecycle

The roadmap's 0N entry does not name School lifecycle.
`docs/modules/ORGANIZATION.md` says the `schools.status` change is a
platform-administered action, not built, with no phase assigned. If the
owner puts it in 0N, these gates apply:

- **Create / initial setup.** No School-creation code exists; Schools
  come from seeders. Creating one needs its first School Admin
  membership — which is membership administration (section 14).
- **Activate / suspend.** Enforcement today is partial: `ResolveSchoolContext`,
  `SchoolSwitchController` and the API middleware refuse a non-active
  School, but queued jobs (`SetTenantContextForJob` loads the School by
  id only), outbox consumers and the scheduled commands that walk every
  School (webhook, communications and automation redispatch,
  announcement publishing, pruning) do not check status. What
  "suspended" must stop (web, API, jobs, webhooks, communications,
  payment callbacks, scheduled work) is undecided (D11).
- **Archive / delete.** Must not be built without the legal gates.
  All 147 foreign keys referencing `schools` are `ON DELETE CASCADE`,
  including `school_audit_events`, `journal_entries`, `students` and
  `school_memberships`, and the runtime role holds `DELETE` on `schools`
  (checked in DDEV; no code deletes a School). Deleting a School row
  would therefore remove its audit evidence and financial ledger with it.
  PHASE-0L-CLOSEOUT §6.2 already lists "legal holds and audit evidence
  surviving School deletion" and retention as **[LEGAL REVIEW
  REQUIRED]**; Finance (ADR 0030), HR/Payroll and Student records carry
  their own retention obligations. Recorded here, not changed.

**Resolution (Phase 0N.8, ADR 0047).** Lifecycle is
`provisioning → active → suspended → active` on the existing
`schools.status` column (new value `provisioning`; `archived` kept, no
transition). Suspension is enforced at execution time in every business
substrate (webhooks and communications deferred, automation skipped,
announcements held, AI and invitation acceptance refused), with platform
safety work continuing; the gaps listed above, plus AI minting and the
public Guardian invitation route found during 0N.8, are the Phase 0N.9
scope. Archive/delete stay excluded behind the legal gate; the runtime
role loses `DELETE` on `schools` in 0N.9.

## 14. Membership administration

- **Today.** Memberships are created only by seeders and by the Guardian
  invitation/activation flow (`GuardianAccountActivationService`, gated by
  `guardians.manage` + `school.members.manage`). There is no staff
  membership or role-assignment surface in any module;
  AUTHORIZATION.md lists "a UI for managing role assignments" as not
  implemented.
- **Ownership.** Membership and role assignment inside a School belong
  to the School (`school.members.*`, `school.roles.*` exist for that).
  The roadmap does not put platform-level membership administration in
  Phase 0N, so a platform module should not duplicate it; the School-side
  surface is its own future unit.
- **Where 0N touches it.** Creating a School needs a first School Admin;
  and "give the operator a real membership" is one possible alternative
  to elevation (D3). Both are decisions, not assumptions.
- **Resolution (Phase 0N.8, ADR 0047 §4, §6).** The platform may
  establish or replace the bootstrap School Admin (a real, ordinary
  membership and `school_admin` assignment for an exact, existing,
  enabled user) only while the School is `provisioning`; the path closes
  permanently at first activation. Ongoing platform membership
  administration is not authorized in v1; losing every School Admin later
  is a future break-glass decision.

## 15. Decision matrix

| # | Decision | Repository evidence | Options | Approval needed | Blocks implementation? |
|---|---|---|---|---|---|
| D1 | Group/Trust admin principal | ADR 0004; `school_group_members` migration ("a future group-scoped role"); `roles.scope` ∈ {platform, school}, trigger-enforced | (a) new `group` scope + assignment table; (b) platform role limited to named groups; (c) no group admin — platform only | Product + security (ADR) | **Decided (a), ADR 0045** — distinct Group-scoped principal (`group` roles, `group_role_assignments`); grants no School capability; School entry only via ADR 0044 elevation |
| D2 | May platform admins (not only group admins) enter a School? | ADR 0004 platform "audited path"; nothing authorises School data access | (a) yes, under D4–D8; (b) group admins only; (c) nobody — support via real membership | Product + security | **Decided (a), ADR 0044** — only through explicit temporary elevation |
| D3 | Elevation vs ordinary membership | Switching needs a real membership | (a) elevation as its own mechanism; (b) grant a time-boxed real membership; (c) membership only, no elevation | Security | **Decided (a), ADR 0044** — elevation is its own mechanism; never membership |
| D4 | Elevation controls | None specified | reason code and/or free text; confirmation; maximum duration; automatic exit; persistent banner | Security + product | **Decided, ADR 0044** — reason code (catalog TBD), confirmation, bounded lifetime (value TBD), banner, Exit, one School, no nesting |
| D5 | MFA for platform actions and elevation | ADR 0037 (MFA for Highly Sensitive capabilities); no platform rule | (a) `mfa` on every platform action; (b) on elevation only; (c) none | Security | **Decided for elevation, ADR 0044** — existing MFA required to start elevation; MFA for other platform actions not decided |
| D6 | Second-person approval for elevation | None | none / by another platform admin / by the School's own admin | Product + security | **Decided (none in v1), ADR 0044** — later ADR may require it per elevated operation |
| D7 | Domains excluded or restricted while elevated | HR.md, ADR 0042/0043, AUTHORIZATION.md Layer 5 principle | read-only; exclude Highly Sensitive modules; per-module opt-in | Security (+ legal for children's data) | **Decided, ADR 0044** — elevation alone authorizes zero source modules; per-operation opt-in only |
| D8 | Effective capabilities while elevated | None | fixed "support" capability set; copy of a School role; nothing beyond School setup | Security | **Decided, ADR 0044** — elevation establishes context, grants no School capability |
| D9 | Platform-admin landing | DDEV-DEMO-REVIEW ("no platform UI") | (a) platform-scope landing showing no tenant data; (b) School directory (needs D14) | Product | **Approved (a) and implemented, Phase 0N.1** |
| D10 | No-School behaviour on School routes | Section 11 | (a) redirect to School selection, no auto-select; (b) also auto-select a single active membership | Product | **Approved (a) and implemented, Phase 0N.1** |
| D11 | School lifecycle in 0N, and what suspension stops | ORGANIZATION.md; section 13 | include create/activate/suspend; defer archive; delete excluded | Product + legal (archive/delete, retention) | **Decided, ADR 0047** — create (root, `provisioning`), activate (bootstrap admin required), suspend (closed reason codes; execution-time enforcement per substrate), resume (no global replay); fresh MFA for every lifecycle action; archive/delete excluded pending a retention/legal decision |
| D12 | Who may create Schools and grant platform roles | No platform-role administration exists | platform_super_admin only; two-person rule; CLI-only | Security | **Decided, ADR 0046** — `platform_super_admin` is root/bootstrap (never runtime-granted); School creation root-only (what it means stays D11); root grants only code-approved non-root roles (v1: `platform_auditor`); no self-grant |
| D13 | Platform-level membership administration | Section 14 | out of 0N (School-owned); first School Admin at creation only | Product | **Decided for v1, ADR 0047** — bootstrap School Admin before first activation only; no ongoing platform membership administration; break-glass recovery a future decision |
| D14 | Classification of School metadata, memberships, platform roles, elevation records, platform audit | No rows in DATA-CLASSIFICATION.md (section 9) | tiers per section 9 or stricter | Security / privacy | **Decided for elevation records only, ADR 0044** — Highly Sensitive (v1); *ADR 0045 (Group records), ADR 0046 (platform audit, platform role assignments) and ADR 0047 (School metadata/lifecycle Confidential, bootstrap relationship Sensitive, lifecycle audit Highly Sensitive) added rows*; ordinary School memberships beyond the bootstrap relationship still unclassified |
| D15 | Group-level / cross-School reporting | ADR 0040 §4, ADR 0042, ADR 0043 | own ADR; or remove reporting from 0N | Product + security (+ legal) | **Decided, ADR 0048** — Group-scoped `group.reporting.view` (v1: `group_admin`); only Analytics-registered Group-safe reports (v1: `curriculum.coverage`); per-School execution, one TenantContext at a time, active member Schools only; source-defined aggregation; current MFA; platform-ledger audit; no persistence, export, Compliance, Automation or AI cross-School access; implementation is Phase 0N.11 |
| D16 | Platform audit review surface | ADR 0042 §13 item 2 | who may read `platform_audit_events`, with what metadata | Security | **Decided, ADR 0046** — `platform.audit.view` (root + `platform_auditor`); context-neutral, Highly Sensitive, empty metadata allowlist, access audited; Schools see no elevation events in v1 |
| D17 | Auditing denied selection / elevation | ADR 0017 ("worth auditing in later phases") | audit denials in `platform_audit_events` or not | Security | **Decided, ADR 0044** — denied elevation audited in the platform ledger (denied School selection unchanged) |
| D18 | Group membership governance | None | who adds or removes a School from a group; does the School consent | Product + legal (data-sharing) | **Decided, ADR 0045** — platform-governed in v1; Group Admins cannot change membership, Groups or grants |

## 16. Readiness status

**BLOCKED — ARCHITECTURE / PRODUCT / SECURITY DECISIONS REQUIRED.**

Partial School switching existing does not make Phase 0N ready: its core
items — elevation, group administration, group reporting — have no
specified design, principal model or controls (D1–D8, D15, D18), and
each needs a new ADR. *(Phase 0N.2: elevation now has its contract,
ADR 0044, and may proceed to its substrate checkpoint once the owner
records the implementation-time values listed there — elevation
duration, reason-code catalog, MFA re-verification vs. re-login, target
selection. Group administration, group reporting, School lifecycle,
platform role grants, platform membership administration and platform
audit review remain blocked on D1, D11–D13, D15, D16, D18.)*
Engineering prerequisites:

| Prerequisite | Status |
|---|---|
| No-School web requests handled without a 500 | **Done** (Phase 0N.1, D10(a); section 11 "Resolution") |
| Platform-scope landing | **Done** as the neutral `/app` state (Phase 0N.1, D9(a)); no platform administration UI |
| Elevation substrate | **Done** (Phase 0N.3, ADR 0044 amendment); no School route opted in |
| Classification rows for 0N records | **Elevation records done** (D14, ADR 0044, DATA-CLASSIFICATION.md); Group records (ADR 0045), platform audit/roles (ADR 0046), School metadata/lifecycle and the bootstrap relationship (ADR 0047) added; ordinary memberships still missing |
| Suspension enforced beyond web/API | **Done** (Phase 0N.9, ADR 0047 amendment): execution-time checks in webhook, communication, announcement, automation, AI and invitation paths |
| School deletion safe for audit/finance evidence | **Guarded, not solved** (Phase 0N.9): the runtime role cannot `DELETE` a School; the cascade FKs remain, and archive/delete need a retention/legal decision |
| RLS, runtime role, platform/School role separation | Present and verified |

## 17. First implementation checkpoint (implemented as Phase 0N.1)

**School context and platform landing foundation** — implemented as
**Phase 0N.1 — Safe School Context & Platform Landing** after the owner
approved D9(a) and D10(a); section 11 "Resolution" records what was
built and how it differs in detail from the proposal below (the JSON and
mutation refusal is 409 `school_context_required`; the landing's neutral
state applies to every account without a membership, not only platform
accounts). The proposal as written in the audit:

1. One named web middleware, applied to every School-scoped web route,
   that requires a resolved School before the controller runs: a web
   request with none goes to School selection on `/app` with a message
   (Inertia-safe redirect); a JSON request gets a stable refusal
   (a 4xx code), never a 500. Capability checks stay where they are,
   after it. No auto-selection.
2. `/app` for an actor with platform capabilities and no membership:
   a platform-scope landing that states the account has no School
   context and shows no tenant data, School list or counts.
3. A guard test asserting every School-scoped `app/*` web route carries
   the middleware (explicit allowlist: `/app`, School activation, account
   security, logout, platform MFA reset).
4. Tests: platform admin, multi-School user before and after selection,
   suspended membership, suspended School, a stale session School, and
   the three currently-403 routes; no route returns 500 without a
   School; switching and isolation unchanged; no new capability, no
   migration, no audit change (D17 stays open).
5. Update `DDEV-DEMO-REVIEW.md` (the "500 until you select a School"
   note) and this document.

Out of this checkpoint: elevation, School directory, School lifecycle,
groups, membership administration, any cross-School read.

## 18. How this was verified

- Baseline `origin/main` = `0d4fc9a`, single worktree.
- Roadmap, DOMAIN-MAP, ADR 0004/0017/0021/0022/0037/0040/0042/0043,
  TENANCY.md, AUTHORIZATION.md, DATA-CLASSIFICATION.md, ORGANIZATION.md,
  HR.md, PHASE-0L-CLOSEOUT.md and DDEV-DEMO-REVIEW.md read; searches for
  Phase 0N, multi-/cross-School, elevation, platform admin, `BYPASSRLS`,
  School context and group terms across `docs/` and `apps/platform/`.
- Code: `ResolveSchoolContext`, `SchoolSwitchController`,
  `DashboardController`, `LoginController`, `EnsureCapability`,
  `AuthorizesCapability`, `CapabilityResolver`, `TenantContext`,
  `SetTenantContextForJob`, `EnsureSchoolMembershipContext`,
  `OperationalStatusService`, `CapabilityAndRoleSeeder`, the Phase 0B
  tenancy migrations, `DemoDataBuilder`.
- DDEV (read-only observation; database `db`, runtime role
  `school_os_app` with `rolbypassrls = false`): route matrix for
  platform admin and multi.school with no School; multi.school with
  Annexe selected (own student 200, Demo student 404); annexe.admin and
  platform admin refused Demo School; memberships and roles; 0 School
  groups; RLS and FK catalog queries; `DELETE` grant on `schools`.
  Side effect: the sign-ins and one School selection added
  `auth.login_succeeded` and `school_context.activated` rows to the
  DDEV demo's `platform_audit_events`; no other data changed.
