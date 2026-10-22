# ADR 0076: Platform & Organization Control-Plane Contract (ORG.1)

- Status: **Accepted. ORG.1 CONTRACT PUBLISHED (2026-10-17, documentation
  only). ORG.1A FOUNDATION HARDENING IMPLEMENTED (2026-10-18, §27): V-1 to
  V-5 closed; three rollback-proven migrations; no new capability, role,
  route or Organization feature. ORG.2 AUTHORIZATION COVERAGE GUARD
  IMPLEMENTED (2026-10-18, §28): every School-facing route has a
  machine-checked authorization contract; two authorization findings
  fixed; no new capability or role. ORG.3 PLATFORM SCHOOL REGISTRY
  IMPLEMENTED (2026-10-18, §29): read model, search, filters, keyset
  pagination, derived readiness and warnings, School overview under
  `platform.schools.view`; no new capability, role, table or migration.
  ORG.4 PLATFORM OPERATOR PERSONAS IMPLEMENTED (2026-10-18, §30): Platform
  Manager (`platform_manager`) and `platform.schools.onboard`; Auditor reads
  the Registry and Groups; Manager/Auditor exclusivity in service and
  database; platform-route coverage guard. ORG.5 SCHOOL MODULE
  ENTITLEMENTS IMPLEMENTED (2026-10-18, §31): code-owned catalogue (7 core,
  22 optional), `school_module_entitlements` (FORCE RLS, no DELETE),
  existing Schools backfilled, new Schools core-only, CapabilityResolver
  intersection, execution-time guard on optional-module background work,
  `platform.school_entitlements.view/.manage` for root and Manager, the
  dead `students.processing_authorizations` flag retired. ORG.6 PLATFORM
  AUDITOR CONFIGURATION OVERSIGHT IMPLEMENTED (2026-10-18, §32): the Auditor
  also holds the existing `platform.school_entitlements.view` and reads each
  School's module state on the Registry overview; no new capability, route,
  table or migration. ORG.7A CAMPUS AUTHORITY FOUNDATION IMPLEMENTED
  (2026-10-18, §33): optional `campus_id` scope on School role assignments
  (NULL = whole School, unchanged), scope-aware resolver path, Campus scope
  adopted for Visitor visits and Inventory stock; Hostel, Transport and
  Library deferred (Student lookup needs the SIS wave). ORG.7B SECOND
  ADOPTION WAVE CLOSED (2026-10-18, §34): Visitor cross-Campus check-in
  disclosure removed, shared lookups pinned minimal; HR, Admissions,
  Communications, Fees, Payments and Canteen audited and DEFERRED with
  recorded decision points; no new capability adopted. ORG.7C ACADEMIC & SIS
  CAMPUS DEPENDENCY AUDIT DONE (2026-10-18, §35, read-only): proposed
  Student, HR and Admissions Campus contracts, capability matrix, defects
  found (not fixed), recommended sequence. ORG.7D-0 CAMPUS PREREQUISITE
  HARDENING IMPLEMENTED (2026-10-18, §36): Room and enrollment Campus bound
  in the database, active-Campus writes, Canteen picker authority,
  whole-School-only operation form; no Campus capability adopted.** The next ORG phase needs
  separate, explicit owner authorization.
- Date: 2026-10-17
- Programme: **ORG — Platform & Organization Foundation**
  (`MASTER-ROADMAP.md`, post-foundation programme 11).
- Baseline: `origin/main` = local `main` = `HEAD` =
  `f183c851ae229ad83c6649781358b4548602ea5a` (LC-4). The tree is clean,
  there is one worktree, and the historical stash (`phase-5d3-preflight`)
  is untouched.
- Evidence: the ORG.0 readiness audit (2026-10-17, read-only, reported to
  the owner in session; its findings are summarized in §1 and its defects
  in §24).
- Amends: **ADR 0046** (D12 decision 2; frozen invariants 3 and 6) and
  **ADR 0047** (§1 decisions 1 and 5; §7; invariant 7). Each carries an
  amendment note pointing here. Cross-referenced, unchanged: ADR 0004,
  ADR 0020, ADR 0044, ADR 0045, ADR 0048, ADR 0071.

## 1. Context

The owner wants a governance/control plane for Lycenza: a place from
which platform operators manage every School tenant, and a School-side
organization model (Schools, Campuses, staff, roles, modules) that can
express real operating structures. Reference-ERP screenshots and menu
matrices were supplied as requirement evidence.

ORG.0 audited the repository at `f183c851` and found that most of the
foundation already exists and is database-enforced:

| Concept | State at `f183c851` | Canonical implementation |
|---|---|---|
| Tenant | **Exists** | School; `school_id` (ADR 0004, ADR 0020); `BelongsToSchool`/`SchoolScope` (fail closed); `TenantRls` FORCE RLS; `school_os_app` without BYPASSRLS |
| Platform plane | **Partial** | `App\Domain\Platform\Application\{Schools,Elevation,Groups,Roles,Audit,Domains}`; `/app/platform/{schools,groups,roles,audit-log,elevation}` |
| Platform roles | **Partial** | `platform_super_admin` (root, out of band), `platform_auditor` (only runtime-assignable role, `platform.audit.view` only) |
| School lifecycle | **Exists** | `SchoolLifecycleService` (create, activate, suspend, resume, close, reopen); `trg_schools_status_transition`; no archive, no delete |
| School Groups | **Exists** | ADR 0045 / ADR 0048; `group.*` scope; reporting only |
| Campus | **Data only** | `campuses` (`unique(id, school_id)`), composite FKs from ~25 tables; **no authorization uses Campus** (`membership_role_assignments` has no campus column; `CapabilityResolver` has no campus parameter; no HTTP path sets `TenantContext::campus()`) |
| Roles | **Exists** | Global templates (ADR 0071): 17 School staff roles + `guardian`; runtime cannot write the catalogue |
| Capabilities | **Exists** | 196 keys; one Gate `capability` → `CapabilityResolver`; four scopes that never mix (DB triggers) |
| Ownership scope | **Exists** | ActingEmployee + TeachingOwnership; self scope; `GuardianStudentScope` |
| Module entitlement | **Missing** | Only per-School feature flags (`FeatureFlagResolver`, "NEVER an authorization check"; two flags; no writer) |
| Navigation | **Exists** | `StaffNavigation` + `NavigationBuilder`: a capability projection; every route re-checks |
| Audit | **Exists** | `school_audit_events` (RLS, append-only), `platform_audit_events` (append-only); one writer `AuditRecorder` |

ORG.0 also found what is missing: a Platform Manager persona (blocked by
ADR 0046 D12 and the root-reserve trigger), Campus-scoped authority,
module entitlements, a single authorization coverage guard, and a real
School registry (search, filter, counts, readiness). **This ADR freezes
the decisions those phases need.** It redesigns nothing that works.

### 1.1 Terminology

| Product / UI word | Canonical repository concept | Persistence |
|---|---|---|
| Platform, Platform Console | The Lycenza platform control plane | Platform tables (no `school_id`) |
| Organization, School, Tenant | **School** | `schools`, `school_id` |
| Branch, Campus | **Campus** | `campuses`, `campus_id` |
| Group, Trust, Franchise | **School Group** | `school_groups` |
| Platform Admin | `platform_super_admin` (root) | `platform_role_assignments` |
| Platform Manager | a future runtime-assignable platform role (§7) | not created |
| Platform Auditor | `platform_auditor` | `platform_role_assignments` |
| Module | a future code-owned product module (§12) | not created |

The UI may display "Organization" (for the School's own administration
area) and "Branch" (for Campus) where that helps users. Persistence,
capability keys, route names and authority semantics keep the canonical
words. `tenant_id`, `organisation_id`, `organization_id` and `branch_id`
remain forbidden column names (ADR 0020).

## 2. Owner decisions (2026-10-17)

The product owner approved D1–D20 for ORG.1. They are recorded here as
ORG-D1–ORG-D20 and specified in the sections named.

| ID | Decision | Section |
|---|---|---|
| ORG-D1 | School remains the only canonical tenant; ADR 0004 / ADR 0020 stay authoritative | §4 |
| ORG-D2 | No new Organisation entity above School; School Groups remain the cross-School construct with ADR 0045 / ADR 0048 semantics; cross-School operational roles deferred | §4, §18 |
| ORG-D3 | Campus is the canonical Branch entity; no Branch table | §10 |
| ORG-D4 | Role does not define scope; no role-name branching | §11 |
| ORG-D5 | School role assignments may later carry an optional Campus scope; NULL = whole School; existing grants keep their meaning; adoption is incremental by domain | §11 |
| ORG-D6 | Platform Admin = existing root (`platform_super_admin`); no second root-equivalent role | §6 |
| ORG-D7 | A distinct, runtime-assignable Platform Manager persona, created in a later phase | §7 |
| ORG-D8 | Platform Manager may not activate, suspend, resume, close, reopen or purge a School, nor manage platform roles, Group grants or School authority | §7 |
| ORG-D9 | Platform Auditor remains strictly read-only | §8 |
| ORG-D10 | Platform authority never becomes School authority; ADR 0044 unchanged; no impersonation | §5 |
| ORG-D11 | Module entitlements are a new concept: effective capabilities = role/scope grants ∩ capabilities of entitled modules | §12 |
| ORG-D12 | Feature flags and entitlements remain different mechanisms | §13 |
| ORG-D13 | Existing Schools must not lose functionality when entitlements arrive | §22 |
| ORG-D14 | A non-disableable core module set exists | §14 |
| ORG-D15 | Navigation remains a projection of authority | §15 |
| ORG-D16 | Approvals remain domain capabilities; no generic workflow engine; naming rule for new capabilities | §16 |
| ORG-D17 | The eight-layer authorization evaluation order | §17 |
| ORG-D18 | Global role templates remain canonical; no tenant-owned roles in ORG | §19 |
| ORG-D19 | Reference role names are evidence, not requirements | §19 |
| ORG-D20 | SIS stays paused through ORG.1–ORG.6; SIS is the last Campus-scope consumer; LC-5–LC-10 remain open and paused | §23 |

### 2.1 Contract defaults added by this ADR

ORG.1 also had to settle questions the owner decisions leave open. These
are contract defaults; the owner may override any of them before the
phase that implements it.

| ID | Default | Section |
|---|---|---|
| ORG-C1 | A Campus-scoped assignment confers only capabilities whose domain has adopted Campus scope; until then it confers nothing (fail closed) | §11.3 |
| ORG-C2 | Authority over School access (`school.members.*`, `school.roles.*`, the grant rights) and School-wide configuration stay whole-School only in v1 | §11.4 |
| ORG-C3 | Platform Manager and Platform Auditor are mutually exclusive on one user | §7.4 |
| ORG-C4 | Registry readiness is advisory and derived; it never becomes an activation precondition beyond ADR 0047 §5 | §9.3 |
| ORG-C5 | A capability may require more than one module; it is available only when every one is entitled | §12.3 |
| ORG-C6 | `TenantContext::campus()` is context, never authority | §11.8 |
| ORG-C7 | Entitlement changes must take effect within a documented staleness bound no longer than today's 60 s capability cache | §12.8 |

## 3. Existing architecture preserved

Nothing in ORG weakens, replaces or bypasses:

1. School tenancy (ADR 0004, ADR 0020, ADR 0022), `BelongsToSchool`,
   fail-closed `SchoolScope`, FORCE RLS (`TenantRls`), and `school_os_app`
   without BYPASSRLS or superuser (ADR 0021; CLAUDE.md rules 5, 17–23, 26).
2. The four authority scopes and their database separation (CLAUDE.md
   rules 25, 84, 85; `trg_role_capabilities_scope`,
   `roles_scope_check`).
3. Root provisioning out of band and the root-reserve triggers (ADR 0046,
   0O.1A amendment).
4. Elevation that grants no School capability, fixed 30 minutes, default
   deny on every School route (ADR 0044; CLAUDE.md rule 83).
5. The School lifecycle, its database transition trigger, closure, no
   archive, no delete, and execution-time re-checks (ADR 0047; CLAUDE.md
   rule 86).
6. The staff role catalogue, grant authority and grant history (ADR 0071;
   CLAUDE.md rules 92–95).
7. Teacher ownership (ADR 0063), Guardian scope (ADR 0070), self-service
   and self-administration protections (ADR 0065, ADR 0071 §26).
8. Legal and environment gates (production refusals in code, `legal-gated`
   capabilities held by no role) and every retention decision (E21).
9. Separation-of-duties checks in the application and the database
   (fee concessions, payroll runs, mark corrections, leave, communications).
10. The capability-projected navigation (ADR 0073 §6, §17).
11. The two audit ledgers and their separation (ADR 0017, ADR 0046 §11).

## 4. School is the canonical tenant (ORG-D1, ORG-D2)

- **School is the only tenant.** Every tenant-owned row carries
  `school_id`; every query is School-scoped by construction and by RLS.
- **No entity above School is introduced.** The hierarchy is:

  ```
  Lycenza Platform
    ├── School A ── Campus A1, Campus A2
    ├── School B ── Campus B1
    └── School Groups (cross-School governance/reporting, ADR 0045/0048)
  ```

- **School Groups keep exactly their ADR 0045 / ADR 0048 semantics.** A
  Group is never tenant context, grants no School capability, and reaches
  tenant data only through the one registered Group-safe report.
- **A multi-School organisation is represented as several Schools**,
  optionally in a Group. A person who works for several Schools holds a
  separate membership and separate grants in each.
- **Cross-School operational roles** (e.g. a corporate finance officer
  acting in several Schools at once) are **deferred** until a concrete
  business requirement and a separate ADR exist (§18).

## 5. Platform authority vs School authority (ORG-D10)

**Platform authority is never School authority.**

1. A platform role holds only `platform.*` capabilities (database:
   `trg_role_capabilities_scope`). No platform role, present or future,
   carries a School, Group or portal capability.
2. `CapabilityResolver` answers platform, School and Group questions
   separately and never merges them. A platform capability never satisfies
   a School check.
3. A platform operator obtains School authority only the way anyone does:
   an ordinary, active School membership with ordinary School grants,
   given by that School's own administrators (ADR 0047 D13 after
   activation; the bootstrap path before it).
4. Elevation (ADR 0044) establishes context only and grants no School
   capability. No School route accepts elevation (CLAUDE.md rule 83).
   Making any School operation usable under elevation still needs its own
   ADR, a narrow `platform.*` capability and allow/deny tests.
5. **No impersonation or "view as user" exists or is authorized.** Any
   future proposal is a separate, high-risk ADR (at minimum dual control,
   a closed reason code, a time bound, visible notice and full audit).
6. A School role, however powerful, never implies platform scope. A School
   Administrator sees only their own School.

## 6. Platform Admin = root (ORG-D6)

"Platform Admin" in the product is the existing **`platform_super_admin`**
root role. Nothing changes:

- **Provisioning:** out of band only (`platform:bootstrap-root`,
  `platform:provision-root`) on the admin connection; never granted or
  revoked in the application; rare.
- **No second root-equivalent role** (CLAUDE.md rule 85). The
  root-reserved capabilities `platform.role_grants.manage` and
  `platform.schools.manage` stay on root only (trigger
  `trg_role_capabilities_root_reserved`).
- **Duties (v1):**

| Duty | Capability | Controls |
|---|---|---|
| School registry view | `platform.schools.view` (once wired, §9) and today's lifecycle pages | ordinary session |
| Create / onboard a School | `platform.schools.manage` (today); the onboarding capability as well once it exists (§7) | fresh MFA, confirmation |
| Activate, suspend, resume, close, reopen | `platform.schools.manage` | fresh MFA, confirmation, closed reason codes |
| Grant / revoke runtime platform roles | `platform.role_grants.manage` | audited; **fresh MFA to be added in ORG.1A** (§24, V-2) |
| Group governance and Group grants | `platform.school_groups.manage`, `platform.school_group_grants.manage` | audited |
| Module entitlements (future) | entitlement capability (§12) | fresh MFA, reason, impact preview |
| Platform audit review | `platform.audit.view` | current MFA assurance |
| Elevation | `platform.schools.elevate` | ADR 0044 controls |
| Reset another user's MFA | `platform.users.mfa.reset` | existing |

- **The UI may label root "Platform Admin";** code and checks keep using
  capabilities, never the role name (CLAUDE.md rule 24).
- **Root must never:** hold School capabilities; read tenant business data
  or `school_audit_events`; impersonate; delete or purge a School (tenant
  destruction is not authorized, E21-D11); edit the capability catalogue
  at runtime; grant root.
- **Dual approval** for close and reopen is recommended for a later
  hardening phase once more than one root is guaranteed. It is not
  adopted now, because v1 cannot guarantee a second root is available.

## 7. Platform Manager (ORG-D7, ORG-D8)

### 7.1 Purpose

The Platform Manager is an **operational tenant-onboarding role, not a
weaker root.** It prepares Schools for service. It never decides whether a
School operates, never governs platform authority and never acts inside a
School.

### 7.2 Capability split

`platform.schools.manage` **remains root-only and root-reserved.** The
Platform Manager never receives it. Onboarding is delegated through new,
narrower, non-root-reserved capabilities, introduced by a later
capability-catalogue release (ORG.4). The duties separate as follows:

| Duty | Capability | Platform Manager | Root |
|---|---|---|---|
| **VIEW** — School registry | `platform.schools.view` (existing key, final) | yes | yes |
| **VIEW** — School Groups | `platform.school_groups.view` (existing key, final) | yes | yes |
| **ONBOARD** — create a School in `provisioning`; set its permitted provisioning metadata; establish / replace the bootstrap administrator while `provisioning` | new key, provisionally `platform.schools.onboard` | yes | yes |
| **ENTITLEMENT MANAGEMENT** — view and change non-core module entitlements | new keys, provisionally `platform.school_entitlements.view` / `.manage` | yes | yes |
| **LIFECYCLE CONTROL** — activate, suspend, resume, close, reopen | `platform.schools.manage` (root-reserved) | **no** | yes |
| **SECURITY GOVERNANCE** — grant / revoke platform roles | `platform.role_grants.manage` (root-reserved) | **no** | yes |
| **GROUP GOVERNANCE** — Group membership and Group grants | `platform.school_groups.manage`, `platform.school_group_grants.manage` | **no** | yes |
| **ELEVATION** | `platform.schools.elevate` | **no** (v1) | yes |

Keys marked "provisionally" are finalized by the ORG.4 catalogue release,
under the existing naming convention (`platform.<resource>.<verb>`). The
role key is provisionally `platform_manager`.

### 7.3 Rules for the Platform Manager

1. **Onboarding stays inside ADR 0047.** The Manager may create a School
   only into `provisioning`. The bootstrap administrator must be an
   existing, enabled user who is not the operator (`self_nomination`); an
   administrator without an activated credential still blocks activation
   (`admin_not_activated`, ADR 0047 Phase 0O.12A note). The bootstrap path
   still closes for good at first activation. Permitted provisioning metadata is what School creation
   accepts today (`name`, `slug`, optional `code`) plus the School's
   initial entitlements (§12). School profile fields stay School-owned
   (`docs/modules/ORGANIZATION.md`).
2. **Every onboarding and entitlement write** needs the capability, a
   fresh MFA re-verification (`MfaReverificationService`), explicit
   confirmation and a per-actor rate limit, exactly like today's lifecycle
   writes. Every refusal is audited.
3. **The Manager may not:** activate, suspend, resume, close or reopen a
   School; purge or delete one; grant or revoke platform roles; grant
   root; manage Group membership or Group grants; hold or acquire any
   School, Group or portal capability; read tenant business data or
   `school_audit_events`; impersonate; elevate; disable a core module
   (§14).
4. **Natural separation of duties.** Because activation stays root-only,
   a School onboarded by a Manager is activated by a different person.
   This is a consequence, not an additional check.
5. **Grant governance.** Root grants and revokes the Manager role through
   `PlatformRoleGovernanceService` (code allowlist plus
   `roles.runtime_assignable`). No self-grant, no delegation chain.

### 7.4 Separation from the Auditor (ORG-C3)

A user may not hold an active Platform Manager grant and an active
Platform Auditor grant at the same time. The auditor oversees operator
activity, including the manager's. ORG.4 enforces this in the service and
the database (the `platform_role_assignments` governance trigger).

## 8. Platform Auditor (ORG-D9)

The existing `platform_auditor` stays **strictly read-only**.

- **Today:** `platform.audit.view` only (platform-ledger envelope, current
  MFA assurance, one `platform.audit_log.viewed` per review).
- **Later (ORG.6), read-only additions** to purpose-built platform read
  models: the School registry and lifecycle state (`platform.schools.view`),
  School Group membership (`platform.school_groups.view`), entitlement
  configuration (`platform.school_entitlements.view`, provisional),
  operational/readiness warnings, and platform-role assignment counts or
  equivalent non-sensitive summaries.
- **The Auditor must never:** mutate anything; elevate; enter School
  operational authority; read tenant business records; read
  `school_audit_events` (ADR 0046 §10–§11); impersonate; export audit
  metadata, IP or user agent; acquire a School capability; hold the
  Manager role (§7.4).
- **Any future access to School audit evidence** needs a new, narrow ADR
  with a legal/privacy justification. ORG grants none.

## 9. School Registry (platform control plane)

### 9.1 Responsibility

The School Registry is the platform's read/write surface for tenants:
list and search Schools, open a School overview, onboard (§7) and change
lifecycle (root). It is **context-neutral** (no `active_school_id`, no
School route, no elevation) and is never a School data browser.

### 9.2 Registry read model (ORG.3)

The registry may expose **non-business tenant metadata only:**

| Field | Source | Notes |
|---|---|---|
| School id, name, `legal_name`, `slug`, `code` | `schools` | |
| Lifecycle status; closure state | `schools.status`, closure columns | |
| Campus count (active / inactive) | Campuses | aggregate only |
| Active academic year | Academic Structure | name only |
| Domain status | `school_domains` | state only |
| Administrator readiness | `SchoolAdministrators` | a yes/no "qualifying, activated administrator exists"; the bootstrap administrator's identity stays visible only while `provisioning` (ADR 0047 amendment, item 8) |
| Module entitlement summary | entitlements (§12) | module keys and state |
| Readiness and warnings | derived (§9.3) | |
| Recent platform audit events for this School | `platform_audit_events` with this School as subject | envelope fields only, through `PlatformAuditEventReader` rules |

- **List behaviour:** keyset pagination, deterministic order, filters by
  status, closure, Group and exact name/slug/code. No free-text search
  across tenant data.
- **The registry never surfaces:** students, guardians, staff HR records,
  payroll, fees, ledger or payment data, marks, `school_audit_events`
  content, tenant files or documents, or any other tenant business record.
- **Reading RLS-protected tables (campuses, academic years):** only
  through the owning module's Application service, inside that one
  School's `TenantContext` (the ADR 0048 one-School-at-a-time precedent),
  returning aggregates or names only. Never a raw cross-tenant query, never
  BYPASSRLS, never a cross-tenant cache. Page size bounds the work.
- **Access:** `platform.schools.view` (Platform Admin, Platform Manager,
  and the Auditor from ORG.6). Registry reads of Confidential metadata are
  not individually audited, matching the lifecycle pages today (ADR 0047
  amendment, item 8). ORG.6 decides whether Auditor read models need
  current MFA assurance.

### 9.3 Readiness is derived and advisory (ORG-C4)

Readiness is **computed when read, never stored**, unless later evidence
proves a stored state is required. Candidate checks: a qualifying,
activated administrator; at least one active Campus; an active academic
year; domain state where custom domains are enabled; entitlements
configured. Readiness and warnings are **advisory**: they never add an
activation precondition beyond ADR 0047 §5, which deliberately invents no
setup requirements.

## 10. Campus / Branch (ORG-D3)

- **Campus is the canonical sub-School organizational unit.** There is no
  Branch table; "Branch" is at most a UI label for Campus.
- Campus remains a **sub-tenant dimension, not an isolation boundary**
  (ADR 0004). RLS and tenant context stay School-level.
- Campus integrity stays structural: `campuses.unique(id, school_id)` and
  composite `(campus_id, school_id)` foreign keys (CLAUDE.md rule 70).
  Campuses are deactivated, never deleted (CLAUDE.md rule 73).
- Which entities are School-wide and which are Campus-scoped stays as
  documented in `docs/modules/ACADEMIC-STRUCTURE.md` ("Multi-campus rules")
  and in each module's schema.
- **Today no authority is Campus-scoped.** §11 defines what ORG.7 adds.

## 11. Campus-scoped assignment semantics (ORG-D4, ORG-D5)

ORG.1 defines the semantics only. No schema is authorized here.

### 11.1 Role ≠ scope

A role template is a capability bundle. Scope is a property of the
**assignment**. The same role may be assigned with different scopes:

| Example | Role | Scope |
|---|---|---|
| Principal of the whole School | `principal` | whole School |
| Principal of Campus A | `principal` | Campus A |
| Accountant for Campus B | `accountant` | Campus B |
| Teacher | `teacher` | whole School (membership), **plus** teaching ownership |

No code branches on a role name (CLAUDE.md rule 24).

### 11.2 Scope values

- **Absent / NULL Campus scope = whole-School authority.** It covers every
  Campus of that School, present and future, and the School's records
  that have no Campus. This is exactly what every grant means today.
- **Campus scope = Campus X** means authority restricted to Campus X, and
  only for capabilities whose domain supports Campus scope (§11.3).
- **One assignment is either whole-School or exactly one Campus.**
  Several Campuses are expressed as several assignments, never an array or
  JSON scope.
- **Compatibility:** no migration may ever reinterpret an existing
  unscoped grant as Campus-limited. Existing rows keep NULL, and NULL
  keeps its meaning.

### 11.3 Capabilities and Campus support (ORG-C1)

Each School capability is declared, in a code catalogue reviewed like
`CapabilityClasses`, as either **School-only** or **Campus-capable**:

- A Campus-scoped assignment confers **only the Campus-capable
  capabilities** of its role, restricted to that Campus. The role's
  School-only capabilities are not conferred by that assignment. Example:
  Principal @ Campus A does not receive `academics.years.manage`, because
  academic years are School-wide.
- **Fail closed until adoption.** A capability becomes Campus-capable only
  when its domain adopts Campus scope in ORG.7. Until then, a Campus-scoped
  assignment confers **nothing** for it. The existing `can()` /
  `schoolCapabilities()` answers therefore keep returning only
  whole-School grants. Campus-scoped authority is reachable only through a
  new scope-aware resolver path that an adopting domain calls explicitly.
  This is the safety rule that stops a Campus grant from silently acting
  School-wide in code that has not adopted Campus scope.
- A Campus-scoped grant of a role that would confer no Campus-capable
  capability is refused, like an empty role today.

### 11.4 Authority that stays whole-School (ORG-C2)

In v1 these capabilities are School-only, so they are never conferred by
a Campus-scoped assignment:

- School access administration: `school.members.*`, `school.roles.*` and
  the grant rights `school.roles.grant.*`;
- School configuration: `school.profile.*`, `school.settings.*`,
  `school.campuses.*`, `school.domains.*`, `school.audit.view`;
- integration administration (`integrations.*`) and School-wide academic
  configuration (`academics.years.*` and similar), unless a domain's
  adoption explicitly argues otherwise.

Consequences: grant issuers (`RoleGrantAuthority`, the grantor trigger)
are always whole-School holders. The last-administrator protection
(`SchoolAdministrators`) and ADR 0047 §5 activation count whole-School
grants only. Campus administrators who administer staff access are a
later decision.

### 11.5 Combination rules

- **Several Campus assignments** for the same capability combine as a
  union of Campuses.
- **Whole-School supersedes narrower grants** of the same capability.
  Holding Accountant School-wide and Accountant @ Campus B is permitted and
  resolves to whole-School. If the whole-School grant is revoked, the
  Campus grant still applies.
- **Duplicates:** at most one active assignment per (membership, role,
  scope). The scope is either whole School or one specific Campus.
- **Ownership combines with AND.** For teacher, self and Guardian
  surfaces, Campus scope (where adopted) and ownership must both pass.
  Campus scope never widens ownership, and ownership never widens Campus
  scope.
- **Other gates are unaffected.** Campus scope never replaces teacher
  ownership, Guardian/Student scope, HR self scope, payroll
  self-protection, legal gates or separation-of-duties checks. It is an
  additional dimension.

### 11.6 Integrity and lifecycle

- **Belonging to the School is structural.** The assignment row carries
  `school_id` and the Campus through a composite foreign key to
  `campuses(id, school_id)`, plus RLS. No cross-School Campus reference
  is possible, even by raw SQL (CLAUDE.md rule 70).
- **Inactive Campus:** no new assignment may target an inactive Campus.
  Existing assignments to it confer **no new operational work** (no create
  or change for that Campus). Historical records of that Campus stay
  readable according to the domain's retention rules and its declared
  Campus-capable reads, and always to whole-School holders. Reactivating
  the Campus restores the grant's effect; history is unchanged.
- **Revocation** is per assignment, keeps history (revoked, never deleted;
  CLAUDE.md rule 92) and invalidates that user's School capability cache.
  Off-boarding revokes every assignment of the membership, scoped or not.
- **Grants and revocations are audited** with the existing School-ledger
  role-grant events, adding the Campus id as an identifier in metadata.

### 11.7 How domains apply Campus scope (ORG.7)

- **Scope value.** For (user, School, capability) the resolver returns one
  of: *none*, *whole School*, or *a set of Campus ids*.
- **Read models take the scope as an explicit parameter.** It is never
  read from request input or `TenantContext`. Campus-scoped reads apply
  `campus_id IN (…)`. A user-chosen Campus filter is **intersected** with
  the scope, never added to it.
- **Records without a Campus** (`campus_id` NULL, or entities with no
  Campus dimension) are visible only to whole-School scope, unless the
  domain's adoption explicitly declares a Campus-scoped read of them (for
  example, the School-default fee structure a Campus cashier needs).
- **Writes:** the target's Campus must be within scope. Creating a
  School-wide (NULL Campus) record needs whole-School scope. Moving a
  record between Campuses (e.g. a Campus transfer) needs scope over both
  the source and the destination.
- **Domains without a Campus dimension** (Finance ledger, Payroll, Leave,
  StaffAttendance, Documents, Analytics, Compliance, Automation today)
  keep their capabilities School-only. Campus-scoped grants confer nothing
  there.
- **Derived Campus** (e.g. `student_enrollments.campus_id` from the
  Section) uses the stored, FK-consistent column as authoritative.
  *(ORG.7C correction, §35.1: the column is stored and Campus-FK'd, but the
  database does not yet bind it to its Section. Consistency is enforced
  only in `StudentEnrollmentService`, and binding it is a prerequisite
  before any Student-dependent adoption.)*
- **Students:** a Student's Campus visibility derives from enrollments. A
  Student with no current enrollment is visible only to whole-School
  scope, unless the SIS adoption (ORG.7, last) decides otherwise.
- **Navigation** shows an entry when the capability is held at any scope.
  The page then applies the scope.

### 11.8 Campus context is not authority (ORG-C6)

`TenantContext::campus()` is an optional working context (today replayed
only for jobs). It is never consulted for an authorization decision. A
future "current Campus" selector in the UI is a filter intersected with
the user's scope, never a grant.

### 11.9 Jobs and background work

- A job that performs actor-authorized work carries the School id
  (CLAUDE.md rule 21), the actor, and an explicit Campus id when the work
  is bound to one Campus. It **re-resolves the actor's scoped capability
  at execution**, never trusting a scope computed at dispatch, and
  re-checks the Campus belongs to the School and is active for new work.
- System jobs that act without an actor (retention, maintenance) are
  unaffected by Campus scope.

### 11.10 Incremental adoption

Campus scope is adopted **domain by domain** in ORG.7: lowest risk first
(Visitor, Library, Hostel, Transport, Inventory, Canteen), then HR and
Fees/Payments, then Attendance, Timetable, LMS, Academics and
Examinations, then Admissions, and **SIS last** (ORG-D20). Each adoption
declares its Campus-capable capabilities, applies §11.7, and ships allow
and deny tests (including "a Campus A holder cannot read or write Campus
B"). Academic Structure configuration stays School-only.

## 12. Module entitlements (ORG-D11)

### 12.1 Definition

An **entitlement** says whether a School may use a product module. It is
platform-governed tenant configuration. It is **not** a role, a
permission, a navigation setting or a feature flag.

**Effective School capabilities = capabilities granted by role and scope
∩ capabilities available under the School's entitled modules.**

A user can never gain a module capability that the School is not
entitled to, and **a disabled module never stays executable because a
user still holds a historical grant.**

### 12.2 Module catalogue (code-owned)

- A code catalogue (reviewed like `CapabilityClasses`; no database row is
  the source of truth) defines each module:
  - a **stable identifier** (lowercase snake case, never reused or
    renamed once released);
  - its capability keys;
  - its dependencies;
  - whether it is **core**.
- The catalogue and the per-School entitlement state are owned by the
  **Platform** domain. `CapabilityResolver` (Identity & Access) consumes
  them through a Platform Application contract. Platform is the shared
  kernel every module already depends on, so no new dependency direction
  appears. `DOMAIN-MAP.md` is updated in ORG.5.
- Every School capability belongs to at least one module. A catalogue
  guard test refuses an unmapped capability, in the way
  `CapabilityClasses` refuses an unclassified one.

### 12.3 Capability → module mapping (ORG-C5)

- A capability may require **more than one** module. It is available only
  when **every** required module is entitled. Example:
  `portal.fees.view` requires both the Guardian Portal module and Fees.
- Portal (`portal.*`) capabilities belong to the Guardian Portal module
  **and** to the module whose data they show.

### 12.4 Provisional module map

The identifiers and boundaries below are **provisional**. ORG.5 freezes
them against the code. Names follow the `DOMAIN-MAP.md` modules.

| Module (provisional id) | Capability families | Core? | Provisional dependencies |
|---|---|---|---|
| `identity_access` | `school.members.*`, `school.roles.*` | **core** | — |
| `organization` | `school.profile.*`, `school.settings.*`, `school.campuses.*`, `school.domains.*` | **core** | — |
| `academic_structure` | `academics.*`, `teaching.assignments.*` | **core** | — |
| `student_records` | `students.*` (incl. `students.processing_authorizations.*`), `enrollments.*`, `guardians.*` | **core** | — |
| `staff_records` | `hr.employees.*`, `hr.departments.*`, `hr.positions.*`, `hr.categories.*` | **core** | — |
| `compliance_audit` | `school.audit.view` | **core** | — |
| `documents` (infrastructure) | none of its own (owner-delegated) | **core** | — |
| `admissions` | `admissions.*` | optional | student_records |
| `attendance` | `attendance.*` | optional | academic_structure |
| `timetable` | `timetable.*` | optional | academic_structure |
| `academics` (Syllabus, Curriculum Delivery) | `syllabus.*`, `curriculum.delivery.*` | optional | academic_structure |
| `lms` | `lms.*` | optional | academic_structure |
| `examinations` | `examinations.*` | optional | academic_structure, student_records |
| `finance` (ledger) | `finance.ledger.*`, `finance.accounts.*`, `finance.periods.*` | optional | — |
| `fees` (receivables, payments) | `finance.fee_*`, `finance.charges.*`, `finance.payments.*` | optional | finance, student_records |
| `leave` | `hr.leave.*` | optional | staff_records |
| `staff_attendance` | `hr.staff_attendance.*` | optional | staff_records, leave (StaffAttendance → Leave, `DOMAIN-MAP.md`) |
| `payroll` | `payroll.*` | optional | staff_records, finance |
| `library` | `library.*` | optional | (fines: fees) |
| `transport` | `transport.*` | optional | (billing: fees) |
| `hostel` | `hostel.*` | optional | (billing: fees) |
| `inventory` | `inventory.*` | optional | — |
| `canteen` | `canteen.*` | optional | inventory, fees (to be verified) |
| `visitor` | `visitor.*` | optional | — |
| `communications` | `communications.*` | optional | — |
| `guardian_portal` | `portal.*` | optional | communications, plus the source module per capability |
| `analytics` | `analytics.*` | optional | the source module per report |
| `automation` | `automation.*` | optional | — |
| `integrations` | `integrations.*` | optional | — |

"(billing: fees)" means the operational module works alone, while its fee
integration (ADR 0067 seams) needs Fees. ORG.5 decides whether that is a
dependency or a capability-level requirement.

### 12.5 Per-School entitlement state

- **Ownership:** keyed by `school_id`. Written only by a Platform
  Application service and never by a School. Readable by the School as a
  read-only fact (e.g. on its settings page).
- **History:** every change keeps the previous state (an append-only
  change record, or a status row plus audit; ORG.5 chooses). Nothing is
  deleted.
- **Storage:** follows CLAUDE.md rule 18 if tenant-owned. Being written
  from platform context is an ORG.5 design point; the precedents are
  `TenantRls` with platform scope and the documented platform-table
  exceptions.

### 12.6 Changing entitlements

- **Who:** Platform Admin (root) and Platform Manager, with the
  entitlement-management capability (§7.2). Never a School actor.
- **Every enable or disable needs:** the capability, fresh MFA, explicit
  confirmation, and a closed reason code (plus optional bounded
  justification, never personal data). Every refusal is audited.
- **Disable shows an impact preview first:** affected grants and roles,
  pending scheduled or queued work, open workflow items and dependent
  modules.
- **Disabling never deletes, revokes or archives data** and never revokes
  role grants; the grants simply confer nothing for that module.
  Re-enabling restores the effect.
- **Dependencies:** enabling a module requires its dependencies to be
  entitled. Disabling one that an entitled module depends on is refused
  until the dependants are disabled.
- **Core modules** can never be disabled through entitlement management
  (§14).
- **Lifecycle interaction:** entitlement changes are allowed in
  `provisioning`, `active` and `suspended`. They never change lifecycle
  state.

### 12.7 Enforcement points

1. **Capability resolution:** `CapabilityResolver` intersects School
   capabilities with entitled modules. Every Gate, middleware, trait and
   direct resolver check inherits it. That is why ORG.2's coverage guard
   (§24) precedes ORG.5.
2. **Direct URL / API:** a disabled module's routes refuse through the
   capability check. No route stays reachable because a menu was hidden or
   a link was bookmarked.
3. **Jobs, consumers, scheduled work and the outbox:** a business effect
   of a disabled module **re-checks entitlement at execution time**, like
   `SchoolOperationalGuard` (CLAUDE.md rule 86). It refuses or defers with
   a bounded outcome and never loops. Platform safety, retention, legal
   holds and maintenance continue for disabled modules (the ADR 0047 §9
   principle). ORG.5 decides per substrate (webhooks, communications,
   assessment runs).
4. **Navigation:** follows automatically, because it is a projection of
   resolved capabilities (§15).
5. **Legal gates:** an entitlement can never make a legal-gated
   capability, an environment-gated surface (`PortalAvailability`,
   `StudentMarkAvailability`) or a production refusal reachable. Both
   layers must pass.

### 12.8 Staleness bound (ORG-C7)

Capabilities are cached for 60 s per (School, user). An entitlement
change must take effect within a documented bound no longer than that,
across all users of the School. Example: a per-School entitlement version
in the cache key, through `TenantCache`. Execution-time checks (§12.7(3))
never rely on the cache.

### 12.9 Audit

Platform ledger, identifiers and codes only:
`platform.school_entitlement.enabled`, `.disabled`, `.denied`
(provisional names). Metadata: module key, reason code, and for a disable
the preview counts. Never capability arrays or personal data. Whether the
School's own ledger also records a notice is an ORG.5 decision.

## 13. Feature flags remain distinct (ORG-D12)

| | Feature flag | Entitlement |
|---|---|---|
| Purpose | Controlled rollout, experiments, temporary operational switches | Licensed / approved product availability |
| Authorization? | **Never** (`FeatureFlagResolver` docblock) | Yes: it constrains effective capabilities (§12) |
| Scope | Platform default + per-School override | Per School |
| Writer | none today | Platform Admin / Platform Manager (§12.6) |

- A flag may switch behaviour *inside* an entitled module. It can never
  enable a module the School is not entitled to, and never grants a
  capability. Entitlement is evaluated first.
- **ORG.5 may:** retire the dead `students.processing_authorizations` flag
  (seeded, never read); decide whether `automation.rules` is a rollout
  switch (keep as a flag) or product availability (move to the
  `automation` entitlement, on evidence); and decide whether the dormant
  `platform.feature_flags.view/.manage` capabilities get a root-only flag
  surface or are retired through a catalogue release.
- Until then, both flag tables and their resolver stay exactly as they are.

## 14. Core modules (ORG-D14)

**Core modules cannot be disabled through tenant entitlement
management.** The logical core:

1. **Identity & Access:** memberships, roles, grants, MFA, invitations.
2. **Organization:** School identity, settings, Campuses, domains.
3. **Academic Structure:** years, terms, grade levels, sections,
   subjects, offerings, rooms, **and Teaching Assignments**. Teacher
   ownership (ADR 0063) derives from Teaching Assignments, which every
   teacher surface uses.
4. **Student Records:** Students, enrollments, the processing-authorization
   registry (ADR 0038) **and Guardians**.
5. **Staff Records:** the HR employee foundation (Employee,
   EmploymentRecord, EmployeeAssignment, departments, positions,
   categories). `ActingEmployee`, teacher ownership, self-service and
   Payroll all depend on it. Leave, Staff Attendance and Payroll are
   optional modules on top of it.
6. **Compliance / Audit:** the School audit-log review and audit
   infrastructure.
7. **Documents infrastructure:** shared storage and the owner-delegated
   document service.

**Students and Guardians form one core family, not two modules.**
Guardian records and Guardian↔Student relationships are part of the
student record: legal-guardian status, contacts, `GuardianStudentScope`,
fee subjects and communications all depend on them. Neither can be
operated meaningfully without the other. The **Guardian Portal** (the
`portal.*` capabilities and Guardian accounts) is a separate,
**optional** module. It stays production-refused in code until POR-L1
(ADR 0070).

**Platform infrastructure is outside the module model:** tenancy, audit
ledgers, the outbox, the durable email layer (ADR 0055/0056), retention,
observability and health. It is never entitlement-controlled.

ORG.5 binds this logical core to catalogue entries. No core module may
later become disableable without an ADR amendment.

## 15. Navigation remains a projection (ORG-D15)

```
capability resolution (roles ∩ scope ∩ entitlements)  ──►  navigation projection
```

- `StaffNavigation` / `NavigationBuilder` stay the single catalogue and
  evaluator. Navigation never reads roles, entitlement rows or menu
  configuration directly. It follows entitlements and Campus scope
  because the capabilities it evaluates already reflect them.
- **A hidden entry never substitutes for a backend check. A visible entry
  never grants authority.** Every destination keeps its own capability
  check (`NavigationVisibilityTest`, `HubAuthorizationTest`).
- **No menu configuration is stored** as entitlement or authorization
  state. Reference-product role/menu matrices are requirement evidence
  only. Web, mobile and dashboard projections derive from the same
  capabilities when those clients exist.

## 16. Approval capability convention (ORG-D16)

- **No generic workflow engine** (CLAUDE.md rule 2). Approval stays
  module-owned.
- **Naming rule for NEW capabilities:**

  ```
  <domain>.<resource>.request
  <domain>.<resource>.approve
  <domain>.<resource>.reverse
  <domain>.<resource>.override
  ```

  These are used only where the domain actually has that operation.
  Existing keys are **not renamed** for cosmetic consistency, for example
  `communications.approve`, `hr.leave.approve`, `payroll.runs.approve`,
  `finance.fee_concessions.request/.approve`,
  `examinations.marks.correction.request/.approve` and
  `finance.ledger.reverse`.
- **Meanings:**
  - `request` proposes.
  - `approve` decides another person's request. It is never implied by
    `manage` or `request`.
  - `reverse` creates a new compensating record and never edits or
    deletes (CLAUDE.md rules 9, 11, 91).
  - `override` is a documented exception path with a closed reason code,
    fresh MFA and an audit event. It never bypasses a legal gate, the
    lifecycle, tenancy or separation of duties.
- **Separation of duties:**
  - The requester never approves their own item.
  - Material workflows also enforce this in the database: financial,
    payroll, marks, refunds, student status changes and other high-risk
    corrections. The existing `fee_concessions_sod_check`,
    `payroll_runs_sod_check` and
    `student_mark_corrections_maker_checker_check` are the pattern.
  - Holding both capabilities, as `school_admin` does, never satisfies
    separation of duties. The check is per actor and per item.
- **Classification:** approve, reverse and override keys are classed
  `authority` unless the catalogue review decides otherwise. Operational
  roles never carry them (ADR 0071 §7).
- **Campus:** approval authority may be Campus-scoped where its domain
  adopts Campus scope (§11). Separation of duties is unchanged.
- **Known gaps (ORG.8 candidates, each needing its own contract work):**
  - a refund/reversal approver for payment corrections (this amends the
    ADR 0031 correction contract, CLAUDE.md rule 91);
  - an actor rule between ledger posting and reversal;
  - library fine void approval;
  - attendance regularisation;
  - student status (inactive / reactivate) requests;
  - fee unassignment;
  - lesson-plan approval (no lesson-plan module exists).
- **ORG.8 contract audit (2026-10-21):** §56 freezes the v1 approval
  contract against repository truth, records gaps G-1..G-6 and the
  implementation sequence; nothing is built.

## 17. Authorization evaluation order (ORG-D17)

| # | Layer | Enforced today at | State |
|---|---|---|---|
| 1 | Platform / environment policy | `EnvironmentPolicy`, `ProductionConfigurationGuard`, `ClassifyRequestHost` (421), `*Availability` classes and their route middleware (`portal-development-only`, `marks-development-only`, `teacher-attendance-api`), elevation refusal in `RequireSchoolContext` | exists |
| 2 | Legal / regulatory availability | `legal-gated` capabilities held by no role, with no grant right covering them; read-only catalogue (ADR 0071); production refusals in code; empty production `PartnerScopeRegistry` | exists |
| 3 | School lifecycle / operational state | `ResolveSchoolContext` / `RequireSchoolContext` (active School only), `EnsureSchoolMembershipContext` (API 404), `SchoolOperationalGuard` inside transactions, job re-checks | exists |
| 4 | Module entitlement | — | **ORG.5** |
| 5 | Role capability | Gate `capability` → `CapabilityResolver`; `capability:` middleware (`EnsureCapability`); `AuthorizesCapability`; Application-service checks | exists |
| 6 | Assignment scope (School or Campus) | School: active membership + School grant. Campus: none | School exists; Campus **ORG.7** |
| 7 | Ownership / self / Guardian scope | `ActingEmployeeResolver` + `TeachingOwnership` and the Teacher*Guard classes; `HrSelfAdministrationGuard`; Payroll self-refusal; `ActingGuardianResolver` + `GuardianStudentScope` | exists |
| 8 | Request-specific assurance | `FreshMfaRequirement::requireForAction()` (after the capability check, outside the transaction), `SensitiveReadAssurance`, separation-of-duties checks (application + database), `EnsureIdempotent` / `IdempotencyGuard`, `RoleGrantAuthority` | exists |

- **Not every request needs every layer.** A layer applies only when
  relevant: a platform page has no layers 3–7, and a School read with no
  ownership dimension skips 7.
- **A lower layer never overrides a refusal by a higher one.** No role,
  scope, ownership, flag or entitlement can re-open what platform policy,
  a legal gate or the School lifecycle refused. An entitlement can never
  open a legal gate.
- **Authentication and tenant/membership validation come first,** and
  idempotency replay is evaluated only after authorization (CLAUDE.md
  rule 32).
- **Order of checks inside a service may differ** where a refusal must be
  audited. The platform lifecycle POSTs deliberately skip route middleware
  so the service audits the refusal. The rule is unchanged: nothing
  executes unless every applicable layer passes.

## 18. School Groups and cross-School authority

- School Groups stay governance and reporting constructs (ADR 0045,
  ADR 0048). A Group grant never creates or implies a School membership
  or School capability. Group entry into a School is ADR 0044 elevation,
  which grants nothing. The single Group-safe report remains the only
  tenant-data exception.
- **Cross-School operational authority is not introduced.** Where a person
  genuinely works for several Schools, they hold separate memberships and
  separate grants in each, given by each School's own administrators.
- A future "organisation-level role" (e.g. Finance Manager over selected
  Schools) needs a concrete business requirement and its own ADR. It must
  not reuse Group authority for operational data.

## 19. Role-template policy (ORG-D18, ORG-D19)

- **School roles remain platform-curated global templates** (`roles` has
  no `school_id`) assigned within a School and, once ORG.7 lands, within a
  Campus. The runtime cannot write the catalogue (ADR 0071 §11).
- **No tenant-owned or arbitrary roles in ORG.** If custom roles are ever
  required, the preferred direction is **constrained composition from
  platform-approved capability bundles**, never unrestricted capability
  creation. Such roles never include authority, legal-gated or
  owned-scope capabilities. This requires its own ADR 0071 amendment.
- **Reference role names are evidence, not requirements.** Computer
  Operator, Class Coordinator, Corporate Academic Incharge and Franchise
  BDM do not become roles automatically. A new role template is added only
  when:
  - a real Lycenza workflow needs it;
  - existing roles cannot express it safely;
  - its capability set is reviewed;
  - the catalogue release process (ADR 0071, `StaffRoleCatalogueSnapshotTest`)
    is followed.
- Homeroom / class-teacher or grade-coordinator authority, if needed, is
  an **ownership** predicate under a teacher-identity ADR (in the style of
  ADR 0063), not a new role.

## 20. Audit expectations

| Action | Ledger | Event | State |
|---|---|---|---|
| School created / onboarded (root or Manager) | platform | `platform.school.created`, `.bootstrap_admin_assigned`, `.bootstrap_admin_replaced` | exists; the actor is recorded as today |
| Activate / suspend / resume / close / reopen | platform | `platform.school.*` | exists |
| Lifecycle or onboarding refused | platform | `platform.school.lifecycle_denied` | exists |
| Platform role granted / revoked / refused (incl. Platform Manager) | platform | `platform.role_grant.*` | exists |
| Module enabled / disabled / refused | platform | `platform.school_entitlement.*` (provisional) | ORG.5 |
| Campus-scoped School grant / revoke | School | existing role-grant events, with the Campus id in metadata | ORG.7 |
| Privileged access (elevation) | platform + `school_audit_events.elevation_id` | `platform.school_elevation.*` | exists |
| Tenant setting changed by a platform actor | — | not applicable: no platform path writes School settings, and none is authorized | — |

Every record keeps actor, target, action and time, and scope where
relevant. Records are identifiers and codes only, never personal data or
capability arrays. Ledgers stay append-only and separate (ADR 0046 §11).
The Platform Auditor's read path is `platform.audit.view` (today) plus
the ORG.6 read models; it never gains mutation authority.

## 21. Explicit non-goals

ORG does **not** introduce:

- a tenant column other than `school_id`, or an Organisation/Branch entity;
- cross-School operational roles, or Group authority over operational
  data;
- impersonation, "view as user" or School authority under elevation;
- a runtime-grantable root, or any root-equivalent role;
- tenant-owned or arbitrary roles;
- a generic workflow or approval engine;
- menu-as-security, or stored menu configuration;
- a School archive, delete or purge path (tenant destruction stays not
  authorized; E21-D11);
- billing, subscriptions, plans or pricing (entitlements are availability
  only; any commercial model needs its own decision);
- configurable Guardian/Student profile-field exposure (it stays fixed in
  code under ADR 0070 until POR-L1);
- Student accounts (POR-L1), or a mobile menu projection before a real
  mobile client exists.

## 22. Migration and compatibility requirements

1. **Unscoped grants keep their meaning forever** (§11.2). Campus scope is
   additive. No backfill reinterprets a grant.
2. **Campus-scoped grants confer nothing in non-adopting domains** (§11.3),
   so introducing the column cannot widen or narrow any existing check.
3. **Entitlement backfill (ORG-D13).** When entitlements are introduced,
   every existing School is entitled to every currently generally
   available module it can reach today, in the same migration or release
   that introduces enforcement. No School loses a working domain.
   Backfilling a module whose surfaces are still environment- or
   legal-gated (Guardian Portal, StudentMark, statutory payroll) leaves
   them exactly as gated as before. The entitlement never opens a gate.
4. **New Schools:** the default entitlement set at onboarding is an ORG.5
   decision. The recommended default is the same generally available set,
   adjustable during onboarding, so onboarding never strands a School
   without modules it expects.
5. **No capability key is renamed** (§16). `platform.schools.manage`
   stays root-reserved, and no root-reserve trigger is relaxed.
6. **Dormant keys:** `platform.schools.view` gets its first use in ORG.3.
   `platform.feature_flags.*` stay dormant until ORG.5 decides (§13).
7. **Every migration** keeps a working `down()` and a rollback proof
   (CLAUDE.md rule 10). Every tenant-owned table keeps `TenantRls`
   (rule 18) and composite foreign keys (rule 70).

## 23. Phased adoption plan

Each phase needs separate, explicit owner authorization. Each ships its
own UI (the SWC practice; no separate "administration UI" phase) and its
own allow/deny, tenancy and negative tests (CLAUDE.md rule 13).

| Phase | Scope | Depends on |
|---|---|---|
| **ORG.1** | This contract (documentation only) | — |
| **ORG.1A** | Hardening defects V-1–V-5 (§24) | ORG.1 |
| **ORG.2** | Authorization coverage guard: prove every School route and Application entry point is capability-gated; move ad-hoc enforcement (e.g. inline `canInSchool` + manual 403) onto the Gate. No behaviour change. | ORG.1A |
| **ORG.3** | School Registry read model (§9) under `platform.schools.view` | ORG.2 |
| **ORG.4** | Platform Manager catalogue release and role (§7), the Manager/Auditor exclusivity (§7.4); requires the ORG.1A platform-grant MFA | ORG.1A, ORG.3 |
| **ORG.5** | Module catalogue, entitlement state, resolver intersection, execution-time guard, backfill, flag clean-up (§12–§14) | ORG.2, ORG.4 |
| **ORG.6** | Platform Auditor read models (§8) | ORG.3, ORG.5 |
| **ORG.7** | Campus-scoped assignments and domain adoption waves, **SIS last** (§11.10) | ORG.2, ORG.5 |
| **ORG.8** | Approval-authority completion (§16 gaps) | ORG.2 |
| **ORG.9** | Reference parity: requires the owner's screenshots and XLS menu reports available to the execution environment | ORG.5 |
| **ORG.10** | UAT, DDEV smoke, full regression and closure | all |

**SIS (ORG-D20):** SIS is not modified in ORG.1–ORG.6. SIS adopts Campus
scope only in the last ORG.7 wave, after the semantics are frozen, the
coverage guard exists, the registry and platform contracts exist, and the
entitlement infrastructure exists. LC-5–LC-10 remain open and paused.

## 24. ORG.1A prerequisites (ORG.0 findings, accepted, not fixed here)

| ID | Finding (verified at `f183c851`) | Fix direction (ORG.1A) |
|---|---|---|
| V-1 | `School::$fillable` includes `status` (`apps/platform/app/Models/School.php:59`); only controller whitelists protect the lifecycle column, and the transition trigger would accept active → suspended from a mass-assignment bug. The migration comment `2026_08_23_090100…:17-19` claims the opposite. | Remove `status` from `$fillable` (lifecycle writes already use `forceFill` in `SchoolLifecycleService`); add a guard test; correct the comment. |
| V-2 | Platform role grant/revoke (`PlatformRoleGovernanceService`, `PlatformRoleAdminController`) requires no fresh MFA, unlike every lifecycle write. | Fresh MFA re-verification on grant and revoke, **before ORG.4 creates a second grantable role**. |
| V-3 | 204 `school_id → schools` foreign keys are `ON DELETE CASCADE`, including `school_audit_events` and finance tables; `users → school_memberships → membership_role_assignments` cascades away grant history. Only the runtime role's missing DELETE protects them. | RESTRICT at least for audit, finance and grant-history paths; rollback-proven. |
| V-4 | `school_audit_events.elevation_id` is a single-column FK to `school_elevations(id)` (`2026_10_15_090100…:20`), and `school_elevations` has no `unique(id, school_id)`, so the database does not prevent a cross-School elevation reference. | Composite `(elevation_id, school_id)` FK. |
| V-5 | Composite `nullOnDelete()` that includes NOT NULL `school_id`: `hr_departments` (campus, parent; `2026_08_23_100500…:69-75`), `employee_assignments.manager_assignment_id` (`2026_08_23_100900…:72-74`), `communication_messages` reply-to, `communication_attachments`. A parent delete fails instead of demoting the row (fails closed). | `ON DELETE SET NULL (<column>)` column lists (PostgreSQL 15+) or RESTRICT, matching each docblock's stated intent. |

Order: V-1 and V-2 (code) are independent and small. V-3, V-4 and V-5
are schema changes, each rollback-proven. V-2 must land before ORG.4.
V-3 must land before any future archive or purge contract.

## 25. Consequences and risks

**Positive:**
- **Nothing that works is rebuilt.** Tenancy, scopes, the role catalogue,
  navigation and audit are reused.
- **The Platform Manager is delegated narrowly.** Root keeps lifecycle and
  security governance, and onboarding gains a natural second person.
- **Campus authority is additive and fail-closed.** No existing grant
  changes meaning, and non-adopting code cannot be widened by accident.
- **Entitlements enforce through the existing resolver**, so the backend,
  API, jobs and navigation agree.

**Risks and mitigations:**
- **Entitlement enforcement depends on coverage.** An unguarded route
  would ignore entitlements. ORG.2 must precede ORG.5.
- **Campus adoption touches many read models.** Composite foreign keys
  (Academic Structure, Examinations' campus trigger, LMS) make Campus
  changes costly. Domains are adopted one at a time, with SIS last.
- **Cache staleness after entitlement or grant changes** is bounded by
  ORG-C7, and execution-time checks never use the cache.
- **Two platform operators (Manager, Auditor) increase the attack
  surface.** Mitigated by narrow keys, fresh MFA on every write, mutual
  exclusivity, no School authority and full audit.
- **The provisional module map may be wrong at the edges** (dependencies
  marked "to be verified"). ORG.5 freezes it against the code, with a
  guard test refusing unmapped capabilities.

## 26. References

- ADR 0004, ADR 0020, ADR 0021, ADR 0022 (tenancy); ADR 0017 (audit);
  ADR 0044 (elevation); ADR 0045, ADR 0048 (Groups); ADR 0046 (platform
  authority, amended); ADR 0047 (School lifecycle, amended); ADR 0063
  (teacher ownership); ADR 0065 (HRX); ADR 0067 (operational fee seams);
  ADR 0070 (portal); ADR 0071 (role catalogue); ADR 0073 (navigation).
- `docs/architecture/TENANCY.md`, `docs/security/AUTHORIZATION.md`,
  `docs/modules/ORGANIZATION.md`, `docs/modules/ACADEMIC-STRUCTURE.md`,
  `docs/architecture/DOMAIN-MAP.md`.
- Code (read only): `App\Support\Authorization\CapabilityResolver`,
  `CapabilityClasses`, `App\Support\Navigation\StaffNavigation`,
  `App\Support\FeatureFlags\FeatureFlagResolver`,
  `App\Domain\Platform\Application\Schools\SchoolLifecycleService`,
  `App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService`,
  `App\Support\Tenancy\TenantContext`.

## 27. ORG.1A — Foundation hardening (implemented, 2026-10-18)

Owner authorization: ORG.1A, scope V-1 to V-5 only (§24). Baseline
`origin/main` = `e08f9bde` (ORG.1). Nothing else in §23 was started.

### 27.1 V-1 — School status is not mass assignable

- `status` is removed from `School::$fillable`. `create()`, `fill()` and
  `update()` now drop it, even if a controller whitelist ever slips.
- `SchoolLifecycleService` writes it explicitly (`forceFill()`) for create,
  activate, suspend, resume, close and reopen. The database transition
  trigger is unchanged and stays authoritative.
- The guarded local demo builder creates its two `active` Schools with
  `forceCreate()`. `SchoolLifecycleArchitectureGuardTest` also refuses
  `new School(` and `School::query()->forceCreate(` outside the lifecycle
  service.
- The Phase 0D migration comment ("nothing in … `School.php`'s fillable
  list lets a School-scoped capability touch `status`") was inaccurate
  before ORG.1A and is now true. That historical migration is not edited.

### 27.2 V-2 — Fresh MFA for platform-role governance

- `PlatformRoleGovernanceService::grant()` and `revoke()` take the request
  and call `FreshMfaRequirement::requireForAction()`. This is the existing
  mechanism; no new MFA path is added.
- **Order:** the call comes after the capability, assignability, target
  and self-grant checks (an unauthorized caller never burns a code) and
  outside the transaction (a rollback never un-consumes one).
- **Refusals:** no enrolled factor is an audited refusal
  (`mfa_not_enrolled`), and so is a wrong, replayed or used code
  (`mfa_verification_failed`). A missing code is plain validation. Sign-in
  assurance alone never suffices, and a refusal changes no assignment.
- **The Roles page** sends a code with every grant and revoke.
- **Unchanged:** the allowlist, `roles.runtime_assignable`, the governance
  trigger, root provisioning out of band, and the read-only Auditor (audit
  review still needs current assurance only).

### 27.3 V-3 — Protected history fails closed under deletion

- **Inventory at `e08f9bde`:** 211 `school_id → schools` foreign keys
  cascade and 4 restrict; 5 `users` foreign keys cascade.
- **Classification:**
  - A audit;
  - B accounting;
  - C receivables and settlement;
  - D grant history;
  - E operational, configuration and domain rows, including evidence
    governed by its own E21 retention contract;
  - F ephemeral or credential material (idempotency keys, counters, MFA
    factors, recovery codes, activation and recovery tokens).
- **The 22 A–D keys** (migration `2026_12_18_090200`) become `ON DELETE
  RESTRICT` under their own names:
  - A: `school_audit_events`;
  - B: `ledger_accounts`, `journal_entries`, `journal_lines`,
    `financial_periods`, `financial_period_account_balances`,
    `financial_period_charge_states`, `financial_period_expiries`,
    `payroll_run_postings`, `payroll_statutory_run_postings`;
  - C: `charges`, `fee_adjustments`, `payments`, `payment_allocations`,
    `payment_receipts`, `payment_corrections`, `payment_correction_lines`,
    `payment_provider_events`;
  - D: `school_memberships` (School and User),
    `membership_role_assignments` (School and the composite membership
    key).
- **E and F are unchanged** (about 190 School cascades). Account links
  (identity, not grants), domain evidence such as leave ledgers, mark
  revisions and staff-attendance corrections, and `school_group_members`
  were reviewed and left as they are.
- **Why this does not conflict with retention:**
  - retention prunes delete these rows directly as the retention role,
    never through a School or User delete;
  - the retention guards act only for that role;
  - no application path deletes Schools, Users or memberships (the runtime
    role cannot delete Schools or Users).
- **Evidence the finance graph was already mostly protected:** its rows
  already refused an ordinary School cascade through internal RESTRICT
  references (documented in `CreatesFinancialPeriodFixtures`). The School
  key now makes that explicit for every protected table.
- **Test support:** committed test fixtures are removed by
  `TestCase::purgeSchoolsAsAdmin()`. It is the existing replica-mode
  pattern, test database only, and also removes the backlog projections
  replica mode skips. `deleteSchoolAsAdmin()` uses it.

### 27.4 V-4 — Audit elevation references are School-bound

- `school_elevations` gains `UNIQUE (id, school_id)`.
- `school_audit_events_elevation_id_foreign` (single column) is replaced by
  `school_audit_events_elevation_school_foreign`: `(elevation_id,
  school_id) → school_elevations(id, school_id)`, still RESTRICT. A NULL
  elevation stays valid.
- The migration refuses, with a count, if any row references another
  School's elevation. None existed on the DDEV development, DDEV test or
  isolated test databases.

### 27.5 V-5 — Composite SET NULL clears only the relationship

The five constraints keep their names and become `ON DELETE SET NULL
(<column>)`, so deleting the parent demotes the child and never touches
`school_id`:
- `hr_departments` (`campus_id`; `parent_department_id`);
- `employee_assignments` (`manager_assignment_id`);
- `communication_messages` (`reply_to_message_id`);
- `communication_attachments` (`communication_message_id`).

The composite same-School check is unchanged.

### 27.6 Evidence

- **Data preconditions:** zero violating rows on all three databases (§27.4,
  plus cross-School V-5 references, NULL tenant keys and orphaned
  memberships).
- **Rollback (`/rollback-proof 3`):** the schema-only dump is byte-identical
  before and after re-migration (the rollback removes 37 schema lines);
  the rolled-back constraint definitions equal the pre-ORG.1A ones exactly
  (22 V-3 keys, the V-4 single-column key, the 5 whole-key SET NULLs);
  `platform:verify-database` reports `failed=0`.
- **Tests:**
  - new: `SchoolStatusMassAssignmentTest`, `PlatformRoleGovernanceMfaTest`,
    `ProtectedHistoryDeletionTest`, `SchoolAuditElevationIntegrityTest`,
    `TenantCompositeSetNullTest`;
  - affected-area targeted run (Platform, Postgres, Tenancy, Authorization,
    Audit, Retention, Identity, Compliance): 1,865 tests green after
    fixture updates;
  - the exact-SHA `CI gate` carries the canonical full regression (ADR 0072
    §28.8).
- **DDEV:** `ddev demo-reset --build` passes, and both demo Schools come up
  `active`.

### 27.7 Unchanged and still open

- No platform role, capability, Campus authority, entitlement, navigation,
  SIS or route change; `platform.schools.manage` stays root-reserved.
- **Remaining ORG findings:**
  - `platform.schools.view` and `platform.feature_flags.*` are dormant
    (ORG.3 / ORG.5);
  - `students.processing_authorizations` is a dead flag (ORG.5);
  - the runtime role still holds DELETE on `school_memberships`. No code
    uses it, and grant history is now RESTRICT-protected; revoking it is a
    candidate for a later hardening pass;
  - domain-evidence cascades (class E) remain retention-governed.
- **Next:** ORG.2 (authorization coverage guard) needs owner authorization.

## 28. ORG.2 — Authorization coverage guard (implemented, 2026-10-18)

Owner authorization: ORG.2 only. Baseline `origin/main` = `b9380e0a`
(ORG.1A). ORG.3 and later phases were not started.

### 28.1 The School-route predicate

A route is **School-facing** when it carries `school-context` (signed-in
School web) or `school-membership` (School API), or it is one of the seven
C5 routes listed by name in `SchoolRouteCoverage::special()`. That
classification uses route middleware, not URL prefixes.

Every other route is listed by name in `RouteUniverse::NON_SCHOOL` with its
category and authority:
- A, platform/Group: 33;
- E, partner/internal service: 5;
- F, authentication/account: 23;
- H, public/non-School: 9.

A route of any kind that is not classified fails CI. There is no prefix,
wildcard or HTTP-verb exemption.

### 28.2 Coverage classes

School context or membership is never a contract. It proves the actor
belongs to the School, not that they may perform the operation.

| Class | Contract | Evidence the guard checks in code (comments ignored) |
| --- | --- | --- |
| C1 | the route's own `capability:` middleware | read from the route |
| C2 | an unconditional `capability` Gate call in the action, or in a `$this->helper()` it calls unconditionally (`authorizeCapability()`, `authorizeAnyCapability()`, `Gate::authorize()`, `Gate::forUser()->authorize()`, a `Gate::denies()` refusal) | the keys are resolved from the code (literal, `Class::CONST`, `Class::staticCall('…')`) and must include the declared ones |
| C3 | the action delegates to an Application/Domain or Support method that authorizes the actor | the action calls the method, and that method makes an `authorize*()`/Gate call within three hops, or is a listed domain authority (`StaffRoleCatalog`, which also refuses disabled Users and is deliberately not routed through the Gate) |
| C4 | a scoped authority (teacher ownership, acting employee, Guardian scope, own membership, announcement creator), plus its base capability | the action calls the declared authority method; the base capability is C1 or checked like C2 |
| C5 | context establishment, one-time token acceptance, a legal-gated notice page | governing ADR/invariant, reason and an existing test; at most 10 |

Two flags make existing behaviour explicit:
- **`validatesFirst()`** (44 routes) marks an action whose authority runs
  after input validation, so an empty unauthorized request sees a
  validation error first. That is existing behaviour, kept.
- **`forEveryMember()`** (3 routes) marks routes every active member
  passes by design: the actor's own email preference (show and update),
  and the data-free statutory-payroll notice page.

### 28.3 Implementation (test-owned, no runtime footprint)

All of this lives in `tests/Support/Authorization/`:
- **`Coverage`:** the value object.
- **`SchoolRouteCoverage`:** 777 entries, one per non-C1 School route,
  plus the scoped authority of owned surfaces that also carry C1.
- **`RouteUniverse`:** the non-School classification.
- **`SourceCalls` and `CoverageEvidence`:** the token-level evidence
  checks.
- **`CoverageGuard`:** the checks themselves.

There is no database table and nothing is tenant-configurable.

The tests are in `tests/Feature/Authorization/Coverage/`:
- **`AuthorizationCoverageGuardTest`:**
  - every route is classified exactly once;
  - every School route has a contract;
  - every entry names a real route, once;
  - evidence holds;
  - no patterns;
  - declared and route-middleware keys exist in the catalogue as
    School/Guardian keys;
  - C5 stays small and governed;
  - a `{school}` route is School-facing or platform-governed.
- **`AuthorizationCoverageGuardSelfTest`:** probe routes, registered on
  the test application only, prove the guard catches:
  - a web or API route with only context/membership;
  - an unclassified route;
  - a stale, duplicate or wrong-key entry;
  - a non-controller C3.
- **`SchoolRouteRefusalSweepTest`:** requests every School route as a
  capability-less member (403/404 only, except the flagged routes) and as
  another School's administrator (never served). API requests use a
  session actor, so the bearer-token scope gate does not mask the School
  check.
- **`RepresentativeRouteAuthorizationTest`:** allow, deny and cross-School
  per enforcement style, plus the two findings below.
- **`HttpLayerResolverEnforcementGuardTest`:** HTTP-layer code never
  refuses on a raw `CapabilityResolver` answer.

### 28.4 Counts at publication

- **Routes:** 1,267 registered; 1,197 School-facing (644 web, 547 API, 6
  context/token routes, plus the statutory notice page, which is a web
  route); 70 non-School.
- **By class:**
  - C1: 422;
  - C2: 605;
  - C3: 85;
  - C4: 78 (52 of them also carry C1);
  - C5: 7.
- **Reporting categories:** the availability-gated surfaces (portal, marks,
  teacher attendance API; 27 routes) are all C1 behind their availability
  middleware.
- **Unclassified School routes:** 0.

### 28.5 Gate normalization (class C → Gate)

Direct `CapabilityResolver` use in the HTTP layer was classified:
- **A** — resolver internals;
- **B** — domain authority: `StaffRoleCatalog`, Group `canInGroup()` (rule
  84), and the internal AI authorization oracle (ADR 0023);
- **C** — HTTP refusals on a raw resolver answer;
- **D** — response projection (`canX` flags, optional sub-sections).

Every class C site now refuses through the Gate, with identical status and
message:
- **`SchoolDomainController`** (view and manage) and
  **`ApiClientController`** (view and manage): `Gate::forUser()->denies()`,
  keeping the same `AccessDeniedHttpException` messages. Neither
  controller uses the resolver any more.
- **`ApiClientController`'s inline MFA assurance** is replaced by
  `SensitiveReadAssurance::requireForPage()`, the same checks and the same
  401/403 `MfaRequired` page.
- **Communications** (the announcement show, update, publish, cancel,
  schedule and approval actions, the thread show, and attachments): the
  `communications.manage` half of each "creator/participant or manager"
  check uses `Gate::allows()`.

Class D projection is unchanged. Domain and service resolver use outside
the HTTP layer is retained (Application services authorize through
`authorizeCapabilityFor()`, the Gate).

### 28.6 Findings fixed (deny-side only; no valid access lost)

1. **Rule 32 (idempotent replay).** `POST
   …/employees/{employee}/hr-document-records` and `…/{document}/archive`
   carried `idempotent` with no route capability, because the service picks
   the tier capability per call. A stored success could therefore be
   replayed to an actor who had since lost the capability.
   - **Fix:** `capability:` now accepts an any-of form (`a|b`, each checked
     through the same Gate, like `authorizeAnyCapability()`). Both routes
     carry the floor
     `hr.employees.documents.manage|hr.employees.sensitive.manage`.
   - **Effect:** every actor who can succeed holds one of the two, so the
     request is authorized before the idempotency guard runs. The tier
     check in `EmployeeDocumentService` is unchanged.
2. **Authorship without authority.** Announcement attachment upload and
   removal needed only "creator or `communications.manage`", while editing
   the announcement needs `communications.announce` as well. An off-boarded
   creator who stays a Guardian member keeps an active membership (POR.1,
   ADR 0070 §24.8) and could still change their draft's attachments.
   - **Fix:** both actions now require `communications.announce` first, as
     `AnnouncementController::update()` does.
   - **Effect:** no system role loses access (only `school_admin` holds
     `communications.manage`, and it also holds `communications.announce`).

No other route changed behaviour. 403/404 privacy semantics, ownership,
Guardian scope, self-service and payroll self-protection guards, legal
gates, RLS and database triggers are untouched.

### 28.7 Observations (recorded, not changed)

- **Validation before authority.** 44 web actions, mostly Leave, staff
  attendance, Payroll and statutory forms, validate input before their
  service authorizes. `exists:` rules can therefore confirm, within the
  same School only, that a record id exists.
- **Record lookup before authority.** Some Payroll web actions resolve the
  record (`findOrFail`) before the service authorizes: a same-School 404
  versus 403.
- **The statutory-payroll hub.** It renders a data-free notice page for
  every member by design (E45; `StatutoryPayrollUiTest`,
  `EmploymentChainProductionPathTest`). It is recorded as C5 rather than
  changed.
- **Not built:** a platform-route coverage guard (platform routes are
  classified and reasoned in `RouteUniverse`, but carry no evidence check)
  and an entry-point guard for jobs and commands. Both are future
  hardening.
- **DDEV-only failures.** Three host-classification tests fail only under
  DDEV, because DDEV exports `APP_URL` and `phpunit.xml` does not force it.
  The canonical CI run is the reference.

### 28.8 Contributor rule

A new School-facing route either carries `capability:` middleware or gets
a `SchoolRouteCoverage` entry whose evidence the code shows. A new non-School
route is classified in `RouteUniverse`. Otherwise CI fails (CLAUDE.md
rule 98). Never add a capability, a wildcard or a C5 entry to make the
guard pass. An unexpressible route is an architecture gap to report.

## 29. ORG.3 — Platform School Registry (implemented, 2026-10-18)

Owner authorization: ORG.3 only. Baseline `origin/main` = `c421796b`
(ORG.2). ORG.4 and later phases were not started.

### 29.1 Before ORG.3

- **The old list.** `/app/platform/schools` listed every School (name,
  slug, code, status) with no search, filter or pagination.
- **The old detail page.** It showed the same fields, closure, the
  bootstrap administrator while `provisioning`, and the lifecycle
  actions.
- **Gating.** Both pages needed `platform.schools.manage`.
  `platform.schools.view` existed but no route checked it.

### 29.2 Authorization

- **Registry reads.** The list and overview (`app.platform.schools.index`,
  `.show`) need `platform.schools.view` through route `capability:`
  middleware (platform mode).
- **Lifecycle pages.** Create and review keep `platform.schools.manage`.
  The lifecycle POSTs are unchanged: no route capability, and the
  services authorize, audit refusals and need confirmation plus a fresh
  MFA code.
- **What the overview offers.** Actions and the create link appear only
  for `platform.schools.manage`, the same Gate check the services make.
- **Navigation.** The navigation entry follows the page:
  `platform.schools.view`.
- **Personas as built:**
  - `platform_super_admin` holds both capabilities;
  - `platform_auditor` holds `platform.audit.view` only, so it is refused
    (pinned by a test). ORG.4/ORG.6 own any Auditor extension;
  - School roles never reach the registry, and a School grant plus a
    platform grant never merge.
- **No new capability or role.**

### 29.3 Read model (`App\Domain\Platform\Application\Schools\Registry`)

- **`SchoolRegistryReadService`:**
  - **Platform tables, read directly.** `schools` carries search, filters
    and keyset pagination. `school_domains` states and Group membership
    are batched for the page.
  - **RLS-protected structure, one School at a time.** It is read only
    through the owning modules, inside ONE School's TenantContext:
    - `CampusStructureSummary` (new, Campuses): counts, plus names/codes
      on the overview;
    - `CurrentAcademicYearResolver::tryResolve()`: the active year's name;
    - `SchoolAdministrators::readiness()` (new): `ready`,
      `not_activated` or `missing`, no identity.
  - **No shortcuts.** No BYPASSRLS, no admin connection at runtime, no
    cross-tenant query or cache.
- **`SchoolAdministrators` gains a narrowing pre-filter.**
  - It reads only memberships with an active grant whose role carries
    `school.members.manage`.
  - School capabilities come only from active grants, so this is a strict
    superset of the true administrators. The unchanged per-user check
    still decides.
  - It serves activation, off-boarding and the registry alike.
  - No second definition of a School administrator exists.
- **List search.** It is case-insensitive substring search on `name`,
  `legal_name`, `slug` and `code` only.
  - Bounded to 100 characters.
  - `%`, `_` and `\` are escaped.
  - Parameter-bound ILIKE.
  - This replaces §9.2's "exact name/slug/code". It still never touches
    tenant data.
- **Filters:**
  - status: `provisioning`, `active`, `suspended` (open) or `closed`;
  - School Group (exact id);
  - custom-domain state: `none`, `pending`, `active` or `attention`
    (precedence: suspended > active primary > being verified);
  - **deferred:** the readiness filter, which would need every School's
    tenant reads.
- **Pagination.** Keyset on `(name, id)` with an opaque cursor.
  - 25 per page by default, bounded 10–50.
  - A forged cursor is refused.
  - No duplicates or gaps under stable data.
- **Query cost.** A 10-row page issued 173 statements with 10 Schools and
  with 30 Schools. It is proportional to the page, never to the
  population (`SchoolRegistryTenancyAndQueryBoundsTest`).

### 29.4 Readiness and warnings (§9.3, ORG-C4)

- **`SchoolReadiness` is derived when read** and never stored (no column;
  a test pins it).
- **Checks:**
  - an activated administrator;
  - at least one active Campus;
  - an active academic year;
  - only where custom domains are enabled and a domain is claimed, an
    active primary domain.
- **States:** `ready`; else `partially_configured` while `provisioning`;
  else `needs_attention`.
- **Warnings** (key and severity, no tenant data):
  - `awaiting_activation`, `school_closed` (info);
  - `school_suspended`;
  - `administrator_missing` and `administrator_not_activated`;
  - `no_active_campus`, `no_active_academic_year`;
  - `custom_domain_suspended`; `custom_domain_pending` (info).
- **Advisory only:** readiness never becomes an activation precondition.

### 29.5 Recent platform events on the overview

- **Read path.** `PlatformAuditEventReader::recentForSchool()` reads the
  10 newest events whose subject is the School, envelope columns only,
  through the existing `(subject_type, subject_id)` index.
  - Included: `platform.school.*` (lifecycle, including refusals) and
    `platform.school_elevation.*`.
  - Excluded: a user's own `school_context.activated`.
- **Access.** Events are shown only through
  `PlatformAuditLogReviewService::reviewSchool()`:
  - `platform.audit.view` and current MFA assurance, both re-checked;
  - one `platform.audit_log.viewed` with the School as subject.
  - Otherwise the section says why it is unavailable and records nothing.
- **Never** `school_audit_events`.

### 29.6 Unchanged

- **No new things:** no new capability, role, table, column, migration,
  index or API route.
- **Lifecycle unchanged:** rules, fresh MFA, root-only School creation and
  the bootstrap administrator's provisioning-only visibility.
- **No Platform Manager, module entitlements, Campus authority or SIS
  change.**
- **Entitlement summary.** The §9.2 entitlement row stays for ORG.5. No
  placeholder control is shown.

### 29.7 Evidence

- **Tests:**
  - `Tests\Feature\Platform\Schools\Registry\SchoolRegistryTest`
    covers authorization for every persona, metadata only, search,
    filters, pagination, readiness fixtures A–G, the unactivated
    administrator, readiness never stored, and audit review and envelope;
  - `SchoolRegistryTenancyAndQueryBoundsTest` covers one-School reads and
    context restoration, the RLS negative case, pre-filter equivalence
    and the query bound;
  - updated `SchoolLifecycleTest` (the row shape) and
    `SchoolLifecycleArchitectureGuardTest` (view and manage routes).
- **DDEV smoke:**
  - the Platform Admin sees both demo Schools: Demo *Ready*, Annexe
    *Needs attention* (no active academic year);
  - search and status filter narrow correctly, and an invalid filter is
    refused;
  - the overview shows its checks, with events gated on MFA;
  - the School Admin and the Platform Auditor get 403.

## 30. ORG.4 — Platform operator personas and onboarding authority (implemented, 2026-10-18)

Owner authorization: ORG.4 only. Baseline `origin/main` = `7efd14b8`
(ORG.3). ORG.5 and later phases were not started.

### 30.1 Persona matrix (pinned by `PlatformPersonaMatrixTest`)

| | Platform Admin (`platform_super_admin`, root) | Platform Manager (`platform_manager`) | Platform Auditor (`platform_auditor`) | Any School role |
|---|---|---|---|---|
| Registry list and overview | yes | yes | yes | no |
| Create a School (into `provisioning`) | yes | yes | no | no |
| Bootstrap administrator while provisioning | yes | yes | no | no |
| Activate, suspend, resume, close, reopen | yes | no | no | no |
| Grant or revoke platform roles | yes | no | no | no |
| School Groups | read and govern | read | read | no |
| Platform audit review (current MFA) | yes | no | yes | no |
| Elevation | yes | no | no | no |
| School authority or tenant business data | no | no | no | own School only |

### 30.2 Catalogue (`CapabilityAndRoleSeeder`, code-owned)

- **New capability:** `platform.schools.onboard` (namespace `platform`;
  not root-reserved). The `role_capabilities` scope trigger keeps it off
  every School, Group and Guardian role (a raw-SQL test pins it).
- **New role:** `platform_manager`, a runtime-assignable system platform
  role holding exactly `platform.schools.view`,
  `platform.school_groups.view` and `platform.schools.onboard`.
- **Auditor:** `platform_auditor` gains `platform.schools.view` and
  `platform.school_groups.view`, keeping `platform.audit.view`.
  - This brings forward, at the owner's direction, the Registry and Group
    reads §8 had placed in ORG.6.
  - Entitlement views wait for ORG.5, and any other Auditor read model for
    ORG.6.
- **Root** gains `platform.schools.onboard`. `platform.schools.manage` and
  `platform.role_grants.manage` stay root-reserved: the trigger is
  unchanged, and tests prove neither runtime role can receive them.
- **Not granted:**
  - `platform.operations.view`: its consumers are the internal JSON
    status endpoint, two CLI commands and the local MFA demo ping. None is
    a persona read surface, so it is recorded and deferred;
  - entitlement capabilities (ORG.5).

### 30.3 Onboarding split

- **The rule.** `SchoolLifecycleAuthority::capabilitiesFor()` is the one
  per-operation rule:
  - `Create` and `ReplaceBootstrapAdmin` accept onboard **or** manage;
  - every status transition accepts manage alone.
- **Where it lives.** CREATE stays in `SchoolLifecycleService` (CLAUDE.md
  rule 86: the only School creator). There is no separate onboarding
  service, because the split is a capability rule, not a new code path.
- **Initial status.** A School always starts in `provisioning`. `status`
  is not fillable (ORG.1A V-1), and submitted `status` or `closed_at`
  fields are ignored (tested).
- **Routes:**
  - create and the bootstrap-admin review page:
    `capability:platform.schools.onboard|platform.schools.manage,platform`
    (the ORG.2 any-of floor);
  - the lifecycle review page: `platform.schools.manage`;
  - the POSTs are still service-authorized, and refusals are audited.
- **Database.** The SR.1 grantor trigger's ADR 0047 bootstrap exception
  now accepts an active platform grant carrying onboard as well as manage.
  It is still limited to the `school_admin` role, a School in
  `provisioning`, and an enabled grantor.
  - Without this the Manager's bootstrap grant was refused, which tests
    surfaced.
  - `down()` restores the previous function verbatim.
- **Order** (unchanged; a refusal never spends a code): account state →
  the operation's capability → state checks → confirmation → enrolled
  factor → fresh MFA re-verification. Tests prove a Manager's refused
  activate, suspend, resume, close and reopen did not consume the code it
  then used to create a School.
- **Duplicates.** Duplicate slugs and codes are refused by the unique
  constraints, and no second School is created.

### 30.4 Manager / Auditor exclusivity (§7.4, ORG-C3)

- **Service.** `PlatformRoleGovernanceService::EXCLUSIVE` refuses the
  conflicting grant after the self-grant check and before the MFA code
  (audited `role_conflict`).
  - A racing grant refused by the database maps to the same outcome.
- **Database.** Migration `2026_12_19_090000` adds
  `trg_platform_role_assignments_separation`, a separate BEFORE INSERT
  trigger.
  - It takes a per-user advisory transaction lock and refuses an active
    grant of one role while the other is active.
  - It applies to runtime and out-of-band grants alike.
- **Revocation.** Revoking one role makes the other grantable again.

### 30.5 Pages

- **Platform Roles:** one section per runtime role (Manager, Auditor) with
  its grants, an explicit role choice on grant, and a fresh code for every
  write. Platform Admin is described as root and never offered.
- **Registry:**
  - *Create a School* for onboard holders;
  - lifecycle actions only for `platform.schools.manage`;
  - the bootstrap-administrator action for onboard holders while
    provisioning;
  - a provisioning School shows *Only a Platform Admin can activate it* to
    anyone without manage.
- **Overview audit section.** The Manager does not hold
  `platform.audit.view`, so the section shows "not part of your access".
- **Registry cost.** Unchanged: one extra Gate check per page, no per-row
  work.

### 30.6 Platform-route coverage guard (the ORG.2 §28.7 follow-up)

- **`PlatformRouteCoverageGuardTest`.** Every RouteUniverse category-A
  route carries platform-mode `capability:` middleware or a
  `PlatformRouteCoverage` entry (25 entries) verified by the same
  `CoverageEvidence`.
  - The entries include the real authority of Group-governance mutations,
    whose route middleware is only a read floor.
  - No platform route uses School-mode capability middleware.
  - A probe route proves an uncovered platform route is caught.
- **Recognized domain authorities** gained:
  `PlatformRoleGovernanceService::requireCapability`,
  `SchoolGroupGovernanceService::require` and
  `GroupCurriculumCoverageReportService::authorizingGrant`.

### 30.7 Demo, evidence and what stays out

- **Demo persona.** `platform.manager@example.test` (Platform Manager,
  granted by the demo Platform Admin) joins the DDEV demo.
- **DDEV smoke (status codes):**
  - Admin: Registry 200, create 200, suspend review 200, roles 200;
  - Manager: Registry 200, create 200, suspend review 403, roles 403,
    overview offers no lifecycle action;
  - Auditor: Registry 200, create 403;
  - School Admin: everything 403;
  - the Roles page lists both runtime roles.
- **Rollback proof (`/rollback-proof 1`):** the schema is identical after
  rollback and re-apply (54 lines removed by the rollback), and
  `platform:verify-database` reports `failed=0`.
- **Not done here:** no module entitlements, Campus authority, School
  capability for any platform role, impersonation or SIS change.
- **Observation (security review, below the bar):** activation re-checks a
  qualifying administrator under the School lock, but it does not pin the
  identity shown on root's review page. A Manager who replaces the
  bootstrap administrator between root's review and confirmation would
  have the new person activated.
  - The replacement is audited.
  - §7.3.4 accepts the separation as a consequence, not a check.
  - Pinning the reviewed administrator is a candidate hardening for a
    later phase.

## 31. ORG.5 — School module entitlements (implemented, 2026-10-18)

Owner authorization: ORG.5 only, under owner decisions D1–D11 (below).
Baseline `origin/main` = `5d7ebef8` (ORG.4). ORG.6 and later phases were
not started.

Owner decisions applied:
- **D1/D2:** existing Schools keep every current optional module.
- **D3:** core is never disableable.
- **D4:** a new School starts core-only. This supersedes the §22.4
  recommendation of a general-availability default.
- **D5:** activation needs no optional module.
- **D6:** root and Manager manage; the Auditor is not extended.
- **D7:** platform ledger only.
- **D8:** flags stay distinct.
- **D9/D10:** disable deletes nothing; re-enable restores.
- **D11:** no billing.

### 31.1 Catalogue (`App\Domain\Platform\Application\Modules\ModuleCatalogue`)

Code-owned (no table). Stable snake-case keys, each with a label, the
core flag, dependencies, capability prefixes and a description. Two
additional diagnostics fields:
- `ASYNC_MODULES`: the modules with background entry points;
- `RESTRICTIONS`: the legal and environment gates that stay authoritative.

**Core (7, §14, never stored, never disableable):**
- `identity_access`
- `organization`
- `academic_structure` (with `teaching.assignments.*`)
- `student_records` (Students, enrollments, the processing-authorization
  registry and Guardian identity/relationships: one family)
- `staff_records` (the HR employee foundation)
- `compliance_audit`
- `documents` (infrastructure; no capability of its own; access is
  decided by the owning record)

**Optional (22):** `admissions`, `timetable`, `attendance`, `curriculum`
(Syllabus and Curriculum Delivery), `lms`, `examinations`, `finance`,
`fees` (fee setup, charges, concessions, late fees, payments),
`leave`, `staff_attendance`, `payroll`, `library`, `transport`,
`hostel`, `inventory`, `canteen`, `visitor`, `communications`,
`guardian_portal`, `analytics`, `automation`, `integrations`.

**Mapping (ORG-C5):**
- **Rule.** The longest matching prefix wins. Four explicit multi-module
  rules apply: `portal.communications.view` / `.reply` →
  `guardian_portal` + `communications`; `portal.attendance.view` →
  `guardian_portal` + `attendance`; `portal.fees.view` →
  `guardian_portal` + `fees`. A capability is effective only when every
  required module is available.
- **Coverage.** Every `school` and `guardian` capability in the
  catalogue is mapped; platform and Group capabilities are not
  (`ModuleCatalogueArchitectureTest`).
- **Guardian Portal.** It has no dependency on Communications: the
  portal's children and profile views need no Communications. Only the
  views that show another module's data require that module.

**Dependencies (real code/product dependencies only):**
- `attendance` → `timetable` (registers are taken against the schedule);
- `fees` → `finance` (charges and payments post to the ledger);
- `staff_attendance` → `leave` (DOMAIN-MAP);
- `payroll` → `finance` (payroll posts journals);
- `library` → `fees` (fines are Fees charges);
- `canteen` → `inventory`, `fees` (orders issue stock and charge);
- `analytics` → `curriculum` (the only report is curriculum coverage).

Transport, Hostel and Admissions work without Fees: their fee seams
(ADR 0067) write inert intents. This is recorded rather than made a
dependency, so that disabling Fees never strands an operational module.

The architecture test proves: no unknown dependency, no self-dependency,
no cycle, and no core module depending on an optional one.

### 31.2 Persistence (`school_module_entitlements`, migration `2026_12_20_090000`)

- **Columns:** `id`, `school_id` (FK `schools`, cascade: tenant-lifetime
  configuration, not history), `module_key`, `enabled`, `reason_code`,
  `changed_at` and timestamps.
- **Constraints:**
  - `UNIQUE (school_id, module_key)`;
  - a CHECK admitting exactly the 22 optional keys (literal in the
    migration, never imported; the architecture test pins it to the
    catalogue);
  - a CHECK on the closed reason codes (`pre_org5_backfill`,
    `onboarding_configuration`, `school_request`, `platform_policy`,
    `operational_correction`).
- **Tenancy and privileges:** FORCE RLS (`TenantRls::enable`), and DELETE
  revoked from the runtime role (`TenantRls::revokeDelete`).
- **What it never holds:** prices, plans, seats, invoices or commerce
  data.
- **Retention:** catalogued in `school_configuration` (tenant lifetime).
- **Unknown or core keys** can never be written. Had one existed, it
  would grant nothing: the reader keeps only catalogue optional keys.

### 31.3 Backfill, new-School default and cutover

- **Backfill (same migration, same transaction).** One `enabled = true`
  row per (existing School, optional module), reason `pre_org5_backfill`.
  The rule is purely backward compatibility (every School could reach
  every optional module before ORG.5); it says nothing about licensing.
  Legal and environment gates are unchanged.
- **New Schools.** A missing row means **disabled**, so a School created
  by `SchoolLifecycleService` after the migration is core-only with no
  extra write. Root- and Manager-created Schools are identical, and no
  creation field can opt in (tested).
- **Cutover proof.** The supported workflow migrates first (on the admin
  connection) and then rolls the application out.
  - Between the two, old code reads no entitlement, so nothing changes.
  - A School that old code creates in that window has no rows. New code
    treats it as core-only, which is exactly the D4 default for a School
    created after ORG.5.
  - New code without the table fails closed: the reader errors and grants
    nothing. It never treats a missing table as "everything".
  - There is no window in which an existing School loses a module.
- **Fixtures.** `SchoolFactory` enables every optional module after
  creating a School (it models an existing School), and
  `withCoreModulesOnly()` models a new one. The demo builder enables
  every optional module for both demo Schools.

### 31.4 Resolution and enforcement

- **`SchoolModuleEntitlements`** is the one reader.
  - Core modules plus enabled optional rows, read inside the target
    School's TenantContext (prior context restored).
  - `entitledModules()` is cached for 60 s per School
    (`school:{id}:entitled-modules`) and forgotten after commit, so a
    change is immediate for every user.
  - `isEnabledFresh()` is uncached; background work uses it.
- **`CapabilityResolver::schoolCapabilities()`** returns the cached
  grants intersected in memory with the entitled set (one cached lookup
  per request, never a query per capability).
  - Group and platform resolution are unchanged.
  - Every Gate, `capability:` middleware, `AuthorizesCapability` call and
    navigation projection inherits it.
  - Proven end to end on web, API, a service (`FeeAssessmentRunService`
    resume), navigation, the Guardian portal (`portal.fees.view`) and
    teacher ownership (`TeacherAttendanceAccess`: entitlement AND
    capability AND ActingEmployee/ownership).
- **Legal gates stay higher.** With every module enabled, production
  still refuses the portal (tested).
- **Role grants.** §12.6 freezes no refusal rule, so none was added.
  - `RoleGrantAuthority` reads held capabilities from the database, as
    before.
  - A grant made while a module is disabled commits and confers nothing
    until it is enabled (tested).
- **Group-safe reporting.** The Group-safe curriculum-coverage report
  (ADR 0048) reads Analytics under `group.reporting.view` and does not
  consult the member School's entitlements. Recorded for ORG.6 review,
  not changed.

### 31.5 Management (`SchoolModuleEntitlementService`, the only writer)

- **Capabilities.** `platform.school_entitlements.view` / `.manage`
  (namespace `platform`) are held by root and `platform_manager` only.
  The scope trigger keeps them off every School, Group and Guardian role.
  `platform_auditor` was not extended in ORG.5. (Superseded in part by
  §32: ORG.6 gives it `.view`, never `.manage`.)
- **Order** (no code is spent on a static refusal):
  1. account enabled, then `.manage`;
  2. a known module, then not core;
  3. the School not closed (409);
  4. a closed reason;
  5. a real change ("already enabled/disabled" is a validation error, not
     audited);
  6. dependencies;
  7. confirmation;
  8. an enrolled factor;
  9. a fresh code (`MfaReverificationService`).
- **The transaction.** The School row FOR SHARE plus
  `pg_advisory_xact_lock('school_entitlements:<id>')`; state, closure and
  dependencies are re-read; the row is upserted inside the School's
  TenantContext and the platform event recorded; the cache is forgotten
  after commit.
- **Events.** `platform.school_entitlement.enabled` / `.disabled`
  (metadata: `module_key`, `from`, `to`, `reason_code`, and for a disable
  `affected_capabilities` and `affected_active_grants` counts) and
  `.denied` (`outcome_code`, plus `module_key` when it is a real module).
  Never a School audit event.
- **Impact preview** (advisory; re-computed when the change executes):
  dependants still enabled, the number of capabilities that stop taking
  effect, active grants carrying one, and navigation entries hidden. No
  names.
- **Lifecycle.** Allowed for provisioning, active and suspended Schools;
  refused for a closed one. Never a lifecycle change.
- **Pages.** The School overview gains a *Modules* section (view
  capability): core shown as always available, optional with
  *Enable…* / *Disable access…*.
  - `/app/platform/schools/{school}/modules/{module}/{enable|disable}` is
    the review page (`.manage` middleware): reason, confirmation, code,
    and for a disable the impact and "nothing is deleted".
  - The POST is service-authorized.
  - Both routes are classified in `RouteUniverse`; the POST is a
    `PlatformRouteCoverage` C3 entry
    (`SchoolModuleEntitlementService::requireManage` is a recognized
    domain authority).

### 31.6 Background work (`ExecutesModuleWork`, `ModuleExecutionGuard`)

| Entry point | Module | Outcome when not entitled at execution |
|---|---|---|
| `ProcessCommunicationDeliveryJob` | communications | delivery `cancelled`, `failure_code = module_not_entitled`, no attempt, never redispatched |
| `communications:publish-scheduled` | communications | the School is skipped; announcements stay `scheduled` (held), published normally once enabled |
| `WebhookFanoutConsumer` | integrations | no delivery row; the event is acknowledged (outbox evidence kept) |
| `DeliverWebhookJob` | integrations | delivery `abandoned` (reason `module_not_entitled`), no HTTP call |
| `AutomationTriggerConsumer` | automation | no execution; the event is acknowledged |
| `RunAutomationExecutionJob` → `AutomationExecutionService` | automation | execution `skipped`, `automation_module_not_entitled` (terminal) |
| `ExecuteFeeAssessmentRunJob`, `ExecuteLateFeeRunJob` → item executors | fees | run paused (no item changes, no re-dispatch), audited with reason `module_not_entitled`; resuming needs a Fees capability, which resolves to nothing until the module is enabled |

- **Semantics.** Work queued while a module was enabled never performs
  that module's effect after it is disabled. Every outcome is the
  substrate's own terminal or held state, and none retries.
- **Guard placement.** The guard runs inside that School's TenantContext
  and never reads another School.
- **Exempt (core or infrastructure, listed with reasons in
  `AsyncModuleContractArchitectureTest`):** the email layer, domain
  checks, account recovery, the outbox substrate, the audit ping, the
  worker canary and the School-setting notice consumer.
- **Not module work.** Redispatch commands only redispatch the guarded
  jobs. Retention, holds and maintenance continue (the ADR 0047 §9
  principle).
- **Recorded, not changed:** Payroll's HRX evidence read of
  StaffAttendance does not consult the StaffAttendance entitlement (inert
  until HRX-L4); Transport, Hostel and Admissions fee intents are written
  while Fees is disabled and stay inert.

### 31.7 Feature flags (§13)

- **`automation.rules`** is kept as an operational rollout switch layered
  on the `automation` entitlement. Execution needs both; neither creates
  or overrides the other (tested in both directions).
- **`students.processing_authorizations`** is retired by migration
  `2026_12_20_090100`.
  - It was seeded but never read (ADR 0038 drift note); its override rows
    cascade.
  - `down()` restores the catalogue row; inert overrides are not
    recreated.
  - The `students.processing_authorizations.*` capabilities are
    untouched.
- **`platform.feature_flags.view` / `.manage`** stay **reserved and
  non-authoritative**: held by root only, read by no code, with no
  surface.
  - Retiring them is a catalogue release, deferred because nothing forces
    it.
  - They are never repurposed for entitlements.

### 31.8 Reference permissions workbook (ORG.9 primary evidence)

- **The file.** The owner supplied `Menu_Permissions_Master.xlsx`
  (SHA-256 `96cf8596efaf3d7689b994e9ae1c62e66e3d2701047e22f78298ac69bb32b47a`).
  It holds 19 consolidated "Manage Menus" exports in three role-group
  sheets: Corporate, Branch - Main and Branch - Principal Set.
  - 2,963 rows (1,596 unique menu paths, 88 flagged sensitive) across 7
    module groups and 52 reference modules.
  - It is held by the owner, not committed, and retained as primary
    evidence for ORG.9.
- **How it was used.** Only to check that the catalogue covers the
  reference functional families.
  - No capability, role, grant, template, menu setting or Campus scope was
    derived from its Yes/No cells.
  - No reference module name became a Lycenza module id.
  - The four layers stay distinct: reference menu visibility ≠ Lycenza
    module entitlement ≠ Lycenza capability ≠ Lycenza scope and ownership.

| Reference group (modules) | Lycenza modules today | Not built in Lycenza (ORG.9 candidates, no module created) |
|---|---|---|
| Organisation, SIS & Operations: Organisation, SIS, Admissions, Transport, Hostel, Inventory, Library, Certificates, Achievements, Login Statistics, Mentors, Learner Profile, Appointments and call logs | `organization`, `identity_access`, `academic_structure`, `student_records` (core); `admissions`, `transport`, `hostel`, `inventory`, `library`, `attendance`, `timetable`, `visitor` | certificates and TC, ID cards, achievements, learner profile and discipline, mentors, PTM, login statistics, appointments and call logs, student contracts and quotas, emergency exits, meals |
| Gradebook & Exams: CBSE/State, Cambridge, IB, ICSE, Higher Ed, Pre School, Exam Cell, ECA and PET | `examinations` (development; marks production-refused) | board-specific gradebooks, report-card publishing, exam cell, ECA/PET, health, pre-school assessment, competitive exams |
| Finance: Finance, Expenses Management, Budget, Finance Intelligence, School Store | `finance` (ledger, accounts, periods), `fees` (setup, charges, concessions, late fees, payments) | expense and petty-cash vouchers, budgets, bank reconciliation, revenue intelligence, school store (Lycenza's canteen is not a store), scholarships |
| HR: HR, Staff Wall | `staff_records` (core), `leave`, `staff_attendance`, `payroll` | biometric attendance, exit clearance and NOC, staff tasks, staff wall and meetings |
| Communication: Announcements, Email, SMS, WhatsApp, Voice Calls, Notifications, Diary, Calendar, Student Wall, Activities and Photo Gallery | `communications` (announcements, conversations, templates, in-app and email); `guardian_portal` | SMS, WhatsApp, voice, diary, calendar, walls, photo gallery |
| Academics / LMS: Assignments, Content Management, Teaching Plan, Subjects Management, Online Exams, Objective Exams, Online Classes, NEP HPC, Learning Taxonomy, Reflections, Baseline Analysis, Class Room Observation | `lms` (content, assignments), `curriculum` (syllabus, delivery), `academic_structure` (subjects) | lesson plans and planners, question banks and online/objective exams, online classes, NEP HPC, taxonomy diagnostics, reflections, classroom observation |
| Digital Document Library | `documents` (core infrastructure; access decided by the owning record) | a separate document-library product surface |

- **Boundaries the reference crosses** (recorded; the catalogue is
  unchanged by evidence):
  - **Attendance without a timetable.** The reference takes daily
    attendance with no timetable. Lycenza's registers are bound to
    `TimetableEntry`/`TimetablePeriod`, so `attendance → timetable` stays
    a real dependency. Daily (non-period) attendance is a parity question.
  - **Fees inside other modules.** The reference keeps fee screens inside
    other modules (transport fee, hostel fee, library fee receipt,
    admission/registration offline payments, examination fees). In
    Lycenza those are Fees capabilities reached through the ADR 0067
    seams: Library depends on Fees; Transport, Hostel and Admissions do
    not.
  - **Payroll postings.** The reference posts payroll from Expenses
    Management. Lycenza Payroll posts through Finance (`payroll → finance`).
  - **Platform administration.** The reference's Organisation module mixes
    School setup with platform administration (admin users, merchant
    registration, invoices, KYC, support tickets). In Lycenza these split
    into core `organization` / `identity_access` and the platform plane,
    or are out of scope (billing).
- **Sensitive reference functions for ORG.8 (approvals) and ORG.9
  (parity), noted only:**
  - fee concession approval and settings;
  - fee refunds and bulk refunds;
  - deleting or adjusting fee payments;
  - fee unassign and bulk unassign;
  - offline-payment approval;
  - bank reconciliation;
  - cash-deposit, petty-cash and journal-voucher approvals and opening
    balances;
  - report-card and remarks publish settings (every board);
  - transfer-certificate generation;
  - student inactivate/reactivate approvals and bulk branch transfer;
  - password resets and admin/staff user management, security settings;
  - staff-attendance modification and deletion;
  - payroll month settings and payroll posting;
  - leave approval;
  - deleting online-exam results.

  Lycenza already models several of these as separate capabilities,
  maker/checker or correction contracts (concessions FEE.3, payment
  corrections UAT-C3, Leave approval HRX.2, staff-attendance corrections
  HRX.3). The rest are unbuilt.

### 31.9 Evidence

Recorded in the ORG.5 completion report: the targeted suites, the
rollback proof of both migrations, the security review and the
exact-SHA CI regression.

## 32. ORG.6 — Platform Auditor configuration oversight (implemented, 2026-10-18)

Owner authorization: ORG.6 only, under owner decisions D1–D8. Baseline
`origin/main` = `7ec816fc` (ORG.5). ORG.7 and later phases were not
started.

### 32.1 Catalogue (`CapabilityAndRoleSeeder`, code-owned)

- **`platform_auditor`** holds exactly: `platform.audit.view`,
  `platform.schools.view`, `platform.school_groups.view` and (new in
  ORG.6) the existing `platform.school_entitlements.view`.
- **New capabilities:** none. **New routes, tables or migrations:** none.
- **Never held by the Auditor:**
  - `platform.school_entitlements.manage`;
  - onboarding, lifecycle and root-reserved keys;
  - Group governance and elevation;
  - `platform.operations.view` and `platform.feature_flags.*`;
  - any School, Group or Guardian capability.
- **Materialization.** Role capabilities are synced by the idempotent
  `CapabilityAndRoleSeeder`, which runs through `DatabaseSeeder` in every
  environment (PRODUCTION-RELEASE.md). A grant names a role, never a
  capability, so existing Auditor grants pick up the new capability with
  no data change.
- **Unchanged:** Manager/Auditor exclusivity (service and
  `trg_platform_role_assignments_separation`). The Manager keeps exactly
  its ORG.5 set.

### 32.2 Read model (reused, not forked)

- **Same page for every persona.** The Auditor uses the ORG.3/ORG.5
  School overview (`/app/platform/schools/{school}`). There is no new
  page and no per-persona copy.
  - The `modules` prop is the `SchoolModuleEntitlementService::overview()`
    output, gated by the view capability: key, label, description, core
    flag, enabled state, dependencies and dependants, from the code
    catalogue plus `SchoolModuleEntitlements::optionalStates()` (one
    School's RLS context, the caller's context restored).
  - The enable/disable links render only for `.manage` (`canManageModules`).
- **What the Auditor does not see:**
  - the impact preview (grant counts, navigation), which is
    mutation-workflow data on the `.manage`-gated review page;
  - administrator identities;
  - domain hostnames;
  - School audit data;
  - any business record.
- **Configuration, not business data:**
  - "Fees enabled" is configuration; the School's collections are not;
  - "Payroll disabled" is configuration; payroll runs, salaries and
    payslips are not;
  - "Attendance enabled" is configuration; attendance records are not.

  A test proves the overview reads none of 18 tenant business tables
  (students, HR, payroll, attendance, marks, charges, payments, journals,
  documents, communications, leave, `school_audit_events`).
- **Assurance.** Viewing configuration and module state needs only an
  ordinary session (D4). The overview's recent platform events stay
  behind `platform.audit.view` plus current MFA assurance (D5); without
  assurance the section reports `mfa`.
- **History.** Entitlement history is the platform ledger, read only
  through the platform audit review. There is no second history table.

### 32.3 Persona matrix (pinned by `PlatformPersonaMatrixTest` and `PlatformAuditorConfigurationOversightTest`)

| | Admin | Manager | Auditor |
|---|---|---|---|
| Registry and School overview | yes | yes | yes |
| Module state view | yes | yes | yes |
| Module enable/disable | yes | yes | no |
| Create/onboard School | yes | yes | no |
| Activate, suspend/resume, close/reopen | yes | no | no |
| Platform-role governance | yes | no | no |
| Platform audit review (current MFA) | yes | no | yes |
| Elevation | yes | no | no |
| School business data or School audit ledger | no | no | no |

- **Direct requests are refused.** Every Auditor mutation request (module
  review pages and POSTs, create, activate, suspend, close, bootstrap
  administrator, role grants, Group governance, elevation) is refused,
  **with a valid fresh MFA code**.
- **The services refuse too.** They refuse the Auditor at the capability
  step, and no code is consumed. A School administrator, a teacher and a
  Guardian reach no platform page.

### 32.4 Decisions and observations

- **`platform.operations.view` stays ungranted (D8).** Its consumers are
  unchanged since ORG.4: the internal JSON status endpoint, CLI commands
  and the local-only MFA demo route. None is a persona surface.
- **No noisy audit.** Viewing configuration writes no audit event, which
  follows the Registry convention. The platform audit review itself is
  still audited.
- **Cost.**
  - The Registry list is unchanged: no entitlement work per row, and the
    query bound test still passes.
  - The overview takes 23 statements, one of them the entitlement read.
- **ORG.5 observations carried, not absorbed:**
  - the Group curriculum-coverage report ignores entitlements;
  - the HRX-L4 payroll attendance evidence;
  - the email hand-off window;
  - the bootstrap-administrator review race.
- **Reference evidence.** No reference-workbook role maps to the Auditor
  (§31.8).

## 33. ORG.7A — Campus authority foundation (implemented, 2026-10-18)

Owner authorization: ORG.7A only (owner decisions D1–D10: Campus is the
Branch; role ≠ scope; NULL = whole School forever; one Campus per
assignment; whole-School supersedes; union of Campuses; scope is additive;
School stays the tenant; a selected Campus is never authority; fail closed
until adoption). Baseline `origin/main` = `09e699e0` (ORG.6). ORG.7B was
not started.

**The model:**

    School = tenant (RLS)
    Campus = an authorization scope inside it, never a tenant
    assignment campus_id NULL = the whole School
    a selected or current Campus = never authority

### 33.1 Storage (migration `2026_12_21_090000`)

- **Column.** `membership_role_assignments.campus_id` is nullable, with a
  composite foreign key `(campus_id, school_id) → campuses(id, school_id)`
  ON DELETE RESTRICT, so grant history outlives any Campus.
- **No reinterpretation.** No existing row changes: every pre-ORG.7A grant
  keeps NULL and its meaning.
- **One active grant per (membership, role, scope).** Two partial unique
  indexes enforce it:
  - `membership_role_assignments_one_active` covers the whole-School scope
    (`campus_id IS NULL`);
  - `membership_role_assignments_one_active_campus` covers each Campus.

  History stays. Whole School plus Campus A plus Campus B is permitted.
- **Triggers:**
  - the scope trigger admits a Campus only on a `school`-scope role that
    carries no access administration (`school.members.manage`,
    `school.roles.manage`, `school.roles.grant.*`), and only an ACTIVE
    Campus of the same School for a new grant, read FOR SHARE so a
    concurrent inactivation serializes;
  - these Campus checks run when a grant is created only, so a revocation
    is never refused;
  - the history trigger makes the Campus immutable;
  - the grantor trigger counts only the issuer's whole-School grants.
- **Rollback.** `down()` restores the three functions and the single index
  verbatim. It refuses while any Campus-scoped grant row exists, revoked history
  included, so it is effectively one-way once Campus grants have been used: a temporary
  CHECK is validated against every row regardless of RLS, so no narrow
  grant is ever silently widened.

### 33.2 Resolution

- **Whole-School paths read whole-School grants only.**
  `CapabilityResolver::schoolCapabilities()`, `can()`, the `capability`
  Gate, `capability:` middleware and `RoleGrantAuthority::held()` read
  only `campus_id IS NULL` grants. A Campus grant never satisfies a
  whole-School check, in any domain, adopted or not.
- **Campus grants have their own reader.**
  `CapabilityResolver::campusCapabilities()` maps each ACTIVE Campus to
  the ADOPTED capabilities of the actor's active Campus grants, under the
  same gates (enabled User, active membership, module entitlement).
  - The grant map is cached with, and forgotten by, the School capability
    cache.
  - Campus status is re-read on every call.
- **`CampusScopeResolver::campusScope(actor, capability, School)`** returns a
  `CampusScope`:
  - the whole School, when the capability is held whole-School;
  - a set of active Campuses, when it is adopted and held through Campus
    grants;
  - none, otherwise.

  `CampusScope::restrict()` applies the scope in SQL (`campus_id IN (…)`;
  NULL excluded); `covers()` checks one record. Nothing else constructs a
  scope.
- **Entry versus record authority.** The `campus-capability` Gate,
  `capability:x,campus` middleware and the `AuthorizesCampusScope` trait
  are ENTRY only ("held somewhere"). The action must then apply the scope
  through its domain access class.
- **Navigation.** `NavigationBuilder` unions in the adopted capabilities
  held in any active Campus: visibility only.

### 33.3 Adoption (`CampusScopeCatalogue`, code-owned)

| Domain | Adopted | Records | School-wide (NULL Campus) | Inactive Campus | Shared lookups |
|---|---|---|---|---|---|
| Visitor | `visitor.visits.view`, `.manage` | `visitor_visits.campus_id` NOT NULL | none exist | grant confers nothing; whole-School holders keep all | check-in Visitor search (active, id/name/phone) and host Employee search (active, names); the directory `visitor.directory.*` stays School-only |
| Inventory | `inventory.stock.view`, `.manage` | via the Location's Campus | School-wide stores are whole-School only | grant confers nothing | active Item catalogue lookup; `inventory.directory.*` stays School-only; a movement is shown only when every Location it touches is in scope; a transfer needs both ends |

- **Access classes.** `VisitorVisitAccess` and `InventoryStockAccess` are
  the only places these records are queried or targeted.
  - Lists are filtered in SQL.
  - A record outside the scope is 404, the same concealment as another
    School's record.
  - A request-supplied Campus or Location outside the scope is 404 and
    nothing is written.
  - Whole-School behaviour is unchanged.
- **Fixed in passing.** The stock Location search's ungrouped `orWhere`
  is now grouped inside the scoped query.
- **Deferred:**
  - **Hostel, Transport and Library.** Each assigns or lends to Students
    through a whole-School Student search. A Campus-safe Student lookup
    needs the Student-Campus semantics reserved for the SIS wave (§11.7),
    so they wait until then (or for an owner decision on a narrow
    lookup).
  - **Canteen, HR, Fees, Admissions, Attendance, Exams, LMS and SIS** are
    later waves by instruction.
- **No async adoption.** No adopted domain has background work, so there
  is no job context change.

### 33.4 Granting

- **Where.** Settings → Staff accounts grants a role for **Entire School**
  or **one active Campus**.
  - A role is Campus-scopable when it is a `school` role with no access
    administration and at least one adopted capability
    (`StaffRoleCatalog::campusScopable()`). Otherwise the scoped grant
    would confer nothing, which is refused like an empty role.
  - `school_admin` is never scopable.
  - `principal` is scopable: it holds only view access keys, which a
    Campus grant does not confer.
- **Who.** Unchanged: whole-School `school.roles.manage` plus coverage
  (`RoleGrantAuthority`), fresh MFA, the School access lock. The Campus is
  read FOR SHARE inside the transaction.
- **Refusals:** `role_not_campus_scopable`, `campus_unavailable` (another
  School's Campus, an unknown or inactive one), `role_already_granted` for
  the same scope.
- **Revoke** names the scope (no `campus_id` = the entire-School grant).
- **Audit.** `school.membership.role_assigned` / `.role_revoked` carry
  `campusId` (identifier only) for a scoped grant.
- **Not scopable:** invitations and reactivation stay whole-School.
- **The page.** It lists each grant separately with its scope and shows,
  for a Campus scope, which product areas apply. Other permissions of the
  role stay unavailable from that grant until their area adopts Campus
  scope.

### 33.5 Coverage (C6)

- **New class C6 (Campus-aware).** It is declared through `Coverage::campus()`
  (key, access class, method) or `campusLookup()` (key, reason).
- **Evidence.** CoverageEvidence proves three things:
  - the key is adopted;
  - the action unconditionally calls `authorizeCampusScope()` with it;
  - the action calls the declared access method.
- **Floor routes.** A route with a `capability:x,campus` floor fails CI
  without a C6 entry, and the guard self-tests both cases.
- **Architecture.** `CampusScopeAdoptionArchitectureTest` pins three rules:
  - adopted keys are real non-whole-School capabilities of declared
    domains with complete policies;
  - an adopted key is never checked with the whole-School Gate or floor;
  - the adopted controllers never query their models directly, build a
    CampusScope or read a context Campus.

### 33.6 Unchanged

- Platform roles and personas: no Campus column; platform authority stays
  separate.
- Guardian grants are never scopable (trigger), and `GuardianStudentScope`
  is untouched.
- Teacher ownership is unchanged.
- Module entitlements stay per School and are applied before Campus scope.
- SIS is unchanged, and LC-5–LC-10 remain paused.
- Group authority is unchanged.

## 34. ORG.7B — Campus second adoption wave (implemented, 2026-10-18)

Owner authorization: ORG.7B only. Baseline `origin/main` = `fe6ab5a7`
(ORG.7A). The ORG.7A invariants (§33) are unchanged; no ORG.7C or ORG.7D
work was started.

### 34.1 Visitor hardening and owner-approved lookup contracts

- **Check-in conflict concealment.** A Campus-scoped actor's check-in
  conflict is now one generic refusal (`VISITOR_CHECK_IN_UNAVAILABLE`,
  409, "This Visitor cannot be checked in right now."). It applies:
  - whether the other active Visit is in their scope or not, so the
    response itself is identical in both cases;
  - to a concurrent check-in too.

  The response never names another Campus, Visit or state. Whole-School
  actors keep the specific messages.
- **Accepted residual (owner to confirm).** A Visitor has at most one
  active Visit in the whole School (unchanged rule). So a refused check-in
  still tells the actor that the Visitor is busy somewhere. Combined with
  their own empty in-scope Visit list, they can infer "somewhere outside
  my Campuses", though never where, which Visit or its state. Closing it
  would need a per-Campus active-Visit rule, which was not chosen.
- **Pre-existing, recorded.** `gate_pass_number` is unique School-wide,
  so a Campus actor can learn that a gate-pass number has already been
  used somewhere. `VisitorVisitAccess::concealCheckInConflict()`
  is used by both controllers, and the test is
  `VisitorCampusScopeTest::a_campus_scoped_check_in_conflict_never_discloses_another_campus`.
- **Visitor directory lookup (approved).** A School-wide shared reference
  for check-in only: active Visitors with id, name and phone. Any further
  field needs a privacy review. It is never Campus operational authority;
  the directory capabilities stay School-only.
- **Host lookup (approved, temporary).** Active Employees, id and display
  name only. It confers no HR authority, and is re-evaluated when HR
  adopts Campus scope. Both lookups' fields are pinned by
  `the_shared_check_in_lookups_stay_minimal`.
- **Rollback contract (confirmed, unchanged).** Before any Campus-scoped
  grant exists, `2026_12_21_090000` rolls back exactly (pristine proof,
  ORG.7A). After any current or historical Campus grant exists, rollback
  refuses rather than widen authority (post-use proof, ORG.7A, on DDEV).
  Future Campus migrations follow the same rule. ORG.7B has no migration.

### 34.2 Domain audits (mini-contracts)

| Candidate | Campus in the data | Decision | Why (the open decision) |
|---|---|---|---|
| **HR** | `employee_assignments.campus_id` (optional; `is_primary`, dated); `hr_departments.campus_id` (optional); no Campus on `employees` | **Deferred** | An Employee's Campus is derived and may be multi-valued or empty. Owner decisions needed: primary vs any current assignment; a newly created Employee has no assignment, so a Campus actor could not see whom they created; whether `hr.employees.sensitive.*`, personal, document and note capabilities are ever Campus-adoptable; ended employment and history. Departments are School HR configuration (whole-School). Self-protection and grant rights stay as they are. |
| **Admissions** | `admission_applications.campus_id` NOT NULL; `applicants` have no Campus | **Deferred** | `admissions.view` / `.manage` each cover applicants (people with no Campus until an application exists), applications, the Guardian search (School-wide Guardian records) and conversion (`AdmissionConversionService` creates Student, Guardian, relationship and enrollment: SIS, ORG.7D). Needed: an applicant visibility rule, and whether conversion and Guardian search stay whole-School-only operations of an adopted key. |
| **Communications** | `communication_announcements` and `communication_threads` `campus_id` (optional) | **Deferred** | The tag is informational by contract: audiences ignore it, and no reliable Campus↔membership relationship exists (`SchoolWideAudienceResolver`). Treating it as authority would reinterpret history. |
| **Fees** | `fee_structures.campus_id` (optional override); `fee_assessment_run_items.campus_id` (snapshot); none on `charges` | **Deferred** | A structure's override applies to candidates drawn from Student enrollments, and the School default (no Campus) spans every Campus without an override. A charge's Campus is therefore only derivable through SIS. The structure capabilities also govern School-wide fee heads. |
| **Payments** | none | **Deferred** | Campus is only a derived filter via the Student (SIS). A request filter is never authority. The ledger stays whole-School. |
| **Canteen** | `canteen_outlets.campus_id` (optional) | **Deferred** | Orders target Students and post charges through Fees (`ChargeService`), which stays whole-School. Adopting would need Student lookup and Fees Campus semantics. |

- **No adoptions this wave.** No capability was added to
  `CampusScopeCatalogue` in ORG.7B. Adopted capabilities remain
  `visitor.visits.*` and `inventory.stock.*`.
- **No hidden SIS.** No generic Campus Student search was created.
  Hostel, Transport and Library stay deferred (§33.3).
- **When later waves adopt.** Existing Campus-scoped grants automatically
  become useful for each newly adopted capability family. That is
  expected, and grants are never rewritten.

### 34.3 Campus-status cost (evaluated, not changed)

- **The cost.** A Campus-scoped request makes a constant number of small
  Campus-status reads (§33: Visitor 22 queries against 10 for a
  whole-School actor).
- **The fix considered.** A per-request memo would need a scoped container
  binding whose reset must be proven in queue workers and in multi-request
  tests. That reset is what keeps an inactivation effective in the next
  request.
- **Decision.** The status stays re-read on every call. The cost is
  bounded and not per row, and correctness is preferred to the saving.
  The 60-second authorization cache is not extended to Campus status.

## 35. ORG.7C — Academic & SIS Campus dependency audit (read-only, 2026-10-18)

Owner authorization: a read-only contract audit only; no implementation.
Baseline `origin/main` = `39ba0bab` (ORG.7B). Evidence: live schema
(`\d`, `pg_proc` on the test database), services, triggers, tests and
`route:list`. No production code changed.

**Owner decision recorded.** The Visitor residual (§34.1: the School-wide
one-active-Visit rule reveals "busy somewhere") is accepted as LOW.
`VISITOR_CHECK_IN_UNAVAILABLE` stays the external behaviour. The
uniqueness rule and School-wide gate-pass uniqueness are unchanged.

### 35.1 Student and enrollment reality

- **`students`.** No Campus. Status `active`/`inactive` only, with no CHECK
  and no alumni state. A Student can exist with no enrollment
  (`StudentService::create`); Admissions conversion creates Student and
  enrollment atomically.
- **`student_enrollments`.** `campus_id` is NOT NULL and Campus-FK'd, but
  it is **not** bound to the Section: there are separate `(campus_id,
  school_id)` and `(section_id, school_id)` keys, and no FK to
  `sections_context_unique`. It is set from the Section in code only.
  `school_os_app` can UPDATE every placement column; the retention guard
  freezes id, school, student and `starts_on` only. Section Campus is
  immutable in code only.
- **At most one `active` enrollment per (Student, academic year)**
  (`student_enrollments_one_active_per_student_year`), deliberately not
  global. Rollover leaves the source row active and creates the target
  one, and closing a year never ends its placements, so **two active
  placements in two years, possibly in two Campuses, are normal.**
- **No status CHECK, no date-overlap exclusion.** Student status and
  enrollment status are independent.
- **"Current" has five or more resolvers that disagree:**
  - `StudentEnrollmentReadService::currentFor` (active row in the School's
    active year) is the canonical one;
  - `campusAttribution` (charge-year attribution, LC-4);
  - `StudentEnrollmentFeeTargetReadService::currentForYear` (open and not
    cancelled);
  - the roster service (as of a date, any status);
  - ad-hoc readers (Communication Hub: any active row, no year;
    OutstandingFees: a direct model read).
- **Transfer.** `transferPlacement()` works within a year and a grade, may
  change Campus, and ends the source row as `transferred` and creates a
  new one. There is no separate Campus transfer. In-place `campus_id`
  edits are not done by code, but are not blocked by the database.
- **Promotion** (rollover) creates target-year rows. The target Campus is
  the target Section's.
- **Leavers.** `students.status = 'inactive'`. Retention exit needs
  inactive plus no open placement plus a terminal last placement.
- **Guardian scope** (`GuardianStudentScope`): legal-guardian relationship,
  active Student and Guardian. No Campus or enrollment condition.
  (Finding: its docblock claims withdrawn/transferred Students are
  excluded; they are not, while `students.status` is active.)

### 35.2 Proposed canonical Student Campus contract (not implemented)

1. **Student identity is School-wide, never Campus-owned.** The
   enrollment is the Campus-bound relationship.
2. **Operational Campus** = the Campus of the Student's `active`
   enrollment in the School's **active** academic year: one value or none,
   from one Students-owned resolver used by every consumer. Rows in draft
   or closed years confer no current Campus authority. Next-year intake
   (draft-year rows only) is whole-School until the year activates, unless
   the owner adds an explicit "intake" rule.
3. **History** (records already written: attendance, marks, charges)
   keeps the Campus stored on the record or its parent, never the
   Student's current Campus. Charge-year attribution may serve finance
   reads only, and an ambiguous result means whole-School only.
4. **No current enrollment** (new, leaver, withdrawn, between years):
   whole-School only. Domains that need historical-purpose access (Fees
   and Payments dues, retention, report history) use record-stored Campus
   or attribution, never a widened Student search.
5. **Transfer stays a named operation.** It is never a `campus_id`
   update, and it requires scope over both the source and destination
   Campus, or whole-School (§11.7).
6. **Database hardening before adoption:**
   - a composite FK `student_enrollments(section_id, school_id,
     academic_year_id, campus_id, grade_level_id) →
     sections_context_unique`;
   - freezing placement columns after insert;
   - a Section Campus immutability trigger;
   - consolidating the current-enrollment resolvers.
7. **Purpose lookups, not `students.view` in Campus.** Every existing
   picker (Library, Hostel, Transport, Canteen web and API, Finance ×5,
   Communications ×2) shows only name and number and posts the id. A
   future lookup returns exactly `id`, `displayName` and `studentNumber`;
   eligibility is a server-side filter (current Campus in scope, or
   charge-year attribution for finance, leavers included). No Campus,
   Section, date of birth or Guardian field is returned; anything more
   needs a privacy review.
8. **Guardian scope is unchanged.** It stays relationship-based, never
   Campus-scoped.

### 35.3 HR

- **Schema.** `employees` has no Campus.
  - `employee_assignments.campus_id` is optional, with `is_primary` and
    dates. The one-open-primary index covers only `ends_on IS NULL`.
  - There is no overlap constraint, and assignments are reached through
    `employment_records`.
- **"Current" has five definitions**, two of them on UTC `Carbon::today()`
  rather than the School-local date. Ending an employment clips only
  assignments with no end date.
- **Proposed contract** (validates the owner model). An Employee's
  operational Campuses are the union of the Campuses of CURRENT
  assignments, where current means all of:
  - an active Employee;
  - employment `active`/`notice_period` (pre_joining is an owner decision)
    covering the School-local date;
  - an assignment covering that date;
  - an active Campus.

  The rule is deterministic in SQL (proven with `EXPLAIN`), lives in one
  HR-owned resolver on the School timezone, and avoids the
  primary-uniqueness hole.
  - **No current assignment** means whole-School only; ended assignments
    confer nothing; history is used only for retention and reads.
  - **No visibility capture.** A Campus actor may act only on an Employee
    already in scope. Onboarding is an atomic "employee + employment +
    assignment in my Campus" path (the import shape). Moving an Employee
    needs both Campuses.
- **Capabilities:**
  - **A (Campus-operational candidates):** `hr.employees.view`;
    `hr.employees.manage`, except link/unlink account, archive/restore and
    import, which stay whole-School; `hr.employees.assignments.view`;
    `hr.employees.assignments.manage` (the highest risk: it defines the
    scope itself and cascades into teaching ownership).
  - **B (whole-School configuration):** `hr.departments.*`,
    `hr.positions.*`, `hr.categories.*`, `hr.leave.configure`.
  - **C (sensitive; stay whole-School in the first release):**
    `hr.employees.personal.*`, `.qualifications.*`, `.documents.*`,
    `.notes.*`, `.sensitive.*`.
  - **D (self-service):** `hr.leave.self`, `hr.staff_attendance.self`.
  - **Relationship-scoped:** `hr.leave.approve`.
  - **Leave and StaffAttendance** (`hr.leave.view/manage`,
    `hr.staff_attendance.view/manage`) stay School-only per §11.7 unless
    amended.
  - **E:** there is no dedicated historical key.
- **Blockers:**
  - the profile entry gate is `hr.employees.personal.view` (class C), so
    an operational Campus HR role cannot open a profile without a gate
    change;
  - 32 of 37 HR controllers and the services query or authorize directly,
    so access classes are needed;
  - the manager-candidate list is School-wide;
  - the architecture guard has no form for "whole-School-only operation of
    an adopted key".
- **Unchanged:** `HrSelfAdministrationGuard`, which runs alongside scope.
- **Async:** no jobs.

### 35.4 Admissions

- **Schema.** `admission_applications.campus_id` is NOT NULL. The
  one-open index includes Campus, so it is not an existence oracle.
  Applicants have no Campus and no update path. The fee head is School
  configuration, and fee selections are written at conversion.
- **Proposed contract** (validates the owner model):
  - **Campus-aware pre-conversion work:** list, open and transition
    applications in scope; out of scope is 404.
  - **Applicants:** visible only through an in-scope application, and that
    application list is scope-filtered.
  - **Creation:** a new applicant comes only from an atomic "applicant +
    first application in my Campus" path. An existing applicant cannot be
    claimed by naming its id (capture risk).
  - **Whole-School only:** the applicant directory, standalone applicant
    create, conversion, both Guardian searches (name search; the contact
    lookup, which is a contact-to-identity oracle), fee-head writes, the
    post-conversion fee selection, and converted Student/enrollment
    references.
  - **Campus is immutable** after create (trigger at adoption); a change
    of Campus is withdraw-and-reapply.
- **Conversion is the SIS boundary.** `AdmissionConversionService`
  composes Student, Guardian, contact, relationship and enrollment
  services that do no authorization of their own, under
  `admissions.manage`. It stays whole-School until the Student contract
  (§35.2) is implemented.
- **The split needs no new capability,** but it does need a guard and ADR
  extension: a declared "whole-School-only operation of an adopted key"
  coverage form (today `CampusScopeAdoptionArchitectureTest` forbids any
  whole-School check of an adopted key).

### 35.5 Academic domains

| Domain | Campus source | Pre-SIS? | Notes |
|---|---|---|---|
| Academic Structure | config (years, terms, grades, departments, subjects, Campus master): none; sections, rooms, offerings, elective groups, teaching assignments: direct, NOT NULL, composite FKs | configuration **never** Campus-mutable | `academics.structure.*` and `academics.subjects.*` bundle School config, Campus entities and (subjects) SIS subject-enrollment writes, so they stay whole-School unless split (a catalogue release, rule 93) |
| Timetable | entries: from the offering, NOT NULL, composite FKs; periods: one School-wide bell schedule | **yes**, after fixes | **Defect R1:** `timetable_entries_room_fk` is `(room_id, school_id)` only, and no code checks the room's Campus, so a cross-Campus room booking is possible. Also needs a minimal Employee lookup (teacher picker) and conflict concealment (teacher/room slot conflicts reveal other Campuses' bookings) |
| Attendance (Tier 1) | session from the locked timetable entry; records bound by session-context and enrollment-context 6-tuple FKs | **yes** | Campus authority comes from the timetable alone; the roster is Section-bound. Needs access classes and `campus_id` indexes on sessions and records |
| Attendance (teacher) | ownership already implies the Campus | with ownership | low value; Campus AND ownership, never instead of it |
| Examinations | `examinations` and grade scales: none (year- and School-wide); papers: direct, NOT NULL, FK-consistent | papers **yes**; marks **after SIS** (and after RES-L1/L2) | production marks gates unchanged and first; marks correction approval scope is an owner decision |
| LMS | offering and section audiences, FK-consistent; no Student reads | admin **yes**; teacher with ownership | LMS document endpoints must adopt together |
| Curriculum Delivery / Syllabus | deliveries direct and FK-consistent; units via the offering | **yes** | syllabus as School configuration is an owner choice; inline controller writes need an access class |

### 35.6 Deferred-domain Campus source map

| Domain | Source category | Unblocked by |
|---|---|---|
| Library | copies A (nullable); loans B by copy but the borrower is D, so F; fines F | Student contract + purpose lookup + copy-vs-borrower rule |
| Hostel | hostels A; rooms and beds B; residency B but the Student is D, so F | Student contract + resident-Campus rule |
| Transport | routes and vehicles A (nullable); route assignments F (route vs vehicle Campus unchecked; driver C); student assignments F | Student contract + shared-route rule + route/vehicle consistency |
| Canteen | outlets A (nullable; a NULL outlet can use a Campus location); orders F (Student D + Fees charge) | Student contract + Fees decision |
| Fees | structures A (nullable default); run items E snapshot; assessments D snapshot; charges, concessions and adjustments F | Student contract + charge-year attribution (finance reads only) |
| Payments | F (no Student or Campus on `payments`) | as Fees; ledger whole-School |
| Admissions | applications A; applicants none | guard extension + capture-safe create (pre-conversion); conversion after Student contract |
| HR | employee C (multi-valued) | owner decisions in §35.3 + guard extension |

### 35.7 Findings (recorded, NOT fixed — each needs owner approval)

1. **R1 (integrity):** cross-Campus room on a timetable entry (no FK or
   code check).
2. **R2 (integrity):** enrollment `campus_id` is not DB-bound to its
   Section, placement columns are not frozen, and Section Campus is
   mutable at the database level. §11.7 has been corrected.
3. **R3 (authorization inconsistency):** the Canteen API Student search
   is gated on `canteen.orders.view`; the web search needs
   `canteen.orders.manage`.
4. **R4:** HR "current" uses UTC dates in the directory and profile, and
   employment end leaves dated assignments open.
5. **R5:** missing active-Campus checks on Section, Offering, Timetable,
   admission-application and assignment writes.
6. **R6:** existing Campus grants widen on adoption. A `principal@Campus`
   grant gains Exams/LMS/Curriculum keys, and the marks keys, the moment
   those domains are adopted. Each adoption release must state this, and
   the marks keys need an explicit owner decision.
7. **R7:** School-wide uniqueness creates "busy elsewhere" residuals on
   adoption: hostel residency, transport assignment, library loan,
   teacher and room slots, codes. Each needs the §34.1 concealment
   decision.
8. **R8 (pre-existing hygiene):** unescaped LIKE wildcards in applicant
   and Guardian searches; the `GuardianStudentScope` docblock does not
   match its behaviour; no DB CHECK on several status columns.

### 35.8 Recommended sequence (decision D)

The evidence contradicts "SIS absolutely last" **and** "SIS first". Five
academic domains already carry structurally bound Campus and need no
Student semantics, while every operational deferral needs the Student
contract. Proposed order:

1. **ORG.7D-0 — prerequisites (implementation, owner-approved):**
   - fix R1, R2 (DB hardening), R3 and R5;
   - add the guard/ADR form for "whole-School-only operation of an adopted
     key";
   - decide the minimal Employee lookup (teacher, host and manager
     pickers) and record each concealment decision.
2. **ORG.7D-1 — pre-SIS academic wave:**
   - `timetable.schedule.*`;
   - `attendance.view/manage`;
   - `curriculum.delivery.*` (and `syllabus.*` if owner-approved);
   - `lms.content/assignments.*` admin;
   - `examinations.papers.*`.

   Each with access classes and C6 entries. Marks and teacher keys come
   later.
3. **ORG.7D-2 — narrow Student Campus foundation:** the canonical
   resolver (§35.2) plus purpose lookups. It unblocks Library, Hostel,
   Transport and Canteen, Admissions pre-conversion (with capture-safe
   create), and the marks keys after RES gates.
4. **ORG.7D-3 — HR**, after the owner decisions in §35.3.
5. **ORG.7D-4 — full SIS adoption** (profile and enrollment operations,
   dual-scope transfer, conversion), then Fees and Payments on
   charge-year attribution. The ledger stays whole-School.

## 36. ORG.7D-0 — Campus authority prerequisite hardening (as built, 2026-10-18)

Owner authorization: R1, R2, R3 and R5 of §35.7, the whole-School-only
operation form, and the decisions below. Nothing else. Baseline
`origin/main` = `62985528`.

**Owner decisions (frozen).**

- **D0-1 Student identity:** School-wide; `students` gains no Campus. The
  operational Campus is the active enrollment in the School's active
  year. Historical rows grant no current authority.
- **D0-2 No current enrollment:** no operational Campus. Campus-scoped
  staff gain nothing; whole-School authority follows existing domain
  rules.
- **D0-3 Transfer:** a named operation, never a `campus_id` edit. It needs
  whole-School authority, or authority over both the source and
  destination Campus. Not built.
- **D0-4 Student purpose lookups:** `id`, `displayName`, `studentNumber`,
  with eligibility filtered server-side. Never date of birth, Guardian,
  Section, contact or profile fields.
- **D0-5 Employee Campus:** the union of CURRENT assignments, where
  current means all of:
  - an active Employee;
  - employment active or in its notice period on the School-local date;
  - an assignment covering that date;
  - an active Campus.

  No current assignment means whole-School only; an ended assignment
  confers nothing. Not built.
- **D0-6 Minimal Employee lookup:** `id`, `displayName`. `employeeNumber`
  only where a workflow proves the need. Never email, phone, salary,
  attendance, sensitive, document or note fields. The Visitor host lookup
  stays `id` + `displayName`.
- **D0-7 Sensitive HR stays whole-School in the first HR Campus release:**
  personal and sensitive records, sensitive qualifications, documents,
  notes, payroll-sensitive records, account link/unlink, archive/restore,
  imports, and employment/security administration.
- **D0-8 Admissions:**
  - the application is Campus-bound; applicant identity is School-wide;
  - a Campus actor sees an applicant only through an authorized
    application;
  - the directory and Guardian search stay whole-School (or
    purpose-limited);
  - conversion stays whole-School until the Student foundation.
- **D0-9 Cross-Campus uniqueness disclosure:** School-wide constraints are
  preserved. A Campus-scoped actor gets one generic conflict/unavailable
  refusal that never names another Campus, record, location or person.
  Whole-School actors keep the messages already contracted. Uniqueness is
  never weakened to per-Campus for concealment.
- **D0-10 Future adoption:** an existing Campus grant may become newly
  effective when a family is adopted (intended). Every adoption phase
  reports the new keys, the templates that hold them, and the
  Campus-scoped grants that gain them. High-risk families need owner
  approval. Marks are not authorized for adoption.

**36.1 R1 — Timetable Room Campus.**
- **Precondition.** Zero entries referenced another Campus's Room, in each
  of: DDEV development (150 entries with a Room), DDEV test and isolated
  test.
- **Migration** `2026_12_22_090000`: `rooms_campus_context_unique (id,
  school_id, campus_id)` plus `timetable_entries_room_campus_fk (room_id,
  school_id, campus_id) → rooms (id, school_id, campus_id)`, RESTRICT.
  - MATCH SIMPLE leaves a Room-less entry valid.
  - The School-only `timetable_entries_room_fk` is kept.
  - A referenced Room cannot change Campus.
- **Service.** `TimetableScheduleService` refuses a Room whose Campus is
  not the Offering's (`TIMETABLE_ROOM_CAMPUS_MISMATCH`, 422) and
  translates the FK name.

**36.2 R2 — Enrollment ↔ Section.**
- **Precondition.** Zero rows with a mismatched School, Campus, Year or
  Grade, zero dangling and zero NULL Sections: DDEV development (36
  enrollments), DDEV test (0), isolated test (20).
- **Migration** `2026_12_22_090100`:
  `student_enrollments_section_context_fk (section_id, school_id,
  academic_year_id, campus_id, grade_level_id) → sections_context_unique`,
  RESTRICT. The School-only Section key is kept.
- **Effect.** A raw UPDATE of a placement column, and moving a Section's
  Campus, Year or Grade under its enrollments, are refused.
- **Placement audit.** `createEnrollmentRow()` (through `enroll()` and
  `transferPlacement()`, used by rollover and conversion) is the only
  placement writer. Every other writer changes status, end date or
  rollover bookkeeping only.
- **Freeze trigger.** No placement freeze trigger was added: the FK already
  makes every placement update consistent, and freezing is a SIS decision.
- **Scope.** No Student authorization, search, UI, API, resolver or
  transfer change.

**36.3 R3 — Canteen Student picker.** The API search now needs
`canteen.orders.manage` (route floor and controller), as on the web.
Viewers keep reading orders. The Item search is unchanged
(`canteen.orders.view` on the API, `.manage` on the web): Items are not
personal data, and that asymmetry was not in scope.

**36.4 R5 — Active-Campus writes.**
- **`App\Domain\Campuses\Application\ActiveCampus`** (exists, same
  School, active, share-locked in the caller's transaction) is the one
  validator. It is never authority.
- **Used by:**
  - Section create and Subject Offering create (`campus_id` field error;
    another School's Campus stays a 404);
  - Timetable create, update and activate (`TIMETABLE_CAMPUS_NOT_AVAILABLE`);
  - admission application create (`campus_id` field error);
  - employee assignment create (`HR_ASSIGNMENT_INACTIVE_CAMPUS`; the import
    uses the same path and reports "The referenced Campus is not active").
- **Unchanged:**
  - Section and Offering updates, which never select a Campus;
  - reactivating a Section or Offering on an inactive Campus;
  - Room create (not in the list; recorded);
  - inactive-Campus records, which are kept and stay maintainable
    (Timetable deactivate, assignment end).
- **No trigger:** "active" is mutable state in another row, so the guard
  is the service plus `ActiveCampusWriteTest`'s architecture assertion.

**36.5 Whole-School-only operation of an adopted key.**
- **The declaration:** `CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS`
  (exact route name → capability + reason; empty today). The route carries
  the ordinary `capability:<key>` floor, i.e. the `capability` Gate, which
  a Campus grant never satisfies.
- **The guard:** `CoverageGuard::adoptedWholeSchool()`, run by
  `AuthorizationCoverageGuardTest`, checks every registered route, not only
  School-facing ones (ResolveSchoolContext runs on the whole web and API
  groups). It reads an unknown `capability:` mode as the whole-School Gate,
  exactly as EnsureCapability does. It fails CI for:
  - an undeclared or any-of whole-School floor on an adopted key;
  - a stale, pattern, unadopted or unreasoned declaration;
  - a declaration without its floor, or with a Campus floor or C6 entry.
- **Unchanged:** the controller-level ban on whole-School checks of
  adopted keys (`CampusScopeAdoptionArchitectureTest`). Route floors moved
  from a source scan to the registered-route check.
- **Self-test:** `WholeSchoolOperationOfAdoptedKeyTest` covers the guard
  findings, plus a Campus-only holder refused (403) and a whole-School
  holder admitted on a declared probe.
- **Security review** (ORG.7D-0): no HIGH or MEDIUM findings. Its LOW notes
  on unknown modes, non-School-facing routes and the import message were
  closed above. Reactivation on an inactive Campus stays recorded in §36.7.

**36.6 Adoption impact contract.** `CampusAdoptionImpactTest` pins:
- the system School templates holding adopted keys (front_office,
  principal, school_admin, stores_officer);
- the Campus-scopable ones (front_office, principal, stores_officer);
- the high-risk family list with an empty owner-approved set;
- a ban on marks.

Any adoption fails it until reported. This resolves R6 for now: the
impact is reported and marks are deferred.

**36.7 Out of scope (recorded).**
- R4 (HR dates, open dated assignments);
- R6 beyond the impact contract;
- R7 domain refactors;
- R8 hygiene;
- Room create on an inactive Campus;
- Section and Offering reactivation on an inactive Campus.

No capability, role, route, Campus adoption, SIS, HR or Admissions
authority change. Two rollback-proven migrations. The ORG.7A grant-history
rollback contract is unchanged.

## 37. ORG.7D-1 — Pre-SIS academic Campus adoption (as built, 2026-10-19)

Owner authorization: ORG.7D-1 only (decisions D1-1 to D1-12). Baseline
`origin/main` = `5bd5337f` (ORG.7D-0). ORG.7D-2, Student Campus resolution
and SIS LC-5–LC-10 were not started.

**37.1 Pre-adoption hardening (D1-1, D1-2).** Data validity only, through
`ActiveCampus`, never authority:
- **Room create** refuses an inactive or another School's Campus
  (`campus_id` field error / 404). The Rooms page offers no create form for
  an inactive Campus.
- **Section and Subject Offering reactivation** (`inactive → active`) is
  refused while their Campus is inactive (`status` error). Re-sending
  `active` on a record that is already active is not a reactivation. No
  Campus is activated as a side effect, and nothing is deleted.
- Room reactivation was not in scope and is unchanged (recorded).

**37.2 Adopted (`CampusScopeCatalogue`).** Five domains, existing
capabilities only (no new capability, role, migration or schema change):

| Domain | Adopted keys | Campus source | Access class |
|---|---|---|---|
| `timetable` | `timetable.schedule.view`, `.manage` | the entry's Offering (`campus_id` NOT NULL; Section and Room FK-bound) | `TimetableScheduleAccess` |
| `attendance` | `attendance.view`, `.manage` | new register: the class's entry; written register: its STORED Campus (FK-bound to Section, Offering and every record) | `AttendanceAdministrationAccess` + `CampusAttendanceWriteGuard` |
| `curriculum_delivery` | `curriculum.delivery.view`, `.manage` | the delivery's stored Campus (FK-bound to Section and Offering) | `CurriculumDeliveryAccess` + `CampusDeliveryWriteGuard` |
| `lms` | `lms.content.view/.manage`, `lms.assignments.view/.manage` | the owning Offering (SQL subquery; the Offering never changes) | `LmsAdministrationAccess` |
| `examination_papers` | `examinations.papers.view`, `.manage` | the paper's stored Campus (from its Offering) | `ExaminationPaperAccess` |

- **Every adopted route is C6.** Both web and API: 74 routes (98 C6 in all) with
  `capability:x,campus` API floors and evidence-checked access methods.
- **Lists are filtered in SQL.** Out-of-scope records, Offerings, Sections,
  Rooms, Units and LMS parents are 404, like another School's.
- **Request filters only narrow.** No `campus_id` is accepted from input.
- **Writes are guarded in the service as well as the controller:**
  - Timetable resolves the scope itself, so both the entry's current Campus
    and the target Offering's must be in scope, checked before any
    eligibility check.
  - Attendance and Curriculum Delivery pass a Campus write guard through
    their existing guard hooks.
- **Whole-School behaviour is unchanged.** A whole-School actor gets no
  guard and no predicate.

**37.3 Kept whole-School (D1-3, D1-7, D1-9, §9, §20).**
- The bell schedule (`timetable.periods.*`).
- Academic Structure (`academics.*`), the syllabus catalogue (`syllabus.*`),
  the Examination itself (`examinations.definitions.*`) and grade scales.
- Data Health, the Examination list and every marks/results key.

`CampusAdoptionImpactTest` now bans these prefixes, every `*.teacher` key
and the SIS families explicitly.

**37.4 Shared lookups (declared in `CampusScopeCatalogue::DOMAINS`).**
- **Timetable form, teacher picker:** active Employees, id and name only
  (D0-6).
- **Timetable form, bell schedule:** a new lookup route
  `search/schedule-periods` (web and API) under `timetable.schedule.manage`.
  `search/periods` and the Period catalogue stay on whole-School
  `timetable.periods.view`, as pinned before.
- **Exam papers, parent context:** the paper page reads its parent
  Examination (code, name, window, status) as context.
- **Owner decision needed:** the Examination list stays whole-School, so a
  Campus-only papers holder has no navigation path to the paper page (only
  its URL or the API). Whether the Examination list becomes a shared lookup
  for paper holders is open.

**37.5 Conflicts and history (D1-12, §10, §14).**
- **Timetable slots.** Teacher, Section and Room slots stay unique
  School-wide. A Campus-scoped scheduler's collision is one generic
  `TIMETABLE_SLOT_UNAVAILABLE` 409, identical in and out of scope.
  Whole-School schedulers keep the specific codes.
- **Attendance history.** Authority over a written register is its stored
  Campus, never a current one. A class later moved to another Campus
  leaves its earlier registers with the Campus they were written on.
  "Already submitted" is judged per in-scope class. DDEV development (13
  registers, 78 records) and test data show zero session/entry or
  record/session Campus mismatches.
- **Corrections** follow the register's stored Campus, so a current Campus
  grant never reaches another Campus's history.

**37.6 Teacher and LMS documents (D1-6, §13, §30).**
- **Teacher keys are not adopted:** `attendance.teacher`,
  `curriculum.delivery.teacher`, `lms.*.teacher` and
  `examinations.marks.teacher`.
- **TeachingOwnership is unchanged and still mandatory** for the owned
  surfaces. The `teacher` template stays non-Campus-scopable.
- **A Campus grant of a teacher key confers nothing.** This is pinned.
- **LMS documents.** `LmsParentResourceAuthorization` decides Tier 1 in the
  actor's CampusScope: whole School, or a Campus covering the parent's
  Offering.
  - The teacher path follows unchanged.
  - Tier 1 held only elsewhere answers the same not-found as an unknown
    parent; held nowhere, the Gate refuses (403).

**37.7 Guards.**
- **C2 contracts.** `CoverageGuard::adoptedWholeSchool()` also fails a C2
  Gate contract that names an adopted key (an action checking it
  School-wide through a constant, which no source scan sees). The one
  exception is a declared whole-School hub.
- **Whole-School hubs.**
  - `CampusScopeCatalogue::WHOLE_SCHOOL_HUBS` (new): `data-health` →
    `timetable.schedule.view`.
  - The hub controller may check the declared key with the whole-School
    Gate; nothing else may.
  - `CampusScopeAdoptionArchitectureTest` verifies every declaration.
- **Navigation.** A Campus-held key shows an entry only when every key the
  entry names is adopted, so Data Health never appears from a Campus grant.
- **No `WHOLE_SCHOOL_OPERATIONS` declaration** was needed.

**37.8 Impact (D1-11).**
- **Templates gaining the keys:** `principal` and `school_admin` gain all
  14 adopted keys; only `principal` is Campus-scopable.
- **No high-risk activation:**
  - no marks, Student profile, School settings, staff access, finance or
    sensitive HR key is adopted;
  - `principal`'s marks keys stay whole-School only.
- **Existing Campus-scoped grants that gain authority:** none in DDEV
  development, test or demo data (the demo grants whole-School only), and
  nothing is deployed.

**37.9 Cost and async.**
- **Cost.** One Campus-status read per scope resolution (§34.3, unchanged),
  never per row (pinned). `AuthorizesCampusScope` now resolves the scope
  once instead of twice: the Gate runs only to refuse. Timetable list: 28
  queries whole-School, 36 Campus.
- **Async.** No adopted domain dispatches jobs or outbox work, so there is
  no async authority.

**37.10 Deferred (recorded).**
- Teacher keys (ownership already implies the Campus).
- Exam paper navigation for Campus-only holders (owner decision, §37.4).
- Room reactivation on an inactive Campus.
- Everything needing Student Campus semantics (ORG.7D-2).

**37.11 Security review (ORG.7D-1).** Independent adversarial review of the
candidate: no HIGH finding. Findings fixed before publication:
- **MEDIUM — delivery existence probe.** The Curriculum Delivery
  update/transition API answered an out-of-scope delivery with a different
  not-found message (it carried the stored id) than an unknown one.
  - Both controllers now resolve the delivery through
    `CurriculumDeliveryAccess` first.
  - `CampusDeliveryWriteGuard` refuses without ids.
  - Pinned by an identical-body test.
- **MEDIUM — idempotent replay across a scope change (rule 32).** A
  `capability:x,campus` floor proves the key is held somewhere, so a replay
  of a response written for one Campus was not re-authorized for that
  Campus. This also applied to Visitor check-in since ORG.7A.
  - On a Campus-floored route, `EnsureIdempotent` now binds the actor's
    CURRENT CampusScope into the request fingerprint
    (`RequestFingerprint::compute(..., $authority)`). A changed scope makes
    the same key a 409 `IDEMPOTENCY_KEY_CONFLICT`, and the stored body is
    never served.
  - Every other route's fingerprint is byte-identical (no authority
    component).
- **LOW — stale Timetable entry.** Timetable writes now lock the entry row
  in their transaction and re-check its current Campus, so an entry moved
  to another Campus after the caller read it is never written.
- **LOW — LMS upload order.** The LMS Document upload authorizes the parent
  before checking the classification tier, so an unreachable parent answers
  like an unknown one whatever the tier. Over HTTP the tier is fixed
  (`internal`), so this is service-level defence only.

Accepted residuals (LOW, declared):
- **Teacher slots.** A Campus-scoped scheduler can infer that a teacher is
  busy somewhere (the Visitor precedent, §34.1). Section and Room slots
  never cross a Campus.
- **Moved classes.** A class moved onto an in-scope Campus shows its
  earlier register as already taken.

## 38. ORG.7D-2 — Student Campus foundation (as built, 2026-10-19)

Owner authorization: ORG.7D-2 only (decisions D2-1 to D2-10). Baseline
`origin/main` = `0188caec` (ORG.7D-1). No consuming domain was
Campus-adopted, no SIS feature changed, and LC-5–LC-10 remain paused.

    Student identity       = School-wide (no Campus on `students`)
    operational Campus     = the current active-year placement's active Campus
    historical Campus      != current Campus
    Campus Student lookup  != students.view

**38.1 The canonical rule.**
- **Current placement.** The single SQL definition is
  `StudentEnrollmentReadService::constrainToCurrentPlacement()`: the
  `active` Enrollment in the School's `active` AcademicYear.
  - `currentFor()` and `currentForMany()` use it, and so does every new
    reader.
  - Never the newest row, the latest or last-active year, an Enrollment's
    own year, or a request or selected Campus.
- **Operational Campus.** `StudentOperationalCampusResolver` (Students)
  returns a `StudentOperationalCampus` value that keeps two facts apart:
  - `hasCurrentPlacement()`: the current placement exists;
  - `operationalCampusId()`: that placement's Campus, only while BOTH the
    Student and the Campus are `active`.
- **Data, never authority.** One query for many Students; fresh on every
  call (no cache); inside the target School's `TenantContext`.
- **Integrity it relies on (database-enforced, pinned):**
  - `academic_years_one_active_per_school`: at most one active year;
  - `student_enrollments_one_active_per_student_year`: at most one current
    placement;
  - `student_enrollments_section_context_fk` (ORG.7D-0): the placement's
    Campus is its Section's.
  - Zero violations exist in DDEV development and test data.
- **Unchanged.** The status columns still have no CHECK (R8 recorded); the
  resolver treats only the exact value `active` as active.

**38.2 Behaviour pinned (`StudentOperationalCampusTest`).**
- **Active year decides:**
  - a prior-year placement never decides the current Campus;
  - a draft/future-year placement never does either;
  - "latest year" is not current.
- **Year switch.** Switching the School's active year moves the current
  Campus with no Enrollment rewritten.
- **No active year:** no current Campus and no lookup candidates.
- **Leaver:** the placement is kept as context, but there is no
  operational Campus.
- **Inactive Campus:** the placement is kept, the Campus is not
  operational, and it is eligible again on reactivation.
- **Finance attribution.** `campusAttribution()` (charge-year attribution)
  is separate and still answers for a leaver.

**38.3 Readers.** Inventory and outcome:
- **Current placement (A):**
  - `currentFor()` and `currentForMany()` stay canonical.
  - **Normalized:**
    - the Communication Hub Student picker label took any active row of
      any year, so after a rollover it could show last year's class; it now
      uses `currentForMany()`;
    - Outstanding fees' "in this class now" filter queried Enrollments in a
      Finance controller; it now uses `currentStudentIdsIn()`, with the same
      rule;
    - the Data Health "active Student without current placement" check
      re-implemented the rule; it now uses the predicate.
- **Year-specific (B), retained:**
  - Fees target `currentForYear()` and run candidates;
  - Elective candidate and transfer readers;
  - `SubjectOfferingRosterReadService`;
  - the Communications Grade/Section audience resolvers (a named cohort);
  - rollover and closed-year services;
  - Data Health closed-year and inactive-Section checks.
- **As-of date (C), retained:** `SubjectOfferingEligibilityReadService`.
- **Finance attribution (D), retained:** `campusAttribution()`.
- **Roster (E), retained:** `StudentEnrollmentRosterReadService::membersAsOf()`.
- **Year-scoped history, retained:** the Guardian attendance view (this
  year's records, joined by the record's own Enrollment).

**38.4 Guard (`StudentCampusFoundationArchitectureTest`).**
- **One definition.** A class that resolves the active AcademicYear and
  reads Enrollments must use `constrainToCurrentPlacement()`, or be a
  declared year-scoped history reader.
- **Outside Students,** only ten declared readers touch Enrollment
  storage, each with a non-"current" reason. Comments are stripped before
  matching.
- **Identity.** `students` has no `campus_id`, `current_campus_id` or
  `operational_campus_id`.
- **No exposure.** No route or controller references the lookup or the
  resolver.
- **DTO.** The lookup DTO is exactly `id`, `displayName`, `studentNumber`.

**38.5 Purpose-limited lookup.** `StudentPurposeLookup::search(School,
CampusScope, term, limit)` is application infrastructure only.
- **Caller flow:** domain capability → `CampusScopeResolver` for that
  capability → the resolved `CampusScope` → lookup. Only the resolver builds
  a `CampusScope`, so a request Campus id cannot select candidates.
- **Candidates.** Current operational Students only, filtered in SQL in one
  bounded query (at most 50) with each Student at most once:
  - whole School: any operational Campus;
  - Campus set: operational Campus in the set;
  - none, or no active year: nothing.
- **Search:** name words and Student number only, through
  `StudentSearch::anyField()` (LIKE escaped).
- **Result:** `StudentLookupCandidate` (`id`, `displayName`,
  `studentNumber`), deterministically ordered.
- **Exposure:** no HTTP route, and no generic Campus Student directory or
  UI.
- **Query plan:** it uses `student_enrollments_one_active_per_student_year`
  and the Campus index. No new index and no migration.

**38.6 Unchanged.**
- `students.*` and `enrollments.*` capabilities and role templates (a
  principal granted for one Campus still gets no Student or Enrollment
  access; pinned).
- `TeachingOwnership` and `GuardianStudentScope`.
- Fees and Payments semantics, charge-year attribution and every
  historical record's stored Campus.
- **Jobs:** none implement current Student Campus. A future job resolves at
  execution time and never carries a resolved Campus as authority.

**38.7 Owner decisions recorded.**
- **Exam papers.** A Campus-scoped `examinations.papers.view` holder is
  NOT shown the whole-School Examination list. The future UX is a
  dedicated Campus-safe Exam Papers hub filtered by paper Campus authority
  (academic UX follow-up). The gap stays open.
- **Accepted LOW residuals:**
  - School-wide teacher/room slot uniqueness can reveal a generic
    "unavailable";
  - historical Attendance uniqueness can reveal that a register exists
    after a class moved Campuses.
  - Campus-scoped responses stay generic and never disclose another
    Campus, record id, location or out-of-scope person.

**38.8 Security review (ORG.7D-2).** Independent adversarial review: no
HIGH finding. Findings fixed before publication:
- **MEDIUM — latent hydration hazard.** `restrictToOperationalCampus()`
  added joins without fixing the select, so a future `select *` caller
  could have hydrated Students with Campus columns. It is now an EXISTS
  predicate (no join); a `select *` test pins that real Student rows come
  back.
- **LOW–MEDIUM — guard false negatives.** The patterns now also catch:
  - `StudentEnrollment::where/find/...`;
  - `->enrollments` property access;
  - string relation loads (`with/load/whereHas('enrollments...')`);
  - `AcademicYear::where('status','active')`.
  - Lookup/resolver use is now refused anywhere outside Students, not only
    in HTTP layers.
  - A self-test proves the patterns match the pre-ORG.7D-2 ad-hoc readers.
- **LOW — docblocks.** They now name the real guard
  (`StudentCampusFoundationArchitectureTest`) and the
  `CampusScopeAdoptionArchitectureTest` rule that app code never builds a
  `CampusScope` itself.

Recorded, not changed:
- `currentForMany()` and `currentStudentIdsIn()` rely on the caller's
  ambient TenantContext like the rest of the read service; a mismatch
  fails closed.
- The Communication Hub search's unescaped LIKE stays R8.
- `CampusScope`'s public constructors predate ORG.7D-2 and are guarded
  only by that architecture rule.

## 39. ORG.7D-3 — Student-dependent operational Campus adoption (as built, 2026-10-19)

Owner authorization: ORG.7D-3 only. Baseline `origin/main` = `6b1e3da4`
(ORG.7D-2). Each candidate domain was adopted or deferred on its own.
- **Adopted:** Hostel residency, Canteen orders, Admissions before
  conversion (partially: lists, pages and lifecycle).
- **Deferred:** Transport, Library.
- **Untouched:** HR, Fees, Payments, full SIS and LC-5–LC-10. No new
  capability, role template change or migration.

    domain capability → CampusScopeResolver → CampusScope → domain Access (SQL restrict)
    Student picker     = StudentPurposeLookup (current operational Students only)
    every write        = re-checks the Student's CURRENT operational Campus at write time
    whole-School actor = the pre-ORG.7D-3 path, unchanged (no guard object)

**39.1 Search hygiene (R8, done first).** Every touched LIKE search now
escapes `%`, `_` and `\` (`addcslashes($term, '%_\\')`):
- the Applicant directory name and the application list's applicant name;
- the Admissions Guardian search;
- the Canteen order item searches (web and API);
- the Canteen Outlet location and Item stock-item searches (the `orWhere` is
  now grouped);
- the Hostel bed search;
- the Communication Hub Student search (R8 from §38.8, same semantics).

The Library copy search was not touched (Library deferred) and stays R8.

**39.2 Hostel residency (adopted: `hostel.residency.view`, `.manage`).**
- **Campus source.** A residency takes its Campus from its Hostel
  (`hostels.campus_id` NOT NULL). Room and Bed carry no Campus column; they
  reach the Hostel by `(id, school_id)` foreign keys, so the Campus is bound
  only through the Hostel row. `HostelResidencyAccess` restricts residencies and beds by
  Hostel Campus in SQL; out of scope = 404.
- **Integrity added.** A Hostel's Campus can no longer change once the
  Hostel has Rooms ("A Hostel's Campus cannot change once it has Rooms.",
  422). Without this, editing the Hostel would silently move every existing
  bed and residency to another Campus scope.
  - The check runs under a row lock on the Hostel, and both Room-creation
    paths take that row `FOR SHARE`.
  - The `hostel.updated` audit records `campusBefore` and `campusAfter`.
  - This is application-enforced only. No trigger was added, because no
    migration was needed for integrity at this point (recorded).
- **Picker.** Whole-School actors keep the pre-existing active-Student
  search (limit 10, LIKE escaped). A Campus-scoped actor gets
  `StudentPurposeLookup` with their resolved scope. The HTTP row is
  `{id, studentNumber, name}`, where `name` carries the lookup DTO's
  `displayName` under the UI's existing field.
- **Write rule (v1).** `HostelResidencyCampusGuard::beforeAssign()` runs
  inside `HostelResidencyService::assign()` after the row locks:
  - the bed's Hostel Campus must be in scope (else 404);
  - the Student must be operational on THAT Campus
    (`StudentOperationalCampusResolver::isOperationalOn()`).
  - A Campus-scoped actor therefore places a Student only in a Hostel of
    the Student's current Campus.
  - Unknown, inactive, other-Campus, already-resident and lost-race
    Students all get the one generic 422
    `HOSTEL_RESIDENCY_UNAVAILABLE` ("This Student cannot be given a
    residency here right now.").
  - Ending a residency checks the residency's own scope only.
- **Declared whole-School operations:** Hostel fee intent (web), the
  residency fee selections (API) and both carry-forwards. These are
  Fees-linked and School-wide. They have ordinary `capability:` floors, and
  their literal controller checks were removed.
- **Directory unchanged.** The Hostel/Room/Bed directory
  (`hostel.directory.*`) stays whole-School configuration.

**39.3 Canteen orders (adopted: `canteen.orders.view`, `.manage`).**
- **Campus source.** An order's Campus is its Outlet's
  (`canteen_outlets.campus_id`, fixed after creation). A NULL-Campus
  (School-wide) Outlet and its orders are whole-School only.
  `CanteenOrderAccess` restricts outlets, orders and the order form's Outlet
  list.
- **Picker.** Same shape as Hostel: the whole-School path is unchanged,
  and a Campus-scoped actor gets the purpose lookup. The web and API rows
  are `{id, studentNumber, name}` (previously `firstName`/`lastName`
  split; `Orders/Create.vue` was updated). The Hostel row shape is
  unchanged.
- **Owner decision applied.** The Item picker (web and API) needs
  `canteen.orders.manage`. `orders.view` alone reaches neither order-entry
  picker; previously the API Item search accepted `.view`.
- **Write rule.** `CanteenOrderCampusGuard::beforePlace()` runs before the
  lookups and again inside the placement transaction:
  - the Outlet must be in scope, else `CANTEEN_OUTLET_NOT_FOUND`;
  - the Student must be operational on the Outlet's Campus, else
    `CANTEEN_STUDENT_NOT_FOUND`, byte-identical to an unknown id;
  - fulfil and cancel check the order's Outlet scope only
    (`CANTEEN_ORDER_NOT_FOUND`).
- **Side effects stay Canteen's own:**
  - fulfilment issues stock from the Outlet's location, which is FK-bound
    to the Outlet's Campus;
  - fulfilment creates the charge as a bounded side effect.
  - Neither needs `inventory.*` or `fees.*` (pinned).
- **Directory unchanged.** The Outlet/Item directory and billing settings
  stay whole-School configuration.

**39.4 Admissions before conversion (adopted: `admissions.view`,
`.manage`).**
- **Campus source.** An application's Campus
  (`admission_applications.campus_id` NOT NULL, set at creation, never
  changed by any path). Applicants have no Campus.
- **Campus-aware actions:**
  - the application list: web and API;
  - the application page and API detail;
  - submit, accept, reject and withdraw: web and API.
  - Implementation: `AdmissionApplicationAccess` plus a `CampusScope`
    parameter on `AdmissionApplicationReadService::directory()`/`detail()`.
  - The Campus filter only narrows, and the Campus option list shows only
    in-scope Campuses. An out-of-scope application is 404 and unchanged.
- **Application page for a Campus-scoped viewer:**
  - `canConvert` is false (conversion needs `admissions.manage` for the
    whole School);
  - there is no Applicant link (`canViewApplicants`);
  - `feePanel` is null (fee provenance is whole-School);
  - `compatibleSections` is empty;
  - `canManage` follows the manage scope covering the application's
    Campus.
- **Declared whole-School operations (20: 11 web, 9 API).** These have
  ordinary floors, and their literal controller checks and the
  `AuthorizesCapability` trait were removed:
  - the Applicant directory, page and create;
  - an Applicant's application history (it spans Campuses);
  - starting an application (there is no atomic Applicant-plus-application
    path, so a Campus-only officer cannot start one: recorded gap);
  - both Guardian searches (the contact lookup is an identity oracle);
  - conversion to Student (SIS);
  - the admission fee head and fee provenance.
- **Navigation.** `NavigationBuilder` now shows a Campus-held key only on
  entries whose destination is not a declared whole-School operation.
- **Not used:** `StudentPurposeLookup`. An Applicant is not yet a Student.

**39.5 Deferred.**
- **Transport.**
  - NULL-Campus shared routes are the documented common case, and
    web-created routes and vehicles are always NULL Campus;
  - nothing checks route/vehicle Campus consistency;
  - cross-Campus riding is undefined.
  - Adoption needs an owner policy for shared routes and a consistency
    constraint first.
- **Library.**
  - loans carry no Campus;
  - a copy's `campus_id` is nullable and mutable while on loan;
  - cross-Campus lending is undefined.
  - Adoption needs a loan-Campus rule and copy-Campus immutability while on
    loan. The copy search stays R8.

**39.6 Impact (`CampusAdoptionImpactTest`).**
- **New Campus-scopable templates:** `hostel_warden`, `canteen_operator`,
  `admissions_officer`.
- **Templates gaining adopted keys:** `principal` and `school_admin` gain
  `admissions.*`, `canteen.orders.*` and `hostel.residency.*`.
  - `school_admin` is never Campus-scopable.
  - `admissions.` left the prohibited-prefix list.
- **Existing Campus grants gaining keys:** zero. There are no active
  Campus-scoped grants in the DDEV development or test databases
  (re-verified 2026-10-19), and the production inventory is a deployment
  check.
- **High-risk adoption.** Canteen orders are `financial`-classed:
  fulfilment assesses a Charge. The owner authorized their adoption (ORG.7D-3
  prompt), and it is recorded in `OWNER_APPROVED_HIGH_RISK`.
  - The impact test now also treats any adopted key of the `financial` or
    `authority` class as high-risk, not only the key prefixes.
  - Fulfilment's charge confers no Fees, Payments or ledger authority.
  - No other high-risk family is adopted: marks, HR and Student identity
    stay out.

**39.7 Guards.**
- `CampusScopeAdoptionArchitectureTest` covers the three new domains.
- `CoverageGuard` checks the C6 entries (Hostel web/API, 8 web + 7 API
  Canteen, 6 web + 6 API Admissions) and the 24 declared operations.
- `StudentCampusFoundationArchitectureTest` allowlists exactly the Hostel
  and Canteen access and guard classes as purpose-lookup/resolver
  consumers.
- New tests: `HostelCampusScopeTest`, `CanteenCampusScopeTest` and
  `AdmissionsCampusScopeTest`. They cover allow and deny cases, generic
  refusals, write-time re-checks, module-disabled and inactive-Campus
  behaviour, and the unchanged whole-School path.
- No queued job or outbox consumer in these domains reads Campus authority.

**39.8 Security review (ORG.7D-3).** Independent adversarial review: no
HIGH or MEDIUM finding. LOW findings fixed before publication:
- **Hostel Campus change race.** The rooms check and the update now run in
  one transaction, under a row lock serialized against Room creation, and
  the Campus change is audited (39.2).
- **Canteen fulfil/cancel error body.** A whole-School actor's unknown order
  had turned into a generic 404. The service now resolves the order again,
  so every actor gets `CANTEEN_ORDER_NOT_FOUND`, with a web form error as
  before. A Campus actor's out-of-scope order gets the same code from the
  write guard, identical to an unknown id.
- **OpenAPI.** The Hostel store 422 and the Canteen store 404 descriptions
  now document the Campus-scoped generic refusals.
- **Omitted write guard.** The guard parameter is optional (null = the
  whole-School path). `CampusScopeAdoptionArchitectureTest` now fails any
  app call to `HostelResidencyService::assign` or
  `CanteenOrderService::place/fulfill/cancel` that does not pass
  `writeGuard()`.
- **Remaining unescaped searches.** The LIKE searches in the touched files
  are now escaped: the Canteen Item and Outlet directories, and the
  Communication Hub conversation, member and Guardian searches.
- **High-risk accounting.** See 39.6.

Recorded, not changed:
- **Admissions Campus immutability.** §35.4 proposed a trigger for an
  application's Campus "at adoption". It was not added: no code path
  updates `admission_applications.campus_id`. This is a recorded deviation,
  to be revisited if an edit path is ever built.
- **Idempotency fingerprints after deploy.** Moving a floor to
  `capability:x,campus` adds the actor's Campus authority to the
  idempotency fingerprint, for whole-School actors too. A key stored before
  deployment and retried afterwards gets `IDEMPOTENCY_KEY_CONFLICT` rather
  than a replay, for the retention window only. Affected routes: Admissions
  submit/accept/reject/withdraw, Hostel residency store and Canteen order
  store (release note).
- **Write-time read without a lock.** The write-time
  `isOperationalOn()` reads the placement without locking it. A transfer
  committing in the same instant can interleave (accepted LOW). **Closed
  by ORG.7D-4 (§40):** `lockOperationalOn()` replaced it.
- **Residency inference.** A Campus warden can infer that an offered
  Student already lives in some Hostel when assignment is refused (never
  which one). Accepted LOW, consistent with the generic-refusal standard.
- **Converted ids.** The application page still carries the bare converted
  Student/Enrollment ids for converted applications. This is the
  pre-existing ids-only boundary; the Student profile itself stays 403.
- **UI-only `canManage`.** On the Hostel and Canteen pages it means
  "manage held for some Campus", so an action button can show for a record
  whose action answers 404.

**39.9 CI Larastan memory.** The first ORG.7D-3 candidate (`53bea7fd`,
run 39254278558) passed all four test shards and the coverage proof. Its
Larastan step failed with a parallel-worker crash ("Child process error
(exit code 255)") at `--memory-limit=512M`, the same transient as
ORG.7D-1. As the owner pre-authorized in ORG.7D-2, the CI Larastan step
alone now runs at 768M (`.github/workflows/ci.yml`). The local gate
(CLAUDE.md, the publish-gate skill) stays 512M and passes. No analysis
level, baseline or path set changed.

## 40. ORG.7D-4 — Student eligibility transaction hardening; Transport and Library Campus contracts (as built, 2026-10-19)

Owner authorization: ORG.7D-4 only. Baseline `origin/main` = `0ad4ce31`
(ORG.7D-3).
- **Implemented:** transaction-safe Student operational eligibility, its
  architecture and concurrency tests, and these contracts.
- **Not adopted:** Transport, Library, HR, Fees/Payments, full SIS.
- **Unchanged:** `CampusScopeCatalogue`, every capability, every role
  template. No migration.

    picker answer (StudentPurposeLookup)    = advisory candidate, never proof
    unlocked resolver (…CampusResolver)     = advisory read, never proof
    write-time proof                        = StudentOperationalEligibility::lockOperationalOn()
                                              inside the consuming write transaction

**40.1 Owner decision frozen: no cross-Campus boarding.** A Campus-scoped
Hostel actor may NOT board a Student whose current operational Campus
differs from the Hostel's Campus. There is no exception for Campus-scoped
staff. Whole-School Hostel actors keep their existing behaviour. This is no
longer an open question.

**40.2 The race closed.** ORG.7D-3's write guards re-checked eligibility
with an unlocked read (`isOperationalOn()`). A concurrent writer could
commit after that read and before the consuming write committed. Under
READ COMMITTED a plain read never waits for an uncommitted change, so the
write could commit on a stale answer. The writers involved:
- a placement transfer, completion, withdrawal or cancellation;
- a Student inactivation;
- an academic-year activation or close;
- a Campus deactivation.

**40.3 State inventory: what decides eligibility.**

| State | Row | Writer and its lock |
|---|---|---|
| active AcademicYear | `academic_years` (School's `active` row) | `AcademicYearService::activate()` locks the previous active row FOR UPDATE and updates it; `close()` updates it |
| Student lifecycle | `students` row (`status`) | `StudentService::changeStatus()` / `update()`: plain UPDATE |
| current placement | `student_enrollments` (the `active` row in the active year) | `StudentEnrollmentService` (transfer, complete, withdraw, cancel) and rollover item execution: Section FOR UPDATE, then Enrollment FOR UPDATE, then UPDATE; a transfer or rollover then INSERTs a new row, whose foreign keys take KEY SHARE on the Student, Campus, Section and year |
| placement Campus | the Enrollment's `campus_id` (FK-bound to its Section, ORG.7D-0) | changes only through a transfer (a new row) |
| Campus active | `campuses` row (`status`) | `CampusAdministrationService::update()`: plain UPDATE (ActiveCampus already share-locks it) |

**40.4 The API.** `App\Domain\Students\Application\StudentOperationalEligibility::lockOperationalOn(School,
studentId, campusId): bool`.
- **Where it runs:** only inside a transaction (else `LogicException`),
  inside the target School's TenantContext, with explicit `school_id`
  predicates.
- **Return:** a boolean only, never a model.
- **What it is:** data validity, never authorization. The caller has
  already authorized its capability and Campus scope, and passes the target
  Campus (the Hostel's or the Outlet's).
- **The lock set:** four single-row, primary or unique-index FOR SHARE
  reads, in this fixed order:
  1. the active year (`CurrentAcademicYearResolver::shareLockActiveId()`,
     owned by AcademicStructure);
  2. the Student, which must be `active`;
  3. the current Enrollment (`constrainToCurrentPlacement()`), whose Campus
     must be the target;
  4. the Campus (`ActiveCampus::find()`, the existing ORG.7D-0 share lock;
     no second Campus lock exists).
- **Why FOR SHARE is enough:**
  - FOR SHARE conflicts with every writer above. They UPDATE the row, or
    take FOR UPDATE on it, so each waits for the consuming write to commit.
    The order is then write, then state change.
  - If the writer committed first, the consumer's FOR SHARE waits for it.
    PostgreSQL then re-checks the row's new version against the predicate
    (`status = 'active'`), so the answer is false. A row the writer
    inserted (a transfer's new placement, a newly active year) is not in the
    consumer's statement snapshot, so it is never guessed at. The order is
    then state change, then a generic refusal.
  - FOR SHARE is compatible with FOR SHARE and with FK KEY SHARE. Two
    consumers never serialize against each other, and a transfer's inserts
    are not blocked by it. Nothing School-wide is locked. FOR UPDATE was not
    used because it would serialize consumers needlessly.
- **New rows are safe.** A writer that only inserts can only create
  eligibility, never remove it. Examples: a first enrollment for an
  unplaced Student, or a new active year when none is active. A consumer
  that answered false refused; there is no stale yes.

**40.5 Lock ordering and deadlock safety.**
- **Consumer order:** year → Student → Enrollment → Campus.
  - Hostel first takes its own Student lock and Bed FOR UPDATE. Since
    ORG.7D-4 the Student lock is FOR NO KEY UPDATE
    (`HostelResidencyService::STUDENT_LOCK`). Re-locking its own row in
    share mode is not an upgrade.
  - Canteen takes share locks only.
- **Writer orders:**
  - activation: years only, plus an outbox insert;
  - status change: the Student row only;
  - placement writers and rollover: Section → Enrollment, then inserts.
    The foreign keys' KEY SHARE is compatible with FOR SHARE and with FOR
    NO KEY UPDATE, but not with FOR UPDATE;
  - Campus update: the Campus row only.
- **Why no cycle:**
  - No writer waits for a row the consumer locks earlier in its order, and
    no consumer upgrades a share lock it holds.
  - A deadlock, if one ever occurred, would be detected by PostgreSQL and
    surface as an error, never a silent commit. The race suite (40.7)
    completes every ordering without one.
- **No global School lock** was needed, and no advisory lock was added.
- **The deadlock found and fixed (security review).** Hostel used to take
  the Student row FOR UPDATE.
  - With the new Enrollment share lock, a Campus-scoped assignment (holding
    the Student, waiting on the Enrollment) and a transfer or rollover
    (holding the Enrollment, its insert's FK waiting for KEY SHARE on the
    Student) could deadlock. That failed closed with a 40P01 error, but it
    was a new availability defect.
  - The Student lock is now FOR NO KEY UPDATE. That mode is still exclusive
    against another assignment, a Student UPDATE and a share-lock check, but
    compatible with KEY SHARE. The transfer completes, and the resumed
    assignment refuses.
  - Taking eligibility before the Student lock was rejected: it would
    upgrade share to exclusive on the same row.
- **Negative controls:**
  - With the Enrollment share lock removed, the Canteen transfer races fail
    ("overlap not proven").
  - With the Student lock back at FOR UPDATE, the deadlock regression test
    fails, because the transfer blocks behind the Hostel lock.
- **Which locks protect Hostel:** its Student lock still covers a Student
  status change. The new Enrollment and year locks are what cover it against
  a transfer, withdrawal, completion, cancellation or year switch.

**40.6 Consumers migrated, no authorization change.**
- **Hostel:** `HostelResidencyCampusGuard::beforeAssign()` calls
  `lockOperationalOn(Hostel Campus)` after the Student and Bed locks.
- **Canteen:**
  - `CanteenOrderCampusGuard::beforePlace()` is the early refusal. It runs
    before the service's unguarded lookups and uses the same locked check in
    its own short transaction (savepoint).
  - `atPlacement()` is the proof, run inside the placement transaction.
  - `beforeChange()` (fulfil/cancel) now also requires the Outlet's Campus
    to be active, share-locked through ActiveCampus. A grant on an inactive
    Campus confers nothing, so a Campus deactivation serializes against a
    Campus-scoped fulfilment.
- **Unchanged:**
  - public error codes and messages (`HOSTEL_RESIDENCY_UNAVAILABLE`,
    `CANTEEN_STUDENT_NOT_FOUND`, `CANTEEN_ORDER_NOT_FOUND`);
  - the generic refusals;
  - the whole-School paths (no guard);
  - Admissions (it consumes no Student).
- **Removed:** `StudentOperationalCampusResolver::isOperationalOn()`. The
  resolver is read-only and advisory.

**40.7 Proofs.**
- **`StudentEligibilityConcurrencyTest`:** two real OS processes. The
  contender is positively observed blocked in `pg_stat_activity` before the
  holder commits (`ForcesConcurrentOverlap`). Both serialization orders are
  covered for each of transfer, withdrawal, Student inactivation,
  academic-year activation, academic-year close and Campus deactivation (the
  real `CampusAdministrationService` path), for Hostel residency and Canteen
  orders (24 cases).
  - **The year switch:** Y1 is active with the Student on Campus A, and Y2
    places them on Campus B. A Campus A write either commits first (the
    activation waits) or is refused. It never commits after the switch.
- **Also in that file:**
  - Canteen fulfilment against a Campus deactivation, and cancellation in
    both orders;
  - the deadlock regression: a transfer completes while the Hostel Student
    lock is held, and the assignment then refuses;
  - the outside-a-transaction refusal.
- **`StudentOperationalEligibilityTest`:** every component of the rule
  (inactive Student, no or ended placement, a draft-year placement,
  inactive or reactivated Campus, no active year), other-School and
  unknown ids, and the cost (exactly 4 `for share` / `limit 1` reads).
- **`StudentCampusFoundationArchitectureTest`:**
  - write guards call `lockOperationalOn` and never the lookup or the
    resolver;
  - each guard proof (`beforeAssign`, `atPlacement`, `beforeChange`) runs
    inside its service method's `DB::transaction`;
  - only declared write guards reference the assertion;
  - the resolver keeps no write-time method;
  - the assertion is one boolean method.

**40.8 Cost.** These are representative Campus-scoped writes measured with
the query log, before and after:
- Hostel assignment: 22 → 24 queries.
- Canteen order: 21 → 25 queries; the check runs twice (early refusal plus
  proof).
- Each check is 4 indexed single-row reads. There is no scan, no N+1 and no
  School-wide lock.
- The purpose lookup stays unlocked and bounded (at most 50 rows).

**40.9 Transport Campus contract (frozen for the implementation wave; not
adopted).** Evidence comes from a read-only audit of the schema, services,
routes, catalogue and DDEV data.
- **Schema reality:**
  - `transport_routes.campus_id`: nullable, FK under MATCH SIMPLE, RESTRICT.
  - `transport_vehicles.campus_id`: nullable, same FK form.
  - `transport_stops`: route-owned, no Campus.
  - `transport_route_assignments`: route ↔ vehicle ↔ driver history; one
    active per route; no per-vehicle or per-driver uniqueness.
  - `transport_student_assignments`: riders; no Campus; one active per
    Student; stops FK-bound to the rider's route.
  - Fee tables: route fee heads and append-only fee selections.
  - No capacity enforcement, no settings, no async.
- **Today:**
  - web create cannot set any Campus, so every web route and vehicle is
    NULL;
  - the API sets and changes `campus_id` at any time, riders included,
    validated as `string` with no ActiveCampus;
  - nothing reads route Campus;
  - nothing checks route/vehicle Campus consistency;
  - the rider check is "Student active" only;
  - the web Student picker is School-wide active Students; the API has
    none.
  - DDEV: 1 route and 2 vehicles, all on a Campus; 8 riders, all matching;
    0 Campus grants.
- **NULL route = School-shared = whole-School authority only.** A
  Campus-scoped actor never manages, lists or assigns riders to a NULL
  route. NULL never means "every Campus".
- **A Campus-bound route's authority** is its `campus_id`, which must be in
  the actor's Transport scope. Riders, stops and the route's assignments are
  reached through the route.
- **Rider rule (v1):**
  - a Campus-scoped rider assignment requires the route Campus in scope;
  - the Student must be operational on that route Campus, proven by
    `lockOperationalOn(route Campus)` inside the assignment transaction;
  - every refusal is one generic `TRANSPORT_ASSIGNMENT_UNAVAILABLE`. That
    covers already-assigned (today 422), the race (409), not eligible,
    other Campus and unknown.
  - Whole-School actors are unchanged, cross-Campus riding included.
  - A rider record is seen by the route's Campus, and history follows the
    route.
- **Prerequisite, history:** the route's Campus becomes immutable once it
  has stops, riders or route assignments (the Hostel precedent, no
  migration). Campus writes validate a uuid and use ActiveCampus. Without
  this, rider history would follow a mutable route Campus.
- **Vehicles are mixed:** nullable Campus, one vehicle may serve several
  Campuses' routes, and nothing checks consistency.
  - The fleet registry and the route ↔ vehicle/driver assignment
    (`transport.vehicles.*`) stay whole-School in v1.
  - Splitting daily operations from fleet configuration needs a new
    capability, which is out of scope. That is an owner decision for a later
    wave.
  - Campus staff never mutate shared vehicles. In v1 they need no vehicle
    reference data.
- **Stops are route-owned:**
  - read-only within an in-scope route (`id`, `name`, `sequence`);
  - managed with the route.
  - The stop update endpoint addresses a stop by id, so a future scoped
    check goes stop → route → Campus.
- **Conflicts:** only one-active-per-Student is School-wide. It becomes the
  generic refusal for Campus actors and is never weakened. Code uniqueness
  is accepted under rule 101.
- **Proposed split:**
  - Campus-adoptable: `transport.assignments.view/.manage` (riders) and
    `transport.routes.view` (in-scope routes and their stops).
  - Whole-School: `transport.vehicles.*`, `transport.routes.manage`
    (adopting it is an owner option once route Campus is immutable; the
    fee-head mapping stays a declared whole-School operation either way).
  - Declared whole-School operations: Transport fees (web), both
    carry-forwards, the fee-selections index and the fee-head show/update.
  - Picker: `StudentPurposeLookup` under the resolved Transport scope,
    returning `id`, `studentNumber` and `name` (displayName).
  - Direct resources out of scope are 404, including the API `student_id`
    filter. Async: none.
- **Role impact:** `transport_coordinator`, `principal` and `school_admin`
  hold all six keys.
  - `principal` is already Campus-scopable.
  - `transport_coordinator` becomes Campus-scopable on adoption.
  - `school_admin` is never Campus-scopable.
  - Existing Campus grants that gain keys: 0 in DDEV.
- **Fees:** a rider assignment records FEE optional selections as a bounded
  side effect under `transport.assignments.manage` (`children` class), like
  Hostel. The fee amount follows the Student's enrollment Campus (FEE).
  Owner to confirm that this side effect is not high-risk.
- **Blockers before adoption:**
  1. route Campus immutability, plus uuid validation and ActiveCampus;
  2. a web Campus field on route create (else Campus staff see nothing);
  3. generic refusal and 404 concealment;
  4. the scoped picker;
  5. the R8 searches (route, vehicle, operations, Employee and route picker
     searches: 7 sites, recorded);
  6. C6 conversion of the C2 entries;
  7. the owner decisions on `routes.manage` adoption and the fee side
     effect.
- **Recorded, not fixed:**
  - routes and vehicles can be deactivated while in use;
  - the API accepts inactive stops;
  - the driver lookup (`employee_number`, `full_name`) would need
    shared-lookup approval if ever exposed to Campus staff.

**40.10 Library Campus contract (frozen for the implementation wave; not
adopted).**
- **Schema reality:**
  - `library_titles`: School-shared, no Campus.
  - `library_copies.campus_id`: nullable, composite FK RESTRICT, no location
    or shelf.
  - `library_loans`: Student borrower only, no Campus snapshot, no actor;
    one active loan per copy (partial unique); no borrower limit;
    reservations, holds and lost/damaged flows do not exist.
  - `library_fines`: overdue only, created at check-in through FEE's
    authorization-neutral `FeeSourceChargeService`. The actor needs
    `library.circulation.manage` only.
  - No async; retention only.
- **Today:**
  - web copy create sends no Campus, so every browser copy is NULL;
  - the only Campus move is API `PATCH /library-copies/{id}`, with no
    transaction, no lock, no active-loan check and no ActiveCampus;
  - copy create has no ActiveCampus either;
  - circulation reads no Campus.
  - DDEV: 12 copies, all on a Campus; 5 loans; 0 copy updates ever; 0
    borrower/copy Campus mismatches; 0 fines.
- **Identity versus physical copy:**
  - Title = School-shared reference, never duplicated per Campus.
  - Copy = operational Campus resource. NULL = School-shared = whole-School
    authority only; NULL never means every Campus.
  - Loan = a historical transaction with the copy's Campus at checkout.
- **Historical loan Campus (mandatory):**
  - loan access never follows the Student's current Campus or the copy's
    later Campus;
  - a later Student transfer or copy move never re-attributes an old loan;
  - check-in is scoped by the loan's stored Campus.
- **Lending rule (v1):** a Campus-scoped librarian lends only when all of
  these hold:
  - their scope covers Campus A;
  - the copy's Campus is A;
  - the borrower is operational on A, proven by `lockOperationalOn(A)`
    inside the checkout transaction after the copy lock.
  - There is no cross-Campus lending for Campus staff. Whole-School
    librarians are unchanged.
- **Picker:** `StudentPurposeLookup` under the resolved Library scope,
  returning `id`, `studentNumber` and `name`; no `students.view`.
- **Conflicts:** for Campus actors, one generic `LIBRARY_CHECKOUT_UNAVAILABLE`
  covers out-of-scope copy, copy not available, unknown, inactive or
  other-Campus Student, and the lost race. Today `COPY_NOT_AVAILABLE` and
  `STUDENT_NOT_ELIGIBLE` are oracles. One-active-per-copy is preserved.
- **Proposed split:**
  - Campus-adoptable: `library.circulation.view/.manage`.
  - Whole-School: `library.catalogue.*` (titles, copies, copy moves; the
    title page shows active borrowers' names and School-wide copy counts,
    so it stays whole-School) and `library.fines.*` (financial).
  - The available-copy search becomes scoped and LIKE-escaped.
- **Fee/fine boundary:** a Campus-scoped check-in assesses an overdue fine,
  a bounded Library-origin side effect with no Fees authority. But
  `library.circulation.*` is classed `children` only, so the class-aware
  impact check would not flag it. Before adoption, record an owner approval
  in `OWNER_APPROVED_HIGH_RISK`, or reclassify. Otherwise the check-in fine
  path is deferred.
- **Migration required before adoption (not authorized here):**
  - `library_loans.campus_id`: uuid NULL, composite FK RESTRICT, indexed.
  - A BEFORE INSERT trigger: equal to the copy's Campus, read FOR SHARE.
  - Immutability of `campus_id` (and ideally `library_copy_id` and
    `student_id`).
  - A `library_copies` BEFORE UPDATE OF `campus_id` refusal while an active
    loan exists, plus the same check in the application (transaction, copy
    lock, audit inside it).
  - Retention-anchor tracking of `campus_id`.
  - ActiveCampus on copy create and move.
- **Data precondition:** backfill each loan's Campus from its copy only
  where no `library.copy.updated` Campus change and no copy `updated_at`
  after checkout exists. DDEV: unambiguous for every row; there is no
  production data. Any ambiguous row stops the migration for an owner
  remediation decision. Ambiguous history is never rewritten.
- **Role impact:**
  - `librarian` (catalogue, circulation, `fines.view`) becomes
    Campus-scopable on adoption;
  - `principal` gains the circulation keys;
  - `school_admin` is never Campus-scopable;
  - `CampusScopedRoleGrantTest` pins the librarian as not scopable today.
- **Owner decisions:**
  1. copy-move policy (snapshot plus triggers recommended over composite-FK
     pinning, which would freeze any copy with loan history);
  2. a web Campus field for copies;
  3. cross-Campus returns;
  4. the fine approval or reclassification;
  5. accepting that an unplaced Student cannot borrow from Campus staff.
- **Recorded, not fixed:**
  - the copy update's audit runs outside a transaction;
  - non-uuid ids are validated as strings;
  - the title and copy LIKE searches are unescaped (R8);
  - adopting the circulation key on `POST /library-loans` changes its
    idempotency fingerprint (release note).

**40.11 Unchanged.**
- `CampusScopeCatalogue`, capabilities and roles.
- `campusAttribution()` and financial Campus semantics.
- Student profile authority, TeachingOwnership and GuardianStudentScope.
- HR (R4 not touched), Fees/Payments and SIS.
- LC-5–LC-10 stay paused.

**40.12 Security review (ORG.7D-4).** Independent adversarial review: no
HIGH finding.
- **Verified:** every writer of the four states; EvalPlanQual refusal when
  the writer commits first; insert-only writers that can only create
  eligibility; no cross-School locks; no picker used as proof; whole-School
  paths and error codes unchanged.
- **Fixed before publication:**
  - **MEDIUM — Hostel deadlock.** A Hostel assignment could deadlock
    against a transfer or rollover. Fixed by FOR NO KEY UPDATE, with a
    regression test and a negative control (40.5).
  - **LOW — test gaps.** Withdrawal and year close added (they are where the
    new locks protect Hostel); cancellation raced in both orders; the real
    Campus update path used; the in-transaction placement of every guard
    proof pinned.
- **Recorded, not changed:**
  - Canteen fulfilment "write first" is not raced: it needs a full billing
    fixture, and the lock is the same ActiveCampus share lock that the
    cancellation races prove.
  - The whole-School Hostel and Canteen paths still check `isActive()` on a
    Student loaded before the transaction, as before ORG.7D-4. Out of scope.
- **Environment note.** Under `ddev test`, 18 Identity tests (account
  recovery and activation links, staff invitations) fail because DDEV
  exports `APP_URL=https://lycenza.ddev.site` while the tests expect the CI
  `http://localhost:8000`. With `APP_URL=http://localhost:8000` all 159
  pass. This is environment-specific, pre-existing, and reported
  separately, not a D4 regression.

## 41. ORG.7D-5 — Library and Transport Campus adoption (as built, 2026-10-19)

Owner authorization: ORG.7D-5 only. Baseline `origin/main` = `5484ba2d`
(ORG.7D-4). Both domains were adopted; neither was deferred.
- **Library:** circulation only.
- **Transport:** route viewing and rider assignments.
- **Untouched:** HR, Fees/Payments, full SIS, marks and LC-5–LC-10.
- **No new capability or role template change.** One migration (Library).

    adopted (Library)    = library.circulation.view, library.circulation.manage
    adopted (Transport)  = transport.routes.view, transport.assignments.view, transport.assignments.manage
    whole-School         = library.catalogue.*, library.fines.*, transport.routes.manage, transport.vehicles.*
    NULL Campus          = School-shared = whole-School authority only (never "every Campus")

**41.1 Library data precondition (audited before the migration).** DDEV
development database, every School:
- 6 titles; 12 copies, all on a Campus, 0 NULL;
- 4 active and 1 returned loan; 0 active loans on a NULL-Campus copy;
- 0 copies updated after any of their loans' checkout;
- 0 `library.copy.updated` audit events;
- 0 cross-School loan/copy or copy/Campus mismatches.

The backfill was unambiguous for every row.

**41.2 Loan Campus (migration `2026_12_23_090000_freeze_library_loan_campus`).**
- **Column:** `library_loans.campus_id` (uuid, nullable: a NULL-Campus
  copy makes a NULL-Campus loan), with a composite FK `(campus_id,
  school_id) → campuses` ON DELETE RESTRICT and an index
  `(school_id, campus_id)`.
- **Backfill:** from the loan's copy, only after an in-migration ambiguity
  check. A copy updated after the checkout, or any `library.copy.updated`
  event at or after it, aborts the whole migration with nothing changed
  (owner remediation; never a guess).
- **`library_loans_campus_guard`:**
  - BEFORE INSERT: the Campus must equal the copy's, read FOR SHARE;
  - BEFORE UPDATE: `campus_id` and `library_copy_id` never change.
- **`library_copies_campus_move_guard`:** BEFORE UPDATE OF `campus_id`,
  refused while the copy has an active loan.
- **Not retention-tracked:** an immutable column can never re-record a
  row.
- **Proofs:**
  - forward migration and backfill (5/5, 0 mismatches);
  - raw PostgreSQL as `school_os_app` under RLS context (immutability,
    move-on-loan, mismatched insert all refused; a move off loan allowed);
  - rollback (column, FK, index, triggers and functions removed), then
    reapply;
  - `platform:verify-database` failed=0.

**41.3 Library authority.**
- **One copy writer.** `LibraryCopyService` creates and moves copies for
  the whole-School catalogue on web and API. It applies ActiveCampus, takes
  the copy row FOR UPDATE (the lock checkout takes), refuses a move while on
  loan ("A Copy on loan cannot change Campus. Check it in first."), and
  audits inside the transaction.
- **Web catalogue.** It now sets a copy's Campus on create, moves it, and
  shows it. The title search is LIKE-escaped.
- **`LibraryCirculationAccess`.** Loans are restricted by
  `library_loans.campus_id`; lendable copies by `library_copies.campus_id`
  (NULL excluded for Campus actors). The borrower picker is
  `StudentPurposeLookup` for Campus actors; whole-School actors keep the
  existing picker.
- **`LibraryCirculationCampusGuard`, checkout.** After the copy lock, the
  copy's Campus must be set and in scope, and
  `lockOperationalOn(copy Campus)` must hold. Every refusal (unknown,
  inactive, unplaced or other-Campus Student, copy moved, on loan,
  inactive, lost race) is 422 `LIBRARY_CHECKOUT_UNAVAILABLE`. An
  out-of-scope or NULL copy is 404, like an unknown one.
- **`LibraryCirculationCampusGuard`, check-in.** The loan's frozen Campus
  must be in scope and active (ActiveCampus share lock), else 404. There is
  no cross-Campus return.
- **History** (lists, the loan page, API show) follows the loan's frozen
  Campus. A Student transfer or a later copy move never re-attributes a
  loan (tested).
- **Fine side effect (owner-approved high-risk).** A Campus-scoped
  check-in assesses the overdue fine through the unchanged
  `LibraryFineService → FeeSourceChargeService` seam:
  - the fine is tied to the loan and audited (`library.fine.assessed`,
    actor = the librarian);
  - no `fees.*`, `finance.*` or `library.fines.*` authority;
  - the fine panel and the "fine assessed" redirect are shown only to a
    whole-School fines viewer.
  - `CampusAdoptionImpactTest::OWNER_APPROVED_SIDE_EFFECTS` records it and
    proves no Campus-scopable holder also carries financial authority.
- **Unchanged:** whole-School librarians, cross-Campus lending included.

**41.4 Transport authority.**
- **One route writer.** `TransportRouteService` creates and updates routes
  for the whole-School `transport.routes.manage` on web and API:
  - ActiveCampus applies (uuid, same School, active);
  - the Campus is fixed once the route is used (a Stop, a rider or a
    vehicle/driver assignment, any status, checked under the route row
    lock: "A Route's Campus cannot change once it has Stops, riders or
    vehicle assignments.").
  - Every dependent writer share-locks the route first: Stop creation (web
    and API), rider assignment, and route vehicle assignment (which already
    locks it FOR UPDATE).
- **Web route pages.** They now set, show and change a route's Campus. The
  route and route-picker searches are LIKE-escaped.
- **`TransportRiderAccess`.**
  - Routes are restricted by `transport_routes.campus_id`; NULL routes are
    excluded for Campus actors.
  - Stops and riders are reached through their route; a stop id outside
    the in-scope routes is 404.
  - The rider picker is `StudentPurposeLookup`.
  - The route page's fee head panel is shown to a whole-School viewer only.
- **`TransportRiderCampusGuard`, assign.** It runs after the Student lock
  and the route share lock: the route's Campus must be set and in scope,
  and `lockOperationalOn(route Campus)` must hold. Every refusal (including
  already-assigned and the race) is 422 `TRANSPORT_ASSIGNMENT_UNAVAILABLE`.
- **`TransportRiderCampusGuard`, end.** The route's Campus must be in scope
  and active, else 404.
- **Lock mode.** The rider service's Student lock is now FOR NO KEY UPDATE
  (`TransportStudentAssignmentService::STUDENT_LOCK`, the ORG.7D-4 §40.5
  rule). The guard pins that no guarded service locks the Student FOR
  UPDATE.
- **Vehicles.** They stay a mixed, School-shared fleet, managed
  whole-School (`transport.vehicles.*`, including daily operations).
  Campus staff get no vehicle reference data: the v1 rider workflow does
  not need it. No vehicle/route Campus consistency is invented.
- **Stops.** They follow their route for visibility; stop management stays
  `transport.routes.manage`.
- **Rider fee side effect (owner-approved high-risk).** `assign()` records
  the active-year fee intent through `TransportFeeSelectionService` in the
  same transaction. That is bounded, audited, needs no Fees authority, and
  keeps the existing year and enrollment attribution (never today's Student
  Campus). It is recorded in `OWNER_APPROVED_SIDE_EFFECTS`.
- **Declared whole-School operations (5):**
  - web Transport fees and their carry-forward;
  - the API carry-forward;
  - a rider's fee selections;
  - the route fee head.
  - They have ordinary floors, and their literal controller checks were
    removed.
- **Defect found and fixed.** `TransportFeeController` bound tenant models
  implicitly, and that binding runs before `school-membership` sets the
  School context. With no ambient context (as in production), the fee head
  and fee-selection API answered 404 for everyone; existing tests had passed
  only through context leaking from fixtures. Ids are now resolved inside
  the action, after authorization, and a regression test pins it.
- **Historical semantics.** A rider record keeps its route, and the
  route's Campus is now fixed once used. Existing DDEV rows: 1 route on a
  Campus, 8 riders, all on the route's Campus (no ambiguity, nothing
  backfilled).
- **Unchanged:** whole-School actors, cross-Campus riding included.

**41.5 Role impact (`CampusAdoptionImpactTest`).**
- **Newly Campus-scopable templates:** `librarian` (circulation) and
  `transport_coordinator` (routes.view and assignments.*).
- **Templates gaining the adopted keys:** `principal` (already scopable)
  and `school_admin` (never scopable).
- **Existing Campus-scoped grants:** 0 in the DDEV development and test
  databases (re-verified 2026-10-19).
- **No role gains** `students.view`, `fees.*`, payments, finance or staff
  access. `transport.routes.manage` stays ineffective from a Campus-only
  grant.
- **Snapshot change.** `CampusScopedRoleGrantTest` now uses `accountant`
  as its "confers nothing for a Campus" example, because `librarian` is now
  scopable.

**41.6 Coverage and guards.**
- **C6 entries:**
  - Library: 4 API + 7 web (`create` is a data-free lookup page);
  - Transport: 7 API + 9 web.
- **Catalogue:** the `CampusScopeCatalogue` domains are `library` and
  `transport`; `WHOLE_SCHOOL_OPERATIONS` now has 29 entries.
- **`CampusScopeAdoptionArchitectureTest`:** the domain paths cover both
  domains, and the write-guard pass assertion covers
  `LibraryLoanService::checkout/checkIn` and
  `TransportStudentAssignmentService::assign/end`.
- **`StudentCampusFoundationArchitectureTest`:**
  - the new access classes and guards are declared consumers and write
    guards;
  - each guard proof runs inside its service's transaction;
  - no guarded service locks the Student FOR UPDATE.
- **Navigation:** the Circulation, Routes and Student-assignments entries
  become Campus-aware. Transport fees stays hidden from Campus actors (a
  declared operation).

**41.7 Concurrency (`StudentEligibilityConcurrencyTest`, real two-process,
57 cases).**
- **Library checkout and Transport rider assignment:** each against the six
  state changes, in both orders (24 cases).
- **Copy move vs checkout:**
  - move first: the checkout sees the new Campus and is refused;
  - checkout first: the move is refused, and the copy and loan keep
    Campus A.
- **Route Campus change vs rider:**
  - change first: the rider sees the new Campus and is refused;
  - rider first: the change is refused.
- Plus the ORG.7D-4 Hostel and Canteen matrix.

**41.8 Unchanged.**
- Student profile authority (picker ids never open a profile; pinned).
- GuardianStudentScope and TeachingOwnership.
- `campusAttribution()` and financial Campus semantics.
- Module entitlements stay higher-order (disable → 403; re-enable
  restores; an inactive Campus confers nothing).
- No per-Campus entitlement.

**41.9 Security review (ORG.7D-5).** Independent adversarial review: no
HIGH finding.
- **Fixed before publication — MEDIUM, rider refusal oracle.**
  `TransportStudentAssignmentService::assign()` checked the Student's
  state before the route-active and stop-on-route checks, and the
  controllers resolved the Student first. A Campus actor naming an inactive
  in-scope route, or another in-scope route's stop, therefore got
  `TRANSPORT_ROUTE_NOT_AVAILABLE` / `TRANSPORT_STOP_NOT_ON_ROUTE` for an
  active Student anywhere, but the generic refusal for an unknown,
  inactive or other-Campus one.
  - Fix: the Student-independent checks are one method,
    `assertRouteAndStops()`. It runs first in the service, and both
    controllers call it before resolving the Student.
  - Pinned by
    `TransportCampusScopeTest::route_and_stop_refusals_never_depend_on_the_named_student`.
  - Library already ran its copy-state and due-date checks after its guard.
- **Fixed — LOW.** The new web Copy route (`app.library.copies.update`)
  is `whereUuid`.
- **Recorded, accepted:**
  - **Idempotent replay after a scope shrink.** An actor who loses one
    Campus but keeps another can replay a stored checkout or rider
    response. This is the same accepted `,campus` + `idempotent` pattern as
    Admissions, Visitor, Hostel and Inventory.
  - **Non-UUID ids.** Ids on the Transport fee endpoints and the API
    `student_id` filters answer a database 500, not a 404 (pre-existing; no
    disclosure).
  - **Route Campus immutability is application-enforced** under the route
    lock that every dependent writer shares, with no trigger backstop (the
    Hostel precedent).
  - **`library_loans.student_id` is not trigger-frozen** (only the
    runtime role can UPDATE; retention only deletes).
  - **The backfill's ambiguity rule is deliberately over-conservative**
    (any copy change after checkout aborts).
- **Test environment.** `TransportFeeSelectionConcurrencyTest` (Fees code,
  untouched here) once deadlocked inside its own fixture setup in 7 local
  runs, then passed 6 more times. It is recorded as a local flake; the
  exact-SHA CI run is canonical.

## 42. ORG.7E-0 — HR Campus foundation: canonical current Employee placement (as built, 2026-10-19)

Owner authorization: ORG.7E-0 only, decisions D-E0-1 to D-E0-12. Baseline
`origin/main` = `96382fb7` (ORG.7D-5).
- **Built:** one canonical definition of an Employee's operationally
  current assignments and Campus set, on the School-local date. The R4
  consumers were normalized onto it.
- **Designed, not built:** the HR Campus access layer.
- **Unchanged:** no HR capability is Campus-adopted (pinned), and
  `CampusScopeCatalogue` is unchanged. No migration, no capability, no role
  change, no `employees.campus_id`.

    Employee identity             = School-wide (employees has no Campus)
    current assignment            = active Employee + employment active|notice_period covering D
                                    + assignment covering D          (D = SchoolTimezone::today)
    operational Campus set        = union of the non-NULL, active Campuses of current assignments
    primary                       = display summary only, never a limit on the Campus set

**42.1 Schema reality (re-audited).**
- **`employees`:** no Campus; `record_status` is `active|archived`.
- **`employment_records`:**
  - `status` is `draft|pre_joining|active|notice_period|separated|terminated|retired|deceased`;
  - `starts_on` is a date, and `ends_on` is a nullable inclusive date;
  - overlap is application-only.
- **`employee_assignments`:**
  - reached only through `employment_record_id`; no status column;
  - `campus_id` nullable (NULL = School-wide);
  - `is_primary`; `starts_on` and `ends_on` are dates;
  - the partial unique index `employee_assignments_one_primary_open_per_employment`
    covers only `ends_on IS NULL`;
  - no overlap constraint; single-column indexes only.
- **History.** No HR record freezes an assignment or a Campus. Leave,
  staff attendance, payroll, compensation and statutory records key on the
  employment. Teaching assignments carry the Offering's Campus.
- **RLS:** forced on all three tables.

**42.2 Employment lifecycle.**
- **Operational:** `EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES`
  (`active`, `notice_period`). This is the list ActingEmployee already
  uses.
- **Not operational:** `pre_joining` and `draft` (not yet employed), and
  every terminal status.
- **Notice period** has no own field: it is `notice_period` with a future
  `ends_on`.
- **Transitions.** None are modelled. `notice_period` and `pre_joining`
  are written only by API create, and `end()` writes a terminal status at
  once even for a future `ends_on`.
  - The canonical rule therefore excludes a scheduled-end (terminal)
    employment from today. Date-only readers used to show it as current.
- **No new lifecycle states.**

**42.3 Inventory of "current" readers: 21 in `app/`, not 5.**

| # | Reader | Answers | Class | Outcome |
|---|---|---|---|---|
| 1 | `EmployeeAssignment::isCurrent()` | assignment dates, UTC | A | **removed** (no production caller; 3 tests now use the resolver) |
| 2 | Directory Campus filter | primary assignment, UTC, no status | A | **normalized**: canonical union (`whereOperationalOnCampuses`) |
| 2b | Directory Department/Position filters | primary placement | display filter | School-local date; declared |
| 3, 4 | Directory row: current employment and primary placement | display summary | G (display) | School-local date; declared |
| 5, 8 | Directory and Profile manager projection | live pointer | E | unchanged (manager readers are not modified) |
| 6, 7 | Profile current employment and primary placement | display summary | G (display) | School-local date; declared |
| 9 | `ReportingLine` | the reporting line on the caller's date | E | unchanged; declared |
| 10 | `EmployeePickerReadService::managerCandidates` | manager candidates, School-local | E | unchanged; declared (ORG.7E-1 scopes it) |
| 11–13 | `setPrimary` demotion, employment-end clip, closure cascade | "open" = `ends_on IS NULL` | G (write mechanics) | unchanged |
| 14 | `ActingEmployeeResolver` | acting identity, School-local | A (identity) | unchanged |
| 15–16 | `EmploymentCoverage` | planned-or-current employment (leave) | F | unchanged |
| 17–18 | `EmploymentCoverage::holdRecordAttendable`, `EmploymentRoster` | staff-attendance date | D | unchanged |
| 19 | `LeavePageSupport` employment picker | status only | G | unchanged |
| 20 | `EmployeeLifecycleService::separate` future-date guard | UTC "today" | G | **normalized**: School-local date |
| 21 | `EmployeeRetentionEligibility` | retention | G | unchanged |
| (ref) | `PayrollRunService` | payroll period | C | unchanged |

**42.4 The canonical resolver (`App\Domain\HR\Application\CurrentEmployeeAssignments`).**
- **Methods:**
  - `current(School, ?date)`: the current-assignment query;
  - `constrainToCurrent(query, date, alias)`: the one predicate;
  - `campusSets(School, ids, ?date)`: one query for many Employees;
  - `campusesFor(School, id, ?date)`;
  - `whereOperationalOnCampuses(Builder<Employee>, School, campusIds, ?date)`:
    an EXISTS predicate, ready for ORG.7E-1.
- **The date is School-local:** `SchoolTimezone::today()`, a new one-line
  shared form of the existing `CarbonImmutable::now(resolve())` idiom. Other
  copies of the idiom are unchanged. No global clock redesign.
- **Behaviour pinned (`CurrentEmployeeAssignmentsTest`):**
  - multi-Campus union; primary plays no part; NULL-Campus assignments are
    current but place nowhere;
  - no assignment gives an empty set;
  - terminal, `pre_joining` and `draft` employments (even with date-open
    assignments) give nothing; notice period counts until its end;
  - future and ended assignments are excluded, with inclusive bounds;
  - archived Employees give nothing;
  - inactive Campus excluded, and restored on reactivation with no rewrite;
  - the School-local date beats UTC both ways around midnight IST;
  - SQL restriction of an Employee query;
  - another School's Employee or Campus never participates;
  - one query for many Employees.
- **Consumers pinned (`HrCurrentPlacementConsumersTest`):**
  - the Directory Campus filter (secondary Campus counts; a terminal or
    not-yet-started employment does not; an inactive Campus places nobody);
  - separation's School-local "future" guard.
- **Pinned: no HR capability is Campus-adopted.** A `principal@Campus`
  grant confers no HR capability and gets 403 on the directory.

**42.5 Architecture guard (`HrCurrentAssignmentArchitectureTest`).**
- **Declared readers only.** A class reading employee assignments with its
  own "covers the date" predicate must be the resolver or a declared reader
  with a stated question (the display summaries, `ReportingLine`, manager
  candidates). A declaration that no longer matches fails, and a pattern
  self-test proves the guard catches what it is meant to.
- **No UTC "today".** HR code never uses `Carbon::today()`, `today()` or
  `now()->toDateString()`.
- **No date-only model helper** (`isCurrent()` is gone).
- **The resolver itself:** it uses the pinned employment-status list and
  `SchoolTimezone::today()`, and never `is_primary`. No controller consumes
  it in ORG.7E-0.
- **HR is not adopted:** no `hr.*` key in `CampusScopeCatalogue`.

**42.6 Capability classification (from the data each action reads or
writes).**
- **CAMPUS_OPERATIONAL_CANDIDATE:**
  - `hr.employees.view`: the Directory projection only — id, number,
    display name, position, department, Campus, manager; no personal
    tables. One service, so this is the cleanest adoption.
  - `hr.employees.assignments.view`: employment and assignment history.
    It has no route of its own; it is a Profile tier behind
    `hr.employees.personal.view`.
  - `hr.employees.manage`: create and update of core fields only (name,
    work email, work phone).
  - `hr.employees.assignments.manage`: the highest risk. It defines the
    scope, cascades into teaching ownership, and is checked through the
    whole-School Gate in `TeachingAssignmentService::CAPABILITY_EMPLOYMENT_END`
    and `ElectiveTeachingAssignmentService`.
- **WHOLE_SCHOOL_SENSITIVE (D-E0-8):**
  - `hr.employees.personal.*`, `.qualifications.*`, `.documents.*`,
    `.notes.*`, `.sensitive.*` (already on the impact test's HIGH_RISK
    list);
  - the identity and lifecycle half of `hr.employees.manage`: account
    link/unlink and account search, archive/restore, and import (declared
    whole-School operations in ORG.7E-1).
- **CONFIGURATION (D-E0-9):** `hr.departments.*`, `hr.positions.*`,
  `hr.categories.*`, `hr.leave.configure`.
- **OTHER, School-only:** `hr.leave.view/.manage` and
  `hr.staff_attendance.view/.manage` (§11.7; both feed Payroll).
- **SELF_SERVICE:** `hr.leave.self`, `hr.staff_attendance.self`,
  `payroll.payslips.self` (owned scope).
- **Relationship-scoped:** `hr.leave.approve` (ReportingLine).
- **PAYROLL:** all `payroll.*`; Payroll has no Campus.
- **HISTORICAL/COMPLIANCE:** no dedicated key (the timeline sits behind
  `personal.view`).

**42.7 Boundaries.**
- **Self-service** stays relationship-based. `HrSelfAdministrationGuard`
  and `ActingEmployeeResolver` are unchanged and run alongside any future
  scope.
- **Reporting-manager authority** applies only to leave (`hr.leave.approve`
  + ActingEmployee + `holdManagerOf`). Campus scope never substitutes for
  it. A future combination is an intersection (capability AND scope AND
  relationship).
  - Open owner decisions: whether a Campus-only principal still approves
    through the relationship, and cross-Campus managers.
- **Staff attendance, leave and payroll** remain separate, School-only
  modules. The ORG.5 entitlements are intact.

**42.8 Frozen contracts for ORG.7E-1.**
- **HR Campus access layer** (designed, unused): `HrEmployeeAccess`.
  - `restrictEmployeeQuery(Builder, CampusScope)` delegates to
    `whereOperationalOnCampuses(scope campus ids)`; whole-School leaves
    it unchanged.
  - `coversEmployee(CampusScope, employeeId)` is the same predicate for one
    row; out of scope = 404.
  - `authorizedCampusSet(...)` is the scope's Campus ids.
  - A Campus actor reaches an Employee only when the Employee has at least
    one current assignment in scope. Unassigned Employees are whole-School
    only.
- **Onboarding (D-E0-10).** Today `EmployeeService::create` makes only the
  Employee; Employment and Assignment are separate requests. The only
  atomic paths are `EmployeeImportService::importRow` and the API rehire.
  ORG.7E-1 needs one named service, in one transaction:
  - Employee + Employment (`active`) + first current Assignment on an
    in-scope active Campus (ActiveCampus);
  - core fields only;
  - no account link (that stays whole-School);
  - the self-administration guard applied.
- **No capture (D-E0-12).** Assignment creation for an existing Employee
  requires `coversEmployee` BEFORE the write (the Employee is already in
  scope through another current assignment), plus the destination Campus in
  scope. It is otherwise refused generically. Distinctions:
  - an ordinary in-scope update;
  - onboarding (the named atomic path);
  - a move (below);
  - whole-School administration (unchanged).
- **Move (D-E0-11).** A named service action (end the source assignment,
  create the destination one), never a `campus_id` update (no update path
  exists). For a Campus actor it needs scope over BOTH Campuses. Not built.
- **Write targets (security review M1, frozen).** Coverage is a union, so
  reaching an Employee is not enough for every write. For a Campus-scoped
  actor:
  - a per-assignment write (end, set primary, set manager) needs the TARGET
    assignment's own Campus in scope; a NULL-Campus assignment is
    whole-School only;
  - an employment-level write (create employment, end, separate) and an
    edit of School-wide identity fields (`full_name`, `work_email`,
    `work_phone`) need EVERY current assignment of the Employee in scope,
    or whole-School authority. A minor secondary assignment never lets one
    Campus end a person's employment, or its teaching ownership, on another
    Campus.
  - Rehire is whole-School: a separated Employee has no current
    assignment, so a Campus rehire would capture any former Employee.
    Changing that needs an owner decision.
- **Write-path gaps** (assignment create/end do not lock the employment;
  `end()` has no validation; `setPrimary` accepts non-current targets).
  These are recorded for ORG.7E-1, where they gain Campus meaning; they
  were not changed here.
- **Minimal Employee lookup (frozen):** `id` and `displayName`.
  `employeeNumber` only where a consuming workflow needs it. Never phone,
  email, address, salary, attendance, documents, notes, qualifications or
  account data. No Campus Employee directory.
- **Visitor host lookup** (`app.visitor.visits.search-hosts`): School-wide,
  `record_status = 'active'`, id and fullName. It is unchanged here (its
  School-wide contract is intentional; ORG.7A).
  - Recorded for a later decision: restricting it to hosts current on the
    visit's Campus, excluding separated Employees, and escaping its LIKE.
  - The Transport driver and Timetable teacher lookups are likewise
    School-wide by contract.
- **Historical HR** follows each record's own keys. Nothing is
  re-attributed from today's Campus, and timeline and history views stay
  whole-School in the first release.

**42.9 Impact (for ORG.7E-1).**
- **`principal`** (already Campus-scopable) holds `hr.employees.view` and
  `.manage`. Every `principal@Campus` grant would gain the Campus HR
  directory and core create/update on adoption.
- **`hr_officer`** (23 non-sensitive `hr.*` keys, no access
  administration) becomes Campus-scopable the moment any of its keys is
  adopted.
- **`school_admin`** is never scopable.
- **Existing Campus grants:** 0 active in DDEV.
- **Recommended:** add `hr.employees.assignments.manage` to the impact
  test's HIGH_RISK list at adoption.
- **Route impact:**
  - **Campus-aware candidates:**
    - `api.v1.schools.employees.index` and `app.hr.employees.index`
      (the service);
    - create/store/edit/update (web and API: direct `findOrFail` then the
      service);
    - employment-records and assignments store/end/set-primary/set-manager/
      manager-candidates and rehire (web and API).
  - **Whole-School declarations:** archive/restore, account link/unlink and
    search, and imports (web and API).
  - **The profile** (`app.hr.employees.show`, API show/activity) is
    blocked by its `personal.view` entry gate and needs an operational
    profile entry.
  - **The HR hub** (`app.hr.index`) needs a WHOLE_SCHOOL_HUBS entry or a
    Campus-aware hub.
  - **Pattern:** 32 of the 37 HR controllers query models directly, so the
    access layer is the prerequisite.
- **Async:** no HR, leave or staff-attendance jobs; import is synchronous;
  the retention commands are whole-School. Nothing changes.

**42.10 Data, performance, tenancy.**
- **DDEV:** 12 active Employees, 12 active open employments, and 12
  primary open assignments, all on the one active Campus. Zero invariant
  violations, but no multi-Campus, notice, future or ended cases exist, so
  the fixtures in 42.4 prove them.
- **`EXPLAIN`** of the Campus-set query: one statement, index-driven on
  `school_id` (bitmap and index scans), with no per-Employee query.
  - No index was added: there is no plan evidence at this size.
  - Candidates when data grows: `employee_assignments (school_id,
    campus_id)`, `(employment_record_id, starts_on)` and
    `employment_records (school_id, status)`.
- **Tenancy:** every predicate carries `school_id`, and the resolver runs
  in `TenantContext::withSchool`. Another School's Employee or Campus never
  participates (tested). It uses the runtime connection with no
  `BYPASSRLS`.

**42.11 Unchanged.**
- Self-service, the reporting line, staff attendance, leave and payroll.
- Student/SIS, Fees/Payments and every Campus-adopted domain.
- LC-5–LC-10 stay paused.

**42.12 Security review (ORG.7E-0).** Independent adversarial review: no
HIGH finding and no MEDIUM code finding.
- **Verified:**
  - the generated SQL groups correctly, and the employment EXISTS
    correlates to the assignment's own employment;
  - intersection and union semantics; every lifecycle edge;
  - inclusive bounds; archived Employees; inactive and NULL Campuses;
  - cross-School Campuses are impossible (composite FK plus explicit
    joins);
  - the Directory Campus filter stays a non-oracle;
  - the tenancy predicates;
  - whole-School HR, self-service, reporting line, leave, staff attendance
    and payroll unchanged.
- **MEDIUM (contract) — frozen in 42.8 as the write-target rules.** Without
  them, a future Campus actor could act through a secondary assignment on
  another Campus's assignments or employment, or capture a former Employee
  via rehire.
- **Fixed — LOW:**
  - resolver ids are lower-cased and UUID-checked (`campusesFor` with an
    upper-case id, and a non-UUID Campus id, failed or 500'd);
  - separation compares normalized calendar dates, not raw input strings;
  - the guard now also catches raw `starts_on <=` / `ends_on >=`,
    `whereBetween`, `CURRENT_DATE`, relation-based assignment reads,
    `Date::today()`, `today('…')`, `now()->format()`,
    `Carbon::now()->startOfDay()` and `date('Y-m-d')`; its dead `routes/`
    branch was removed;
  - the Directory test now proves the local date with a School-today start.
- **Recorded, accepted:**
  - **Combined filters match different rows.** A combined Campus +
    Department/Position filter tests different rows (any current assignment
    vs the primary placement); this is display vs operational, and is
    documented.
  - **Small new inference channel.** Directory holders can now learn a
    secondary current Campus through the filter: low-sensitivity
    operational data.
  - **Directory behaviour changes.** Archived Employees no longer match a
    Campus filter, and an inactive Campus filter returns nobody. Both match
    the contract.
  - **Silent UTC fallback.** `SchoolTimezone::resolve()` still falls back
    to UTC silently for an unreadable timezone (pre-existing; full models
    are passed).
  - **Display summary across Campuses.** The primary-placement display
    summary may name another Campus and its manager. ORG.7E-1 decides the
    projection for a Campus actor reaching an Employee through a secondary
    assignment.
  - **Stale docblock.** A docblock in the `employee_assignments` migration
    still mentions the removed `isCurrent()`; shipped migrations are not
    edited.

## 43. ORG.7E-1 — HR operational Campus adoption (as built, 2026-10-20)

Owner authorization: ORG.7E-1, decisions E1-1 (principal@Campus may gain
the adopted operational HR keys), E1-2 (`hr_officer` becomes
Campus-scopable), E1-3 (`school_admin` stays whole-School). Baseline
`origin/main` = `d0771ebc` (ORG.7E-0).

    adopted (domain `hr`)   hr.employees.view, hr.employees.assignments.view,
                            hr.employees.manage, hr.employees.assignments.manage
    reach                   an Employee with >= 1 CURRENT assignment on an active
                            in-scope Campus (CurrentEmployeeAssignments, SQL EXISTS)
    see                     only CURRENT in-scope assignments; never NULL-Campus,
                            another Campus's, or history
    identity edit / new     EVERY current Campus in scope (and no current NULL-
    assignment              Campus assignment), decided under EmployeePlacementLock
    per-assignment write    that assignment current, on an active in-scope Campus
    onboarding              one atomic operation (Employee + Employment + Assignment)
    whole-School            Profile, history, employment lifecycle, rehire, import,
                            archive/restore, account links, identity-only create,
                            HR configuration, sensitive HR, leave, staff attendance,
                            payroll

**43.1 Access layer (`App\Domain\HR\Application\HrEmployeeAccess`).** The
one place HR reads or targets records under a CampusScope; it never judges
currency itself (CurrentEmployeeAssignments gained `currentAssignmentsOf`,
`isCurrentAssignment`, `currentEmployment` and an optional narrowing
closure on `whereOperationalOnCampuses`).
- `employees()` restricts in SQL (whole School unchanged; none = no rows);
  `employee()` is 404 out of scope, exactly like an unknown or other-School
  id (pinned: identical envelopes); `coversEmployee()`;
  `authorizedCampusSet()` (null = whole School).
- `employment()`: a Campus-scoped actor reaches only the CURRENT employment
  of an Employee in scope. `assignment()`: only a CURRENT assignment on an
  active in-scope Campus.
- `currentAssignments()` (primary first, display only),
  `coversAllCurrentCampuses()` (a read-only preview for page affordances),
  `campusOptions()`, `departmentOptions()` (School-wide or in-scope
  Departments: reference labels, never configuration).
- `writeGuard()` builds `HrEmployeeCampusGuard` for a Campus scope (null for
  the whole School: the unchanged path).
- `requireWholeSchool()`: the ordinary `capability` Gate, which a Campus
  grant never satisfies -- the service-level defence of every declared
  whole-School HR operation. `holdsWholeSchool()` / `scopeFor()` for
  projections.

**43.2 Write-time guard and the placement lock.**
- `EmployeePlacementLock` is THE lock on placement, the `employees` row:
  `forPlacementChange()` FOR SHARE (assignment create/end/primary,
  employment end) and `forEmployeeWrite()` FOR NO KEY UPDATE (identity
  edit, archive, restore, every Campus-scoped "every current Campus"
  decision, and a Campus-scoped set-primary). They conflict, so a scoped decision and a placement change
  never interleave. Employment creation keeps its FOR UPDATE. NO KEY UPDATE,
  never FOR UPDATE, for the scoped write (rule 101's lesson).
- Lock order, every HR placement writer: Employee FIRST -> EmploymentRecord
  -> EmployeeAssignment rows (sorted id) -> Campus rows FOR SHARE
  (ActiveCampus, sorted id). The account link keeps its own order
  (membership -> User -> Employee); no HR writer takes a membership or User
  lock after the Employee lock. Pinned by `HrCampusArchitectureTest` (the
  lock precedes every other lock and write).
- `HrEmployeeCampusGuard` (inside the transaction, after the lock):
  - identity write and new assignment: the Employee's current Campus set is
    non-empty, entirely in scope, with no current NULL-Campus assignment,
    and each of those Campuses is share-locked active; else NOT FOUND (no
    longer reachable) or `HR_WHOLE_SCHOOL_AUTHORITY_REQUIRED` (403, naming
    nothing);
  - new assignment additionally: the CURRENT employment, a start today or
    later, a non-NULL active in-scope destination, and no Department of
    another Campus (checked before the assignment's own message could name
    that Campus); else the generic `HR_ASSIGNMENT_UNAVAILABLE` (422);
  - end: a current in-scope assignment; last day on or after the School's
    today and its start, never later than an end already set or the
    employment's end (only shortening); and every open subordinate whose
    reporting line the end would clear is itself in scope;
  - primary: never demotes an open primary outside scope or NULL-Campus,
    read after the exclusive placement lock;
  - manager: the manager assignment is itself current and in scope.
- Backdated and extended history is whole-School: a Campus-scoped actor
  never starts an assignment or onboards before today, nor ends one before
  today or later than already set.
- Deviation from §42.8, recorded: a current NULL-Campus (School-wide)
  assignment makes the Employee's identity whole-School (stricter than "every
  operational Campus"), because School-wide placement is whole-School
  administration (owner rule "NULL assignment: whole-School only").

**43.3 Directory (`hr.employees.view`).**
- Campus reader: SQL-restricted; a Campus filter outside scope matches
  nobody; Department/Position filters match an in-scope CURRENT assignment
  (not the primary placement, which may be on another Campus); request
  filters only narrow; `include_archived` adds nothing (archived Employees
  are never current).
- Projection: the row's placement is an in-scope current assignment
  (primary first): its Position, Department, Campus and its manager --
  the manager only when the manager's own assignment is current and in
  scope; never another Campus's placement or manager. The field set is unchanged
  (id, number, display name, Position, Department, Campus, manager); no
  personal table is read.
- Whole-School reader: unchanged (primary placement summary).
- Web: Campus options are the scope's Campuses; rows link to the overview
  unless the reader holds whole-School `personal.view`.

**43.4 Operational overview (`app.hr.employees.overview`, new).**
`EmployeeOperationalOverviewService`: entry `hr.employees.view` (Employee in
scope, else 404). Fields: id, employee number, display name, archived flag;
the in-scope placement (Position, Department, Campus, and the manager's
name only when that manager is current in scope); and,
only with `hr.employees.assignments.view`, the CURRENT assignments in that
scope (id, employment id, Position, Department, Campus, primary, dates,
manager name). Never work/personal contact data, addresses, date of birth,
identifiers, qualifications, documents, notes, pay, leave, attendance or
account data; no table holding them is read. The Profile
(`hr.employees.personal.view`) is unchanged and whole-School; its
employment/assignment history tier now checks whole-School
`assignments.view` explicitly.

**43.5 Core manage and onboarding (`hr.employees.manage`).**
- Campus-safe core fields: `full_name`, `work_email`, `work_phone` (web
  edit/update, API PATCH). Rule: every current Campus in scope (43.2). The
  API response omits `userId` for a Campus-scoped caller.
- `EmployeeOnboardingService::onboard()` (web `app.hr.employees.onboard`,
  `.onboard.store`): ONE transaction creating the Employee, an `active`
  Employment and the first, primary, current Assignment.
  - Authority: `hr.employees.manage` AND `hr.employees.assignments.manage`,
    both covering the destination Campus (the two capabilities the separate
    whole-School steps need). principal@Campus therefore does not onboard
    (it holds no `assignments.*`).
  - Field set: full name, work email, work phone, employment type, start
    date (today for a Campus-scoped actor; on or before today for a
    whole-School one), Campus, Position, optional Department. No account link, personal, category, qualification,
    document, note or pay field.
  - The Campus row is share-locked first; the primitives
    (`EmployeeService::createRecord`, `EmploymentService::createRecord`,
    `EmployeeAssignmentService::createRecord`/`setPrimaryRecord`) carry no
    authorization and are callable only from their services and the
    onboarding service (pinned).
  - No capture: a work email already used in the School is refused
    (generically, `HR_ONBOARDING_UNAVAILABLE`, for a Campus-scoped actor;
    the existing duplicate message for a whole-School one). Nothing
    persists on any refusal (pinned per case, including a late
    inactive-Position failure).
  - The API transport is deferred: its `idempotent` route would need an
    all-of floor (manage AND assignments.manage) ahead of the guard (rule
    32), which the C6 contract cannot express. API identity-only create
    stays whole-School.

**43.6 Assignments (`hr.employees.assignments.*`).**
- View: the overview's current-assignment section only (43.4); history
  stays whole-School.
- Manage (owner-approved HIGH-RISK): create (secondary, no capture), end,
  set primary, set manager and manager candidates (current in-scope
  assignments only), web and API, each under 43.2. A Campus actor never
  touches a NULL-Campus, ended or another Campus's assignment, and
  `campus_id` has no update path.
- Move (D-E0-11): DEFERRED. The named move needs decisions on primary
  transfer, the reporting line and Department compatibility across
  Campuses. The same outcome is reachable with the same authority by a new
  assignment plus an end.
- TeachingAssignments: HR assignment create/end do not touch teaching
  ownership (it follows employment coverage, not HR placement). Employment
  end -- the path that ends ownership -- is whole-School, and the Teaching
  participants still check whole-School `assignments.manage`. No academic
  authority is created for HR Campus actors.

**43.7 Declared whole-School operations (24, exact routes).** Identity-only
create (web create/store, API store), archive/restore (web, API), account
link/unlink (API) and the web account-link search/store/remove, import
(web create/store, API), employment store/end/separate (web, API) and
update (API), rehire (web, API). Each carries its ordinary
`capability:hr.employees.manage|.assignments.manage` floor (web routes
gained theirs), no controller-level check, and the service re-checks with
`requireWholeSchool()`. The HR hub is a declared whole-School hub
(`WHOLE_SCHOOL_HUBS['hr']`: view, manage); Campus-scoped readers get the new
all-adopted **Staff directory** navigation entry.

**43.8 Coverage.** C6 (adopted key, `,campus` floor or `authorizeCampusScope`,
HrEmployeeAccess / service method): API employees.index, .update,
assignments.store/.end/.set-primary/.set-manager; web employees.index,
.overview, .edit, .update, .onboard, .onboard.store, assignments.store/.end/
.set-primary/.set-manager/.manager-candidates. The Profile and sensitive
routes are not C6.

**43.9 Role impact (`CampusAdoptionImpactTest`).**
- `principal`: gains `hr.employees.view`, `.manage` in its Campus grants
  (E1-1). Never personal/sensitive HR, documents, notes, qualifications,
  leave, staff attendance, payroll, HR configuration, import, archive,
  rehire or account administration (pinned `HR_CAMPUS_CONFERRED`).
- `hr_officer`: Campus-scopable; a Campus grant confers exactly the four
  adopted keys (E1-2).
- `school_admin`: holds view/manage, never Campus-scopable (E1-3).
- High-risk classification: `hr.employees.assignments.*` and
  `hr.employees.manage`, owner-approved for the guarded subset only.

**43.10 Unchanged.** Self-service and `HrSelfAdministrationGuard` (still
applied in every write); reporting-manager leave approval (ActingEmployee +
`holdManagerOf`); Leave, Staff Attendance and Payroll (no Campus); module
entitlements (HR stays one module; no per-Campus entitlement); the Visitor
host lookup (School-wide, name-only; tightening recorded); Student/SIS,
Fees/Payments; LC-5–LC-10 paused. No new capability, no migration, no
`employees.campus_id`. No HR job exists or was added; onboarding and import
are synchronous.

**43.11 Concurrency (`HrCampusConcurrencyTest`, real two-process).** The
contender is observed blocked on the holder's uncommitted transaction:
- a Campus edit waiting on a new B assignment is refused (sees B); a new
  assignment waits for an edit already proven;
- an edit waiting on the B assignment's end proceeds on the new placement;
  an A-only edit stays refused behind an A+B edit;
- onboarding waiting on a Campus deactivation is refused with no partial
  Employee; a deactivation waits for onboarding already proven;
- a new assignment waiting on a Campus deactivation is refused;
- a scoped new assignment waits for a placement change, no deadlock.

**43.12 Performance and tenancy.** The Campus Directory restriction is one
statement (EXPLAIN on DDEV, 12 Employees: index scans on `employees.school_id`
and `employee_assignments_campus_id_index`, ~2.5 ms); placement is one
batched query. Query counts do not grow with rows or assignments
(`HrCampusPerformanceTest`). No index added (no plan evidence). Every
predicate carries `school_id`, runs in the School's TenantContext, no
`BYPASSRLS`; another School's Employee or Campus never participates
(tested).

**43.13 Security review (ORG.7E-1).** Independent adversarial review of the
candidate: no HIGH finding.
- **Fixed -- MEDIUM M1:** ending an in-scope assignment cleared the
  reporting manager of open subordinates on other Campuses (the closure
  cascade is School-wide). A Campus-scoped end now refuses generically
  unless every such subordinate is in scope (checked under the assignment
  row lock, which a concurrent setManager also takes).
- **Fixed -- MEDIUM M2:** a Campus-scoped set-primary read the open primary
  under only the shared placement lock, so a concurrent whole-School
  set-primary could commit a B primary that it then demoted. The scoped path
  now takes the exclusive placement lock first (two-process race pinned).
- **Fixed -- LOW:** a reporting manager outside the scope is no longer named
  (Directory, overview); Campus writes no longer backdate or extend history
  (assignment start, end extension, backdated onboarding); the lock-order
  text; `school_id` predicates on the assignment row lock and the manager
  candidate Campus check; the Directory Campus filter is lower-cased.
- **Recorded, accepted -- LOW:**
  - **Duplicate work email on a core edit** still answers the existing
    duplicate message to a Campus-scoped editor: a School-wide "address in
    use" signal that does not differ between in- and out-of-scope (rule 101
    holds). Onboarding answers generically.
  - **An inactive Campus is ignored by the identity rule** (§42: an inactive
    Campus places nobody), so an A-only editor may edit someone whose other
    current assignment is on a deactivated Campus.
  - **`HR_WHOLE_SCHOOL_AUTHORITY_REQUIRED`** tells a Campus actor that a
    reachable Employee has a placement beyond their scope, never where.
- **Verified:** the 24 declared operations carry whole-School floors and
  service re-checks; concealment (identical envelopes, web and API); no
  capture; SQL restriction and narrowing-only filters; the minimized
  overview and API response; no deadlock against employment end, teaching
  participants, compensation, account links, rehire or Campus deactivation;
  idempotent `,campus` floors authorize before the guard; tenancy
  predicates; role confinement.

**43.14 Deferred (owner decision needed to reopen).**
- The named Campus move (43.6).
- The API onboarding transport (43.5).
- Historical HR by Campus (timeline and history stay whole-School).
- The Visitor host lookup tightening (§42.8, unchanged).
- Campus-scoped Leave, Staff Attendance and Payroll (out of scope).

## 44. ORG.7F-0 — SIS Campus authorization and lifecycle contract audit (read-only, 2026-10-20)

Owner authorization: a read-only audit only. No implementation, no
`CampusScopeCatalogue` change, no C6 conversion, no Student Profile change,
and LC-5–LC-10 stay paused. Baseline `origin/main` = `d74cd9d6` (ORG.7E-1).

Evidence:
- code reading of the Students, Guardians, Admissions and Campus-adopted
  consumer services, controllers, routes and coverage registry;
- the live DDEV schema (`\d`, `pg_trigger`, privileges) and `EXPLAIN`;
- 68 architecture and guard tests run on DDEV, all passing (Campus guards,
  coverage guard, Student foundation, eligibility, role snapshot, module
  and async contracts).

No production code, test, capability, role or route changed.

    Student identity        = School-wide (no Campus column; unchanged)
    current Campus          = active Student + active Enrollment in the School's
                              active year + active Campus (§38, unchanged)
    Campus SIS authority    = a future adoption of students.view / enrollments.view
                              (F1), then narrow write paths (F2/F3), each
                              behind one StudentAccess layer
    everything else         = whole-School: lifecycle, history, Guardians,
                              rollover, processing authorizations, bulk

### 44.1 Prerequisite verification

**Verified present and guard-pinned:**
- ADR 0076 §33–§43.
- `StudentEnrollmentReadService::constrainToCurrentPlacement()`, the one
  current-placement definition.
- `StudentOperationalCampusResolver`, which is data only, with an EXISTS
  predicate.
- `StudentOperationalEligibility::lockOperationalOn()`, the write-time proof
  (§40).
- `StudentPurposeLookup`, returning `id`, `displayName` and
  `studentNumber`.
- `CampusScopeResolver`, `CampusScopeCatalogue` (with
  `WHOLE_SCHOOL_OPERATIONS` and `WHOLE_SCHOOL_HUBS`), C6 coverage, and
  `CampusAdoptionImpactTest`.
- `ModuleCatalogue` and the async module contract.

**SIS adoption is barred today:**
- `students.`, `enrollments.` and `guardians.` are in
  `CampusAdoptionImpactTest::PROHIBITED_PREFIXES`.
- `academics.` (which includes `academics.subjects.*`) is prohibited too.
- No SIS controller uses the resolver.

### 44.2 Schema reality

- **`students`:** `student_number` (unique per School), `first_name`,
  `middle_name`, `last_name`, `date_of_birth` (NOT NULL, Highly Sensitive),
  and `status` (`active`/`inactive`, no CHECK).
  - There is **no** gender, address, contact, photo, government identifier,
    medical, health, disability or custom-field data.
  - There is no notes column and no Campus column.
  - `Student::$fillable` includes `status` and `school_id`.
- **`student_enrollments`:**
  - Statuses are `active`, `completed`, `withdrawn`, `transferred` and
    `cancelled`, with no CHECK.
  - At most one `active` row per (Student, year).
  - Campus is bound to the Section by `student_enrollments_section_context_fk`.
  - `school_os_app` can still UPDATE every placement column, and
    `sections.campus_id`. No freeze trigger exists (the §35.2 item 6 freeze
    is still open). The FK and the attendance 6-tuple FK make an in-place
    Campus rewrite fail for rows that are referenced, but not for an
    unreferenced row moved together with its Section id.
- **Guardians:**
  - `guardians` holds names and status only.
  - `guardian_contacts` holds email and mobile, encrypted, with an HMAC
    `lookup_hash`.
  - `student_guardian_relationships` carries type plus the `is_primary`
    (one per Student), `is_legal_guardian`, `is_emergency_contact` and
    `is_authorized_pickup` flags.
- **Documents:** `documents.student_id` and `guardian_id` exist, but every
  Student and Guardian owner path throws
  `DocumentOwnerTypeNotSupportedException` (404). There is no route and no
  capability. Student documents are **not activated**.
- **Not built:** Student import, Student bulk create, a Student custom-field
  system, and medical records.
- **Domain events:** none from Students, Guardians or Admissions, and no
  outbox rows. Rollover execution is synchronous (at most 100 items per
  request). No queued job or consumer touches SIS. Retention prunes are
  console-only and lock through `lockExit`.

### 44.3 Capability inventory

14 keys, all `school` scope:

| Key | Holders | Module | Classes | Gate today | Proposed class |
|---|---|---|---|---|---|
| `students.view` | school_admin, principal | student_records (core) | children | Gate (C2) | **A**, with declared whole-School ops (full Profile, history, API show, Guardians list) |
| `students.manage` | school_admin, principal | core | children | C1 on API writes, Gate on web | **H** (split by declared ops): names are Campus-editable (F2); create, number, date of birth, status, account link and Guardian links are whole-School |
| `enrollments.view` | school_admin, principal | core | children | Gate | **A** for the current placement only; history is **E** |
| `enrollments.manage` | school_admin, principal | core | children | C1/Gate | **H**: transfer (F3) and Campus onboarding (F2) only; enroll-existing, complete, withdraw, cancel and closed-year closure are **D** |
| `enrollments.rollovers.view` | school_admin, principal | core | children | Gate (paired) | **D** |
| `enrollments.rollovers.manage` | school_admin | core | **authority**, children | C1/Gate (paired) | **D** |
| `guardians.view` | school_admin, principal | core | children | Gate | **F** (relationship and School-wide identity); never Campus |
| `guardians.manage` | school_admin, principal | core | children | C1/Gate (+ `school.members.manage`, fresh MFA where noted) | **F**; never Campus |
| `students.processing_authorizations.view` | school_admin, principal | core | children | `capability:` + `mfa` | **G** |
| `students.processing_authorizations.manage` | school_admin, principal | core | **authority**, children | `capability:` + `mfa` | **G** |
| `academics.subjects.view` / `.manage` | school_admin, principal | academic_structure (core) | operational, sensitive | Gate / C1 | **C/I**: School configuration bundled with subject-enrollment writes; prohibited, needs a catalogue split (rule 93) before any change |
| `admissions.view` / `.manage` | school_admin, principal, admissions_officer | admissions (optional) | children | `capability:…,campus` (C6) | already adopted pre-conversion (§39); conversion is a declared whole-School op (§44.17) |

- **No dedicated keys exist** for documents, imports, identifiers, Student
  status, history, Data Health or retention. Data Health opens on any of
  five keys; its Student checks need `students.view` + `enrollments.view`.
- **No legal-gated SIS key exists.**
- **MFA appears only twice:** the `mfa` middleware on processing
  authorizations, and the fresh-MFA check on Guardian portal offboarding.
- **New capabilities** are not needed for F1–F3: the HR pattern (§43:
  adoption plus declared whole-School ops plus service-level field rules)
  covers them. A split may be needed only for F4 (§44.17), or if the owner
  wants Campus Guardian contact visibility (§44.13).

### 44.4 Route inventory (120: 75 web, 45 API)

| Group | Web | API | Coverage today |
|---|---|---|---|
| Directory/search | `app.students.index`, `app.enrollments.index` (all statuses) | `students.index`, `enrollments.index`, `guardian-candidates` | C2 |
| Profile/history | `app.students.show`, `.history` | `students.show` (date of birth), `enrollments.show`, `students.enrollments.index` (history), `students.enrollments.current` | C2 |
| Create/update/status | 5 (create, store, edit, update, status) | 3 | C2 web / C1 API |
| Placements | 7 + closed-year ×2 | 5 | C2 / C1 |
| Rollover | 14 | 12 | C2 / C1 (API writes have no in-controller re-check; acceptable under rule 98 C1) |
| Subject enrollments | 6 | 5 (+ roster) | C2 / C1 (`academics.subjects.*`) |
| Guardian links (Student side) | 8 (`students.manage` AND `guardians.manage`) | 5 | C2 / C1 |
| Guardian admin and contacts | 19 | 8 | C2 / C1 |
| Account link | 3 | — | C2 |
| Processing authorizations | 5 (no UI consumer) | — | C1 + `mfa` |
| Data Health | 2 | — | C2 hub |

- **Bindings:** every action resolves `string` ids through
  `findOrFail()` under SchoolScope and RLS, so another School's id is a 404.
  The one exception is processing authorizations, which use implicit
  binding.
- **Navigation:** `students`, `guardians`, `enrollments` and
  `enrollment-rollovers` appear in the People group. The Data Health hub
  opens on any of its five keys.
- **Other consumers that read SIS keys as link or label hints** (all
  `canInSchool`, so they degrade safely to "no link" for a Campus-only
  actor):
  - `SubjectOfferingController`, `AttendanceExceptionController`
    (`students.view`);
  - `OutstandingFeesController`, `DataHealthController`
    (`enrollments.view`);
  - `AdmissionApplicationController:202` reads
    `campusScope('students.view')` for a converted-Student link. It must be
    re-checked when `students.view` is adopted.

### 44.5 Student Directory (decision: Campus-aware, F1)

- **Today.** The web list returns `id`, `studentNumber`, the names and
  `status`, plus `currentPlacement {gradeLevel, section, rollNumber}` when
  the actor holds `enrollments.view`. It is whole-School, 20 per page, with
  no Campus filter. The API list is the same without placement, up to 100
  per page, with an unescaped name `ilike` and an exact number match.
- **Campus contract.** A Campus `students.view` holder sees only Students
  whose CURRENT operational Campus is in scope:
  - `restrictToOperationalCampus()` applied in SQL (EXISTS), before
    pagination;
  - no PHP post-filtering;
  - a request Campus filter narrows only.
- **Whole-School actor:** the existing Directory, unchanged.
- **Stays whole-School:** no current Campus (new, unplaced, between
  placements), inactive Students and leavers.
- **History never decides visibility:** a former-Campus or future-year
  placement never makes a Student visible.
- **Campus row projection (SAFE_OPERATIONAL):** `id`, `studentNumber`,
  display name (first, middle and last), current Campus name, Grade,
  Section and roll number. Status is not needed, because it is always
  `active` in scope.
- **Excluded:** date of birth, every Guardian field, account link,
  history, processing authorizations and any cross-domain data.
- **Separate surfaces.** This is a SIS surface under `students.view`, never
  `StudentPurposeLookup`. The lookup stays the other domains' picker
  (§38.5), and the two must not share code paths that widen either.

### 44.6 Full Profile versus Campus operational overview (decision: separate, F1)

- **Today's Profile (`app.students.show`):**
  - **Identity:** includes date of birth (Highly Sensitive).
  - **Current placement and FULL history:** both gated server-side on
    `enrollments.view`.
  - **Guardians:** names, flags and a **decrypted contact value**.
  - **Lycenza account link.**
  - **Static communication text.**
  - **No cross-domain panels:** no Fees, Attendance, Library, Hostel,
    Transport, Canteen, Exams, LMS, documents or processing
    authorizations. A separate history page projects the audit ledger by
    per-category capability.
- **The full Profile mixes too much for Campus.** It stays whole-School: a
  declared `WHOLE_SCHOOL_OPERATIONS` entry for `students.view`. A Campus
  actor gets a capability refusal there (403), the HR §43 pattern.
- **New Campus-safe overview** (`/app/students/{id}/overview`, C6) contains:
  - `id`, `studentNumber`, display name;
  - CURRENT placement only: academic year name, Campus, Grade, Section,
    roll number, `starts_on`.
  - Out of scope or no current Campus gives the unknown-resource 404.
- **Guardians on the overview.** By default there is no Guardian block in
  F1. An optional block is an owner decision (§44.13).
- **Excluded from the overview:** date of birth, history, account link,
  processing authorizations, documents and every cross-domain panel.
- **Cross-domain data stays on its own domain routes and capabilities.**
  `students.view` must never become a carrier for Fees, Attendance,
  Library, Hostel, Transport, Canteen, LMS, Exams or marks data. Any future
  panel embeds a domain read only after that domain's capability, module
  entitlement and Campus scope pass independently. Marks stay excluded and
  legal-gated.

### 44.7 Sensitive Student data boundary

- **Whole-School / separately gated:**
  - date of birth (Highly Sensitive child data, DATA-CLASSIFICATION.md
    "Children's data specifically"); the owner may later approve it for the overview;
  - Guardian contact values (`guardians.view`);
  - processing authorizations (`mfa`, and their `note` column is never
    returned);
  - the account link;
  - the audit-history projection;
  - enrollment history;
  - documents, once activated (they inherit their own classification and
    need their own capability and review; never `students.view`).
- **Health, identifier and custom fields do not exist.** Any future one
  must arrive with field-level classification metadata. A Campus surface
  never exposes a field by default, and the Highly Sensitive ones need a
  legal review first (DATA-CLASSIFICATION.md health, government
  identifier and document rows).
- **Today's protection is server-side capability checks only.** There is
  no field-level classification; the projections above are the only
  control. UI hiding is never relied on.

### 44.8 Current and historical enrollment access

- **CURRENT** (the `active` row in the active year, Campus in scope) may be
  Campus-visible:
  - the overview placement;
  - `students.enrollments.current`;
  - `app.enrollments.index` and API `enrollments.index`, restricted in SQL
    to current in-scope rows for a Campus actor;
  - `enrollments.show`, a 404 unless the row is that current in-scope row.
- **HISTORICAL** stays whole-School in v1:
  - prior, closed, future and draft rows, and transferred, withdrawn,
    completed and cancelled rows;
  - the Profile history panel;
  - `students.enrollments.index`;
  - non-current rows in the directory.
- **History is never a reason for access.** Old rows never grant access to
  a former Campus, a current grant never exposes School-wide history, and
  history is never rewritten to a destination Campus.
- **A historical Campus contract** (a former Campus reading what happened
  on it, keyed by stored Campus, §37/§41) can be added later if the owner
  needs it. None is proposed now.

### 44.9 Student identity edits (F2)

| Field | Campus READ | Campus EDIT | Reason |
|---|---|---|---|
| first, middle, last name | yes | yes (owner approval) | operational correction; School-wide effect is benign |
| `student_number` | yes | **no** | School-wide identifier; uniqueness is an oracle (§44.20 F-9); external references |
| `date_of_birth` | no (default) | **no** | Highly Sensitive; legal/age effects (`StudentAge`, processing authorizations) |
| `status` | — | **no** | lifecycle (§44.15) |
| account link | no | **no** | identity and access administration |

- **Authority:** `students.manage` resolved through `CampusScopeResolver`,
  plus the Student's ONE current operational Campus in scope, decided in
  the write transaction under the StudentAccess lock (§44.18).
- **Refusals:**
  - no current Campus (inactive, leaver, unplaced, future-only) refuses
    with the generic unknown envelope;
  - an out-of-field change (number, date of birth) by a Campus actor
    refuses generically.
- **Whole-School:** unchanged.
- **Mass assignment:** the service, not only the controller, must whitelist
  fields; `status` is mass-assignable today (F-7).

### 44.10 Creation, onboarding and capture (F2)

- **Today:**
  - `StudentService::create` creates a Student only.
  - `enroll()` adds a placement for ANY existing Student. It does not check
    whether the Student, Section, year or Campus is active (a documented
    deferral in `STUDENT-ENROLLMENT.md`).
  - Duplicates are detected only by the `(school_id, student_number)`
    unique index; there is no name or date-of-birth matching.
- **Capture risk (P-3).** For a whole-School actor this is correct. Under
  Campus authority, `enroll()` on an existing unplaced, inactive, leaver or
  other-Campus Student is a **capture/transfer bypass**. The services
  authorize nothing, so a Campus path must never reach `enroll()` for an
  existing Student.
- **Campus onboarding** = one named atomic operation:
  - It creates a NEW Student and their active-year Enrollment in an active,
    in-scope Section.
  - There is never an intermediate Campus-less Student under Campus
    authority.
  - **Authority:** `students.manage` AND `enrollments.manage`, both
    resolving the destination Campus.
  - **Destination:** the Section's Campus is in scope and ActiveCampus is
    share-locked; the year is the School's active year (no draft-year
    intake for Campus actors).
  - **Minimum fields:** names, date of birth (write-only for the Campus
    actor), student number (or a server-generated number, owner decision),
    Section and roll number.
  - **Duplicate student number:** generic `STUDENT_ONBOARDING_UNAVAILABLE`.
    Never "already in use", and never a hint about the other Student.
  - **Guardians:** whole-School by default; no Guardian created or linked
    in the Campus path (§44.13).
  - **Transaction and audit:** one transaction, audit events
    `student.created` plus `student_enrollment.created` (which already
    carries `campusId`).
- **Never for a Campus actor:**
  - attaching an existing Student (unassigned, inactive, leaver, or in
    another Campus) through "create enrollment". That is transfer (§44.11)
    or whole-School re-entry (§44.15).
  - Admissions-style identity matching, which would become a cross-Campus
    Student search.

### 44.11 Campus transfer (F3)

- **The named operation already exists.** `transferPlacement()` works
  within one year and one grade:
  - it ends the source row as `transferred` and inserts a new row;
  - the Campus follows the target Section;
  - it is never a `campus_id` PATCH.
  - Lock order today: both Sections FOR UPDATE in id order → source
    Enrollment FOR UPDATE → a conditional UPDATE → INSERT (FK KEY SHARE on
    the Student, Campus and year).
- **Future Campus authority:**
  - `enrollments.manage` with BOTH the locked source placement's Campus AND
    the target Section's Campus in scope;
  - whole-School keeps today's behaviour;
  - refusal is one generic envelope (no "busy elsewhere", no destination
    detail).
- **Missing today (P-2), required for the Campus path:**
  - Student active, checked under lock;
  - target Section `active`;
  - target Campus active, share-locked through `ActiveCampus` (a transfer
    to an inactive Campus currently yields a placement with no operational
    Campus);
  - the audit carrying `fromCampusId`/`toCampusId`; today it carries
    Section ids only.
- **Side effects (verified; no events, nothing reacts):**

| Item | Effect of a cross-Campus transfer today | Contract |
|---|---|---|
| Section, Grade | new row; grade change refused | unchanged |
| Elective subject enrollments | stay `active`, anchored to the `transferred` row | **owner decision:** end, re-anchor or refuse while active (F3) |
| Attendance, marks, papers history | untouched (stored Campus / enrollment context) | never rewritten |
| Library loans | unchanged (loan Campus frozen at checkout, §41) | return follows loan Campus |
| Hostel residency | stays active on the source Hostel | **owner decision:** refuse a Campus-scoped transfer while active, or require whole-School (no cross-Campus boarding for Campus staff, §40.1) |
| Transport assignment | stays active on the source route | same decision as Hostel |
| Canteen | orders keep Outlet Campus; future orders follow eligibility | unchanged |
| Fees/charges | untouched (charge-year attribution, LC-4) | never re-attributed (§44.24) |
| Guardians, documents, teaching | relationship-based / not activated / Section rosters by date | unchanged |

- **Concurrency (verified, no cycle today):**
  - Eligibility consumers wait on the source Enrollment FOR UPDATE and then
    refuse, because the new row is outside their snapshot.
  - A future Campus transfer that also locks the Student **must take it
    before the Sections and Enrollment**, in this order:
    1. active year FOR SHARE;
    2. Student FOR NO KEY UPDATE;
    3. Sections in id order;
    4. source Enrollment FOR UPDATE;
    5. target Campus via `ActiveCampus`.
  - Locking the Student after the Enrollment would deadlock against
    Hostel/Transport `assign()`, which hold the Student FOR NO KEY UPDATE
    before taking the eligibility Enrollment FOR SHARE.
  - FOR UPDATE on the Student stays banned (rule 101, §40.5).
  - No School-wide lock is introduced.
- **Before F3 (P-4):** the owner should decide on freezing placement
  columns, i.e. the open §35.2 item 6 (a trigger refusing UPDATE of
  `section_id`/`campus_id`/`academic_year_id`/`grade_level_id`/`student_id`
  on `student_enrollments`, and of `sections.campus_id` once a Section has
  enrollments).

### 44.12 Promotion and rollover (decision: whole-School in v1)

- **Rollover creates target-year rows** whose Campus comes from the target
  Section:
  - Execution is synchronous and capped.
  - It re-checks snapshots for drift, but **not** Student, Section or
    Campus active status.
  - The dry run includes inactive Students.
  - The item path locks the source Enrollment FOR UPDATE before the target
    Section (an inversion of the documented Section-before-Enrollment
    order; no cycle found; F-8).
- **No current Campus authority over future-year placement.** A future-year
  placement is not current Campus authority, and today's Campus does not
  confer management of next year's placement.
- **Both keys stay whole-School.** `enrollments.rollovers.*` are
  whole-School, and `.manage` is classed `authority`. Every rollover route
  that also checks `enrollments.view`/`.manage` must be declared whole-School
  if those keys are adopted (§44.19).

### 44.13 Guardians

**Identity** is School-wide and relationship-based, never Campus-owned:
- 12 of 24 DDEV Guardians relate to more than one Student;
- `GuardianStudentScope` is unchanged;
- Guardian search, profile, contacts, status, portal invitation and
  offboarding, account link and communication consent stay whole-School
  under `guardians.*` (class F);
- the Guardian name search and the contact-candidate lookup (an HMAC
  contact-to-identity oracle) are never Campus-reachable.

**Relationship mutation** (link existing, create-and-link, type and flags,
primary, unlink) needs `students.manage` AND `guardians.manage` today:
- It stays whole-School in v1. Its effects reach Students on other
  Campuses: a shared Guardian, portal visibility through
  `is_legal_guardian`, and processing-authorization evidence.
- A Campus actor creating Guardians or attaching existing Guardians is
  **not proposed**.

**Guardian data through a Campus Student:**
- F1 shows none.
- An optional, purpose-minimized block (owner decision D-4) would show:
  - relationship type;
  - Guardian display name;
  - `isPrimary`/`isEmergencyContact`/`isAuthorizedPickup`;
  - **no contact value**.
- An emergency phone for Campus staff would be a separate, explicitly
  approved projection (possibly a new key), never `guardians.view` in
  Campus.

**Prerequisite P-1 (F-1):**
- Today's Profile returns the decrypted Guardian contact under
  `students.view` alone. That contradicts
  `docs/modules/STUDENT-GUARDIAN-IDENTITY.md:472` (contact information =
  `guardians.view`), and `StudentGuardianLinkUiTest:76` pins the current
  behaviour.
- It is latent today, because both holders also hold `guardians.view`.
- It must be corrected (contact value only with `guardians.view`, and an
  inactive primary never chosen) before any `students.view` adoption.

### 44.14 Documents, custom fields, bulk work and imports

- **Documents:** not activated for Students or Guardians. When activated
  they need their own capability, classification per document, and their
  own review. They are never Campus-adopted through `students.view`.
- **Custom fields:** none exist. The future contract is field-level
  classification (`safe_operational` / `sensitive` / `highly_sensitive`),
  and a Campus projection must not show any field by default.
- **Bulk operations:** rollover bulk decisions, closed-year placement
  closure and rollover execution all stay whole-School. No Campus bulk
  operation is proposed for v1.
- **Imports:** there is no Student import. A future import stays
  whole-School in the first SIS Campus release.

### 44.15 Lifecycle: status, withdrawal, leavers and reactivation (decision: whole-School in v1)

**Status writers:**
- `StudentService::changeStatus()` sets `active`/`inactive`. It takes no
  explicit lock, but its UPDATE row lock does conflict with eligibility's
  FOR SHARE, so it serializes (§40.3 holds).
- Its audit `previousStatus` is read from memory, and it audits even when
  nothing changed (F-6).
- Inactivation does not end placements, residency or assignments; Data
  Health reports the result.
- No other writer exists besides `create()`. Retention deletes rather than
  writes.

**Withdrawal, leavers and re-entry:**
- `complete`, `withdraw` and `cancel` are terminal; re-entry is a new row.
- All of these remove the current Campus, so they stay **whole-School**.
  That covers status change, withdrawal, completion, cancellation,
  closed-year closure, reactivation and re-entry.
- A Campus "request leaver/withdrawal" workflow is a possible later
  approval-pattern feature (§16), not proposed.

**No Campus path for any Student without a current Campus:**
- This covers new-before-placement, inactive, leaver, withdrawn, between
  placements, future-only and history-only Students.
- No ordinary update or enrollment path can capture them; reactivation and
  any later placement are whole-School named operations.

**Race recorded (F-5, LOW, pre-existing, whole-School path):**
- Hostel and Transport `assign()` check `$student->isActive()` on the
  pre-transaction model and do not re-check it under their Student lock.
- A concurrent inactivation can therefore commit between the check and the
  lock.
- The Campus-scoped path is safe (the §40 guard).

### 44.16 Concealment, stale pages and direct access

- **Campus operational surfaces** (Directory row, overview, current
  enrollment, F2/F3 writes): a Student or Enrollment outside the scope, or
  with no current Campus, answers exactly as an unknown id does: 404 on
  reads, and one generic envelope on writes. This is the convention of
  §34 and §43.
- **Whole-School surfaces** (full Profile, history, lifecycle, Guardians):
  a capability refusal (403) from the whole-School floor, the same for every
  Student, so nothing is revealed.
- **A Student who moves out of scope:**
  - Every Campus write re-decides the current Campus inside its transaction
    through StudentAccess (§44.18). A stale page, a serialized Campus id or
    a UI Campus value never authorizes anything.
  - Once Campus A's actor sees the transfer committed, they get the
    unknown envelope.
- **Malformed ids:** the web `{student}` is UUID-constrained (404). The API
  has no equivalent guard on Students routes (HR has a pinned one), so a
  malformed id is likely a 500 (F-12, not reproduced).

### 44.17 Admissions conversion (decision: WHOLE_SCHOOL_ONLY in v1)

- **Conversion is one transaction under `admissions.manage` alone:**
  1. Application FOR UPDATE;
  2. Student create;
  3. Guardian instruction (create, which runs a School-wide contact-match
     oracle, or link-existing, which needs a School-wide Guardian pick);
  4. enroll (no ActiveCampus re-check);
  5. converted;
  6. fee selection.
- **Why it stays whole-School:** a Campus conversion would need
  - generic duplicate and contact-match semantics;
  - no School-wide Guardian pick;
  - an ActiveCampus share lock;
  - a decision that `admissions.manage` alone may create a Student (today's
    trusted seam) when used from a Campus.
- **Re-evaluate as F4** once F2 onboarding exists (it is the same shape:
  new Student plus active-year placement in an in-scope Campus). The
  Campus-scoped part would be "convert into a Section of the application's
  own Campus" with Guardian creation from application data only. That may
  require a split or a whole-School Guardian step.

### 44.18 Proposed `StudentAccess` (Students-owned; not built)

One access class composes the §38 and §40 foundations, so controllers never
compute Campus. Responsibilities:
- `restrictToScope(Builder<Student>, School, CampusScope)` delegates to
  `restrictToOperationalCampus()` (SQL EXISTS; whole-School is a no-op;
  none matches nothing).
- `restrictEnrollmentsToScope(Builder<StudentEnrollment>, …)` returns only
  current in-scope placements for a Campus actor.
- `findInScope(School, CampusScope, studentId)` returns the Student, or the
  unknown-envelope 404.
- `lockedWriteTarget(School, CampusScope, studentId)` runs inside the write
  transaction. It takes the active year FOR SHARE, then the Student FOR NO
  KEY UPDATE, then the current Enrollment FOR SHARE, then `ActiveCampus`,
  and returns the locked Student plus the current placement only when the
  Campus is in scope; otherwise a generic refusal. It extends §40's order
  with an exclusive Student lock in the same position.
- `lockedTransferTarget(…, sourceEnrollmentId, targetSectionId)` applies
  §44.11's order and the dual-scope rule.

Rules for it:
- It never carries an `authorized` flag or a Campus id across a request or
  job.
- `CampusScope` comes only from the resolver.
- An architecture guard should pin that SIS controllers never call the
  resolver, `currentFor`, or Enrollment storage for authorization.

### 44.19 C6 impact and declared whole-School operations (proposed, not declared)

**C6 candidates:**

| Wave | Route(s) | Capability | Campus source | Restriction | Write re-check |
|---|---|---|---|---|---|
| F1 | `app.students.index`, API `students.index` | `students.view` | current placement | `StudentAccess::restrictToScope` | — |
| F1 | new `app.students.overview` | `students.view` (+ `enrollments.view` for the placement block) | current placement | `findInScope` (404) | — |
| F1 | `app.enrollments.index`, API `enrollments.index`, `enrollments.show`, `students.enrollments.current` | `enrollments.view` | the row's Campus, current rows only | `restrictEnrollmentsToScope` | — |
| F2 | `app.students.edit/update`, API `students.update` | `students.manage` | current placement | `findInScope` | `lockedWriteTarget`; names only |
| F2 | new onboarding (web first) | `students.manage` + `enrollments.manage` | target Section | in-scope Section options | ActiveCampus + active year, generic |
| F3 | `app.enrollments.transfer.create/store`, API `enrollments.transfer` | `enrollments.manage` | source AND target | `lockedTransferTarget` | dual scope under locks |

**Whole-School operations to declare when a key is adopted** (exact route +
capability + reason, `CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS`):
- **`students.view`:**
  - `app.students.show` (full Profile: date of birth, history, Guardians,
    account link);
  - `app.students.history`;
  - API `students.show` (date of birth);
  - API `students.guardians.index` (relationships);
  - the converted-Student link at `AdmissionApplicationController:202` must
    switch to the whole-School check or to the overview.
- **`enrollments.view`:**
  - API `students.enrollments.index` (history);
  - all 26 rollover routes, which check it together with the rollover
    keys, plus the `data-health` hub and its Student checks (extend
    `WHOLE_SCHOOL_HUBS['data-health']`).
- **`students.manage`:**
  - `app.students.create/store`, API `students.store` (standalone create);
  - `app.students.status`, API `students.status`;
  - the 3 account-link routes;
  - the 8 web and 5 API Guardian-relationship routes (also gated on
    `guardians.manage`).
- **`enrollments.manage`:**
  - `app.students.enrollments.create/store`, API `students.enrollments.store`
    (existing-Student enroll = capture);
  - complete, withdraw and cancel (web and API);
  - the 2 closed-year placement routes;
  - every rollover write.

### 44.20 Security and privacy findings (recorded, NOT fixed; each needs owner approval)

**F-1 — MEDIUM, latent, a prerequisite for F1.** The Profile shows a
decrypted Guardian contact under `students.view` alone, and may choose an
inactive primary contact (§44.13).

**F-2 — MEDIUM, a prerequisite for F2/F3 Campus paths.** `enroll()`,
`transferPlacement()`, rollover and conversion check neither the active
Student, the target Section, nor ActiveCampus. This is documented as a
whole-School deferral.

**F-3 — MEDIUM, a prerequisite for F2.** Services authorize nothing, so
`enroll()` on an existing Student is a capture path for any future Campus
caller.

**F-4 — LOW–MEDIUM, owner decision for F3.** A cross-Campus transfer leaves
active electives anchored to the transferred row, and leaves Hostel and
Transport assignments on the source Campus. No event is emitted. The
transfer audit carries no Campus ids.

**F-5 — LOW, pre-existing.** The whole-School Hostel/Transport `assign()`
student-active check is not re-checked under the lock (§44.15).

**F-6 — LOW.** `changeStatus()` audits a stale `previousStatus` and audits
no-ops. The same applies to Guardian `changeStatus()` and to
`AcademicYearService::close()`.

**F-7 — LOW.** `Student::$fillable` (and `Guardian::$fillable`) include
`status` and `school_id`; only controller whitelists protect them. This is
the rule 97 pattern for Schools.

**F-8 — LOW.** Rollover item execution:
- locks the source Enrollment before the target Section, with an unlocked
  target-Section validity read;
- the dry run admits inactive Students.
No deadlock cycle was found. While the source year is active, the
FOR UPDATE on the source row blocks eligibility consumers for that Student
for the item transaction.

**F-9 — LOW.** `DuplicateStudentNumberException` echoes the number (an
existence oracle). The web Students page masks it; the Admissions web
conversion passes it through.

**F-10 — LOW (R8 continued).** These LIKE searches are unescaped:
- the API Students index;
- the web and API Guardian index;
- the Student "add Guardian" search;
- the Communications audience search;
- the account-link searches.

**F-11 — LOW (R8 continued).**
- The `GuardianStudentScope` docblock still claims withdrawn/transferred
  exclusion.
- `setPrimary` after a concurrent unlink demotes the old primary and
  promotes nothing.
- Web `linkNew` uses two transactions, so it can leave an orphan Guardian.
- Contact `deactivate` is not transactional.
- Candidates are not filtered by `is_active`.

**F-12 — LOW, not reproduced.** The Students API has no malformed-UUID
guard.

**Verified safe:**
- RLS and SchoolScope on every SIS table, with no BYPASSRLS path.
- Status, transfer, Campus and year writers all serialize against
  `lockOperationalOn()` (§40.3 confirmed).
- No SIS job carries authority.
- No audit row carries a name, date of birth or contact value.
- `student_enrollment.created` and `admission_application.converted`
  already carry `campusId`.

### 44.21 Role impact

**Holders:**
- `school_admin`: all 14 keys; never Campus-scopable (access
  administration).
- `principal`: 13 keys (not `enrollments.rollovers.manage`); Campus-scopable.
- `admissions_officer`: only `admissions.*`.
- No other role holds a Student key: front_office, teacher, accountant,
  cashier, librarian, hostel_warden, transport_coordinator,
  canteen_operator, stores_officer, hr_officer and
  communications_coordinator all hold none. No registrar, class-teacher or
  academic-coordinator role exists.

**What `principal@Campus` would gain:**

| Family | Gain |
|---|---|
| Directory and overview | F1: in-scope current Students |
| Current enrollment read | F1 |
| Identity edits | F2: names only |
| Onboarding | F2: new Students into in-scope Sections |
| Transfers | F3: only between in-scope Campuses |
| Enrollment history | nothing (declared whole-School) |
| Lifecycle (status, withdraw, complete, cancel, reactivate) | nothing |
| Rollover | nothing |
| Guardians | nothing; the optional minimized block only if D-4 is approved |
| Processing authorizations | nothing |
| Documents | nothing |
| Bulk work | nothing |

- Each wave must state this in `CampusAdoptionImpactTest`. F2/F3 writes
  are high-risk (children data mutation) and need explicit owner approval.
- **No unexpected activation.** No other Campus-scopable role (front
  office, admissions officer, warden, librarian, transport, canteen,
  stores, HR officer) gains any Student authority from F1–F3, because none
  holds the keys. The new holders after adoption are principal only.
- **`school_admin` is unchanged** (whole-School).

### 44.22 Data audit (DDEV, 2026-10-20)

- **Population:** 2 Schools with one Campus each (the demo multi-Campus case
  is two Schools). 39 Students, all active. 36 have a current placement
  (2026-27, Main Campus); 3 annexe Students have no enrollment at all.
- **No lifecycle history yet:**
  - no prior-year or draft-year rows;
  - no transfers;
  - no Student with more than one Campus in their history;
  - no inactive Students;
  - no inconsistent states (no inactive Student with an active placement,
    no active placement in a closed year, no unknown statuses).
- **Guardians:** every placed Student has 2 Guardians (mother and father,
  all flags true); 12 of 24 Guardians relate to more than one Student.
  There are 48 active contacts and 2 Student account links.
- **Other data:** no Student documents, no processing authorizations, one
  draft rollover plan, 12 subject enrollments.
- **Consequence:** F1–F3 need seeded multi-Campus, transfer and leaver
  fixtures in their own tests. The DDEV demo alone does not exercise them.

### 44.23 Performance, tenancy and RLS

- **Query plan.** On DDEV, the Campus Directory predicate (Student EXISTS a
  current active-year placement on an active in-scope Campus, ordered by
  name, limit 25) plans as a semi-join that uses
  `student_enrollments_one_active_per_student_year`. It is one bounded
  query, with no per-Student resolver call.
- **Indexes.** The data is too small to justify one. F1 must `EXPLAIN` at a
  realistic scale (thousands of Students across several Campuses) before
  proposing any index, for example a partial `(academic_year_id, campus_id)
  WHERE status = 'active'`.
- **Tenancy is unchanged.** School stays the tenant: School RLS AND Campus
  application scope, no Campus RLS, no organisation or branch id, no
  admin or BYPASSRLS runtime path. Every StudentAccess query carries an
  explicit `school_id` predicate.

### 44.24 Fees and Payments dependency

**Before a Fees/Payments Campus phase, SIS must have:**
- F1 (the canonical StudentAccess read restriction, so a Finance Student
  view can compose it);
- F-1 fixed;
- the transfer contract frozen (F3 or at least §44.11's lock order).

**Finance Campus decisions never use the current Student Campus.** They use
the record's own context:
- charges, concessions, adjustments, payments, allocations and balances
  use charge-year attribution (`campusAttribution()`, LC-4) or the stored
  run, assessment or structure Campus;
- leavers keep historical finance attribution;
- an ambiguous attribution means whole-School only;
- a transfer never re-attributes a charge or payment;
- the ledger and journals stay whole-School.

### 44.25 Reference parity (for ORG.9; nothing copied)

The reference workbook and screenshots name these functions: Student
inactivate/reactivate, branch transfer, bulk branch transfer, transfer
certificates and Student directories. These parity questions are open for
ORG.9:
- whether a bulk Campus transfer is wanted (v1: no);
- whether a transfer or leaving certificate is a document type (Documents
  are not activated);
- whether Campus staff should *request* a leaver or reactivation for
  whole-School approval.

Reference Branch role permissions are not adopted.

### 44.26 LC-5–LC-10

All stay paused by owner direction. Mapping:

| Slice | SIS Campus impact |
|---|---|
| LC-5 (applicant edit ADM-14, undo deactivation) | Applicant identity edit is a declared whole-School Admissions op (§39); reactivation in Campus-adopted domains must keep their ActiveCampus and Campus-fixity rules. Independent of SIS. |
| LC-6 (attendance daily) | ATT-07 per-Student history follows stored record Campus (§37). Independent. |
| LC-7 (Data Health exits) | Whole-School hub; touches §44.19's hub declaration. Independent. |
| LC-8 (ENR-01 Section pickers, STU-14 inactivate confirmation) | Touches SIS surfaces that F2/F3 change. ENR-01's restriction of transfer targets to the same year and grade is compatible with F3; do it before F3, or fold it into F3. |
| LC-9 (operations edits) | Each domain's existing access class applies. Independent. |
| LC-10 (responsive) | Independent. |

None needs to wait for SIS Campus authorization. Their order is the owner's
choice.

### 44.27 Proposed canonical SIS Campus contract (summary)

| Surface | Contract |
|---|---|
| Student Directory | Campus-aware (current in-scope only, SQL), minimal projection (§44.5) |
| Operational overview | new Campus-safe page, current placement only (§44.6) |
| Full Profile | whole-School (declared) |
| Current enrollment | Campus-visible when in scope |
| Historical enrollment | whole-School |
| Identity edits | names only, current Campus in scope, under lock; number, date of birth and status whole-School |
| Creation | Campus onboarding = atomic new Student + active-year placement in scope; standalone create whole-School; generic duplicate |
| Existing-Student enrollment | whole-School (capture) |
| Campus transfer | named op, source AND destination in scope, locked, generic refusal, side-effect decisions (§44.11) |
| Promotion and rollover | whole-School |
| Withdrawal, leaver, status, reactivation, re-entry | whole-School |
| Guardians | School-wide identity; relationship and contact whole-School; optional minimized block (D-4) |
| Documents and custom fields | not activated / none; future field-classified and separately gated |
| Bulk work and imports | whole-School |
| Processing authorizations | whole-School, `mfa`, legal/privacy |

### 44.28 Recommended waves and the owner decisions they need

- **ORG.7F-1 (recommended next):**
  - fix F-1 and F-12;
  - add StudentAccess (read half);
  - adopt `students.view` + `enrollments.view` for the Campus Directory,
    the overview and current enrollment reads;
  - declare whole-School operations (§44.19) and Campus nav (the
    all-adopted-only rule);
  - make the impact statement (principal@Campus);
  - scale EXPLAIN.
  - No writes.
- **ORG.7F-2:**
  - fix F-2 and F-3 for the Campus paths;
  - add StudentAccess `lockedWriteTarget`;
  - name edits;
  - atomic Campus onboarding (web first, like HR §43.5);
  - adopt `students.manage` and `enrollments.manage` with every non-adopted
    operation declared;
  - fix F-7 and F-9 on the touched paths.
- **ORG.7F-3:**
  - P-4 (placement freeze, owner decision);
  - the Campus transfer (dual scope, §44.11 lock order, Campus ids in the
    audit);
  - the F-4 side-effect decisions;
  - lifecycle stays whole-School unless separately approved.
- **ORG.7F-4 (optional):** Campus Admissions conversion, only after F2
  proves onboarding, with generic Guardian and duplicate semantics.

**Owner decisions needed:**
- D-1: is date of birth on the Campus overview? (default no)
- D-2: are names Campus-editable? (default yes, F2)
- D-3: is the student number caller-supplied or server-generated on Campus
  onboarding?
- D-4: is there a minimized Guardian block on the overview, and is any
  contact value shown?
- D-5: Hostel, Transport and elective handling on a Campus-scoped transfer.
- D-6: the placement-column freeze (P-4).
- D-7: whether principal@Campus may gain F2/F3 writes (high-risk).

Implementation of any wave needs explicit owner approval. The regression
counter is unchanged (0/5); this audit is documentation-only.

## 45. ORG.7F-1 — SIS Campus read surfaces (as built, 2026-10-20)

Owner authorization: ORG.7F-1 only. Baseline `origin/main` = `c603f858`
(ORG.7F-0, §44).

**Owner decisions recorded:**
- **F1-D1:** no date of birth on any Campus Student surface.
- **F1-D2:** no Guardian block on the Campus overview. That means no name,
  relationship or contact value. A future minimized Guardian projection
  needs its own review.
- **F1-D3:** future Campus onboarding never takes a user-chosen
  School-wide Student number.
- **F1-D4:** name edits are approved in principle for F2 only.
- **F1-D5:** a Campus transfer never auto-moves Hostel, Transport or
  electives (§45.9).
- **F1-D6:** the placement-column freeze is required before F3.
- **F1-D7:** principal@Campus gains READS only in F1. F2/F3 writes come
  only under their own reviewed high-risk contracts.

    adopted (domain `students`)   students.view, enrollments.view
    reach                         a Student whose CURRENT operational Campus is in scope
                                  (active Student + active Enrollment in the School's
                                  active year + active Campus; SQL EXISTS)
    see (Campus)                  id, studentNumber, names; current Campus, Grade,
                                  Section, roll number (enrollments.view reach)
    never (Campus)                date of birth, Guardians and contacts, history,
                                  account link, processing authorizations, other
                                  domains' data, any write

**45.1 F-1 closed: Guardian contact values.**
- **Root cause.** `StudentController::show` eager-loaded
  `guardianRelationships.guardian.contacts` and serialized the decrypted
  primary (else first active) contact under `students.view` alone.
  `guardians.view` is the capability that covers Guardian contact
  information (`docs/modules/STUDENT-GUARDIAN-IDENTITY.md`).
- **Fix: the value is sent only to a `guardians.view` holder.** Without it,
  the contacts are not loaded at all, so `contact` is `null` and no value
  exists anywhere in the response. This holds for whole-School readers
  too, and no Guardian access was widened.
- **Fix: only an ACTIVE contact is ever chosen.** That is the active
  primary, else the first active one; a deactivated primary is never
  chosen.
- **Unchanged:**
  - The relationship flags and the Guardian name stay on the Profile
    (documented, Phase 1A.6).
  - The API `students/{id}/guardians` never carried a contact value; now
    pinned.
  - The Campus Directory and overview carry no Guardian data at all
    (F1-D2), even for an actor who also holds `guardians.view`.
- **Pinned by:** `StudentProfileGuardianContactTest`.

**45.2 F-12 closed: malformed ids.** Reproduced: a malformed `{student}`,
`{enrollment}` or `{relationship}` id on the JSON API reached PostgreSQL as
an invalid-UUID query (22P02) and aborted the request's transaction. It is
now NOT FOUND before any query, the HR convention (`abort_if(!
Str::isUuid(...), 404)`), in:
- the Student detail, update and status actions;
- the Guardian relationship index, link, update, primary and unlink
  actions;
- the Enrollment history, store and lifecycle actions;
- every StudentAccess read.

Pinned by `StudentApiMalformedIdTest`: 13 routes × 3 malformed shapes, plus
a well-formed unknown id. Web routes were already UUID-constrained (LC-1).
After the security review the guard was extended to every remaining
path-id lookup in the Students and Guardians API controllers: Guardian,
contact, subject-enrollment, offering roster and the rollover
plan/item/mapping/subject-mapping routes.

**45.3 StudentAccess (READ side only).**
`App\Domain\Students\Application\StudentAccess` composes the §38
foundation and never judges currency itself:
- `students()`: whole School = every Student; Campus set =
  `restrictToOperationalCampus()` (SQL EXISTS); none = nothing.
- `student()`: NOT FOUND for a malformed id, an unknown id, another
  School's Student, and one out of scope.
- `enrollmentDirectory()` and `enrollment()`: whole School = every row;
  Campus set = THE current placement (`constrainToCurrentPlacement()`) on
  an in-scope Campus of a Student who is operational in scope.
- `currentPlacements()`: one SQL query per page. For a Campus reach it
  returns only the current in-scope placement of a Student operational in
  scope, the same rule as the directory.
- `placementInYear()`: whole-School named-year reads; nothing for any
  other scope.

It has no write method (`lockedWriteTarget`/`lockedTransferTarget` are
F2/F3) and no cache, so the next request after a transfer sees the new
Campus (pinned).

**45.4 Campus-aware routes (C6, 7).**

| Route | Key | StudentAccess |
|---|---|---|
| `app.students.index`, `api.v1.schools.students.index` | students.view | `students` |
| `app.students.overview` (new) | students.view | `student` |
| `app.enrollments.index`, `api.v1.schools.enrollments.index` | enrollments.view | `enrollmentDirectory` |
| `api.v1.schools.enrollments.show` | enrollments.view | `enrollment` |
| `api.v1.schools.students.enrollments.current` | enrollments.view | `student` (+ current placements) |

- **Where they live:** new dedicated read controllers
  (`App\…\StudentDirectoryController`, `App\…\EnrollmentDirectoryController`,
  and the Students API pair). The existing controllers keep the
  whole-School actions. The API enrollment presenters are shared by trait
  (`PresentsStudentEnrollment`).
- **Campus reader behaviour:**
  - Directory rows carry only `id`, `studentNumber` and the names, plus
    `currentPlacement {campus, gradeLevel, section, rollNumber}` when the
    `enrollments.view` reach covers it.
  - Status is omitted (always active), and rows link to the overview.
  - Filters narrow only: `status=inactive`, another Campus, another year
    or another Student all find nothing.
  - The enrollment filter options are cut to the active year, in-scope
    Campuses and their Sections.
  - The current placement for another year is `null`.
- **Search input is literal.** Name search uses `StudentSearch` (LIKE
  escaped): the API name filter was unescaped `first/last ilike` and now
  matches every name word in any name field (F-10, in the Directory only).
- **Overview fields:** `id`, `studentNumber`, the names and the current
  placement. `canOpenProfile` is true only for whole-School readers.

**45.5 Declared whole-School-only operations (10) and one hub.**
- **`students.view`:**
  - `app.students.show` (full Profile);
  - `app.students.history`;
  - `api.v1.schools.students.show` (date of birth);
  - `api.v1.schools.students.guardians.index`.
- **`enrollments.view`:**
  - `api.v1.schools.students.enrollments.index` (full history);
  - the 5 rollover reads (web index/show, API index/show/items). Those
    also keep `capability:enrollments.rollovers.view`.
- **Data Health:** `WHOLE_SCHOOL_HUBS['data-health']['enrollments.view']`.
- **The route floor is the check.** Each operation carries the ordinary
  `capability:<key>` route floor; the controller's former whole-School
  Gate call was removed, because the floor replaces it. The history
  service re-checks with the `capability` Gate.
- **Link hints are explicit whole-School checks.** Hints that open the
  full Profile, or show a class on a whole-School Finance list, are now
  explicit whole-School scope checks: the Subject offering roster,
  Attendance exceptions, the Admissions converted-Student link, and
  Outstanding fees.
- **School writes are untouched.** Every Student and Enrollment write keeps
  its unadopted whole-School key (`students.manage`, `enrollments.manage`,
  `enrollments.rollovers.manage`, `guardians.*`), so a Campus grant never
  reaches one (pinned for web and API).

**45.6 Role impact (machine-checked, `CampusAdoptionImpactTest`).**
- **New holders of adopted keys:** principal and school_admin
  (`students.view`, `enrollments.view`).
- **Campus-scopable holders:** principal only.
  `SIS_CAMPUS_CONFERRED = {principal: [enrollments.view, students.view]}`.
- **school_admin:** whole-School only.
- **No other role gains anything.** admissions_officer, front_office,
  hostel_warden, librarian, hr_officer, canteen_operator, stores_officer,
  transport_coordinator and teacher gain nothing (pinned).
- **Prohibited prefixes are narrowed, not lifted:** `students.manage`,
  `students.processing_authorizations.`, `enrollments.manage`,
  `enrollments.rollovers.` and `guardians.`.

**45.7 Navigation.** The `students` and `enrollments` entries name only
adopted keys, so they appear for a Campus grant and land on the Campus-aware
directories. Rollover (needs `enrollments.rollovers.view`), Guardians and
Data Health stay hidden. Navigation is never authorization.

**45.8 Performance and tenancy.**
- **Bounded queries.** One EXISTS predicate and one placements query per
  page. The query count does not grow with rows
  (`StudentCampusReadPerformanceTest`), and there is no per-row resolver
  call.
- **Index.** Uses `student_enrollments_one_active_per_student_year` (§44.23).
  No index was added; there is no evidence one is needed.
- **Tenancy.** Every predicate carries `school_id`, and RLS and SchoolScope
  are unchanged. Another School's Student or Campus never participates
  (pinned). No migration, and no new capability.

**45.9 Recorded for F2/F3 (not built).**
- **F-2 (open):** active Student, Section and Campus checks on `enroll`,
  transfer, rollover and conversion.
- **F-3 (open):** `enroll()` can place any existing Student. Campus
  onboarding must create a NEW Student atomically and never call a path
  that can capture one.
- **Student numbers (F1-D3):**
  - **No canonical server-side allocator exists.** `StudentService::create`
    and Admissions conversion both take the number from the caller.
  - **F2 onboarding is BLOCKED** pending an owner number policy.
- **Campus transfer (F1-D5):**
  - It does not move Hostel residency, Transport rider assignments or
    electives.
  - An unresolved active source-Campus relationship in those domains
    BLOCKS the Campus transfer until its owning domain resolves it.
  - History is unchanged.
- **Placement freeze (F1-D6):** generic runtime mutation of enrollment
  placement columns must be refused before F3 publishes. The named
  lifecycle operations become the only placement writers. The §44.11 lock
  order (Student before Sections and Enrollment) applies.
- **principal@Campus (F1-D7):** approved in principle for F2 (names,
  safe onboarding) and F3 (transfer between two in-scope Campuses),
  conditional on those phases proving their high-risk guards.

**45.10 Security review (ORG.7F-1).** Independent adversarial review of the
candidate: no HIGH or MEDIUM finding.
- **Fixed -- LOW-1:** a mixed-scope reader (whole-School `students.view`,
  Campus `enrollments.view`) got current placements filtered in PHP,
  without the operational-Student rule. An inactive Student's
  active-year placement could be shown. Placements are now one SQL query
  with the same rule as the directory (pinned).
- **Fixed -- LOW-2:** every StudentAccess enrollment query carries an
  explicit `school_id`.
- **Fixed -- LOW-3:** the F-12 guard covers every remaining path-id
  lookup in the Students and Guardians API controllers.
- **Fixed -- INFO:**
  - `placementInYear()` refuses any non-whole-School scope itself;
  - the unused `requireWholeSchool()` was removed;
  - Enrollment-directory Student links follow the reader's
    `students.view` reach (Profile, overview, or none).
- **Recorded, accepted:**
  - Grade Levels stay School-wide reference labels in the filter options.
  - A Campus grant's cached half (60 s) can under-grant for up to a
    minute after deploy, never over-grant.

## 46. ORG.7F-2 — SIS Campus narrow writes: Student-number allocation, placement validity, name edits and atomic onboarding (as built, 2026-10-20)

Owner authorization: ORG.7F-2, decisions F2-D1 to F2-D12, plus F2-D13
(asked during the phase). Baseline `origin/main` = `2d733fdf` (ORG.7F-1, §45).

**F2-D13 (owner, 2026-10-20).** `students.date_of_birth` is NOT NULL and
feeds the age and legal-basis logic (`StudentAge`). Campus onboarding
therefore takes the date of birth as a WRITE-ONLY creation input:
- It is required to create the record.
- It is never shown back to a Campus user, never Campus-editable, and on no
  Campus surface.
- There is no schema change.

    adopted (domain `students`)   + students.manage, enrollments.manage
    Campus writes (4 C6 routes)   name edit (first/middle/last only, current in-scope
                                  Student, under lock); atomic onboarding (BOTH keys
                                  in the destination Campus; new Student + current
                                  placement; server-allocated number)
    everything else on those keys 58 declared whole-School-only operations

**46.1 Student-number audit (read-only, before any change).**
- **Column:** `students.student_number` is `varchar(255)` NOT NULL.
- **Uniqueness:** already enforced per School
  (`students_school_id_student_number_unique`), unchanged. Cross-School
  reuse is allowed.
- **DDEV data:**
  - 39 numbers, 0 numeric-only and 39 alphanumeric (`LDS-0001`,
    `ANX-0001`);
  - no duplicate within a School and no number shared across Schools;
  - the longest value is 8 characters.
- **Factory:** numeric 100000–999999.
- **Creation paths:**
  - `StudentService::create` (web and API create; typed number, legacy and
    unchanged);
  - Admissions conversion (typed number, unchanged);
  - Campus onboarding: the ONLY path that uses the allocator.

**46.2 The allocator (F2-D5 to F2-D7).**
- **Counter table:** `student_number_sequences`, one row per School
  (`next_value`, `last_allocated_value`, CHECKs).
  - FORCE RLS; the runtime role may read, insert and update, never delete.
  - Seeded per School, School context set row by row so it works for a
    non-superuser migration role, at (greatest digits-only number) + 1, or 1
    when there is none. DDEV: both Schools start at 1.
- **`StudentNumberAllocator::allocate(School)`:**
  - runs inside the creating transaction;
  - upserts the row lazily, then locks it FOR UPDATE;
  - skips a candidate already held by a Student (a bounded defence);
  - advances the row and returns the plain decimal value.
  - The result is School-wide and stable, never Campus- or year-coded, never
    typed and never previewed (no route).
- **Never reused.** The `students_advance_number_sequence` trigger
  (BEFORE INSERT OR UPDATE OF `student_number`) moves the counter past ANY
  digits-only number written by any path, so a number once held (manual,
  conversion, purged later) is never generated.
  - Leading zeroes count numerically: `0009` is 9.
  - Alphanumeric values (`A-15`) never count.
  - Digit strings longer than 18 characters are outside the counter's range;
    the unique index remains the backstop.
- **Transactional, so gapless on rollback.** A rolled-back onboarding leaves
  the counter where it was. Its number was held by no Student, so it is
  issued once, to the next onboarding. Owner direction (gap-tolerance
  acceptable) is satisfied more strictly: no gaps from rollbacks, and no
  reuse.
- **Forward only.** A second trigger refuses any decrease of `next_value`
  or change of the seed, whoever writes.
- **Rollback.** `down()` drops everything ONLY while the counter is
  unmoved: no number generated AND `next_value = seeded_next_value`. A
  number written since seeding could belong to a Student later purged.
  - The check is an RLS-proof CHECK, which fails otherwise.
  - **Proven on DDEV:** the CHECK is refused once a row is marked as
    allocated.
  - **Rollback-proof:** identical schema, 84 lines, `verify-database
    failed=0`.

**46.3 F-2 closed: placement validity.** ONE rule in
`StudentEnrollmentService` (`assertPlaceable`) for every new placement:
- an active Student;
- an active Section of the same School;
- that Section's Campus active (`ActiveCampus`, share-locked).

Year, Campus and Grade context stays bound by the ORG.7D-0 composite FK. A
future (draft) year is a valid target (rollover); only onboarding requires
the active year. Data validity only, so whole-School authorization is
unchanged.
- **Lock order** (every enrollment-creating path):
  1. Student FOR SHARE (`lockStudentForPlacement`);
  2. Sections FOR UPDATE (id order);
  3. Enrollment;
  4. Campus share lock.
- **Writers:**
  - `enroll`;
  - `transferPlacement`: the Student is locked before the discovery read,
    the Sections and the source Enrollment;
  - rollover item execution: the Student is locked before the source
    Enrollment; `PlacementTargetUnavailableException` is drift and blocks
    the item with `placement_target_unavailable_at_execution`;
  - Admissions conversion: through `enroll`;
  - Campus onboarding: through `enroll`.
- **Rollover dry run** now blocks `student_inactive`,
  `target_section_inactive` and `target_campus_inactive` (staff labels
  added).
- **Refusal:** `PLACEMENT_TARGET_UNAVAILABLE` (422) with a reason.
  - **Behaviour change:** re-entry of an inactive Student now needs
    reactivation first.
- **Amended pin (ORG.7D-4 §40.5).** A transfer now WAITS for a Hostel or
  Transport Student lock instead of passing it. There is no cycle in either
  order: the transfer holds nothing the assignment needs until it has the
  Student. The pin was renamed and is proven with real overlap.

**46.4 `StudentAccess::lockedWriteTarget()` (write side).** It runs inside
the write transaction and returns the locked Student or the unknown 404.
- **Whole School:** the Student, FOR NO KEY UPDATE.
- **Campus set:**
  1. the active year FOR SHARE;
  2. the Student FOR NO KEY UPDATE (active);
  3. its current Campus, read under that lock, must be in scope;
  4. `StudentOperationalEligibility::lockOperationalOn()` then share-locks
     the current Enrollment and the Campus and re-proves the placement.

It is never a general finder, and there is no `lockedTransferTarget` (F3).

**46.5 Campus name edit.**
- **Routes:** `app.students.names.edit` / `.update` (C6, `students.manage`).
- **Service:** `StudentNameEditService`. It whitelists
  `first_name`/`middle_name`/`last_name` (over-posted number, date of birth,
  status and `school_id` are ignored, pinned) and runs under
  `lockedWriteTarget()`.
- **Audit:** `student.updated` with `changedFields` and `scope`.
- **Out of scope** (other Campus, former, future, inactive, unplaced,
  unknown) is 404.
- **Overview:** `canEditNames` only within reach.
- **Web only.** The API full update stays whole-School (it carries the
  number and date of birth).
- **Races (real processes, both orders):** transfer, inactivation, year
  switch and Campus deactivation. Whoever locks first commits; a state
  change that committed first refuses the edit (404).

**46.6 Atomic Campus onboarding (F2-D8, F2-D9, F2-D12).**
- **Routes:** `app.students.onboard` / `.onboard.store` (C6). Both
  `students.manage` AND `enrollments.manage` scopes resolve; the
  destination Campus must be in both.
- **`CampusStudentOnboardingService::onboard()`**, one transaction:
  1. active year FOR SHARE (none: refused);
  2. the destination Section (this School, the active year, a Campus in both
     scopes);
  3. the allocated number;
  4. a new active Student (names, write-only date of birth);
  5. `enroll()` (F-2 under locks);
  6. audit `student.created`, `student_enrollment.created` (with
     `campusId`), and `student.campus_onboarded` (ids only).
- **DTO, exactly:** `first_name`, `middle_name`, `last_name`,
  `date_of_birth`, `section_id`, `roll_number`. Never a Student number,
  Student id, status or Guardian (over-posting ignored, pinned).
- **No capture.** The service never reads an existing Student.
- **Refusals:**
  - One generic `STUDENT_ONBOARDING_UNAVAILABLE` for every probeable
    refusal: another Campus, a draft or closed year, an inactive Section or
    Campus, unknown, no active year. Nothing is created and no number is
    consumed.
  - A taken roll number in the actor's own Section is ordinary feedback;
    the whole onboarding rolls back.
- **Duplicate identity:** the repository has no name or date-of-birth
  matching, so there is nothing to conceal. Server-generated numbers cannot
  collide.
- **Races (real processes):**
  - two onboardings get distinct numbers;
  - against Campus deactivation, Section deactivation and a year switch, in
    both orders: commit, or the generic refusal with no partial Student.
- **API onboarding: DEFERRED.**
  - The JSON API needs an all-of floor proven before `idempotent` replay
    (rule 32), and the `,campus` floor checks one key. Same as HR §43.5.
  - Web onboarding has no stored replay, so it is safe.
- **Rule 29:** a double submit creates two Students, the same as the
  legacy create and HR onboarding. Accepted LOW and recorded.
- **UI:**
  - The Directory shows "Onboard student" (`canOnboard`: both keys
    somewhere).
  - The form offers in-scope active current-year Sections.
  - After success the overview shows the allocated number.

**46.7 Adoption bookkeeping.**
- **58 declared whole-School-only operations**, exact route names with
  reasons. They cover create, store and the full edit (number, date of
  birth), status, Guardian links (8 web + 4 API), account links, enroll of
  an existing Student (capture, F-3), complete, withdraw, cancel, transfer
  (F3), closed-year closure and every rollover write.
- **Removed controller checks.** Every whole-School `authorizeCapability`
  call on these keys was removed. The route floor is now the check, and 37
  C2 entries became C1.
- **Defence in depth kept:** `ClosedYearPlacementClosureService` still
  re-checks with the Gate.
- **View hints** (`canManageStudents`, `canManageEnrollments`, rollover
  manage, `canManage`) are explicit whole-School checks.
- **`CampusAdoptionImpactTest`:**
  - principal and school_admin hold the new keys;
  - principal is the only Campus-scopable holder;
  - `SIS_CAMPUS_CONFERRED.principal` = the four SIS keys;
  - `students.manage` and `enrollments.manage` are HIGH_RISK and
    owner-approved (F2-D10).
  - admissions_officer, front_office, hostel_warden and librarian gain no
    Student write (pinned).
- **`StudentCampusWriteArchitectureTest`:**
  - the name-edit fields and route DTO;
  - the onboarding DTO;
  - allocator use only in the onboarding service, which is its only caller;
  - only the four C6 writes;
  - 25 named lifecycle, enroll, transfer and Profile routes declared.

**46.8 Recorded for F3 (not built).**
- **Placement writers.** The only runtime INSERT is
  `StudentEnrollmentService::createEnrollmentRow`; demo seeding uses
  `enroll()`. The only UPDATEs are status and `ends_on` (transfer,
  complete, withdraw, cancel). No runtime code rewrites a placement column.
- **Proposed freeze (D-6, before F3 publishes):** a
  `BEFORE UPDATE ON student_enrollments` trigger refusing any change to
  `student_id`, `school_id`, `academic_year_id`, `campus_id`,
  `grade_level_id`, `section_id` or `starts_on`. It also allows status only
  `active` → `completed`/`withdrawn`/`transferred`/`cancelled` with
  `ends_on` set once. `sections`' placement columns are already frozen by
  the composite FK while any Enrollment references them.
- **Transfer prerequisites (F1-D5):**
  - a dual-scope `lockedTransferTarget` using §46.3's order;
  - an active source-Campus Hostel residency, Transport rider assignment or
    elective BLOCKS a Campus-scoped transfer until its owning domain
    resolves it; nothing is auto-moved;
  - Campus ids in the transfer audit;
  - generic concealment.

**46.9 Security review (ORG.7F-2).** Independent adversarial review of the
candidate: no HIGH or MEDIUM finding.
- **Fixed -- LOW-1: a typed number equal to the next generated one could
  deadlock (40P01).**
  - Cause: the AFTER trigger let the manual writer claim its unique index
    entry before waiting on the counter.
  - Fix: the trigger is now BEFORE, so the writer queues on the counter
    first and then meets the ordinary duplicate refusal (two-process race,
    both orders, pinned).
- **Fixed -- LOW-2: rollback then re-seed could re-issue a purged
  Student's number.**
  - Fix: `seeded_next_value` is stored, and rollback refuses once the
    counter moved past it.
- **Fixed -- LOW-3: the counter was forward-only by convention only.**
  - Fix: a database trigger now refuses a decrease.
- **Fixed -- INFO:** the trigger functions pin `search_path` and qualify
  the counter table, and EXECUTE is revoked from PUBLIC.
- **Recorded, accepted:**
  - **Number volume:** School-wide sequential numbers let a Campus actor
    estimate the School's intake volume. This follows from F2-D5–D7.
  - **18-digit typo:** one manually typed number of up to 18 digits moves
    every later generated number past it, permanently (forward-only by
    design). An input ceiling on manual numbers is an owner option.
  - **Double submit:** a double-submitted onboarding fails on the roll-number
    index, so no duplicate Student survives.
  - **Edit-names page:** it needs `students.manage` only. No role holds
    manage without view.

## 47. ORG.7F-3 — SIS Campus transfer and placement immutability (as built, 2026-10-20)

Owner authorization: ORG.7F-3, decisions F3-D1 to F3-D12. Baseline
`origin/main` = `e00beec9`: ORG.7F-2 (§46) plus a separate supply-chain
slice that moved vite 8.2.2 to 8.2.4 (GHSA-587h-7wmj-h5pm, a High advisory
with a fix, published 2026-10-20, which blocked every candidate's language
audit).

    placement context    year, Campus, Grade, Section: DB-frozen after INSERT
    Campus transfer      enrollments.manage over BOTH Campuses; CURRENT placement of an
                         active Student; another Campus; today; ends the source row +
                         inserts the destination row (never an UPDATE)
    blockers (refuse)    active source-Campus Hostel residency, Transport rider,
                         elective anchored to the source placement -- never moved
    not blockers         Library loans (Campus frozen at checkout), Canteen orders
                         (Outlet Campus), Finance, history, Guardians

**47.1 Placement-writer inventory (re-verified).**
- **A — INSERT:** only `StudentEnrollmentService::createEnrollmentRow`.
  It is reached from `enroll` (whole-School, Admissions conversion,
  rollover execution, Campus onboarding) and `transferPlacement`. Demo
  seeding uses `enroll()`.
- **B/C — status and `ends_on`:** `transferPlacement` (source →
  `transferred`), complete, withdraw and cancel.
- **D — placement-context UPDATE:** none in production code.
- **E — rollover:** through `enroll()`.
- **F — transfer:** "end + insert".
- **G — cleanup:** retention DELETE only (Student core purge). Test race
  scripts insert raw rows.
- **H — raw:** the runtime role could UPDATE context columns. This phase
  closes that.
- **`roll_number`:** no writer, no correction workflow, and the
  `(school, year, section, roll)` key is unique in any status. It is not
  part of the Campus/Section identity, so it is NOT frozen.
- **Already fixed by `retention_eligibility_guard`:** `id`, `school_id`,
  `student_id` and `starts_on`; an ended record's `ends_on` and status.

**47.2 Placement freeze (F1-D6).**
- **Trigger:** `student_enrollments_freeze_placement`, BEFORE UPDATE OF
  `academic_year_id`, `campus_id`, `grade_level_id`, `section_id`.
  - It refuses any change for every role except the table owner
    (migrations, operator repair; the existing guard's convention, and the
    runtime role is never an owner member, rule 85).
  - It has a pinned `search_path` and no PUBLIC EXECUTE.
- **Proof as `school_os_app` (raw SQL):** all four columns are refused;
  `status`/`ends_on` and `roll_number` updates still succeed; RLS stays
  forced; the ORG.7D-0 Section-context key still refuses a mismatched
  INSERT (`PlacementFreezeTest`).
- **No data rewritten.**
- **Rollback-proof:** identical schema, 41 lines, `verify-database
  failed=0`. Rollback re-enables direct context updates by the runtime role
  and changes no data.

**47.3 Existing transfer, the capability, and same-Campus moves.**
- **`transferPlacement` already was the named operation:**
  - same year and same Grade (refuses a Grade change);
  - ends the source (`transferred`, `ends_on` = effective − 1) and inserts
    the destination;
  - Campus follows the destination Section.
- **Capability:** `enrollments.manage`, unchanged. There is no new
  capability, and `students.manage` is not involved.
- **Same-Campus Section changes stay whole-School:**
  - the Enrollment-based web pages `app.enrollments.transfer.*` remain
    declared;
  - the Campus guard refuses `source Campus = destination Campus`.

**47.4 `StudentAccess::lockedTransferTarget()` and the guard.**
- **`lockedTransferTarget()`**, inside the transfer transaction:
  1. active year FOR SHARE;
  2. the Student FOR SHARE (active);
  3. the CURRENT placement (`constrainToCurrentPlacement`), whose Campus
     must be in scope;
  4. the source Campus, share-locked active (`ActiveCampus`), so a source
     Campus deactivation serializes with the transfer.

  Otherwise the result is the unknown 404. The actor never names an
  Enrollment row on the web; the API Campus path accepts only the id of
  that current placement (`expectedSourceId`; any other row is the 404).
- **Not `lockOperationalOn()`:** that helper share-locks the Enrollment,
  and `transferPlacement()` then takes it FOR UPDATE; two concurrent
  transfers would deadlock on the upgrade. The transfer therefore takes
  the same year and Student locks and leaves the Enrollment to
  `transferPlacement()`.
- **`StudentTransferCampusGuard`**, called by `transferPlacement()` after
  its locks (Student FOR SHARE, Sections FOR UPDATE in id order, source
  Enrollment FOR UPDATE, status re-checked), requires:
  - the Student active;
  - source and destination both in the active year;
  - the scope covering BOTH Campuses;
  - the Campuses differing;
  - no `StudentTransferBlocker` reporting a relationship.
- **Then the F-2 rule:** destination Section and Campus active (Campus
  share-locked).
- **Transaction:** one transaction with the Campus-transfer effective date
  set to today (School-local). It never backdates, schedules or changes the
  Student number, names, date of birth or Guardians.
- **Lock order (one direction everywhere):** year SHARE → Student SHARE →
  Sections FOR UPDATE → source Enrollment FOR UPDATE → blocker reads →
  destination Campus SHARE → UPDATE + INSERT.
  - Hostel, Transport and the name edit take the Student lock first, so
    they serialize on the Student.
  - Library, Canteen and elective writers share-lock the Enrollment, so
    they serialize on it.
  - There is no School-wide lock.

**47.5 Blockers (F3-D6, F3-D12).**
- **Contract:** `StudentTransferBlocker` (Students; container tag), the
  `EmploymentEndParticipant` pattern. Students never reads the Hostel or
  Transport tables (rule 4).
- **`HostelTransferBlocker`** (Hostel): an ACTIVE residency in a Hostel of
  the source Campus.
- **`TransportTransferBlocker`** (Transport): an ACTIVE rider assignment on
  a route of the source Campus. A NULL-Campus route does not block.
- **`ElectiveTransferBlocker`** (Students): an ACTIVE subject enrollment
  anchored to the source placement.
- **Answer-only.** Each blocker returns one `exists()`. Nothing is ended,
  moved or rewritten (pinned).
- **Serialization:**
  - Hostel and Transport assignments lock the Student FOR NO KEY UPDATE
    first, against the transfer's Student FOR SHARE.
  - Elective enroll now share-locks its anchor placement
    (`resolveCompatibleEnrollment`, after the Offering, the
    Enrollment-before-Offering order rollover uses), against the transfer's
    FOR UPDATE.
  - In both cases a write committed first is seen, and one arriving later
    re-reads and refuses.
- **Refusal:** the Campus actor gets one generic
  `STUDENT_TRANSFER_UNAVAILABLE` (422) for every in-scope refusal. An
  out-of-scope or unknown Student is the 404.

**47.6 Not blockers, never rewritten (F3-D7 to F3-D9).**
- **Library:** an open loan keeps its frozen Campus; a return follows the
  loan Campus.
- **Canteen:** orders keep their Outlet Campus, and new orders follow the
  new current Campus.
- **Unchanged:** Charges, Payments and balances (charge-year attribution);
  Attendance, marks and LMS history; Guardians and `GuardianStudentScope`.
- **Pinned:** a loan on the source Campus does not block and keeps its
  Campus.

**47.7 Surfaces.**
- **Web (C6):**
  - `app.students.transfer.create`: the form, with
    `StudentAccess::transferDestinations()` = active Sections of the
    source's year and Grade on ANOTHER active in-scope Campus, in SQL;
  - `app.students.transfer.store`: `CampusStudentTransferService::transfer`;
  - the overview shows "Transfer" only when reachable.
- **API (C6):** `api.v1.schools.enrollments.transfer`, with floor
  `capability:enrollments.manage,campus` and `idempotent`.
  - **Whole School:** unchanged (any active placement and date).
  - **Campus:** only the current in-scope placement (else 404), effective
    today (else 422), through the same service.
  - **Replay:** the ORG.7D-1 fingerprint binds the actor's CampusScope, so
    a changed scope never replays a stored response.
- **Audit:** `student_enrollment.transferred` now records `studentId`,
  `from`/`toEnrollmentId`, `from`/`toCampusId`, `from`/`toSectionId`,
  `endsOn` and `scope`, written after the destination row exists. Names,
  date of birth and contacts are never included.
- **Read authority:** the former Campus loses ordinary read on the next
  request and the destination Campus gains it (no authority cache, pinned).

**47.8 Concurrency matrix (real processes, both orders, overlap
observed, no deadlock: 20 races).**
- **State changes:** inactivation, year switch, destination Campus
  deactivation and a second transfer. Whoever locks first wins, and the
  loser is refused.
- **Blockers:** Hostel residency, Transport rider and elective. Committed
  first, each blocks; arriving later, each is refused
  (`HostelResidencyUnavailable`, `TransportAssignmentUnavailable`,
  `IncompatibleSubjectOffering`).
- **Name edit, Library checkout, Canteen order:** committed first, they do
  not block; arriving later, the source-Campus actor is refused.
- **The amended ORG.7D-4 pin** (transfer waits for the Hostel Student lock)
  still holds.

**47.9 Role impact.** No key changes: `enrollments.manage` was adopted in
F2.
- principal@Campus gains the Campus transfer only when both Campuses are
  in scope (F3-D11, high-risk, owner-approved).
- school_admin is whole-School.
- No other Campus-scopable role holds the key (`CampusAdoptionImpactTest`
  unchanged).
- Rollover, withdrawal, reactivation, status, generic enroll, history, the
  Profile and Guardians are unchanged (still declared).

**47.10 Independent security review.** No HIGH or MEDIUM finding. LOW
findings, all fixed before publication:
- **LOW-1:** the API Campus path could act on the current placement
  when given a different, historical Enrollment id of the same Student.
  It now requires the named row to BE the current placement
  (`expectedSourceId`), else the 404.
- **LOW-2:** the API Campus path's `exists` rule distinguished an unknown
  Section from an out-of-scope one. Removed: every destination failure is
  the generic `STUDENT_TRANSFER_UNAVAILABLE`.
- **LOW-3:** the source Campus was not locked; a concurrent deactivation
  could interleave. It is now share-locked through `ActiveCampus`.
- **LOW-4:** a legacy elective with a NULL anchor on the source Campus did
  not block. `ElectiveTransferBlocker` now also matches the Student's
  active NULL-anchor electives of the source year whose Offering is on
  the source Campus.

Accepted as INFO: whole-School same-Campus Section moves stay on the
declared whole-School pages; the transfer audit records ids only; the
Campus actor cannot choose the effective date (today, School-local).

**47.11 Deferred and unchanged.** Not authorized and not built: generic
enrollment editing, direct placement edits, automatic Hostel, Transport
or elective migration, Campus authority over rollover, withdrawal,
reactivation or Student status, Guardian changes, Admissions conversion,
Profile expansion, historical enrollment Campus access, bulk transfer, and
Fees/Payments. ORG.7F-4 and Finance Campus work need owner authorization.

## 48. ORG.7G-0 — Fees & Payments Campus attribution and authorization contract audit (read-only, 2026-10-21)

Owner authorization: ORG.7G-0 (read-only audit; implementation not
authorized). Baseline `origin/main` = `cbd7139d` (ORG.7F-3, §47). No
production code, schema, capability, role, route or coverage entry changed.

    financial Campus   != the Student's current Campus (owner decision, frozen)
    Charge             School + Student + academic year; NO Campus, NO Enrollment
    Payment            School only; reaches Students only through its allocations
    attribution today  re-derived on read (campusAttribution), NOT historically stable
    verdict            PASS WITH PREREQUISITES: a stored, immutable Charge Campus first

**48.1 Schema truth (re-audited from the migrations).**
- **`charges`:** `student_id`, `academic_year_id` (NOT NULL), `due_date`,
  accounts, journal. No Campus and no Enrollment.
  - `charges_immutability_trigger` freezes every column except the one-time
    cancellation pair. DELETE is revoked. `school_id` is ON DELETE RESTRICT.
  - The only INSERT is `ChargeService::assess()`.
- **`fee_assessments`:** stores `student_enrollment_id` (NOT NULL, composite
  FK), frozen by its guard trigger. It is the only stored historical
  placement behind a Charge.
- **`fee_assessment_run_items.campus_id`:** a nullable snapshot with no FK,
  frozen.
- **`fee_structures.campus_id`:** a nullable configuration dimension, frozen
  once not draft. NULL = the School default.
- **`payments`:** School only. No Student, year, Enrollment or Campus.
  Append-only.
  - `payments_source_shape_check`.
  - The deferred `payments_fully_allocated_check` makes allocations equal the
    amount at creation, so an unallocated or advance Payment cannot exist.
- **`payment_allocations`:** append-only and frozen after the settlement
  transaction. There is no unique `(payment, charge)` key, so one Payment
  can settle many Charges.
- **`payment_corrections` / `payment_correction_lines`:** append-only;
  release/apply lines; `payments_effective_allocated()`.
- **`payment_receipts`:** one per Payment; one School-wide financial-year
  series.
- **`fee_concessions` / `fee_adjustments`, `late_fee_*`:** Student + year
  (or source Charge). No Campus.
- **Ledger (`journal_*`, `ledger_accounts`, `financial_period*`):** no
  Campus.
- **Absent tables:** overrides, discounts (a category only), separate
  refunds (a correction kind), credits, a balances table, cashier records.
- **RLS:** every table is FORCE RLS.

**48.2 Capabilities and roles (no change).**
- **Keys:** 15 `finance.*` keys plus `portal.fees.view`. There is no
  `fees.*` or `payments.*` namespace.
  - Modules: Finance = `finance.ledger/accounts/periods`; Fees ("Fees &
    Payments", depends on Finance) = `finance.fee_*`, `finance.charges.*`,
    `finance.payments.*`.
  - Every key is class `financial`, with no grant right and none legal-gated.
- **Holders:**
  - `school_admin` holds all 15.
  - `accountant` holds 12: everything except `ledger.reverse`,
    `periods.manage` and `fee_concessions.approve`.
  - `cashier` holds `payments.view`, `payments.record` and `charges.view`.
  - `principal` holds NONE.
  - No Campus-scopable role holds any Finance key.
- **Adoption:** nothing is adopted. `CampusAdoptionImpactTest` treats
  `finance.` as HIGH_RISK (owner approval required) and forbids Finance
  keys on the Library/Transport roles (FINANCIAL_AUTHORITY_PREFIXES).
- **Scopability is derived** (`StaffRoleCatalog::campusScopable`): adopting
  any key makes every holder without access administration
  Campus-grantable. Adopting a Finance key would therefore make `cashier`
  AND `accountant` Campus-grantable.
  - A Campus grant confers only adopted keys. The ledger, configuration and
    checker keys would not be conferred.
- **Routes:**
  - Web `app.finance.*` routes are C2 (controller Gate plus service
    re-check); corrections are C3.
  - API `/schools/{school}/…` routes are C1 (`capability:` middleware).
  - There is no API for payment recording, corrections, Outstanding or
    collections.
  - No finance route is `idempotent`. Rule 91 keys (payment, correction,
    concession) are server-issued and claimed on the row.

**48.3 Charge creation paths (classification).**
| Path | Year source | Campus context available at creation | Class |
|---|---|---|---|
| Fee assessment run (job) | structure year | the stored `fee_assessments` Enrollment | Whole-School finance mutation (`fee_assessments.run`) |
| Manual charge (web/API) | user-chosen, any year | none consulted | Whole-School finance mutation (`charges.manage`) |
| Late fee (job) | source Charge's year | the source Charge | Whole-School, legal-gated E31 |
| Canteen fulfil | active year | Outlet Campus (nullable) | System/bounded side effect |
| Library fine at check-in | active year | Loan Campus (frozen) | System/bounded side effect (E34) |
| Transport / Hostel / Admissions | intent only (`fee_optional_selections`); a later fee run charges it | the Enrollment at run time | Bounded intent |

Bounded side effects grant no Fees, Payments or ledger key (seeder,
`OperationalFeeSourceArchitectureGuardTest`, `CampusAdoptionImpactTest`).
This audit found no weakening.

**48.4 Existing Campus attribution (inventory).**
- **A, charge-year attribution:**
  `StudentEnrollmentReadService::campusAttribution()`. The only caller is
  the Outstanding Fees `campus_id` filter (E, a filter, never authority).
  - Rule 1: one Campus among the year's non-cancelled placements → that
    Campus.
  - Rule 2: otherwise, the placement in effect on `assessedOn` (the
    Charge's `created_at`, School-local).
  - Otherwise null ("unavailable").
- **B, current Campus:** `currentForMany` / `currentStudentIdsIn` (class
  label and Grade/Section filter only). `StudentOperationalCampusResolver`
  is unused by Finance.
- **C, stored historical Campus:** run items, `fee_assessments`' Enrollment,
  the Library loan Campus, the Canteen Outlet Campus. Only the last two act
  as authority, and only in their own domains.
- **D, allocation-derived:** none. Daily collections, receipts, statements,
  the payments list, the portal, Analytics and Data Health have no Campus
  logic.

**48.5 Finding F-1 (prerequisite): today's Charge Campus is not historically
stable.** `campusAttribution()` re-derives on every read, and its inputs
still change after the Charge exists:
- An ACTIVE placement can be cancelled later (`StudentEnrollmentService::cancel`,
  whole-School `enrollments.manage`, held by `school_admin` and `principal`).
  - After a transfer A→B, cancelling B leaves only A, so Rule 1 moves every
    B-period Charge to A.
  - Cancelling a Student's only placement turns that year's Charges into
    "unavailable".
- A later placement on another Campus in the same year can move an earlier
  Charge from Rule 1 to Rule 2. If its `assessedOn` falls outside every
  placement, it becomes null.
- **Divergence from the stored truth:**
  - A fee-run Charge is dated at EXECUTION, not by its stored Enrollment.
  - A late fee uses its own date, not its source Charge's Campus.
  - Canteen and Library Charges ignore the Outlet and Loan Campus.
- **What already holds:** the placement freeze (§47), the immutable Charge,
  and transfer's "end + insert". A transfer alone never moves an earlier
  Charge whose `assessedOn` falls inside a placement.
- **Tests:** `OutstandingFeesLeaverCampusTest` covers the mover split,
  unplaced and leaver cases. It does not cover cancellation, the Rule 1 → 2
  flip or the date divergences.

Consequence: re-derivation is acceptable as a display filter (today's use)
but NOT as authorization. Campus authority must never move with an
Enrollment edit.

**48.6 Proposed canonical contract (owner decisions G0-D1..G0-D9; not
implemented).**
- **G0-D1, Charge Campus = one stored, immutable attribution.** A nullable
  Campus written once by `ChargeService::assess()` from a caller-supplied
  attribution source, then frozen by `charges_immutability_trigger`. NULL
  = whole-School only, never "all Campuses". Sources:
  - fee run → the stored assessment Enrollment's Campus;
  - late fee → the source Charge's attribution;
  - Canteen → the Outlet Campus (NULL Outlet → the Rule 1/2 placement on
    the fulfil date);
  - Library fine → the Loan Campus;
  - manual Charge → the Student's placement in the Charge's year by the
    Rule 1/2 rule on the creation date, else NULL.
  - **Backfill** of existing Charges uses the same rule, recorded once;
    unresolved → NULL.
  - The Students module supplies the placement answer through a
    Students-owned reader (rule 4). Fees never reads `student_enrollments`
    directly.
  - **Rationale:** this is a historical-stability requirement (§48.5), not
    convenience. The owner may choose a separate attribution table instead
    of a column; either must be insert-only and frozen.
- **G0-D2, Payment Campus (model B).** The Payment stays School-wide. Each
  allocation and correction line takes its Charge's Campus.
  - A Campus actor reaches a Payment, its receipt and its collections row
    only when EVERY original and correction-line Charge is attributed
    inside the actor's scope.
  - A mixed-Campus or NULL-touching Payment is whole-School only. It never
    appears partially or in two Campus views.
  - No Payment Campus column is needed. Unallocated/advance Payments do not
    exist (DB-enforced); if ever added, they are whole-School until
    allocated.
- **G0-D3, Campus collection writes (G2).** A Campus cashier may record a
  Payment only when every allocated Charge is in scope, decided under the
  existing Charge FOR UPDATE locks.
  - A mixed Payment is refused generically.
  - The Student's current Campus is irrelevant: leavers and moved Students
    pay old debt by the Charge's Campus.
  - The receipt follows the same all-in-scope rule.
- **G0-D4, whole-School forever (first releases):**
  - fee heads, structures, lines, instalments, optional selections,
    receipt numbering, the concession account, settings;
  - fee and late-fee runs and voids;
  - manual Charge assess and cancel;
  - concessions (request/approve/revoke);
  - every payment correction (reallocation, reversal, refund);
  - ledger, accounts, journals, periods.
  - Note: reallocation is same-Student in application code only and is not
    year-checked, so it can move money across Campuses.
- **G0-D5, Finance debtor lookup.** A Fees-owned purpose lookup returning
  `id`, `studentNumber` and `displayName` for Students with OPEN Charges
  attributed in scope. It includes inactive Students and leavers, and never
  uses `StudentPurposeLookup` or the Campus Directory.
  - Never: DOB, Guardians, contacts, documents or the Profile.
- **G0-D6, access layer.** One `FinanceCampusAccess` (Fees/Payments-owned):
  SQL restriction of Charges, Outstanding facts and Payments (all-in-scope
  `NOT EXISTS`); whole-School pass-through; multi-Campus union. Out of
  scope = the unknown 404 for a Charge, Payment, receipt or statement
  line. Write refusals are one generic code (the Visitor standard).
- **G0-D7, Campus inactivation.** It denies NEW Campus-scoped collection on
  that Campus (ActiveCampus under the Charge locks) but never hides or
  reassigns history. Whole-School actors keep everything. Reactivation
  restores Campus-scoped access to its open Charges.
- **G0-D8, roles.** `principal` gains nothing (it holds no Finance key). On
  adoption, `cashier` and `accountant` become Campus-grantable.
  - Recommendation: allow `cashier`.
  - Decide `accountant` explicitly: a Campus accountant would hold only
    the adopted keys, which may surprise a reviewer.
  - Owner approval is mandatory (HIGH_RISK `finance.`).
- **G0-D9, legal and module order:** module entitlement → legal
  availability (E30, E31, E32, E34; still documentation-only, with no code
  switch) → capability → Campus scope. Campus scope never enables a fee,
  rate or late fee.

**48.7 Scenarios (contract under G0-D1/D2).**
- **Transfer (A until 30 Sep, B from 1 Oct):**
  - An August Charge stays A, and an October Charge is B.
  - A Payment after the transfer against the August debt is A.
  - A Payment for both Charges is whole-School only.
  - A refund or reversal follows its line's Charge, and corrections are
    whole-School.
  - With stored attribution, cancelling B later changes nothing.
- **Leaver:** no current Campus. Their debt keeps its stored Charge Campus
  and stays in that Campus's Outstanding view.
  - A Campus cashier finds them through the G0-D5 lookup and can collect.
  - Today's filter already keeps leaver debt by charge year (pinned test).
- **Unattributed:** a Charge with no defensible placement is NULL →
  whole-School only. The dev data has none.

**48.8 Reads, filters and performance.**
- **Outstanding Fees:** gated by `charges.view` AND `payments.view`
  (whole-School). The Campus filter is applied in PHP after
  `collectionFacts()` streams every live Charge.
  - A request `campus_id` (validated against the School's Campuses plus
    `unavailable`) only narrows, and can never widen; Finance has no Campus
    authority to widen.
  - G1 must move the restriction into SQL against the stored attribution.
- **Daily collections:** one row per Payment; no Campus; filterable only
  under G0-D2.
- **Receipts:** per Payment, with School-wide numbering. A receipt lists
  every Student and allocation, which is why the all-in-scope rule is
  needed. Provider references are not shown.
- **EXPLAIN (dev data):** both a set-based Charge restriction and the
  all-allocations-in-scope Payment restriction run as one bounded,
  index-driven query (≈0.5 ms; nested-loop or hash semi/anti joins). The
  only O(year) probe was the re-derived single-Campus check on
  `student_enrollments(academic_year_id)`, which a stored attribution
  removes. No index is recommended without G1 evidence.

**48.9 Concurrency, async, callbacks, idempotency.**
- **Locks:**
  - Recording locks Charges FOR UPDATE in id order, after the School share
    lock and key advisory lock; an allocation trigger re-locks.
  - Corrections take the key advisory lock, then the Payment advisory lock,
    then the Charges in id order.
  - A stored, immutable attribution cannot move while a write holds those
    locks, so a Campus check taken there is race-free. A transfer never
    touches Charges.
  - Re-derived attribution would race with an Enrollment cancellation
    (another reason for G0-D1).
- **Jobs:** `ExecuteFeeAssessmentRunJob` and `ExecuteLateFeeRunJob` are
  `TenantScoped` + `ExecutesModuleWork`, user-authorized at dispatch, with
  the actor used for audit only. They carry no "authorized" flag and must
  stay whole-School.
  - Outbox events have no consumer.
  - The receipt backfill, retention prune and balance verify are operator
    or system work.
- **Provider callbacks:** a trusted system boundary with no production
  caller (ADR 0057 deferred). The School is passed in; allocations are
  explicit. Never Campus-user-authorized.
- **Idempotency:** server-issued keys replay only for the same User and
  identical content. A future Campus write must re-evaluate the Campus
  predicate before replaying (rule 32; §37's fingerprint precedent).

**48.10 Security and privacy review (no production fix).**
No HIGH finding.
- **MEDIUM (F-1):** re-derived attribution would let an Enrollment
  cancellation re-home debt. It is harmless today (display filter) and a
  blocker for Campus authority.
- **LOW:**
  - A Payment can span Students, years and Campuses (any whole-School
    recording). This is a design fact, handled by G0-D2.
  - Reallocation is cross-year and same-Student by application code only.
  - The Outstanding filter loads every live Charge in PHP.
  - Fee and late-fee jobs do not re-check the dispatching User (accepted;
    whole-School, audited).
- **INFO:**
  - The seeder comment at `CapabilityAndRoleSeeder.php:472-476` says
    `finance.accounts.manage` is not registered; it is (line 528).
  - `DOMAIN-MAP.md:116` still says "no human Payment mutation route
    exists".
  - `PaymentReceiptBackfillTest` asserts an unordered outbox query in a
    fixed order. It failed once in a shared batch and passed 3/3 alone.
    Fix: compare as a set, in the next code slice.
- **Confirmed isolation:**
  - Guardian fee access stays relationship-scoped (`GuardianStudentScope`,
    production-refused), with no Campus coupling.
  - The SIS overview has no Finance panel.
  - Bounded side effects grant no Fees authority.
  - Legal gates are unchanged.

**48.11 Data audit (DDEV, read-only).**
- **Data with Charges:** 1 School, 39 Charges, all in the one active year
  (36 Students). Every Charge resolves to exactly one Campus; none is
  multi-Campus or unattributed.
- **Charge origins:** all 39 are manual or seed Charges; there are no fee
  runs or late fees.
- **Payments:** 30, every one fully allocated to exactly one Charge (none
  multi-Student, multi-year or multi-Campus). 26 manual (10 cash, 12 bank
  transfer, 4 cheque) and 4 provider.
- **Other records:** 30 receipts; 0 corrections, concessions, adjustments
  or late fees; 0 leavers with debt.
- **Limit:** the transfer, leaver and multi-Campus cases are therefore
  proven from code and schema, not data.

**48.12 Readiness and sequencing.**
- **ORG.7G-1A, attribution foundation (PREREQUISITE, needs approval):**
  - the stored immutable Charge Campus (G0-D1) with backfill and freeze;
  - a Students-owned attribution reader for writers;
  - the Outstanding restriction moved to SQL;
  - tests for cancellation, the Rule 1 → 2 flip and the date divergences;
  - no capability adoption.
- **ORG.7G-1, Campus READ surfaces (after 1A):**
  - `finance.charges.view` / `finance.payments.view` adopted through
    `FinanceCampusAccess`;
  - Outstanding, Charge history, statements, collections and receipts
    under G0-D2;
  - the debtor lookup;
  - declared whole-School configuration, ledger and corrections;
  - owner approval of HIGH_RISK, and the cashier/accountant decision.
- **ORG.7G-2, Campus cashier WRITES (separate):** `finance.payments.record`
  under G0-D3. It needs G1 plus a decision on whether the payment
  idempotency replay re-checks scope.
- **ORG.7G-3, corrections, concessions or manual Charges by Campus:** not
  recommended, and only ever on explicit approval.
- **Not dependencies:**
  - Admissions conversion: Admission fees are charged by fee runs from the
    stored Enrollment.
  - The remaining SIS gaps: rollover, withdrawal, reactivation, history,
    bulk transfer, API onboarding.
  - LC-5..LC-10.

**48.13 Validation.** Read-only.
- **Tests:** Fees, Finance and Payments, Finance web pages, the Outstanding
  Campus tests, `StudentOperationalCampusTest`, and every *Architecture* /
  *Guard* test: 1589 passed. One real-MinIO test failed (environment-only).
  One `PaymentReceiptBackfillTest` ordering flake passed 3/3 alone.
- **Not run:** the full suite (docs-only). Regression counter unchanged at
  0/5.

## 49. ORG.7G-1A — Finance Campus attribution foundation: stored immutable Charge Campus and SQL filtering (as built, 2026-10-21)

Owner authorization: ORG.7G-1A, decisions G1A-D1 to G1A-D9. Baseline
`origin/main` = `e8d600fe` (ORG.7G-0, §48). This closes §48.5 F-1. No
Finance capability, role, route or coverage entry changed.

    charges.campus_id   the Campus the obligation was attributed to WHEN CREATED;
                        written once by ChargeService::assess(), DB-frozen,
                        same-School composite key; NULL = whole-School only
    sources             fee run -> stored assessment Enrollment; late fee -> source
                        Charge; Canteen -> Outlet; Library fine -> Loan; manual ->
                        the one placement in effect on the creation date (FOR SHARE)
    never               the Student's current Campus; re-derivation on read
    unchanged           Payments (School-wide), allocations, receipts, every finance.* key

**49.1 Pre-migration audit (DDEV development data, read-only).**
- **Charges:** 39 in 1 School, all live, all in the one active year,
  for 36 Students.
- **By provenance:** 36 manual and 3 Canteen. There were 0 fee-run, late-fee
  or Library Charges; Transport, Hostel and Admissions create none directly.
- **Edge cases:** no Student with Charges in several years, none on several
  Campuses, no leaver with a Charge, none without a current Campus.
- **Classification:** all 39 UNAMBIGUOUS.
  - The 36 manual Charges each had one valid placement on their creation
    date.
  - The 3 Canteen Charges each had an Outlet with a Campus.
  - 0 were NULL by contract and 0 AMBIGUOUS.
- **Backfill result:** all 39 → Main Campus. `updated_at` and
  `retention_recorded_at` were unchanged (`campus_id` is not a retention
  anchor column).

**49.2 Charge creators (every production INSERT path).** The only INSERT is
`ChargeService::assess()` (pinned). Each caller must pass a
`ChargeCampusAttribution`, a required, non-nullable constructor argument of
`AssessChargeData` and `FeeSourceChargeData`. Its constructor is private,
so no caller can name an arbitrary Campus.

| Creator | Attribution | Stability |
|---|---|---|
| `FeeAssessmentItemExecutor` | `fromEnrollment()`: the Enrollment the run assesses, re-locked FOR SHARE (`lockForFeeAssessment`) | `fee_assessments.student_enrollment_id` is frozen; placement columns are frozen (§47) |
| `LateFeeItemExecutor` | `fromCharge()`: the source Charge's stored Campus (`LateFeeSourceFacts::campusId`) | the source Charge is frozen and locked by `outstanding->locked()` |
| `CanteenOrderService::fulfill` | `fromCanteenOutlet()`: the Outlet's Campus, read FOR SHARE in the fulfil transaction | an Outlet's Campus has no update path; a NULL Outlet → whole-School |
| `LibraryFineService` (via `FeeSourceChargeService`) | `fromLibraryLoan()`: `library_loans.campus_id` | frozen at checkout (§41) |
| Manual Charge (web, API), demo seeder | `placementOnAssessmentDate()`: resolved inside `assess()` | see 49.3 |
| Transport, Hostel, Admissions | intent only (`fee_optional_selections`); a later fee run charges it | fee-run rule |

**49.3 Manual Charge rule and its lock.**
- **Rule:** `StudentEnrollmentFeeTargetReadService::placementCampusOn()` (a
  Students-owned read; rule 4) runs inside `assess()`'s transaction, before
  the ledger posting.
  - It share-locks every placement of the Student in that year (id order).
  - It returns the ONE Campus among the non-cancelled placements in effect
    on the School-local creation date; otherwise NULL.
  - It never uses the current placement, the latest row, or Rule 1 of the
    retired derivation (one Campus in the year regardless of date). A Charge
    raised before any placement starts is therefore whole-School.
- **Race (two OS processes, both orders, no deadlock):**
  - Charge first: the cancellation waits, and the Charge keeps the placement
    it saw.
  - Cancellation first: the Charge waits, then sees no valid placement and
    stores NULL.
  - (`ChargeCampusConcurrencyTest`; the harness op fires deferred ledger
    checks before holding.)
- **Lock order:** placements SHARE, then ledger, then the Charge INSERT (FK
  KEY SHARE on the Student). This is compatible with a transfer (Student
  SHARE, Enrollment FOR UPDATE) and a cancellation (Enrollment FOR UPDATE).

**49.4 Migration `2026_12_26_090000_add_charge_campus_attribution`.**
- **Abort first:** it derives every existing Charge by provenance
  (`derivationSql()`) and aborts the WHOLE migration, listing the Charges,
  when any is ambiguous:
  - several provenance rows;
  - several placements in effect on the creation date;
  - a placement in effect when the Charge was created that has since changed
    (created before the Charge and ended, cancelled or edited after it);
  - a placement recorded after the Charge but backdated over its date;
  - a late fee whose source is ambiguous.
- **Fail closed:**
  - It refuses to run unless the connection's role bypasses RLS (FORCE RLS
    would otherwise show it no rows).
  - It SHARE-locks every table that decides an attribution before the check.
  - It backfills only unambiguous rows.
- **Schema:**
  - `campus_id uuid NULL`;
  - `charges_campus_fk (campus_id, school_id) → campuses (id, school_id)
    ON DELETE RESTRICT` (Campuses are deactivated, never deleted);
  - `charges_school_campus_index (school_id, campus_id)`, justified in 49.7.
- **Backfill:** only `campus_id` is written. No other column or table is
  touched.
- **Freeze:** `fees_reject_recognized_charge_mutation` (the existing
  recognized-fields trigger) also refuses any `campus_id` change.
  - There is no owner or maintenance exemption, like the trigger's other
    columns.
  - The one-time cancellation stays the only UPDATE.
- **Source guards** (`*_charge_campus_guard`, SECURITY INVOKER, pinned
  `search_path`) refuse a source row whose Charge's Campus differs from its
  own historical Campus:
  - BEFORE INSERT on `fee_assessments` and `late_fee_assessments`;
  - BEFORE INSERT OR UPDATE OF `charge_id` on `library_fines` and
    `canteen_orders` (the Canteen guard reads the Outlet FOR SHARE).
  - They share `fees_charge_campus_matches()`: no PUBLIC EXECUTE, EXECUTE
    granted to `school_os_app`.
- **Rollback:**
  - Rollback-proof (one step) is IDENTICAL; the rollback removes 166 schema
    lines; `verify-database` failed=0.
  - Post-use, `down()` REFUSES when any stored Campus differs from what the
    pre-7G-1A runtime derivation would now return (`rollbackBlockers()`), so
    dropping the column never silently re-homes debt.
  - Proven: after a B placement is cancelled behind a B Charge, the blocker
    count is 1.

**49.5 Raw-SQL proof as `school_os_app` (`ChargeCampusIntegrityTest`).**
- An UPDATE of `campus_id` (to another Campus or to NULL) is refused.
- The existing cancellation still succeeds and keeps the Campus.
- Another School's Campus is refused by `charges_campus_fk`.
- A fee assessment, late fee or Canteen order pointing at a Charge of
  another Campus is refused.
- RLS stays forced on `charges`.

**49.6 Historical stability (pinned; `ChargeCampusAttributionTest`).**
- **Transfer:** a fee-run Charge on A and its late fee stay A after a
  transfer to B; the later T2 run charges the B placement.
- **G0 F-1 closed:** cancelling the B placement after a B Charge leaves it
  B. It neither falls back to A nor becomes unavailable.
- **Later placement:** a later same-year placement changes nothing.
- **Leaver and inactive Campus:**
  - A leaver keeps both Charges' Campuses.
  - A deactivated Campus keeps its Charges.
  - The leaver's debt stays visible under the stored Campus filter.
- **NULL:**
  - An unplaced Student's Charge is NULL.
  - A Campus filter never returns it.
  - "Whole school (no campus)" and the unfiltered list do.
- **Other sources:**
  - A Canteen Charge takes the Outlet Campus, although the Student is on
    another Campus; a School-wide Outlet gives NULL.
  - A Library fine takes the Loan Campus, although the Student has no
    placement.
- **Audit:** `charge.assessed` adds `campusId` and `campusSource` (ids
  only).

**49.7 Outstanding Fees in SQL.**
- **Filter:** `ChargeService::collectionFacts($school, $year,
  ChargeCampusFilter)` narrows by the stored Campus in the query
  (`campus_id = ?`, or `IS NULL` for "Whole school (no campus)").
  - The PHP closure filter, the Enrollment query behind it and
    `ChargeCollectionFact::assessedOn` are removed.
  - The query count drops from two to one.
- **Authority:** the request `campus_id` is still validated against this
  School's Campuses plus `unavailable`. It only narrows a whole-School
  reader's list and never widens it (no Finance key is Campus-aware).
- **Wording:** "Charge campus" with a note that it never follows the current
  Campus.
- **EXPLAIN:** on a synthetic 200k-row table shaped like `charges` (20
  Schools, 5 Campuses each), the stored-Campus predicate with only
  `(school_id)` read the School's 10,000 rows and filtered 8,000 (13.2 ms).
  With `(school_id, campus_id)` it read the Campus's 2,000 rows (8.0 ms),
  and the `IS NULL` filter uses the same index. Hence the index.

**49.8 Retired.** `StudentEnrollmentReadService::campusAttribution()` is
removed (its only caller was the Outstanding filter). The ORG.7D-2
docblocks no longer point at it. `ChargeCampusAttributionArchitectureTest`
pins:
- no `campusAttribution(`;
- no current-Campus reader (`StudentOperationalCampusResolver`,
  `StudentOperationalEligibility`, `StudentPurposeLookup`,
  `constrainToCurrentPlacement`) in Fees, Payments, Finance or the Finance
  controllers;
- no PHP charge-line filter.

**49.9 Unchanged.**
- **Payments** stay School-wide: no `campus_id` on payments, allocations,
  receipts or corrections (pinned). Allocation, reallocation, receipt
  numbering and corrections are untouched.
- **`PaymentReceiptBackfillTest`:** the outbox helper has no ORDER BY, and
  the backfill's frozen clock even predates the live settlement. The order
  is incidental, so the test compares the issuance kinds as a multiset; no
  product change.
- **Legal:** E30, E31, E32 and E34 are unchanged. Stored attribution makes
  no fee, rate or late fee lawful.

**49.10 Roles and G1 planning.**
- **Pinned:**
  - No `finance.*` key is in `CampusScopeCatalogue::ADOPTED`.
  - `cashier` and `accountant` are not Campus-grantable.
  - `principal` holds no Finance key.
- **Mechanism to keep `accountant` whole-School in G1 (G1A-D7):**
  `StaffRoleCatalog::campusScopable()` derives scopability from holding ANY
  adopted key. Adopting `finance.charges.view` or `finance.payments.view`
  would therefore make BOTH `cashier` and `accountant` Campus-grantable.
  - G1 must add an explicit, declared exclusion: a role holding any
    whole-School Finance authority key is never Campus-scopable. The keys
    are `finance.ledger.*`, `finance.accounts.manage`,
    `finance.periods.manage`, `finance.fee_structures.manage`,
    `finance.fee_assessments.run`, `finance.charges.manage` and
    `finance.fee_concessions.*`.
  - Declare it beside the access-administration exclusion it extends (the
    database scope trigger already enforces that part), with a test.
  - `accountant` holds several of those keys; `cashier` holds none.
  - This is not built in 7G-1A.

**49.11 Independent security review.** No HIGH and no MEDIUM finding.
- **Fixed before publication:**
  - LOW-1: a placement recorded later but backdated over the Charge date
    was silently adopted by the backfill. It is now ambiguous (pinned).
  - LOW-2: the migration assumed the admin role bypasses RLS. It now fails
    closed otherwise (it would have backfilled nothing and allowed a lossy
    rollback).
  - LOW-3: a check-to-backfill window. It now takes SHARE locks first and
    backfills only unambiguous rows.
  - LOW-6: the backfill derivation is pinned against Canteen and Library
    Charges too.
  - INFO-7: only `ChargeService` may call `withResolvedCampus()` (pinned).
- **Accepted residuals:**
  - LOW-4: a false-positive abort on noisy placement history. It fails
    closed and needs owner remediation, never a guess.
  - LOW-5: an Outlet's Campus is fixed by the application (no update path)
    but not by a database freeze, so the Canteen backfill reads today's
    Outlet Campus. That equals the Campus at fulfil for every row; there is
    no history to check against.
  - No raw refusal test for the Library-fine guard: it shares
    `fees_charge_campus_matches()` with the three tested guards.
- **Deploy note:** this schema and its code ship together. Older code would
  insert NULL-Campus source Charges, which the source guards refuse.

**49.12 Validation.**
- **Affected and shared suites** (Fees, Payments, Finance, Canteen, Library,
  Students, Authorization, Postgres, App, Retention, Transport, Hostel,
  Admissions, and every *Architecture*/*Guard* test): 4310 passed. The 4
  failures are the real-MinIO tests (environment-only).
- **G1A suites:** 24 passed after the review fixes, including both race
  orders, run 3×.
- **Rollback-proof:** IDENTICAL; `verify-database` failed=0.
- **Static and frontend gates:** Pint, Larastan, vue-tsc, ESLint (3
  existing v-html warnings), Prettier and the build all pass.
- **Full regression:** one exact-SHA full CI on the published candidate.

## 50. ORG.7G-1 — Finance Campus read surfaces: stored Charge scope, atomic Payment visibility, debtor lookup (as built, 2026-10-21)

Owner authorization: ORG.7G-1, decisions G1-D1 to G1-D12. Baseline
`origin/main` = `62742f0d` (ORG.7G-1A, §49). No migration, no new
capability, no Finance write made Campus-aware.

    adopted              finance.charges.view, finance.payments.view (domain `finance`)
    Charge               Campus reader sees it only by STORED charges.campus_id in scope;
                         NULL = whole-School only
    Payment / receipt /  Campus-visible only when EVERY Charge it touches (allocations AND
    collections row      correction lines) has exactly ONE non-NULL Campus, in scope --
                         atomic; A+B or NULL-touching = whole-School only, even for A+B
    Charge balance       always correct (G1-D7); a hidden Payment shows as an amount only
    debtors              OPEN in-scope Charge (leavers included); id/studentNumber/displayName
    Campus persona       cashier only; accountant/school_admin never; principal holds no finance.*

**50.1 Role eligibility (G1-D9..G1-D11).**
- **Rule:** `CampusScopeCatalogue::CAMPUS_INCOMPATIBLE` (exact keys, plus
  families ending in '.'):
  - `finance.ledger.`
  - `finance.accounts.manage`
  - `finance.periods.manage`
  - `finance.fee_structures.manage`
  - `finance.fee_assessments.run`
  - `finance.charges.manage`
  - `finance.fee_concessions.`
- **Effect:** `StaffRoleCatalog::campusScopable()` refuses any template
  holding one, decided by authority held, never by role name. Every Campus
  grant passes through `StaffAccessService::grantRole()` (invitations carry
  no Campus), which already checks `campusScopable()`.
  - The Staff accounts page and the service both refuse `accountant` +
    Campus (`role_not_campus_scopable`, 422).
  - The whole-School grant is unchanged.
- **Before/after across all 17 templates:**
  - Newly Campus-scopable: `cashier` only.
  - Newly prohibited: none. `accountant` and `school_admin` were never
    scopable; the rule now holds them out on Finance grounds too.
  - Unchanged: every other template.
- **Existing grants:** no active Campus grant of a Finance-bearing role
  existed (DDEV and the test seed).
- **cashier@Campus confers:** only `finance.charges.view` and
  `finance.payments.view`. Its `finance.payments.record` is not adopted, so
  it confers nothing; payment entry stays whole-School.
- **CampusAdoptionImpactTest pins:**
  - HOLDERS: `accountant` and `cashier` added; `school_admin` gains the two
    keys.
  - CAMPUS_SCOPABLE: `cashier` added.
  - FINANCE_CAMPUS_CONFERRED: `{cashier: [charges.view, payments.view]}`.
  - Every protected-authority holder is non-scopable.
  - `principal` and the bounded-side-effect roles (Canteen, Library,
    Transport, Hostel, Admissions, front office, stores, HR) hold no
    `finance.*` key.
  - The two keys are recorded as owner-approved HIGH_RISK.

**50.2 Access architecture.**
- **The read services stay authorization boundaries.** Each resolves the
  actor's CampusScope itself (`authorizeCampusScopeFor`, 403 when held
  nowhere) and applies it in SQL before any row returns:
  - `ChargeReadService` (stored Campus);
  - `OutstandingFeesReadService` (the intersection of both keys' scopes, a
    new `CampusScope::intersect()`);
  - `PaymentReadService`, `PaymentReceiptReadService`,
    `DailyCollectionsReadService` (`PaymentCampusVisibility`);
  - `PaymentCorrectionReadService` (refuses an invisible Payment);
  - `LateFeeReadService::linksForCharge` (nothing for an out-of-scope
    Charge).
- **`FinanceCampusAccess` (Payments)** is the one access layer controllers
  read through. It composes those services, adds the debtor lookup and the
  per-Charge Payment split, and exposes `restrictCharges` and
  `restrictPayments`.
- **Rule 4:** Fees owns the Charge SQL (`campusAttributionQuery()`,
  `liveChargesQuery()`); Payments never names `charges`.
- **Whole-School-only service reads** use a new
  `authorizeWholeSchoolScopeFor()`: fee and late-fee run reads, the fee
  statement.

**50.3 Surfaces (C6).**
- **Web:**
  - `app.finance.outstanding.index`, `.charges.index`, `.charges.show` and
    the new `.debtors.search` (`capability:finance.charges.view,campus`);
  - `.payments.index` (collections), `.payments.show`, `.payments.receipt`
    (`capability:finance.payments.view,campus`).
  - The read actions moved to `ChargeHistoryController`,
    `PaymentRecordController` and `FinanceDebtorController`. Charge create,
    assess and cancel, payment recording and corrections stay on their
    whole-School controllers.
- **API:** `api.v1.schools.charges.index/.show` (through
  `ChargeReadService`) and `.payments.index/.show/.receipt` (through
  `FinanceCampusAccess`) carry `,campus` floors.
- **Navigation:** a Campus reader lands on the new "Outstanding fees" and
  "Payments received" entries. The Finance hub is a declared whole-School
  hub (`WHOLE_SCHOOL_HUBS['finance']`) and returns 403 to a Campus-only
  reader.
- **Ledger internals** (accounts, journal entry ids) are shown only to a
  whole-School reader. Correction actions stay whole-School capability
  checks, so a Campus reader sees history but no actions.

**50.4 Declared whole-School operations (exact routes):**
- Fee runs: `app.finance.fee-runs.index/.show`,
  `api.v1.schools.fee-assessment-runs.index/.show/.items`.
- Late-fee runs: `app.finance.late-fee-runs.index/.show`,
  `api.v1.schools.late-fee-runs.index/.show/.items`.
- The full Student fee statement (§50.6): `app.finance.fee-statements.index`,
  `.students.search`, `.show`, and `api.v1.schools.students.fee-statement`.
  - Floor `capability:finance.charges.view`; the service also requires
    whole-School `finance.payments.view`.

**50.5 Payment visibility SQL (G1-D4..G1-D6).**
`PaymentCampusVisibility::restrict()` adds one correlated EXISTS per
candidate Payment:

```
SELECT 1 FROM (allocations ∪ correction lines of this Payment) touched
  JOIN Fees' (id, campus_id) ON id = touched.charge_id
HAVING count(DISTINCT campus_id) = 1
   AND bool_and(coalesce(campus_id = ANY(scope), false))
```

- A Payment with no touched rows yields no row, so it is invisible.
- A NULL Campus fails `bool_and`, so it is invisible.
- A+B gives `count = 2`, so it is invisible.
- Whole School: no restriction. None: `1 = 0`.
- Reallocated Payments count their destination Charges, so moving money onto
  another Campus makes the Payment whole-School only.
- Collections totals use the same restricted base, so no Payment is counted
  under two Campuses.
- Receipts keep the School-wide series. A Campus reader sees gaps, which
  reveal only that other receipts exist (no amount, Student or Campus);
  accepted (INFO).

**50.6 Statement: DEFERRED (whole-School).** The Student fee statement
totals every Charge, including other Campuses and NULL-Campus ones, and lists
mixed-Campus Payments. A Campus slice would misstate the Student's position,
so it stays declared whole-School. A Campus reader uses Outstanding fees and
the Charge pages instead.

**50.7 Filters.** Order: capability → `CampusScopeResolver` →
`FinanceCampusAccess`/service SQL restriction → request filters (Student,
standing, year, Grade/Section, Campus, receipt, method).
- **Request filters only narrow.** An out-of-scope `campus_id` returns
  nothing, and Campus options list only Campuses in scope.
- **Grade/Section** means "in this class NOW" (the current placement). It
  is offered only to a whole-School `enrollments.view` holder and never
  re-attributes a Charge.
- **Direct ids:** an out-of-scope, mixed or unknown Charge, Payment or
  receipt is the same 404, revealing nothing.

**50.8 Debtor lookup (G1-D8).**
`FinanceCampusAccess::debtors()`:
- **Eligibility:** Students with an uncancelled Charge in scope where
  amount − live adjustments − `payments_effective_allocated()` > 0, in one
  SQL statement. Active, transferred, inactive and leaver Students alike.
- **Projection:** a new Students-owned `StudentIdentityLookup` (escaped
  name/number search, at most 50 rows) returning `id`, `displayName`,
  `studentNumber` only.
- **Never used:** the SIS purpose lookup, the Campus Directory, a current
  Campus or Profile authority.
- **Audit:** `finance.debtor_lookup.viewed` (result count and scope).

**50.9 Proven (`FinanceCampusReadTest`, `FinanceCampusRoleGrantTest`).**
- **Outstanding (A, B, whole School):**
  - A sees its Charge, the mover's old A debt and the leaver; B sees its
    Charge and the mover's B debt; whole School sees all, the NULL debtor
    included.
  - The A balance includes a hidden mixed Payment's effect (55.00).
- **Charges:**
  - The A list holds exactly the A Charges.
  - B, NULL and unknown Charges are 404.
  - Visible Payment PA is listed and hidden PN is shown as 5.00 with no
    ledger ids.
- **Payments:** A sees PA; B sees PB; A+B sees PA and PB (not PAB or PN,
  gross 90.00); whole School sees all four. Mixed Payments and their
  receipts are 404 for A+B.
- **API:** the same rules; the statement is 403.
- **Debtors:** A finds the leaver; B never does; identity fields only.
- **Refusals:**
  - `principal`, librarian and canteen operator @Campus get 403 everywhere.
  - Campus cashier gets 403 on the hub, statements, fee runs, recording,
    corrections and charge creation.
  - An inactive Campus or a disabled Fees module gives 403; reactivation
    restores access.
- **Grants:** `cashier@Campus` granted with scope on the two keys only;
  `accountant@Campus` refused on the page and at the service.

**50.10 Performance (EXPLAIN, DDEV).**
- **Payment visibility:** walks the `(school_id, created_at)` index with one
  subplan per candidate (allocation and correction-line `(school_id,
  payment_id)` index probes): 3.2 ms for 25 rows.
- **Debtors:** one semi-join over Students with
  `charges(school_id, student_id)` probes: 12 ms, 36 Students.
- **Outstanding:** filters `charges(school_id, campus_id)` (§49).
- No index added: none of these plans showed a need.
- Recorded: the Payment list is not date-bounded on the API, so its cost
  grows with candidate Payments scanned (index probes per candidate). Revisit
  with evidence at scale.

**50.11 Unchanged.**
- Payments (no Campus column), allocation, receipt numbering, provider
  callbacks, async jobs and replay.
- Every Finance write, configuration, run, concession, correction and the
  ledger.
- Student and Guardian authority, HR, Admissions conversion, LC-5..10.
- E30, E31, E32 and E34 (no legal gate claimed closed).

**50.12 G2 (Campus cashier writes) prerequisites (not built).**
- **Entry:** adopt `finance.payments.record` only with owner approval. That
  makes payment entry Campus-aware and nothing else.
- **Authority:** under `ManualPaymentRecordingService`'s existing Charge FOR
  UPDATE locks, every allocated Charge must have a non-NULL stored Campus,
  ONE Campus, in scope. Mixed is refused generically, and a NULL Charge is
  whole-School only.
- **Picker:** reuse the debtor lookup and per-Student open-Charge lists
  restricted by stored Campus.
- **Replay:** the server-issued idempotency key must re-check the actor's
  CURRENT Campus scope against the stored Payment's allocations before
  returning a replay. A scope loss refuses rather than replays (rule 32).
- **Receipt:** issued as today, School-wide series.
- **Stays whole-School:** corrections, reversals and refunds.

**50.13 Independent security review.** No HIGH and no MEDIUM finding.
- **Fixed:**
  - LOW-2: `FinanceCampusAccess::chargePayments()` now re-checks the Charge's
    own finance.charges.view scope instead of trusting the caller.
  - LOW-3: tests added for a reallocation onto another Campus (the Payment
    becomes whole-School only), settled and cancelled debt never making a
    debtor, and the Campus API Charge list.
  - LOW-4: UUID guards before the Campus-path uuid comparisons.
- **Owner decision (not built), LOW-1:** the accountant ban is enforced by
  `StaffRoleCatalog::campusScopable()` on the one grant path
  (`StaffAccessService::grantRole`, Staff accounts page and service).
  - The database scope trigger still refuses only access-administration
    roles.
  - A direct runtime-role INSERT could create `accountant@Campus`. That
    grant would confer only the adopted read keys, never ledger or write
    authority.
  - Extending `assert_membership_role_assignment_scope` to
    CAMPUS_INCOMPATIBLE needs a migration and is recommended before G2.
- **Accepted INFO:**
  - School-wide receipt-number gaps (no amount, Student or Campus
    disclosed).
  - The debtor lookup reflecting settled state under
    finance.charges.view (cashier holds both keys).
  - `otherPaymentsAmount` revealing that hidden Payments exist and their
    amount on an in-scope Charge (G1-D7, by design).

**50.14 Validation.**
- **Affected and shared suites** (every *Architecture*/*Guard* test, Fees,
  Payments, Finance, Authorization, Identity, Students, Canteen, Library,
  Transport, Hostel, Admissions, Postgres, App, Retention, Portal,
  Platform/Modules): 4506 passed. 22 failures are environment-only: 5
  real-MinIO, and 17 Identity tests that assert `APP_URL=localhost:8000`
  under DDEV's `https://lycenza.ddev.site`.
- **After the review fixes:** Finance, Fees, Payments, Campus authorization
  and the role-grant tests: 648 passed.
- **Static and frontend gates:** Pint, Larastan, vue-tsc, ESLint (3
  existing warnings), Prettier and the build all pass.
- **Full regression:** one exact-SHA full CI on the candidate.

## 51. ORG.7G-2 — Campus cashier Payment entry: one stored Campus under Charge locks, scope-checked replay, database Finance-role backstop (as built, 2026-10-21)

Owner authorization: ORG.7G-2, decisions G2-D1 to G2-D11. Baseline
`origin/main` = `3008b832` (ORG.7G-1, §50). One migration, no new
capability, no new table or column, no `payments.campus_id` (G2-D3).

    adopted (new)        finance.payments.record (domain `finance`) -- payment ENTRY only
    Campus persona       cashier@Campus (G2-D1); accountant stays whole-School, DB-enforced (G2-D2)
    allocation rule      EVERY allocated Charge: non-NULL STORED Campus, all ONE Campus C,
                         C in the actor's CURRENT scope, C active -- else one generic 422
    A+B / NULL           whole-School only, even for an A+B actor (G2-D5, G2-D6)
    Student placement    irrelevant: transferred Students and leavers are collectable (G2-D7, G2-D8)
    receipt              unchanged, School-wide series (G2-D9)
    corrections          unchanged, whole-School only (G2-D10)

**51.1 Database backstop (§50.13 LOW-1 closed).** Migration
`2026_12_27_090000_guard_campus_finance_role_grants`:
- **Grant side:** `assert_membership_role_assignment_scope()` (the existing
  scope trigger) gains one block. A row with a non-NULL `campus_id` is
  refused when its role holds any `CAMPUS_INCOMPATIBLE` key:
  `finance.ledger.%`, `finance.accounts.manage`, `finance.periods.manage`,
  `finance.fee_structures.manage`, `finance.fee_assessments.run`,
  `finance.charges.manage`, `finance.fee\_concessions.%`. Decided by
  capability, never by role name.
- **Catalogue side:** a new `BEFORE INSERT OR UPDATE` trigger on
  `role_capabilities` (`role_capabilities_campus_finance_guard()`) refuses
  adding a protected key to a role that has an active (unrevoked) Campus
  grant. `membership_role_assignments` is FORCE-RLS, so the check is
  meaningful only for a writer that bypasses RLS: any other writer of a
  protected key is refused outright (fail closed, never an empty EXISTS).
- **Precondition:** the migration requires the admin connection with RLS
  bypass (as §49's migration does), then refuses to run (changing nothing)
  if any active Campus grant of a protected role already exists. DDEV and
  the test seed had none.
- **Rollback:** `down()` drops the new trigger and function and restores the
  previous scope function body exactly. Proven by the rollback-proof
  (schema dump IDENTICAL after rollback and re-apply, 50 lines removed after the review fixes (43 in the first proof); *(ORG.7G-3 correction, 2026-10-21)*,
  `platform:verify-database` failed=0).
- **Proven** (`FinanceRoleCampusScopeGuardTest`, raw INSERTs below every
  application path): `accountant@Campus` refused; `school_admin@Campus`
  still refused by the access-administration rule; `cashier@Campus` and a
  whole-School `accountant` allowed; the function source mirrors
  `CAMPUS_INCOMPATIBLE` and names no role; a protected key cannot be added to
  a Campus-granted `cashier` by INSERT or UPDATE, with no tenant context set
  (as migrations and the seeder run); the catalogue guard carries the
  RLS-bypass refusal.
- **Deployment note:** catalogue writes already need the admin connection
  (rule 93); a deployment's admin role must bypass RLS (already required by
  §49's migration).

**51.2 Payment-write key audit.** `finance.payments.record` is used by:
- the three record routes (`payments.record.create`,
  `.students.search`, `.store`) -- now C6;
- `ManualPaymentRecordingService::settlementAccounts()` -- Campus-aware
  (any scope may list the School's asset accounts; the account is a ledger
  settlement target, not Campus data);
- reallocation (`PaymentCorrectionService::REALLOCATE_CAPABILITIES`), which
  ALSO needs `finance.charges.manage` -- protected, checked through the
  whole-School Gate, and never Campus-scopable. A Campus cashier therefore
  cannot reallocate.
No API payment-entry route exists (`/api/v1` has none), so the API is
unchanged and Campus API entry is DEFERRED, not built.

**51.3 Write authority (`FinancePaymentAccess`, Payments).**
`ManualPaymentRecordingService::record()`:
1. resolves the CampusScope for `finance.payments.record` FIRST (403 when
   held nowhere, including an inactive Campus or the fees module disabled);
2. validates the shape (unchanged);
3. in the transaction: `SchoolOperationalGuard` (School FOR SHARE), then the
   per-key advisory lock;
4. an existing key: `assertReplayable()` BEFORE `replay()` (51.5);
5. otherwise, for a Campus scope only,
   `lockAllocationsForCampusWrite()`:
   - every Charge id must be a UUID (the controller lowercases ids, so PHP
     and PostgreSQL agree on one lock order);
   - Fees' `ChargeService::lockChargeCampusesForAllocation()` locks every
     requested Charge of this School FOR UPDATE in ONE statement, in
     ascending id order, and returns each STORED Campus;
   - a missing id, another School's Charge, a NULL Campus, two Campuses or
     an out-of-scope Campus: the one generic
     `CampusPaymentUnavailableException` (422 `PAYMENT_UNAVAILABLE`);
   - the one Campus must be active (`ActiveCampus::find`, FOR SHARE);
6. then the existing settlement path (`SettledPaymentRecorder`, which
   re-takes the same Charge locks in the same order -- no upgrade), receipt,
   audit and outbox.
- Whole-School actors never enter the guard: mixed and NULL-Campus payments
  behave exactly as before.
- No Student, Enrollment or current-placement read is involved: the
  Charge's stored Campus is immutable (§49), so historical debt decides.
- **Audit:** `finance.payment.recorded` metadata gains `scope`
  (`school`/`campus`) and `campusId` (the authorized Campus). No PII.

**51.4 Lock order.** advisory key lock → School FOR SHARE → Charges FOR
UPDATE (id order, one statement) → Campus FOR SHARE → recorder re-lock
(same rows, same order) → receipt counter. Opposite-order allocation sets
cannot deadlock (51.7).

**51.5 Replay (rule 32).** Both replay paths (the advisory-lock path and
the unique-index fallback) call `assertReplayable()` before anything is
returned. For a Campus scope the stored Payment must still be visible to
the actor's CURRENT scope under §50.5's atomic rule; otherwise the same
generic refusal. A whole-School scope is unchanged. Losing the scope
entirely is a 403 before the service is reached.

**51.6 Surfaces (C6).**

    route                                      floor                                   access method
    app.finance.payments.record.create         capability:finance.payments.record,campus  ManualPaymentRecordingService::outstandingChargesForStudent
    app.finance.payments.record.students.search capability:finance.payments.record,campus FinanceCampusAccess::collectionDebtors
    app.finance.payments.record.store          capability:finance.payments.record,campus  ManualPaymentRecordingService::record

- **Form:** a Campus recorder is offered only Charges whose stored Campus
  it covers, filtered in SQL before the 100-Charge bound; the Student label
  and list appear only when at least one such Charge is still outstanding
  (the same rule as the debtor search) -- nothing about other Students. The page shows each Charge's
  Campus and explains one payment = one Campus.
- **Search:** a Campus recorder searches only debtors with open debt in
  scope (§50.8's debtor query, leavers included) -- never a Student
  directory. Whole-School search is unchanged
  (`StudentIdentityLookup::search`).
- **Links (display only):** Charge history shows "Record payment" only when
  `FinanceCampusAccess::canCollect()` (whole-School, or the Charge's stored
  Campus in scope); the Payments index shows "Record" when the actor holds
  the key anywhere. The server decides on store regardless.
- `CampusScopeCatalogue::WHOLE_SCHOOL_HUBS['finance']` gains the key: the
  Finance hub still opens on it only School-wide; a Campus recorder starts
  from the Campus-aware Payments received page.

**51.7 Proven.**
- `FinanceCampusPaymentEntryTest`:
  - a leaver's and a transferred Student's Campus-A debt is collectable by a
    cashier@A, receipt `RCPT/…`, per-Campus collections, audit
    `scope=campus`;
  - mixed A+B, a NULL Charge, an out-of-scope Charge: refused generically,
    no partial Payment; an A+B cashier may pay A-only and B-only payments,
    never A+B; a whole-School actor may still pay a mixed set;
  - over-allocation, sum and shape invariants unchanged;
  - replay after the A grant is revoked is refused generically, and with no
    scope left the route is 403;
  - an inactive Campus and a disabled fees module are 403;
  - the form and the search show only in-scope debt.
- `FinanceCampusPaymentConcurrencyTest` (two real OS processes, contender
  observed blocked):
  - two payments on one Charge never over-allocate it;
  - overlapping Charge sets named in opposite order both commit (no
    deadlock);
  - payment first, then Campus deactivation: both commit;
  - deactivation first: the payment is refused.
- `FinancePaymentCampusArchitectureTest`: scope before the transaction;
  one guard call; both replays checked before `replay()`; the guard is
  called only by the recording service and reads no Student placement;
  exactly three adopted Finance keys; `PaymentCorrectionService` has no
  Campus path and reallocation needs a Campus-incompatible key.
- `FinanceCampusRoleGrantTest`, `CampusAdoptionImpactTest`: cashier@Campus
  confers `charges.view`, `payments.record`, `payments.view` and never a
  protected key; `finance.payments.record` recorded as owner-approved
  HIGH_RISK.

**51.8 Unchanged.**
- Corrections (reallocation, reversal, refund), cancellation, manual
  Charges, fee and late-fee runs, concessions, configuration, ledger and the
  statement stay whole-School. The corrections route is a C3 multi-kind
  service contract (reversal and refund do not need `payments.record`), so
  it is not a `WHOLE_SCHOOL_OPERATIONS` declaration; the architecture test
  and the 403 tests pin it.
- Receipt numbering stays School-wide (G2-D9); gaps disclose nothing.
- accountant@Campus and principal Finance authority: not granted.
- Legal gates E30/E31/E32/E34: unchanged (G2-D11).
- Admissions conversion, SIS lifecycle, LC-5..LC-10: untouched.

**51.9 Deferred (owner approval needed).** Campus API payment entry (no API
route exists); Campus corrections, refunds and reversals; accountant Campus
scope; statement Campus adoption.

**51.10 Independent security review.** No HIGH and no MEDIUM finding.
- **Fixed:**
  - LOW-1: the database backstop could pass silently for a writer without
    RLS bypass (FORCE RLS hides the grants). The migration now asserts
    bypass, and the catalogue guard refuses a protected key from any
    non-bypassing writer; tested with no tenant context.
  - LOW-2: the `role_capabilities` guard now fires on INSERT OR UPDATE (as
    its sibling scope and root-reserved triggers do).
  - LOW-3: the record form showed a settled Student's name to a Campus
    recorder; it now needs outstanding in-scope debt.
  - LOW-4: the 100-Charge bound ran before the Campus filter; the filter is
    now SQL, before the bound.
  - LOW-5: tests added for an unknown, another School's and a non-UUID
    Charge id, a settled Student, a non-vacuous NULL-debtor search and the
    unchanged whole-School search.
  - INFO: Charge ids are lowercased at the controller (one lock order for
    mixed-case input).
- **Accepted INFO:**
  - The scope is resolved before the transaction, so a grant revoked during
    the request is not re-checked inside it -- the same as whole-School
    recording today. A Campus deactivation is (Campus FOR SHARE).
  - Replay answers differ (not visible / content mismatch / unknown key);
    observable only with another user's server-issued key.
  - A Campus cashier may settle into any active School asset account, as a
    whole-School recorder may: accounting control, owner decision.
  - The record-form debtor search is not read-audited (it never was).

**51.11 Validation.**
- **Affected and shared suites** (every *Architecture*/*Guard* test, Fees,
  Payments, Finance, Authorization, Identity/Staff, Students, Canteen,
  Library, Transport, Hostel, Admissions, Postgres, App, Retention, Portal,
  Platform/Modules, Demo): 4467 passed. 14 failures:
  - 7 Identity tests and 4 real-MinIO Retention tests, environment-only
    (`APP_URL=localhost:8000` under DDEV's `https://lycenza.ddev.site`; no
    real MinIO);
  - 2 ran against source edited mid-run (the guard and `ChargeService`
    pins), green on the final tree;
  - 1 real: the whole-School recording audit pin now lists `scope=school`
    and `campusId=null` (updated).
- **After the review fixes:** Finance, Fees, Payments, Campus
  authorization, the database guard and the role-grant tests: 666 passed.
- **Migration:** rollback-proof IDENTICAL (50 schema lines removed by the
  rollback), `platform:verify-database` failed=0; re-applied on DDEV.
- **Static and frontend gates:** Pint, Larastan, vue-tsc, ESLint, Prettier
  and the build pass.
- **Full regression:** one exact-SHA full CI on the candidate.

## 52. ORG.7G-3 — Finance Campus closure and UAT readiness audit (read-only, 2026-10-21)

Owner authorization: ORG.7G-3, closure / UAT-readiness / security audit.
Baseline `origin/main` = `a2944052` (ORG.7G-2, §51). No production code,
schema, capability, route or legal-gate change. Documentation only.

    TECHNICAL (v1 scope)   COMPLETE
    LOCAL / UAT            READY (plan: docs/modules/FINANCE.md "Finance Campus UAT readiness")
    PRODUCTION             BLOCKED by E30/E31/E32/E34 and the general production gates (52.12)

**52.1 Frozen v1 scope.** Campus-adopted Finance keys are exactly
`finance.charges.view`, `finance.payments.view` (reads) and
`finance.payments.record` (payment entry). The Campus persona is `cashier`.
`accountant`, `school_admin` and any role holding a `CAMPUS_INCOMPATIBLE` key
are whole-School; `principal` holds no `finance.*` key. Deliberate
boundaries, not defects: API payment entry, the Student fee statement,
payment correction, reallocation, reversal, refund, Payment cancellation
(none exists), manual Charges, fee and late-fee runs, concessions, fee
configuration, accounts, periods, the ledger and accountant@Campus. Any of
them needs a new owner-approved decision.

**52.2 Capability inventory (all 15 `finance.*` keys).**

    CAMPUS_V1_READ                    finance.charges.view, finance.payments.view
    CAMPUS_V1_WRITE                   finance.payments.record
    WHOLE_SCHOOL_CONFIGURATION        finance.fee_structures.manage, .fee_structures.view,
                                      .accounts.manage, .periods.manage
    WHOLE_SCHOOL_HIGH_RISK_MUTATION   finance.charges.manage, .fee_assessments.run,
                                      .fee_concessions.request/.approve/.view
    WHOLE_SCHOOL_LEDGER               finance.ledger.post, .ledger.reverse, .ledger.view
    LEGAL/REGULATORY_GATED            none by key; E30/E31/E32/E34 gate features (52.12)
    UNUSED/OTHER                      none

No discrepancy. `CampusScopeCatalogue::ADOPTED` holds exactly the three
(pinned by `FinancePaymentCampusArchitectureTest`,
`ChargeCampusAttributionArchitectureTest`, `CampusAdoptionImpactTest`); no
Finance wildcard; every `CAMPUS_INCOMPATIBLE` entry is protected and none
is adopted.

**52.3 Role closure (seeded catalogue).**

    ROLE          ADOPTED KEYS                 PROTECTED KEYS            CAMPUS-SCOPABLE  EXPECTED
    cashier       charges.view, payments.view, none                      yes              yes
                  payments.record
    accountant    the three                    8 (ledger, accounts,      no               yes
                                               charges.manage, runs, ...)
    school_admin  the three                    11 (+ 5 access admin)     no               yes
    principal     none                         none                      n/a              yes
    other 13      none                         none                      n/a              yes

No unexpected Campus Finance persona. *(ORG.7H correction, 2026-10-21: protected-key counts 8 and 11, §53.4.)*

**52.4 Database role-guard closure.** `assert_membership_role_assignment_scope`
refuses a Campus grant of a role holding a protected key, and
`role_capabilities_campus_finance_guard` (INSERT OR UPDATE, fail closed
without RLS bypass) refuses adding one to a Campus-granted role. Both test
capabilities, never role names (`FinanceRoleCampusScopeGuardTest`, 4 tests;
§51.1). Application (`StaffRoleCatalog::campusScopable()` over
`CAMPUS_INCOMPATIBLE`) and database agree: the trigger source is pinned to
the catalogue list.

**52.5 Charge historical attribution.** One INSERT
(`ChargeService::assess`, pinned by `only_charge_service_inserts_charges`);
`ChargeCampusAttribution` has a private constructor and named sources only.

    fee run          stored Enrollment (FeeAssessmentItemExecutor)
    late fee         source Charge's stored Campus (LateFeeItemExecutor)
    Canteen          Outlet Campus (CanteenOrderService)
    Library fine     frozen Loan Campus (LibraryFineService via FeeSourceChargeService)
    manual (web/API) the one placement in effect on the creation date, else NULL
    Transport / Admissions / Hostel   intent only (fee_optional_selections) ->
                     charged by a fee run -> fee-run Enrollment
    demo seeder      as manual

`ChargeCampusAttributionArchitectureTest` pins all 8 creator sites; none is
uncovered; no runtime re-derivation remains (`campusAttribution()` retired).
**Immutability scenarios** (`ChargeCampusAttributionTest`): transfer A -> B,
Enrollment cancellation, later same-year Enrollment, leaver and Campus
inactivation are tested directly. An active-year change has no direct test
and needs none: `fees_reject_recognized_charge_mutation` refuses any change
of `campus_id` (and `academic_year_id`), no code updates them, and a manual
Charge reads placements of its own year at creation only.

**52.6 NULL Charge contract.** NULL = whole-School only, everywhere:
`CampusScope::covers` (NULL never covered) and `::restrict` (`whereIn`);
`ChargeReadService`, `OutstandingFeesReadService` (`collectionFacts`, SQL),
`FinanceCampusAccess` (`restrictCharges`, `chargePayments`, `canCollect`),
`PaymentCampusVisibility` (`coalesce(... , false)` makes any NULL-touching
Payment invisible), `FinancePaymentAccess` (any NULL refuses) and
`uncancelledChargesForStudent($campusIds)`. `ChargeCampusFilter::unattributed()`
is a narrowing filter, never authority. Test:
`an_unattributable_charge_is_whole_school_only_and_never_matches_a_campus`.

**52.7 Read closure.** Charges: stored Campus in scope, else 404 (other
Campus, NULL, other School); the Student's current location is irrelevant.
Outstanding fees: Campus authorization first (intersection of both read
scopes), request filters only narrow, the filter means Charge Campus.
Payments, receipts, collections: visible only when the Payment has at least
one allocation (an empty set fails the `HAVING`) and every touched Charge
(allocations and correction lines) has ONE non-NULL Campus in scope;
A+B and NULL-touching Payments are whole-School even for A+B; no partial
projection; receipts follow the Payment (404 otherwise, no probing); one
School-wide receipt series, gaps accepted, never chosen by the actor; a
Payment appears once in its Campus's collections, whole-School totals stay
complete. Balance vs privacy: a Charge's paid/outstanding stay true; a
hidden Payment is an amount only (G1-D7). Debtor lookup: open in-scope debt
(leavers, inactive and transferred Students included), `id`/`studentNumber`/
`displayName` only, no current Campus, no Profile or Guardian data.

**52.8 Write closure (§51).** Every allocated Charge of this School,
locked FOR UPDATE in id order before the decision: non-NULL stored Campus,
ONE Campus C, C active and in the current scope; normal allocation rules;
one transaction (no partial Payment); no Payment Campus column; replay
re-checks the current scope (generic refusal, 403 with no scope); four
two-process races (no over-allocation, no deadlock, deactivation both
orders).

**52.9 Isolation.** A Campus cashier cannot reallocate (needs
`finance.charges.manage`), reverse or refund (`finance.ledger.reverse`),
create or cancel Charges, run assessments or late fees, grant concessions,
or reach configuration, accounts, periods, the ledger or the Finance hub
(declared whole-School hub) — including for a Payment they recorded
(recording confers no ownership). Canteen, Library and Transport roles hold
no `finance.*` key; their fee effects stay owner-approved bounded side
effects. The cashier gains no Canteen, Library, Transport, Student Profile
or Guardian authority.

**52.10 API, statement, entitlement, tenancy.** Finance read API: Campus-
aware where G1 adopted (`/charges`, `/payments`, receipts). Payment-record
API: does not exist (OpenAPI documents recording as browser-only); no
mobile or contract client depends on one; deferred, not broken. The full
Student fee statement is whole-School by design (a Campus slice would
misstate the Student's position); a Campus statement would be a separately
designed, labelled partial statement. Module entitlements intersect Campus
grants (`CapabilityResolver::campusCapabilities`), no per-Campus
entitlement; an inactive Campus confers nothing and its status is re-read on
every call. Tenancy: every query School-bound under the School's RLS
context; another School's ids are refused generically or 404.

**52.11 Database invariants.**

    Charge Campus same-School          charges_campus_fk (campus_id, school_id), RESTRICT
    Charge Campus immutable            fees_reject_recognized_charge_mutation (charges_immutability_trigger)
    source rows agree with Charge      *_charge_campus_guard via fees_charge_campus_matches (4 tables)
    protected role never Campus-granted   assert_membership_role_assignment_scope
    protected key never added to a Campus role   role_capabilities_campus_finance_guard
    Payment fully allocated            payments_fully_allocated_check (deferred CONSTRAINT TRIGGER)
    Charge capacity (no over-allocation)  payments_lock_and_validate_charge_allocation + payments_effective_allocated()
    receipt series School-wide         payment_receipt_counters (per School x financial year)
    RLS forced                         TenantRls on every tenant table; runtime role never BYPASSRLS
Tests: `ChargeCampusIntegrityTest`, `FinanceRoleCampusScopeGuardTest`,
`ChargeCampusConcurrencyTest`, `FinanceCampusPaymentConcurrencyTest`.

**52.12 Legal-gate matrix** (ADR 0058 §6 register; ADR 0074 classification).

    gate  subject                          status                 gates                         dev    UAT        production
    E30   receipt form / GST               LEGAL_REVIEW_REQUIRED  every issued receipt          built  no block   blocks; no code refusal
    E31   late fees / in-year fee changes  LEGAL_REVIEW_REQUIRED  late-fee rules and runs       built  no block   blocks; no code refusal
    E32   RTE / free-seat obligations      LEGAL_REVIEW_REQUIRED  assessment, concessions (RTE) n/a    no block   blocks; no code refusal
    E34   Library fine regulation          LEGAL_REVIEW_REQUIRED  overdue fines                 built  no block   blocks; no code refusal
Approving authority: Legal + Owner (all four). Missing: a qualified answer
for each (or an owner CONDITIONAL amendment backed by a code refusal,
OD-PPR-14a). None is Campus-specific: Campus scope never enables a fee, rate
or late fee (G0-D9). Other production prerequisites: E21 (retention
ratification), E02/E15 (release qualification), E16 (time-bound
exceptions), O1 (25 rows, not satisfied). Nothing here infers approval.

**52.13 Security closure.**

    FINDING                                SEV     PHASE      FIX                                   REGRESSION                         STATUS
    F-1 Charge Campus re-derived on read   MEDIUM  7G-0       stored, frozen charges.campus_id      ChargeCampusAttributionTest et al  CLOSED (7G-1A)
G1A, G1 and G2: no HIGH, no MEDIUM. Accepted LOW/INFO residuals (open by
decision): reallocation same-Student in application code and not year-
checked (whole-School only); fee/late-fee jobs do not re-check the
dispatcher; a Payment may span Students/years/Campuses (handled by atomic
visibility and the write refusal); backfill fails closed on noisy history;
Outlet Campus not DB-frozen (the Canteen guard binds each Charge); no raw
refusal test for the Library-fine guard (shared helper); receipt-number
gaps; debtor lookup reflects settled state; `otherPaymentsAmount` (by
design); scope resolved before the transaction (as whole-School);
replay answers differ by cause; any active asset account may be the
settlement account; the record-form search is not read-audited; a stale
seeder comment on `finance.accounts.manage` (code comment, left for a code
slice).

**52.14 Architecture guards.** Charge attribution, single INSERT, no
re-derivation (`ChargeCampusAttributionArchitectureTest`); Payment no-Campus
and the write/replay guard (`FinancePaymentCampusArchitectureTest`); role
eligibility and impact (`CampusAdoptionImpactTest`,
`FinanceCampusRoleGrantTest`); adoption catalogue and controller entry
(`CampusScopeAdoptionArchitectureTest`); C6 and whole-School declarations
(route coverage guard). Gaps (LOW, no implementation in G3): the
no-re-derivation scan does not include `Http/Controllers/App/Fees`; the
NULL exclusion in `PaymentCampusVisibility` rests on one `coalesce` (tested,
fragile if edited); no direct active-year-change test (structurally
covered, 52.5).

**52.15 Performance.** Outstanding fees: SQL on `charges(school_id,
campus_id)`. Charges: indexed scope filter. Payments/collections: per-
candidate index probes (3.2 ms / 25 rows). Debtors: one semi-join (12 ms /
36 Students). Payment write: one locking statement per payment. Residual:
the API Payment list is not date-bounded, so its cost grows with candidate
Payments — POST-CLOSURE PERFORMANCE ITEM (no UAT-scale evidence of failure).

**52.16 Dependencies.** Not Finance blockers: API Student onboarding,
Admissions conversion, rollover, withdrawal/leaver, reactivation and
historical Student Campus views, bulk transfer (Finance attribution is
stored, so it no longer depends on them); LC-5–LC-10 (paused); reference-
ERP parity (Branch filters, more reports, broader roles) belongs to ORG.9.

**52.17 Documentation corrections (this audit).** §51.1 rollback count (50
after the review fixes); `DOMAIN-MAP.md` Payments row ("no human Payment
mutation route" superseded); `AUTHORIZATION.md` (stale "no grant is
Campus-scoped" marked superseded; SIS and Finance Campus entries);
`ORGANIZATION.md` (ORG.7F/7G status); `FINANCE.md` (UAT readiness plan);
`MASTER-ROADMAP.md` (ORG row).

**52.18 Next.** Finance is the last planned ORG.7 adoption wave (SIS
last, §11.10, then Finance). Recommended, each needing owner authorization:
the ORG.7 programme closure (a read-only, cross-domain Campus closure audit
of §33–§52 and every recorded deferral), then ORG.8 approval-authority
completion. Finance Campus UAT runs under ORG.10 (or earlier on owner
request) from the FINANCE.md plan.

**52.19 Validation.** Every *Architecture*/*Guard* test plus Fees,
Payments, Finance, Campus authorization, the Finance role-grant and
catalogue-snapshot tests: 1547 passed; 1 failure is environment-only (a
real-MinIO Guardian purge test; DDEV has no real MinIO). Documentation guards: 22 passed.
No full regression (documentation only); regression counter 0/5.

## 53. ORG.7H — Campus authorization programme closure audit (read-only, 2026-10-21)

Owner authorization: ORG.7H, programme closure / cross-domain audit / UAT
readiness. Baseline `origin/main` = `a3f55cbb` (§52). No production code,
schema, capability, catalogue, role, RLS or legal-gate change.
Documentation only.

    TECHNICAL (ORG.7, approved v1 scopes)   CLOSED
    LOCAL / INTEGRATED UAT                  READY (docs/product/CAMPUS-INTEGRATED-UAT.md)
    PRODUCTION                              BLOCKED by legal and release gates (53.15)

**53.1 Top-level architecture (reconfirmed, no contradiction).** School is
the only tenant (no `tenant_id`, `organisation_id`/`organization_id` or
Branch entity was introduced; Campus is the canonical Branch). Campus is an
intra-School authorization scope on School role assignments only. Role is
not scope; platform authority is not School authority; capabilities are the
security authority; navigation is a projection; module entitlement is
separate. The evaluation order holds: platform/environment -> legal
availability -> School lifecycle -> module entitlement -> role capability
-> Campus scope -> ownership/relationship/domain validity -> request
assurance (MFA, SoD, idempotency). `CampusScopeResolver` consults the
entitled whole-School capabilities first, then Campus grants (active
Campuses only, entitlement-intersected); ownership, Guardian, HR-self and
reporting-line rules run after it, unchanged.

**53.2 Phases.** 7A foundation + Visitor/Inventory (§33); 7B audit (§34);
7C academic/SIS dependency audit (§35); 7D-0 prerequisite hardening (§36);
7D-1 academic adoption (§37); 7D-2 Student Campus foundation (§38); 7D-3
Hostel/Canteen/Admissions (§39); 7D-4 locked eligibility (§40); 7D-5
Library/Transport (§41); 7E-0 HR foundation (§42); 7E-1 HR adoption (§43);
7F-0 SIS audit (§44); 7F-1 SIS reads (§45); 7F-2 SIS narrow writes (§46);
7F-3 Campus transfer (§47); 7G-0 Finance audit (§48); 7G-1A Charge Campus
(§49); 7G-1 Finance reads (§50); 7G-2 payment entry (§51); 7G-3 Finance
closure (§52).

**53.3 Campus capability inventory (code truth, `CampusScopeCatalogue`).**
38 adopted keys in 15 domains. C6 = route-coverage entries (web + API).

    domain               keys                                                   C6 (api)  authority source
    visitor              visitor.visits.view/.manage                             8 (4)    visit Campus (NOT NULL)
    inventory            inventory.stock.view/.manage                           10 (5)    Location Campus (NULL = School-wide store)
    timetable            timetable.schedule.view/.manage                        18 (9)    Offering/Section Campus
    attendance           attendance.view/.manage                                13 (6)    register's stored Campus
    curriculum_delivery  curriculum.delivery.view/.manage                        9 (5)    Offering Campus
    lms                  lms.content.*, lms.assignments.* (view/manage)         22 (12)   Offering Campus
    examination_papers   examinations.papers.view/.manage                        7 (4)    Offering Campus
    hostel               hostel.residency.view/.manage                           9 (4)    Hostel Campus + Student current eligibility
    canteen              canteen.orders.view/.manage                            13 (6)    Outlet Campus + Student current eligibility
    admissions           admissions.view/.manage (pre-conversion)               12 (6)    application Campus
    library              library.circulation.view/.manage                       10 (4)    copy Campus / loan stored Campus
    transport            transport.routes.view, transport.assignments.*         15 (7)    route Campus + Student current eligibility
    hr                   hr.employees.view/.manage, .assignments.view/.manage   17 (6)    union of current assignments
    students             students.view/.manage, enrollments.view/.manage        14 (5)    current operational placement
    finance              finance.charges.view, .payments.view, .payments.record 15 (5)    Charge stored Campus
    total                38                                                    192 (88)
- Every adopted key has an implemented contract (an access class plus a
  catalogue policy); `hr.employees.assignments.view` has no route floor of
  its own and is read inside `EmployeeOperationalOverviewService` and the
  Profile workspace (§43).
- No wildcard family is adopted; no deprecated key, no `*.teacher`,
  `guardians.`, `portal.`, `school.`, marks, payroll, leave,
  staff-attendance, sensitive-HR or protected Finance key
  (`CampusScopeAdoptionArchitectureTest`, `CampusAdoptionImpactTest`).
- Module entitlement: every adopted key maps to its optional module
  (`ModuleCatalogueArchitectureTest`); a disabled module removes the
  Campus effect exactly like the whole-School one.
- High-risk owner approvals are recorded in
  `CampusAdoptionImpactTest::OWNER_APPROVED_HIGH_RISK` (per adoption
  section).

**53.4 Role / persona matrix (seeded catalogue).** Campus eligibility is
decided by authority held (`StaffRoleCatalog::campusScopable()`): never a
role with access administration, a grant right or a `CAMPUS_INCOMPATIBLE`
Finance key, and only a role holding at least one adopted key.

    role                     Campus-grantable  adopted keys held                         why
    school_admin             no                36 (all but hr.employees.assignments.*)   access administration (5 keys) + 11 protected Finance keys
    principal                yes               33 (no finance.*, no hr assignments keys)  operational, no protected authority
    cashier                  yes               3 finance                                  v1 Finance persona
    accountant               no                3 finance                                  8 protected Finance keys
    admissions_officer       yes               2 admissions                               —
    hr_officer               yes               4 hr                                       —
    hostel_warden            yes               2 hostel                                   —
    canteen_operator         yes               2 canteen                                  —
    transport_coordinator    yes               3 transport                                —
    librarian                yes               2 library                                  —
    front_office             yes               2 visitor                                  —
    stores_officer           yes               2 inventory                                —
    teacher, communications_coordinator, hr_sensitive_records, payroll_officer,
    staff_self_service       no                0                                          nothing to confer (ownership/self rules apply)
No unexpected Campus persona. `school_admin` stays whole-School with its
authority unreduced, and no Campus grant ever satisfies access
administration (whole-School paths read whole-School grants only; the
scope trigger refuses access-administration roles). **principal@Campus**
receives exactly: `visitor.visits.*`, `inventory.stock.*`,
`timetable.schedule.*`, `attendance.*`, `curriculum.delivery.*`,
`lms.content.*`, `lms.assignments.*`, `examinations.papers.*`,
`hostel.residency.*`, `canteen.orders.*`, `admissions.*`,
`library.circulation.*`, `transport.routes.view`,
`transport.assignments.*`, `hr.employees.view/.manage`,
`students.view/.manage`, `enrollments.view/.manage` — no Finance, no
sensitive HR, no Student Profile/history. Finance persona: §52.3
(correction: accountant holds 8 protected Finance keys, school_admin 11).

**53.5 Assignment-scope contract.** `campus_id` NULL = the whole School,
including future Campuses and NULL-Campus records where the domain allows;
`campus_id` C = exactly C for adopted keys; several grants union;
whole-School supersedes; a Campus grant never satisfies `can()`, the Gate,
`schoolCapabilities()`, `capability:` floors, grant authority or the
last-administrator count; no grant was reinterpreted except through an
owner-approved adoption reported by `CampusAdoptionImpactTest`.

**53.6 Inactive Campus.** `CapabilityResolver::campusCapabilities()` reads
Campus status on every call: an inactive Campus confers nothing (reads and
writes); new work on it is refused (`ActiveCampus`); history is kept;
reactivation restores the grant's effect; nothing is rewritten. Domain
detail: whole-School actors keep whole-School reads of inactive-Campus
history; the transfer, onboarding and payment writes lock the Campus FOR
SHARE so a concurrent deactivation is seen or waits.

**53.7 NULL / shared resources.** `CampusScope::covers()` never covers NULL
and `restrict()` uses `whereIn`, so NULL never means every Campus.

    resource                NULL meaning           Campus read  Campus write  history
    Visitor visit           not possible           in scope     in scope      stored Campus
    Inventory Location      School-wide store      no           no            Campus frozen once used (DB, §54; was 53.13 ORG.7H-1)
    Section/Offering/Room   not possible           in scope     in scope      fixed (composite FKs)
    Attendance register     not possible           in scope     in scope      stored at write
    Hostel                  not possible           in scope     in scope      Campus fixed once it has Rooms (app)
    Canteen Outlet          School-wide outlet     no           no            Outlet Campus not editable (app)
    Admission application   not possible           in scope     in scope      fixed at creation (no edit path)
    Library copy            School-shared copy     no           no            move refused while on loan (DB)
    Library loan            loan of a shared copy  no           no            frozen at checkout (DB)
    Transport route         School-shared route    no           no            Campus fixed once used (app)
    Transport vehicle       n/a                    not adopted  not adopted   —
    Finance Charge          whole-School Charge    no           no            frozen (DB)
    Payment                 has no Campus          one Campus only            derived from its Charges
    HR assignment           School-wide placement  never shown  never         makes the Employee whole-School
    HR Department           School-wide department label only  no            declared shared lookup (not authority)
Intentional exception: NULL-Campus HR Departments are shown as reference
labels (a declared shared lookup), never authority.

**53.8 Current vs historical.** Students: current operational placement.
HR: union of current assignments (School-local date). Library: loan's
stored Campus (+ borrower's current Campus at checkout). Finance: Charge's
stored Campus. Transport/Hostel/Canteen: resource Campus + the Student's
current eligibility at write time. Attendance: register's stored Campus.
Admissions: application Campus. Academic: Offering/Section Campus.
Visitor/Inventory: resource Campus. Configuration, directories, runs,
statements: whole-School. No current resolver overwrites stored
provenance: placements (`student_enrollments_freeze_placement`), loans
(`library_loans_campus_guard`) and Charges
(`fees_reject_recognized_charge_mutation`) are DB-frozen; route, Hostel and
Outlet Campuses are fixed in the application.

**53.9 Domain closure (approved v1 scopes, all CLOSED).**
- **Visitor / Inventory (§33–§34):** Campus scope, concealed conflicts,
  minimal shared lookups; accepted: "busy somewhere", School-wide gate-pass
  uniqueness.
- **Academic (§37):** Timetable, Attendance administration, Curriculum
  delivery, LMS administration, exam papers; TeachingOwnership unchanged
  (no `*.teacher` key adopted); marks/results never adopted; Academic
  Structure, bell schedule, syllabus, Examinations and grade scales
  whole-School; active-Campus integrity for Rooms/Sections/Offerings.
- **Admissions (§39):** application Campus-bound and fixed; Applicant
  School-wide, reached only through an in-scope application; conversion,
  Applicant directory, start, Guardian searches and the fee head
  whole-School.
- **Hostel / Canteen (§39–§40):** resource Campus authoritative; the
  Student's current operational Campus must match, checked under the locked
  eligibility proof; no cross-Campus boarding; fee intent and the Canteen
  Charge are bounded side effects with no Finance authority.
- **Transport (§40–§41):** route view and rider assignment; route Campus =
  Student's current Campus; NULL routes, route/stop management, fleet and
  fees whole-School; no cross-Campus riding for Campus staff.
- **Library (§40–§41):** title School-wide, copy operational, loan Campus
  frozen at checkout; Campus lending needs borrower current Campus = copy
  Campus = scope; no cross-Campus lending/returns for Campus staff; NULL
  copies whole-School; fines a bounded side effect.
- **HR (§42–§43):** operational set = union of current assignments on the
  School-local date on active Campuses; identity School-wide; sensitive HR,
  Profile, history, self-service, reporting-manager leave, Payroll and
  Staff Attendance unchanged/whole-School.
- **SIS (§44–§47):** Directory, minimal overview, current placement, name
  edits, atomic Campus onboarding and the named dual-scope transfer; Student
  identity School-wide (no `students.campus_id`); full Profile, history,
  rollover, lifecycle, reactivation, Guardians, bulk transfer, API
  onboarding and conversion whole-School/deferred. Transfer: Student number
  unchanged; history untouched; blocked by an active source-Campus Hostel
  residency, Transport rider or elective; a Library loan neither blocks nor
  moves; Canteen and Finance history kept; Guardians unchanged.
- **Finance (§48–§52):** frozen v1 (§52).

**53.10 Cross-domain order (representative).** Transfer, HR placement
writes, Library checkout, Transport rider assignment and Finance payment
entry all run: tenant (School context, RLS) -> legal/environment (none
gate these in development) -> School operational guard -> entitlement
(resolver) -> capability -> Campus scope (`authorizeCampusScope*`) ->
domain validity under locks (eligibility, placement lock, Charge locks) ->
fresh MFA where required (payment entry) and idempotency/replay re-check.
No deviation found.

**53.11 Routes and declarations.** 192 C6 entries (above), each with an
adopted key, a `capability:<key>,campus` floor or an unconditional
`authorizeCampusScope` in the action, and a named scoped access method
(`CoverageGuard`). 134 exact `WHOLE_SCHOOL_OPERATIONS` (students 67, HR 24,
Admissions 20, Finance 14, Transport 5, Hostel 4) plus 3 declared hubs
(`hr`, `finance`, `data-health`); no wildcard; `adoptedWholeSchool()` fails
any undeclared whole-School use of an adopted key and any stale entry.

**53.12 Cross-cutting closure.** Ownership/relationship rules are
independent (`CampusAdoptionImpactTest` forbids `*.teacher`, `guardians.`,
`portal.`, `hr.leave.`, `hr.staff_attendance.`, `hr.employees.personal.`,
`payroll.`; `GuardianStudentScope` and Leave code hold no Campus logic;
`HrSelfAdministrationGuard` applies to every actor). Direct ids: out of
scope = 404 (unknown-equivalent) everywhere; no scope at all = 403;
declared whole-School operations = 403; writes use one generic refusal
(`LIBRARY_CHECKOUT_UNAVAILABLE`, `TRANSPORT_ASSIGNMENT_UNAVAILABLE`,
`HOSTEL_RESIDENCY_UNAVAILABLE`, `PAYMENT_UNAVAILABLE`, `TIMETABLE_SLOT_
UNAVAILABLE`). A request `campus_id` filter only narrows (rule 101: a
selected Campus is never authority).
- **Purpose-limited lookups** (none is write-time proof; every Campus
  write re-proves under `StudentOperationalEligibility::lockOperationalOn()`
  or its domain locks): Library borrower, Transport rider, Hostel resident,
  Canteen Student (`StudentPurposeLookup`: id, displayName, studentNumber;
  limit 10, max 50); Finance debtors and collection debtors (open in-scope
  debt; 20 / 10); transfer and onboarding destination Sections (page props,
  scope-restricted); HR manager candidates (current in-scope assignments,
  20); HR account candidates, Visitor hosts/directory, Timetable teachers
  and Transport drivers (School-wide by declared contract).
- **Search hygiene:** ORG.7 lookups and directories escape `%`, `_` and `\`
  (`StudentSearch::escape`, `addcslashes`). Not escaped (hygiene, never a
  cross-scope leak — bound parameters inside an already scope-bounded or
  School-wide set): Visitor hosts/directory, Timetable pickers (web + API),
  Inventory item search (also an ungrouped `orWhere` that can bypass the
  active filter on the School-wide catalogue), Transport drivers.
- **Replay:** `EnsureIdempotent` binds the actor's CURRENT CampusScope into
  the request fingerprint (a changed scope is 409, never a stored body);
  capability and entitlement are route floors re-checked on every request;
  no stored "authorized" flag. Payment entry re-checks visibility under the
  current scope before any replay (§51.5). Residual (LOW): with an
  unchanged scope, an API replay returns the actor's own stored body even
  if that record has since left scope. Web Campus writes (transfer,
  onboarding, circulation, residency, riders, orders) use no idempotency
  keys; domain locks and unique indexes prevent duplicates.

**53.13 Security closure.** No HIGH finding in any ORG.7 phase. Every
MEDIUM is closed:

    id      phase  domain        finding                                           closed
    37-M1   7D-1   Curriculum    out-of-scope not-found body differed              §37
    37-M2   7D-1   cross-cut     replay across a scope change not re-authorized    §37 (scope in fingerprint)
    38-M    7D-2   SIS           join could leak Campus columns to select *        §38 (EXISTS)
    40-M    7D-4   Hostel        deadlock vs transfer/rollover                     §40 (FOR NO KEY UPDATE)
    41-M    7D-5   Transport     rider refusal oracle                              §41
    42-M1   7E-0   HR            write-target contract missing                     §42/§43
    43-M1   7E-1   HR            ending an assignment cleared other-Campus reports §43
    43-M2   7E-1   HR            set-primary race                                  §43
    44 F-1  7F-0   SIS           Guardian contact under students.view              §45
    44 F-2  7F-0   SIS           placements skipped active checks                  §46/§47
    44 F-3  7F-0   SIS           enroll() capture path                             §46
    44 F-4  7F-0   SIS           transfer left Hostel/Transport/electives          §47
    48 F-1  7G-0   Finance       Charge Campus re-derived on read                  §49
§35 R1–R5 closed (§36, §42); R6 pinned at every adoption; R7 accepted per
domain; R8 partly closed (remaining LIKE/docblock/CHECK hygiene LOW).
**New in this audit — ORG.7H-1 (LOW, owner decision):** an Inventory
Location's Campus can be changed (or set to NULL) by
`inventory.directory.manage`, a whole-School key, after it has stock
movements; its balances and movement history then follow the new Campus.
Not a Campus-actor bypass (whole-School authority, stock data only), but
the one adopted domain without a "fixed once used" Campus rule (Library,
Transport, Hostel and Canteen have one). Recommended: freeze a Location's
Campus once it has movements or balances (a small hardening slice). Not a
closure or UAT blocker. **CLOSED by ORG.7H-1 (§54, 2026-10-21):** a used
Location's Campus is frozen in the database. Accepted LOW/INFO residuals by domain remain as
recorded in §33–§52 (Visitor busy-somewhere; Admissions no immutability
trigger, fingerprint drift, F-9; Library audit outside transaction,
`student_id` not frozen; Transport non-UUID 500, app-only route freeze,
inactive stops; HR filter/summary/UTC items; SIS F-5–F-8, F-10, F-11; Hostel
inference; Canteen Outlet app-only freeze; Finance §52.13; cross-cutting
`CampusScope` constructor guard, 60 s grant cache).

**53.14 Concurrency, tenancy, invariants.** Canonical locks: Student
eligibility (active year -> Student -> current Enrollment -> Campus, FOR
SHARE; exclusive Student locks FOR NO KEY UPDATE); transfer (Student-first,
one direction); `EmployeePlacementLock` first for every HR placement
writer; Library/Transport/Hostel/Canteen resource then eligibility; Finance
Charges FOR UPDATE in id order then Campus FOR SHARE. No open deadlock
finding; recorded: the `TransportFeeSelectionConcurrencyTest` fixture
flake (local) and F-8 rollover locking (LOW). Tenancy: School is the only
tenant, FORCE RLS on tenant tables, explicit School predicates in every
Campus query, no runtime BYPASSRLS, composite `(id, school_id)` keys stop
cross-School Campus references. DB invariants:

    invariant                                   object                                              DB  app  test
    grant Campus same-School, active            membership_role_assignments FK + scope trigger      yes yes  CampusScopeDatabaseTest-family
    access-admin / Guardian / platform never Campus   assert_membership_role_assignment_scope      yes yes  Postgres guard tests
    protected Finance role never Campus         same trigger (§51)                                  yes yes  FinanceRoleCampusScopeGuardTest
    protected key never added to Campus role    role_capabilities_campus_finance_guard              yes —    FinanceRoleCampusScopeGuardTest
    grantor counts whole-School grants only     trg_membership_role_assignments_grantor            yes yes  grantor tests
    enrollment matches Section School/Year/Campus/Grade   composite FK (§36)                       yes yes  §36 tests
    Timetable Room on the entry's Campus        composite FK (§36)                                  yes yes  §36 tests
    placement immutable                         student_enrollments_freeze_placement               yes yes  transfer tests
    loan Campus frozen; copy move guarded       library_loans_campus_guard, library_copies_campus_move_guard  yes yes  Library Campus tests
    Charge Campus same-School, frozen, bound    charges_campus_fk, freeze trigger, *_charge_campus_guard  yes yes  ChargeCampusIntegrityTest
    route / Hostel / Outlet Campus fixed        application under row locks                         no  yes  domain tests
    RLS                                         TenantRls (FORCE)                                   yes —    RawIsolation tests
Every ORG.7 migration has a working, rollback-proven `down()` (§36–§51).
Grant, Charge and Student-number rollbacks refuse to destroy history
(fail closed); the Library loan-Campus rollback (§41) drops the frozen loan
Campus without a blocker (LOW, 53.13).

**53.15 Legal / production blocker register (unchanged, nothing inferred).**

    gate          subject                         status                                  production effect
    E30           receipt form / GST              LEGAL_REVIEW_REQUIRED                   blocks; no code refusal
    E31           late fees / in-year changes     LEGAL_REVIEW_REQUIRED                   blocks; no code refusal
    E32           RTE / free seats                LEGAL_REVIEW_REQUIRED                   blocks; nothing to switch off
    E34           Library fines                   LEGAL_REVIEW_REQUIRED                   blocks; no code refusal
    E21           retention                       LEGAL_REVIEW_REQUIRED (ratification)    blocks O1 and launch
    E02 / E15     release qualification / digest  GOVERNANCE_ / DEPLOYMENT_REQUIRED       blocks
    E16           vulnerability exceptions        current through 2026-11-12 (time-bound) blocks if lapsed
    O1            Phase 0O definition of done     defined, not satisfied (25 rows)        blocks go-live
    E33/TCH-L1    teacher Attendance              APPROVED WITH CONDITIONS                no teacher grants before deployment re-verification
    E36/E37/E42   marks / teachers / portal marks LEGAL_REVIEW_REQUIRED                   refused in code
    E45 PAY-L1    ESI disability                  LEGAL_REVIEW_REQUIRED (not sent)        statutory payroll not reachable
    HRX-L1..L4    health, biometrics, leave, LOP  OPEN / activation blocked               activation gated
    E46 POR-L1    Guardian/Student portal         LEGAL_REVIEW_REQUIRED (not sent)        refused in code
    E47 SR-L1     staff personas                  QUESTION DRAFTED (not sent)             owner decision OD-PPR-14c
Approvers: Legal + Owner (Operator/Security for E02/E15/E16). None is
Campus-specific; Campus scope never enables a gated feature.

**53.16 Deferred-feature register.** No deferral blocks integrated UAT or
production independently of the gates above; each is a deliberate
whole-School boundary needing an owner decision before any build.

    domain      feature                                                    class        future phase
    HR          named Employee Campus move                                 product      later HR wave
    HR          API onboarding (all-of C6 floor)                           security     C6 all-of floor work
    HR          historical HR by Campus                                    product      later HR wave
    HR          Visitor host lookup tightening                             security LOW hygiene slice
    HR          Campus Leave / Staff Attendance / Payroll; rehire          product/sec  —
    HR          Campus principal leave approval; cross-Campus managers     authority    ORG.8
    SIS         API onboarding                                             security     later SIS wave
    SIS         historical Enrollment by Campus                            product      later SIS wave
    SIS         rollover, withdrawal/leaver, reactivation                  authority    ORG.8 (status approvals)
    SIS         bulk transfer                                              parity       ORG.9
    Admissions  Campus conversion; combined Applicant + Application start  product      ORG.7F-4 (optional)
    Transport   shared-route management; transport.routes.manage;          product      later Transport wave
                fleet/vehicle reference; cross-Campus riding
    Library     cross-Campus lending/returns                               product      later Library wave
    Finance     API payment entry; Campus fee statement; accountant@Campus;   product/authority  owner decision / ORG.8
                corrections, refunds, reversals
    Examinations Campus exam-papers hub (navigation gap)                   UX           academic UX follow-up
    Marks       Campus adoption                                            legal        after RES gates
    Inventory   Location Campus freeze (ORG.7H-1)                          integrity    DONE (§54)
    cross-cut   R8 LIKE/docblock/CHECK remainder; C6 all-of floors          hygiene      hygiene slice
    parity      Branch filters, reports, HR MIS (reference screenshots)   parity       ORG.9

**53.17 Performance / residuals.** API Payment list not date-bounded (post-
closure); 60 s grant cache can under-grant briefly after a grant (never
over-grant beyond revocation handling, which forgets the cache); app-level
freezes noted in 53.14.

**53.18 Integrated UAT.** `docs/product/CAMPUS-INTEGRATED-UAT.md`: 16
personas on actual role templates, one deterministic fixture (Campuses A,
B, inactive C; current/transferred/leaver/unplaced/multi-year Students;
A/B/A+B/unassigned/notice/separated Employees; every domain's current and
historical records), 19 positive and 15 mandatory negative cases, and the
navigation contract (Campus entries only when every named key is adopted
and the route is not whole-School-only; Finance/HR/Data Health hubs
hidden; UX residuals: exam-papers path, Campus admissions start, Record
payment reached from Payments received). Finance detail: FINANCE.md.

**53.19 Dependencies.** LC-5–LC-10 stay paused; none blocks ORG.7 closure,
and none needs further ORG.7 work to resume (they need owner
authorization only). Reference ERP evidence (Iris/MyClassboard Branch
labels, broader reports, HR MIS/reporting screenshots, module grouping) is
parity input for ORG.9, never an ORG.7 requirement.

**53.20 ORG.8 handoff.** Objective: approval-authority completion under
§16 — `.request/.approve/.reverse/.override` keys, approve never implied by
manage, per-item SoD (holding both never satisfies it; DB maker-checker as
the pattern), override with a closed reason, fresh MFA and audit, never
bypassing a legal gate; approval may be Campus-scoped only where the domain
adopted Campus scope. Open gaps: payment correction refund/reversal
approver (amends ADR 0031), ledger post/reverse actor rule, Library fine
void approval, attendance regularisation, Student status (inactive/
reactivate) requests, fee unassignment, lesson-plan approval (no module),
plus the HR Campus-principal leave and cross-Campus manager decisions and
the reference approval functions in §11 parity notes. Domains: Finance/
Payments/ledger, Fees, Library, Attendance, SIS, HR, Payroll, Exams.
Prerequisite: ORG.2 (done) and a read-only ORG.8 contract audit first.
ORG.7 closes first because approvals may be Campus-scoped and need the
frozen scope model, adopted-key list and whole-School declarations as their
base.

**53.21 Validation.** Every *Architecture*, *Guard* and *Campus* test plus
the Authorization suite (catalogue, adoption impact, route coverage and
whole-School declaration guards, every domain's Campus tests): 1362 passed;
1 failure is environment-only (a real-MinIO Guardian purge test).
Documentation guards: 22 passed. No full regression (documentation only); counter 0/5.

## 54. ORG.7H-1 — Inventory Location Campus immutability (as built, 2026-10-21)

Owner authorization: ORG.7H-1, narrow data-integrity hardening closing the
§53.13 LOW ORG.7H-1. Baseline `origin/main` = `895a824f` (§53). No new
capability, role, catalogue, RLS, Canteen or stock-history change. ORG.7
is not reopened: this is a post-closure correction.

**54.1 Contract (owner decisions H1-D1–D5).** An Inventory Location's
`campus_id` is operational provenance. Before any operational use it may be
corrected every way (NULL -> Campus, A -> B, Campus -> NULL) under the
existing whole-School `inventory.directory.manage` and same-School
validation. After first use it is immutable: A -> B, A -> NULL and NULL -> A
are refused, so a used School-shared Location stays shared and a used
Campus-A Location stays A. "Used" is derived from relational evidence, never
a stored flag; no history row is rewritten; other fields (name, status)
keep their edit behaviour. No Campus-scoped actor gains Location
authority.

**54.2 Writer audit.** Before: the API `PATCH .../inventory-locations/{id}`
(`campus_id`, unlocked, inline in the controller) was the only Campus
changer; API and web create set it once; the web edit accepts name/status
only; the demo seeder creates. No import path. Now every Location write
(create and update, web and API) goes through
`App\Domain\Inventory\Application\InventoryLocationService`, pinned as the
only writer in `app/` (`InventoryLocationCampusArchitectureTest`).

**54.3 Operational evidence (the one predicate).** Database function
`inventory_location_has_operational_history(location, school)`, VOLATILE,
SECURITY INVOKER, fixed `search_path`, EXECUTE for `school_os_app` only:

    stock_movements           from_location_id or to_location_id   (receipt, issue, transfer)
    inventory_stock_balances  inventory_location_id                (current stock state, even at 0)
    canteen_orders            inventory_location_id                (placement snapshot, any status)

Canteen stock consumptions reference movements (covered). A Canteen Outlet
naming the Location is configuration, not history (its Campus-bound
composite key already pins the Location's Campus). There are no receipts,
opening balances, reservations or purchase tables. Every foreign key into
`inventory_locations` must be classified as evidence or configuration
(`InventoryLocationCampusFreezeDbTest`), so a new referencing table fails
until decided. The service calls the same function; there is no second
definition.

**54.4 Application rule.** `InventoryLocationService::update()` locks the
Location FOR UPDATE, then refuses an actual Campus change (case-insensitive
id comparison; a no-op is not a change) while the predicate holds:
`LocationCampusFrozenException`, 409 `INVENTORY_LOCATION_CAMPUS_FROZEN`,
"This Inventory Location already has stock history, so its Campus can no
longer change." The audit row and other fields are unchanged.

**54.5 Database backstop** (migration
`2026_12_28_090000_freeze_used_inventory_location_campus`, the ADR 0069 S1
pattern): `trg_inventory_location_campus_freeze` (BEFORE UPDATE OF
`campus_id` on `inventory_locations`) raises `check_violation`
`inventory_location_campus_frozen` when the value actually changes and the
predicate holds — for every role, including raw runtime SQL.
`trg_inventory_location_evidence` (BEFORE INSERT OR UPDATE OF the Location
columns on each evidence table) takes each named Location FOR SHARE, so the
first evidence and a Campus change serialize regardless of which unique
keys include `campus_id` (today the Canteen composite key also makes the
UPDATE take FOR UPDATE, which conflicts with the evidence rows' foreign-key
KEY SHARE). Same-School validity stays the composite foreign key; the
active-Campus rule is unchanged (a Location Campus was never required to be
active); RLS stays forced.

**54.6 Stock writes.** Every `InventoryStockService` write (`receive`,
`issue`, `transfer`, `issueMany` — Canteen fulfilment included) first takes
its Location row(s) FOR SHARE in ascending id order inside the transaction
and refuses (generic `LocationNotAvailableException`) unless each is still
active with exactly the Campus the caller resolved and authorized it under.
A stock write therefore never commits under a Campus the Location no longer
has. Transfers between Locations work exactly as before: the freeze is
about a Location's own identity, not stock moving between Locations.

**54.7 Lock order and races.** Stock writes: Location(s) FOR SHARE (id
order) -> balances FOR UPDATE (id order). Canteen fulfilment: Order -> Canteen
Items -> Location FOR SHARE -> balances -> Outlet FOR SHARE. Location edit:
the one Location FOR UPDATE, then reads only. Share locks never conflict
with each other and the edit holds a single row, so no cycle exists; no
School-wide or advisory lock. Real two-process races
(`InventoryLocationCampusConcurrencyTest`, contender positively observed
blocked): first receipt first -> the edit waits, then is refused; edit
first -> the receipt authorized under the old Campus waits, then is refused
and nothing is recorded; name-only edit first -> the receipt waits, then
succeeds; first receipt first -> a raw runtime UPDATE waits, then the
database refuses it; first Canteen Order first -> the edit waits, then is
refused; edit first -> Canteen's `issueMany()` waits, then is refused. A
mutation check (Location lock removed) makes the edit-first race fail.

**54.8 Data audit and migration.** Before the migration: development
database 2 Locations (both Campus-bound, both used), test database 0; no
cross-School Campus, no orphan movement/balance/order, no Outlet/Location
Campus mismatch, no recorded Campus change. The rule constrains future
changes only; nothing was rewritten. Rollback proof: IDENTICAL schema after
rollback + re-apply (123 schema lines removed), `platform:verify-database`
failed=0. `down()` drops the triggers and functions and changes no data;
rolling back re-opens Campus changes of used Locations. Roll back the code
before the migration: the service calls the predicate function, so code
without the migration answers 500 on a Campus edit (fails closed).

**54.9 Unchanged.** `inventory.directory.*` stays whole-School (route floors
`capability:inventory.directory.manage`; a Campus-scoped `principal` or
`stores_officer` is 403); `CampusScopeCatalogue`, role templates, Inventory
capabilities, Canteen authorization and Charges unchanged; stock history
keeps following the (now fixed) Location Campus; Location deactivation is
unchanged (no delete path exists).

**54.10 Residuals (unchanged, not fixed here).** Unchanged-scope API replay;
LIKE hygiene (including the Location directory search); the Inventory item
search `orWhere`; Library loan-Campus rollback; `StudentPurposeLookup`
docblock; API Payment-list bounds. Pre-existing and recorded: changing the
Campus of an unused Location that backs a Campus-bound Canteen Outlet is
refused by the Outlet's composite key as a raw database error (fails
closed, no data effect; a friendlier message is UX hygiene), and so is a
non-UUID `campus_id` on the API edit (a 500 from PostgreSQL, unchanged). The
freeze is only as strong as its evidence: `stock_movements` and
`inventory_stock_balances` are append-only by application convention only
(no runtime code deletes or repoints them) and `canteen_orders` refuses
DELETE but not an UPDATE of its Location snapshot (no code path does it);
making the evidence database-immutable is a separate hardening decision
(done by ORG.7H-1R, §55).
Independent security review: no HIGH or MEDIUM; its two introduced LOWs
were fixed in this slice (the evidence guard raises on an unknown column
instead of silently skipping the lock; the single-writer guard also scans
`fill`/`save`/`campus_id =` writes and `DB::table` updates).

**54.11 Status.** ORG.7H-1 CLOSED. ORG.7 remains technically complete,
integrated UAT ready, production blocked by the §53.15 gates. Next: ORG.8
read-only contract audit (owner authorization).

## 55. ORG.7H-1R — Inventory use-evidence monotonicity (as built, 2026-10-21)

Owner authorization: ORG.7H-1R, a narrow database-integrity correction
reopening §54 ("PASS WITH PREREQUISITES"). Baseline `origin/main` =
`ecbbefca`. No table, column, marker, capability, role, catalogue,
`CampusScopeCatalogue`, Canteen or Charge change; no data rewritten.

**55.1 Invariant.** Once a Location has committed operational Inventory
history it never again becomes eligible for a Campus change because an
evidence row was deleted, re-pointed, rewritten, compacted or moved. "Used"
is monotonic. `inventory_location_has_operational_history()` stays the one
decision; every relation it reads is now monotonic for the runtime role.

**55.2 The gap (reproduced at `ecbbefca`, raw SQL as `school_os_app`).**

    relation                  INSERT UPDATE DELETE TRUNCATE  writers (app/)
    stock_movements           yes    YES    YES    no        InventoryStockService create() only
    inventory_stock_balances  yes    yes    YES    no        InventoryStockService (insertOrIgnore, quantity)
    canteen_orders            yes    yes    no     no        CanteenOrderService (place; fulfil/cancel status)

Observed: re-pointing a movement's Location, re-quantifying and deleting a
movement, re-pointing and deleting a balance, and re-pointing a Canteen
Order's Location each changed 1 row; a Canteen Order DELETE and every
TRUNCATE were already denied; moving a row to another School is refused by
RLS. A real two-process race reproduced the bypass end to end: a raw
"cleanup" waiting on the first receipt (Location FOR UPDATE) re-pointed the
committed movement and balance, then moved the Location's Campus (`ok`).

**55.3 Repository truth.**
- Movements are the authoritative, immutable ledger (INVENTORY.md §16;
  reversal deferred). No code updates or deletes one.
- A balance is current stock state, reconstructible from movements, but
  every legitimate balance row is created in the same transaction as a
  movement at that Location, its row is never removed (Inventory is tenant
  lifetime: E21 determination, `ResidualRetentionArchitectureGuardTest`)
  and only its quantity changes. Pinning its existence and Location costs
  the product nothing; no new marker or ledger is needed.
- A Canteen Order's Location is a placement snapshot, written once
  (Phase 10F). No retention unit deletes an Order: the Finance D8 unit
  refuses a unit an Order references.
- No SECURITY DEFINER function reachable by the runtime role writes these
  tables; every key into `inventory_locations` and from the evidence
  parents is NO ACTION on update and RESTRICT on delete; the runtime role
  cannot delete a School (rule 86) and owns no table or function.

**55.4 Correction** (migration
`2026_12_29_090000_make_inventory_location_evidence_monotonic`):
- `stock_movements`: `TenantRls::makeAppendOnly()` (runtime UPDATE and
  DELETE revoked).
- `inventory_stock_balances`: `TenantRls::revokeDelete()`.
- `canteen_orders`: DELETE was already revoked.
- `inventory_location_evidence_guard()` (already fired BEFORE UPDATE OF
  exactly the Location columns on each evidence table) now raises
  `inventory_location_evidence_immutable` (`check_violation`) when an
  UPDATE actually changes or clears an evidence row's Location, for every
  role (checked before the NULL skip). A
  no-op write passes; the INSERT path (FOR SHARE first-use lock) is
  byte-for-byte unchanged. Same SECURITY INVOKER, pinned `search_path`,
  owner and ACL (CREATE OR REPLACE).
- `DatabaseRoleVerifier`: the three tables join `NO_RUNTIME_DELETE`,
  `stock_movements` joins `NO_RUNTIME_UPDATE`, so `platform:verify-database`
  fails on a restored grant; `InventoryLocationCampusFreezeDbTest` requires
  every classified evidence table to be listed there.

**55.5 Data audit (before the migration).** Development database: 2
Locations (both used), 12 movements = 12 stock audit events (6 issued, 5
received, 1 transferred; every audited movement still exists), 6 balances
all reconciling exactly to the ledger, no movement-derived balance missing,
5 Orders = 5 `canteen.order.placed`, every Canteen consumption drawn from
its Order's Location, no Order Location differing from its Outlet's, no
recorded Location Campus change. Test database empty. No ambiguity; nothing
repaired.

**55.6 Proof.** `InventoryLocationEvidenceMonotonicityDbTest` (raw runtime
SQL): per source independently, an unused Location becomes used, every
erasing or moving mutation is refused (movements: re-point, detach,
re-quantify, DELETE, TRUNCATE denied; balances: re-point refused, DELETE and
TRUNCATE denied, another School refused by RLS; Canteen Orders: re-point
refused, DELETE and TRUNCATE denied, another School refused by RLS), the
permitted writes still work (a balance quantity, an Order cancellation, a
no-op Location write), and the Campus change is still refused; no parent
deletion cascades evidence away; the privilege matrix, verifier lists and
guard body are pinned. Race G
(`InventoryLocationCampusConcurrencyTest`): the cleanup waiting on the
first receipt now answers `move-movement:denied;move-balance:refused;
delete-movement:denied;delete-balance:denied;campus:frozen`; the six §54.7
races are unchanged. The refusals depend only on privileges and the row's
own OLD/NEW values, never on other rows or timing, and the remaining
permitted UPDATEs never touch a Location column, so no race window exists;
no new lock.

**55.6a Security review** (independent, scoped to §15 of the brief): no
HIGH or MEDIUM. LOW fixed in this slice: the guard's NULL skip ran before
the refusal, so a Location -> NULL re-point (movements only, unreachable by
the runtime role) passed for a role with UPDATE. LOW accepted:
`platform:verify-database` checks the runtime grants, not the guard trigger
or body; a migration rollback is still flagged (55.7), and the trigger and
body are pinned by `InventoryLocationCampusFreezeDbTest` and
`InventoryLocationEvidenceMonotonicityDbTest`; replacing them needs the
owner role.

**55.7 Rollback.** IDENTICAL schema after rollback + re-apply (8 lines);
the rolled-back guard body hashes equal to §54's; `verify-database`
failed=0 applied. Rolling back restores the runtime grants and the §54
guard and re-opens the evidence-erasure bypass -- never silently:
`verify-database` then fails `runtime_destructive_privileges_restricted`
(3 grants). `down()` changes no data.

**55.8 Unchanged and deferred.** Malformed Campus UUID 500, Canteen Outlet
edit 500, LIKE hygiene, Inventory item `orWhere`, replay residual, Library
rollback, `StudentPurposeLookup` docblock and Payment-list scale stay
deferred (§54.10). A future movement reversal is a new movement, never an
edit.

**55.9 Status.** ORG.7H-1R CLOSED once published. ORG.7 remains technically
complete, integrated UAT ready, production blocked by the §53.15 gates.
Next: ORG.8 read-only contract audit (owner authorization).

## 56. ORG.8 — Approval authority and assurance contract audit (read-only, 2026-10-21)

Owner authorization: ORG.8, read-only architecture / authority / assurance
audit; implementation NOT authorized. Baseline `origin/main` = `98932b07`
(§55). No production code, schema, capability, role template,
`CampusScopeCatalogue`, route, RLS or legal-gate change. Documentation only.
Matrix: `docs/security/APPROVAL-AUTHORITY-MATRIX.md`; UAT plan:
`docs/product/APPROVAL-AUTHORITY-UAT.md`. This section refines §16; it does
not replace it.

    APPROVAL ARCHITECTURE        UNDERSTOOD; v1 contract frozen below
    IMPLEMENTATION               NOT STARTED (owner authorization per phase, 56.30)
    PREREQUISITES                ORG.8-P1 subledger reversal guard (G-1) before any Finance approval work;
                                 owner decisions OD-8.1..OD-8.9 (56.29)

**56.1 Terminology (frozen).** REQUEST asks for one action. APPROVE
authorizes that action subject to current validity. REJECT ends a request
without executing it. EXECUTE performs the business action. REVERSE
compensates an executed action with a new record (never an edit or delete,
rules 9, 11, 91). OVERRIDE is a documented exception to a rule that is
explicitly overrideable. CANCEL ends an unexecuted request. Approval,
reversal and override are distinct operations, distinct capabilities and
distinct audit events; no generic "approve" button collapses them.

**56.2 Approval is not permission.** A capability authorizes an action
class; an approval authorizes one item under stated terms. No code may
treat "approved" as `can()`, cache it as authority, or turn it into a
grant. Every approval row is item-bound (56.11).

**56.3 Approval never bypasses authorization.** Execution re-evaluates the
full order (§53.1): platform/environment -> legal availability -> School
lifecycle -> module entitlement -> capability -> Campus/whole-School scope
-> ownership/relationship -> approval/SoD/MFA/idempotency -> domain
validity. An approval satisfies only the approval layer. Repository truth
agrees: every existing approve path re-resolves the decider's capability
in the decision transaction (concessions, marks corrections, Leave
manager re-resolved under `ReportingLine::holdManagerOf`), and
Communications re-checks `currentlyApprovedAndValid` at publish and
schedule (`AnnouncementService` :423, :701).

**56.4 Legal gates are never overridable.** No approval, override or
administrative decision opens E21, E30–E34, E36/E37, E42 (RES-L7), E45,
E46, E47, HRX-L1..L4 or any other recorded gate (§53.15, ADR 0058 §6).
Gates enforced in code (E36/E37 marks, E46 portal, teacher Attendance API)
refuse before any approval logic; gates without a code refusal
(E30–E34) block production by process. ORG.8 changes no gate status.

**56.5 Repository truth: no generic approval framework.** There is no
`approval_requests`, workflow, delegation or sign-off table and no shared
approval service. Approval is module-owned, as §16 decided. Request/decide
workflows that exist:

    workflow                 tables / checks                                                status
    fee concessions          fee_concessions (pending/approved/rejected/withdrawn/revoked),  COMPLETE (G-3)
                             fee_concessions_sod_check, transition trigger, immutable terms
    Communications approval  communication_approval_requests (+ invalidated),               COMPLETE (fingerprint reference)
                             CommunicationApprovalFingerprint, School approval policies
    marks corrections        student_mark_corrections, maker_checker_check, reason_code,    COMPLETE; prod refused (E36/E37)
                             one pending per mark, decided rows immutable
    Leave                    leave_requests + leave_decisions, no_self_decision,            COMPLETE for v1 (G-4)
                             closed rejection/closing codes, content immutable
    payroll runs             draft->calculated->approved->posted, payroll_runs_sod_check,    PARTIAL (G-2)
                             freezes at approval; approver may post
    erasure cases            requested->approved/denied->executing->completed               COMPLETE (compliance, out of scope)

Everything else that changes money, status or history is a direct
privileged operation by one actor (matrix §2): payment corrections,
ledger post/reverse, period close, Charge cancel/voids, fee-run cancel,
Library fine void, Student and Staff Attendance corrections, Student
status, Enrollment end, Campus transfer, Admissions decisions and
conversion, marks paper lock, HR lifecycle. MISSING as request/approve:
payment refund/reversal approval, Library fine void request, Student
status requests, attendance regularisation approval. Lesson plans: no
module exists (MISSING; out of v1). Results/publication/moderation: not
built and LEGAL_BLOCKED (RES-L4..L7).

**56.6 Capability inventory (approval-shaped keys, seeded holders).**
`communications.approve` (school_admin, principal); `hr.leave.approve`
(school_admin, principal; manager path) with `hr.leave.manage`
(school_admin, hr_officer; administrative decide); `finance.fee_concessions
.request` (school_admin, accountant) / `.approve` (school_admin);
`finance.ledger.post` (school_admin, accountant) / `.reverse`
(school_admin); `payroll.runs.prepare` (school_admin, payroll_officer) /
`.approve` / `.post` / `.reverse` (school_admin); `examinations.marks
.correction.request` / `.approve` / `.lock` (school_admin, principal);
`library.fines.void` (school_admin); `finance.periods.manage`
(school_admin). None is Campus-adopted; `finance.ledger.*` and
`finance.fee_concessions.*` are `CAMPUS_INCOMPATIBLE`. Consequence:
school_admin is today the only template that can approve concessions or
payroll, reverse the ledger or void a fine, so in a School whose only
finance-authority holder is one administrator, four-eyes needs a second
person to hold `.request` (accountant) — holding both never satisfies SoD.

**56.7 Maker/checker contract.** Per item, per actor, never per role.
`SELF_APPROVAL_FORBIDDEN` for every request/approve workflow (existing and
future) and extended to PRIOR PARTICIPATION: whoever created, calculated,
amended (override, delta, term change) or requested an item never decides
it. An actor may be maker on item 1 and checker on item 2. No global role
incompatibility is added (the only ones remain Platform Manager/Auditor
exclusivity and `CAMPUS_INCOMPATIBLE`, which is a scope rule, not SoD).
Database CHECKs remain the backstop pattern (§16) for material workflows.

**56.8 Requester / approver / executor.** Four patterns, chosen per domain
by risk, never ceremony:
- A — approval executes in the same transaction (concessions, marks
  corrections, Leave). Default for v1.
- B — approval then a separate executor (payroll approve -> post).
  Approver and executor MAY be the same actor (Checkpoint 9.5; owner
  decision OD-8.4 open per ADR 0071 §26.13); execution re-checks current
  authority.
- C — requester executes after external approval: not used; not adopted.
- D — no request object; fresh MFA + reason + audit (ledger post, period
  close, payment corrections today).
Separate execution is required only where execution is a distinct
financial event (payroll posting).

**56.9 Campus intersection.** All approval keys are whole-School today, so
a Campus grant confers no approval authority (rule 101: whole-School paths
never count Campus grants). Future rule: a Campus-scoped approval key
exists only where its domain adopted Campus scope; requester, approver and
executor each independently need their own scope over the item's STORED
Campus; a Campus A approval never authorizes Campus B; a whole-School
approver may decide a Campus item where the domain contract permits;
multi-Campus grants union as frozen; refusals stay generic (§34).

**56.10 Snapshot vs current.** The decision row keeps historical
provenance (who, when, which scope fact, fingerprint, reason); execution
and every replay re-check CURRENT authority and validity. An approver's
later revocation leaves the historical decision valid as evidence; an
unexecuted pattern-B approval whose approver lost authority is
re-validated at execution against the executor's current authority, and
is invalidated if the domain requires the approver's authority to persist
(default: invalidate, for internal administrative approvals). No domain
today has an "immutable external approval" exception.

**56.11 Drift and fingerprint.** The canonical binding is a server-side
fingerprint of the approval-sensitive fields (recursive key-sorted JSON,
SHA-256: `RequestFingerprint` / `CommunicationApprovalFingerprint` are the
reference). Either the request terms are DB-immutable after submission
(concessions, Leave, marks corrections — preferred), or an
approval-sensitive edit invalidates the approval (Communications). Material
fields per domain: amount, Student, Campus, effective date, target
Employee, Leave dates, Charge allocations, destination/settlement account,
attendance status and date, Student status transition, fine amount.
Operational timing (e.g. schedule time) is excluded only by an explicit
domain decision. Targeted concessions are additionally re-validated under
the Charge lock at approval; standing concessions apply to later Charges
by design (owner decision recorded in ADR 0062).

**56.12 Expiry / invalidation.** No approval expires by clock today and
none should by default. Invalidate (or refuse execution) on: material
drift; effective date passed where the domain is date-bound; Campus
inactive; module disabled; School not operational per that operation's
lifecycle rule; accounting period closed for the target date; approver
authority lost (pattern B, 56.10); legal gate refusing in code. A
re-enabled module or re-activated Campus never revives a materially changed
request; validity is re-evaluated. Pending requests are not auto-expired in
v1 (no requirement); a stale pending item is cancelled explicitly.

**56.13 Fresh MFA (repository truth).** One mechanism:
`FreshMfaRequirement::requireForAction()` — a new TOTP code (step strictly
greater than the factor's last used step, row-locked) or an unused recovery
code PER ACTION; no freshness window; works for session and bearer alike;
runs after the capability, outside the business transaction. Distinct from
assurance (`mfa`/`mfa-page`/`SensitiveReadAssurance`, 60-minute window bound
to the current factor) used for Highly Sensitive reads and marks routes.
Because a code is per action, "MFA expired between approval and execution"
cannot carry over: each step that requires it asks again. Recommended
step-up: payment reversal/refund ON_BOTH (request + approval) or
ON_APPROVAL at minimum; ledger post/reverse ON_EXECUTION (as today);
payroll ON_APPROVAL + ON_EXECUTION (as today); concession approve/revoke/
cancel ON_APPROVAL (as today), reject NONE; marks correction decide (as
today); Student status / Enrollment end ON_EXECUTION (new, with D-13);
Charge cancel/void ON_EXECUTION (new); attendance corrections NONE;
Leave NONE; access administration unchanged. No new MFA mechanism.

**56.14 Reason codes.** Closed catalogues already exist (elevation,
lifecycle, entitlements, Leave, marks, Staff Attendance, erasure, holds).
Rule: every REJECT, REVERSE, OVERRIDE, CANCEL-of-executed and high-risk
direct action carries a CLOSED reason code (machine-auditable), with
optional bounded free text stored on the domain row and never in audit
metadata. REASON_REQUIRED: refund, payment reversal, ledger reverse,
payroll reverse (today optional), Charge cancel/voids, Library fine void
(today free text only), attendance corrections (Student today none),
Student status and Enrollment end, concession revoke/cancel-adjustment,
HR separation, any override. OPTIONAL_COMMENT: approve decisions.
NO_REASON_REQUIRED: request submission where the request itself is the
justification (Leave reason stays optional).

**56.15 Override.** v1 needs no override capability: no existing policy is
designed to be overrideable, and no repository path overrides a policy
(payroll `manual_override` is an adjustment kind, not a policy override;
emergency Communications publish is a named policy path with fresh MFA).
Any future override requires: a dedicated `.override` key (authority
class); the exact named policy it relaxes; a closed reason; fresh MFA;
an immutable audit event; and it may NEVER bypass tenancy, legal gates,
module entitlement, School lifecycle, Campus scope, SoD or domain
invariants (DB constraints).

**56.16 Delegation.** None exists and none is needed for v1 (Leave falls
back to the administrative `hr.leave.manage` path when no manager exists;
no other domain has an approver-absence problem with one whole-School
approver class). Any future delegation: bounded window, explicit delegator
and delegate, delegate must independently hold the capability and scope
(intersection, never amplification), immutable audit, no delegation of
legal-gated or access-administration authority.

**56.17 Thresholds and multi-level.** No threshold exists (ADR 0062 v1
explicitly has none) and none is invented. If the owner later requires
amount thresholds (refund, concession), they are a SCHOOL-CONFIGURABLE
business policy bounded by a code-owned ceiling, evaluated at decision
time against the fingerprinted amount. Every candidate is SINGLE_APPROVAL;
no MULTI_LEVEL requirement exists (Leave multi-level/HR-final explicitly
out of v1, ADR 0065 §6). No N-stage machinery.

**56.18 Finance — payment corrections.** Today pattern D: one actor,
whole-School, fresh MFA, mandatory reason (5-500, DB), server-issued
idempotency key, append-only `payment_corrections`, one reversal per
Payment. Recommendation: reallocation stays direct; reversal and recorded
refund become REQUEST -> APPROVE (approval executes, pattern A),
SELF_APPROVAL_FORBIDDEN, closed reason + text, ON_APPROVAL step-up (and on
request), proposed keys `finance.payment_corrections.request/.approve`.
This amends ADR 0031 §3 and CLAUDE.md rule 91 and needs its own owner
decision (OD-8.1). Campus cashier never requests or approves (corrections
stay whole-School, §51).

**56.19 Finance — ledger.** Post: any `finance.ledger.post` holder, fresh
MFA, NO_APPROVAL. Reverse: `finance.ledger.reverse`, fresh MFA, optional
text, one reversal per entry, reversal posts in the current open period.
No poster column exists, so "poster may reverse own entry" cannot be
enforced (ADR 0071 §26.13, OD-8.2). **New finding G-1 (MEDIUM,
pre-existing, integrity):** the general reverse path accepts any School
journal entry; only Payment settlement entries are protected
(`journal_entries_payment_reversal_guard`). Charge recognition, fee
adjustment, payment correction, payroll and statutory posting entries can
be reversed directly, leaving the owning record live. Reproduced read-only
with an uncommitted scratch test: after a direct reversal the Charge stays
`cancelled_at = NULL` and a later cancel is refused as
`ChargeAlreadyCancelledException`. Requires school_admin + fresh MFA;
audited; repairable by reversing the reversal; no cross-tenant effect.
Not fixed (implementation not authorized): prerequisite ORG.8-P1.

**56.20 Fees — concessions (reference implementation).** Mature: separate
request/approve keys, SoD in service and DB, DB-immutable terms, closed
category, transition trigger, locked decision, fresh MFA on approve/revoke/
cancel-adjustment, idempotent request (server key on web), targeted amount
re-validated under the Charge lock; no thresholds, no expiry. Gaps (G-3,
LOW): revoke and cancel-adjustment have no SoD check; no reason on
revoke/cancel. Recommended pilot (56.31).

**56.21 Fees — unassignment / waiver / correction.** Charge amounts are
immutable (`fees_reject_recognized_charge_mutation`); removal is
cancellation by reversal (Charge cancel, assessment void, late-fee void,
all `finance.charges.manage`, no MFA, optional text); unassigning an
optional fee only changes intent. Classification: cancel/void = ordinary
correction (direct, recommend REASON_REQUIRED + ON_EXECUTION step-up);
waiver = a concession (category `waiver`, already maker/checker); no
override exists. No approval layer recommended unless the owner chooses
(OD-8.3).

**56.22 Library fines.** Generated once at check-in (bounded Finance side
effect, stored loan Campus). Void: `library.fines.void` (school_admin
only), controller-checked, free-text reason (DB), refused when paid or
adjusted, cancels the Charge, never a refund. Waiver = concession.
Recommendation: keep direct for the holder; add a closed reason; an
optional librarian request (`library.fine_voids.request`, Finance-side
decision by the existing key) only if the owner wants librarians to
initiate (OD-8.5). Never broad Finance authority for the librarian. E34
blocks production regardless.

**56.23 Attendance.** Student: corrections are direct compare-and-swap by
`attendance.manage` (Campus-adopted, register's stored Campus) or the
owning teacher; no reason, no window, no approval. Recommendation:
NO_APPROVAL; REASON_REQUIRED (closed); any "late correction needs approval"
window is a School-configurable business policy (OD-8.6), not v1. Staff:
separate system (HRX), direct correction with closed reason, version CAS,
correction evidence trigger, self refused; NO_APPROVAL, unchanged. E33
applies to teacher Student Attendance only.

**56.24 Student lifecycle and transfer.** Status toggle and Enrollment
end are direct whole-School operations (`students.manage`,
`enrollments.manage`: school_admin, principal) without reason, lock (status)
or MFA (G-6). Recommendation: NO_APPROVAL in v1 + closed reason +
ON_EXECUTION step-up + confirmation (LC-8 STU-14); a Campus-staff request
with whole-School approval (`students.status.request/.approve`) only on
owner decision D-13 / OD-8.7. Campus transfer: **NO_APPROVAL** — dual-scope
authority over both Campuses, active-placement guard, Hostel/Transport/
elective blockers, immutable placements and audit already exceed what an
approval adds; Campus actors are limited to today.

**56.25 HR Leave.** Single-level, final either path; manager re-resolved
under lock at decision (a stale manager gets the private 404); no manager
-> administrative fallback; no delegation; no Campus logic (whole-School).
Gaps: G-4 (`hr.leave.manage` writes on one's own leave balance and
administrative cancel of one's own approved leave are not self-refused);
Campus principal approval and cross-Campus managers remain owner decisions
(§53.16, OD-8.8); no fresh MFA (recommend NONE). HRX-L1/L3 open.

**56.26 HR lifecycle.** Onboarding, assignments, separation, rehire,
account links and archive are direct HR operations with the
self-administration guard (rule 95); highly sensitive documents need fresh
MFA. Recommendation: no approval; closed separation reason; core-field and
personal-detail self-access stays the open ADR 0071 §26.13 decision.

**56.27 Payroll.** Prepare (create, calculate, overrides, deltas) ->
approve (fresh MFA; creator != approver in service and DB) -> post (fresh
MFA; approver may post) -> reverse (fresh MFA, optional reason) or a
correction run. No reopen, no payment release. **G-2 (MEDIUM SoD):** the
check compares only the run creator, so a prepare+approve holder can amend
or recalculate another preparer's run and approve it. Recommendation:
prior-participation SoD (56.7), closed reverse reason. Open owner
decisions: approver's own pay, approve != post (OD-8.4). HRX-L4, E45 open.

**56.28 Exams, lesson plans, Admissions.** Marks corrections are the
marks maker/checker (closed reason, SoD, fresh MFA, DB invariants); paper
lock is a direct irreversible step with fresh MFA; results, publication,
moderation are not built and legal-blocked; approval authority never
grants marks access and TeachingOwnership stays independent. Lesson plans:
no module, nothing to approve. Admissions: accept/reject/withdraw are direct
privileged transitions (`admissions.manage`, Campus-adopted on the stored
application Campus), optional unaudited note; conversion whole-School.
Recommendation: NO_APPROVAL; closed rejection reason.

**56.29 Owner decisions (recorded, open).** OD-8.1 payment
reversal/refund maker/checker (amends ADR 0031 §3 / rule 91); OD-8.2
ledger reverser != poster for manual entries (needs a poster column);
OD-8.3 Charge cancel/void approval or reason+MFA only; OD-8.4 payroll
approver's own pay and approve != post; OD-8.5 librarian void request;
OD-8.6 School-configurable attendance correction window; OD-8.7 Student
status requests (D-13); OD-8.8 Campus principal Leave approval and
cross-Campus managers; OD-8.9 Group governance fresh MFA (G-5, platform).

**56.30 Canonical v1 model and sequencing.** Model: domain-owned request
tables and domain-specific queues (option B) over a small shared assurance
kit (option C-lite): a per-item participation/SoD evaluator, the canonical
fingerprint helper, the closed-reason convention, the audit vocabulary
(`<domain>.<item>.requested|approved|rejected|executed|reversed|cancelled|
overridden`, ids and closed codes only), and an architecture guard that
registers every approval workflow (state CHECK + SoD CHECK + fingerprint or
immutable terms + reason policy + step-up policy). State: `pending ->
approved | rejected | cancelled`, plus `invalidated` where terms may change
and `executing -> executed` only for pattern B; terminal decisions by
conditional `UPDATE ... WHERE status = 'pending'` under a row lock, one
decision per request (unique index), transition triggers. Idempotency:
server-issued request keys, payload fingerprint, replay re-checks current
authority before returning a stored result (rule 32); decisions are
idempotent through the state machine. Async: no approval job serializes
"authorized"; a job carries request id, decision id, target and fingerprint
and re-validates at execution (`ExecutesModuleWork` pattern). Audit: the
School audit ledger is append-only and sufficient as evidence; the decision
row must additionally persist decider, time, scope fact (Campus id or NULL),
fingerprint, reason code and the fresh-MFA fact, so history never depends
on today's role or Campus state. No tenant-configurable workflow engine;
approval topology and security policy are CODE-OWNED; only business
policies (Communications approval policy today; a future threshold or
correction window) are School-configurable; legal policy is never
configurable. Platform roles never decide tenant business items.

Sequence (each needs its own owner authorization):
- ORG.8-P1 — subledger journal reversal guard (G-1), integrity only.
- ORG.8A — assurance kit + pilot on fee concessions (G-3): the kit ships
  with its first adopter (rule 2).
- ORG.8B — payroll prior-participation SoD (G-2).
- ORG.8C — Finance high-risk: payment reversal/refund maker/checker
  (OD-8.1), ledger actor rule (OD-8.2), Charge cancel reason/step-up.
- ORG.8D — HR Leave completion (G-4, OD-8.8).
- ORG.8E — SIS status/Enrollment end, attendance reasons, Library void,
  Admissions reasons (OD-8.3/8.5/8.6/8.7).

**56.31 Pilot.** Fee concessions: the most complete existing Finance
request/approve workflow (DB SoD, immutable terms, locked decisions, fresh
MFA, idempotent requests), so the kit is extracted from working code, not
a synthetic demo; Communications supplies the fingerprint/invalidation
reference.

**56.32 Queue privacy.** Each queue lists only items its viewer may decide,
filtered in SQL by capability, Campus scope and relationship; shows
minimal fields (no full Student/Employee profile, no contact value);
out-of-scope ids are the unknown 404. Today's queues already comply: Leave
approvals (current direct reports only, private 404), concessions
(`.view`), marks corrections, Communications approvals.

**56.33 Separation from other work.** ORG.7 LOW residuals (replay residual,
LIKE hygiene, Inventory item `orWhere`, Library rollback,
`StudentPurposeLookup` docblock, Payment-list scale, malformed Campus UUID,
Canteen Location 500) stay separate; no approval workflow depends on them.
LC-5–LC-10 stay paused and none needs ORG.8 (LC-6 ATT-06 and LC-8 STU-14 /
LIB-05 add confirmation only and stay compatible with 56.23/56.24/56.22).
ORG.9 owns reference parity: offline-payment approval, bank
reconciliation, cash-deposit/petty-cash/journal-voucher approvals,
bulk refunds, report-card publish settings, transfer certificates, bulk
branch transfer, staff-attendance modification approvals, approval ageing
(reference `Menu_Permissions_Master.xlsx`, §31.8, not committed; its
"Approve" cells are product exposure, not security design).

**56.34 Validation.** Authorization, Campus, coverage, catalogue and
approval-domain tests and the documentation guards; no full regression (documentation only); counter 0/5. One uncommitted
scratch test reproduced G-1 and was deleted.

**56.35 Status.** ORG.8 contract audit CLOSED once published; PASS WITH
PREREQUISITES (ORG.8-P1, owner decisions). Next: owner authorization of
ORG.8-P1 and then ORG.8A.

## 57. ORG.8-P1 — Ledger / subledger reversal integrity guard (as built, 2026-10-21)

Owner authorization: ORG.8-P1, narrow financial data-integrity correction
of ORG.8 finding G-1 (§56.19). Baseline `origin/main` = `6fa511dc`. No
approval workflow, capability, role, catalogue, Campus, legal-gate or MFA
change.

**57.1 Invariant.** A journal entry whose lifecycle another domain owns is
never reversed except through that domain. The generic ledger reversal is
not a back door around the Charge, fee-adjustment, Payment-correction or
Payroll state machines.

**57.2 Reproduction (before P1, committed tests failing first).** Through
`LedgerAdministrationService::reverse()` (school_admin, fresh MFA): a
Charge entry, a Charge's cancellation entry, a fee-adjustment entry, an
adjustment's cancellation entry, a Payment-correction entry and a payroll
posting's entry were all REVERSED; only a Payment's settlement entry was
refused (`journal_entries_payment_reversal_guard`). The JSON API answered
201 for a Charge entry; the Charge then stayed live and its canonical
cancellation refused as `ChargeAlreadyCancelledException`.

**57.3 Provenance (structural, complete).** Every production writer of
journal entries (machine-inventoried by `JournalProvenanceArchitectureTest`)
stores its entry on an owner column in the same transaction; none of those
columns changes afterwards (immutable / append-only owners). Every foreign
key into `journal_entries` is classified (`SubledgerJournalReversalDbTest`):

    writer                              owner column (kind)                                   canonical reversal
    ChargeService                       charges.journal_entry_id (charge)                     ChargeService::cancel -> charges.cancellation_journal_entry_id
                                        charges.cancellation_journal_entry_id (charge_cancellation)   never
    FeeAdjustmentService                fee_adjustments.journal_entry_id (fee_adjustment)     FeeAdjustmentService::cancel -> .cancellation_journal_entry_id
                                        fee_adjustments.cancellation_journal_entry_id (..._cancellation) never
    SettledPaymentRecorder              payments.journal_entry_id (payment)                   never (corrections post new entries)
    PaymentCorrectionService            payment_corrections.journal_entry_id (payment_correction)    never
    PayrollPostingService               payroll_run_postings original / reversal              PayrollPostingService::reverse (reversal posting) / never
    StatutoryPayrollPostingService      payroll_statutory_run_postings original / reversal    StatutoryPayrollPostingService::reverse / never
    LedgerAdministrationService         none (ledger-native manual posting)                   generic reversal
    DemoModuleData (local demo only)    none (ledger-native)                                  generic reversal
    structure                           journal_entries.reversal_of_journal_entry_id, journal_lines.journal_entry_id

Late fees, Library fines, Hostel/Transport/Canteen/Admissions fees and
fee-run assessments are Charges; concessions post fee adjustments; refunds
and reversals of Payments are Payment corrections. There is no opening,
period-close, import or migration writer of journal entries. Class C
(system-generated) is empty; class D (unknown/legacy) cannot arise: an entry
no owner column names is ledger-native by construction, and a new writer or
a new foreign key fails the architecture/DB guards until classified. No
description, memo or account text is ever read.

**57.4 Canonical policy.** `journal_entry_subledger_owner(entry, school)`
(SECURITY INVOKER, RLS-scoped, not PUBLIC-executable) is the one
classification. `LedgerReversalPolicy::assertGenericallyReversible()` is the
single Finance entry that applies it; `LedgerAdministrationService::reverse()`
runs capability -> entry lookup (unknown and cross-School ids stay the same
404) -> policy -> `LedgerService::reverse()` (already-reversed pre-check,
period assignment, unique reversal, audit); the controllers check the
capability and the fresh MFA code first. A refused entry is the existing
`JournalEntryNotReversibleException` (409 `JOURNAL_ENTRY_NOT_REVERSIBLE`,
browser: reversal error), now with a message that never names the owner;
nothing is written. MFA never bypasses it. Finance still never reads an
owner table itself.

**57.5 Database backstop (every role, raw SQL included).** On
`journal_entries`:
- `journal_entries_subledger_reversal_guard` (BEFORE INSERT, reversal rows):
  refuses at once a reversal of a never-reversed kind (Payment, Payment
  correction, any cancellation or reversal entry). The Payment guard is kept
  unchanged and fires first for Payments.
- `journal_entries_subledger_reversal_owner_check` (DEFERRABLE INITIALLY
  DEFERRED): at COMMIT, a reversal of a Charge, fee-adjustment, payroll or
  statutory original must be linked by its owner (cancellation entry /
  reversal posting). The canonical services link it in the same
  transaction; a generic or raw reversal is rolled back. The check pins
  `app.current_school_id` to the row's own School for its reads and restores
  it, so a cleared or forged context at COMMIT cannot make the owner
  invisible (fail closed); it reads only that School, whose row RLS already
  let the inserter write. `SET CONSTRAINTS ... IMMEDIATE` only checks sooner.
The runtime role holds only INSERT and SELECT on the ledger, owns neither
table nor function, and cannot disable a trigger.

**57.5a Security review** (independent): no HIGH or MEDIUM. LOW fixed: the
classification tested originals before cancellations, so a raw-SQL owner
row naming an existing cancellation entry could make it look like a
reversible original -- the never-reversed kinds are now decided first
(pinned). LOW fixed (tests): another School's entry classifies as nothing
and a reversal under another School cannot name it (same-School key); the
architecture scan also refuses an inline `LedgerService::class)->` call.
LOW accepted: a raw-SQL owner row adopting an entry that was ALREADY
reversed in an earlier transaction is not refused (the runtime role can
already post arbitrary balanced entries; no application path writes owner
rows except with the entry it just posted). LOW accepted: a pre-P1 orphan
leaves its owner un-cancellable (57.9); none exists.

**57.6 Domain flows unchanged.** Charge cancellation, fee-adjustment
cancellation (concession `cancelAdjustment`), Payment corrections (MFA,
mandatory reason, server key, one reversal per Payment), payroll and
statutory reversals all work and satisfy the COMMIT check; no double
reversal (`journal_entries_reversal_of_unique` unchanged).

**57.7 Concurrency (two real processes, contender observed blocked).** Raw
reversal holding vs canonical Charge cancel waiting: the raw reversal fails
at its own COMMIT and the cancellation wins. Canonical cancel holding vs raw
reversal waiting: the raw one is the refused second reversal. Raw reversal
holding vs Payroll reversal waiting: Payroll wins; the one reversal is the
one its reversal posting records. The generic service path never reaches an
insert for an owned entry (refused before), and ownership is fixed when the
entry is created, so the decision cannot change under it. Lock order is
unchanged (the owner's row lock, then the unique reversal index); no School
or global lock; no deadlock found. Two generic reversals of one ledger-native
entry: unchanged (`JournalReversalConcurrencyTest`).

**57.8 Periods, audit, authorization.** Period rules unchanged (a reversal
posts in the current open period; closed periods refuse postings). Audit
unchanged (`journal_entry.reversed` only for a performed reversal; no
refusal event, no owner data logged). `finance.ledger.reverse` stays
whole-School and `CAMPUS_INCOMPATIBLE`; no capability, role, Campus or
platform-persona change; cross-School ids stay not-found.

**57.9 Repair of pre-P1 records.** An orphan reversal written before P1 is
not owner-referenced, so it is ledger-native and stays generically
reversible: reversing it restores the ledger to match the still-live record
(committed test). The original keeps its one historical reversal, so that
Charge can no longer be cancelled canonically; removing it economically is
a fee waiver (concession, category `waiver`). No historical row is rewritten.
Data audit (development database, read-only, as superuser): 72 entries —
39 Charge, 30 Payment, 1 payroll posting, 2 ledger-native — no reversals at
all, no live owner against a reversed entry, no double reversal, no
dangling link, no multi-owner entry. Nothing to repair.

**57.10 Migration and rollback.** `2026_12_30_090000_guard_subledger_journal_reversals`:
one function, two trigger functions, two triggers; no table, column, index or
data change. Rollback + re-apply IDENTICAL (152 schema lines);
`verify-database` failed=0. Rolling back re-opens G-1 (Payment guard stays).

**57.11 Unchanged and deferred.** No approval toolkit, request table,
maker/checker, reason-code framework or new capability; G-2 (payroll SoD),
G-3..G-6 and OD-8.1..OD-8.9 stay open; ORG.7 residuals unchanged; legal
gates unchanged (E30–E34 still block production).

**57.12 Status.** G-1 CLOSED once published. Next: ORG.8A (assurance kit +
fee-concession pilot), owner authorization.

## 58. ORG.8A — Shared approval assurance foundation and fee-concession pilot (as built, 2026-10-21)

Owner authorization: ORG.8A. Baseline `origin/main` = `db9a654a` (§57). No
generic approval table, workflow engine, capability, role-template,
`CampusScopeCatalogue`, migration or legal-gate change.

**58.1 Owner decisions frozen.** 8A-D1: a shared SECURITY foundation over
domain-owned workflows — every domain keeps its own request table, states,
queue and execution transaction. No configurable or tenant workflow engine;
approval topology is code-owned. Fee concessions are the sole pilot.

**58.2 The toolkit (`app/Support/Approval`, plus two hooks).** Only what the
pilot proves common:
- `ApprovalParticipants` — the Users who MATERIALLY took part in one item,
  keyed by participation, supplied by the domain from durable columns.
- `MakerCheckerPolicy` — the one rule: the decider took no material part in
  the item (any participant, not only a requester). Per item and per actor;
  never a role name; holding both capabilities never satisfies it.
- `ApprovalDecisionAuthority` — inside the decision transaction, after the
  item lock: the School is operational (FOR SHARE) and the decider holds the
  decision capability whole-School NOW (`CapabilityResolver::holdsInSchoolNow`:
  fresh, not the 60 s grant or entitlement caches; active membership and
  active whole-School grants share-locked; disabled Users, Campus grants and
  a disabled module never count). A concurrent revocation or suspension is
  ordered before or after the decision; the disabled flag and the module
  entitlement are read fresh at decision time but not locked. Lock order matches `StaffAccessService` (membership,
  then grants); the approval side never takes a lock revocation needs later,
  so no deadlock.
- `ApprovalProvenance` — the approval facts appended to the domain's OWN
  audit event (event names unchanged): workflow, decision
  (`requested`/`approved`/`rejected`), participants and decider by User id,
  the decision scope as the domain states it (`school`), and whether a fresh
  MFA code was verified. Ids and closed codes only.
- `FreshMfaProof` — `FreshMfaRequirement` now returns the fact it verified;
  a domain records it as provenance (refused for anyone but the decider).
  Minted only by `FreshMfaRequirement` (pinned). Assurance, never authority.
- `ApprovalWorkflowRegistry` — the code-owned inventory (architecture guard,
  never read at runtime).
Deliberately NOT in the toolkit: request data, states, transitions, queues,
Charge/payroll/Leave/marks/publishing effects, a fingerprint helper (no
pilot needs one), expiry, delegation, thresholds, multi-level, override,
reason-code framework.

**58.3 Pilot contract (request -> approve/reject, immediate execution).**
- **Participants:** `{requester}` = `fee_concessions.requested_by_user_id`.
  Every request term and the requester are DB-immutable after submission
  (`fees_validate_fee_concession`), nobody amends a concession, so the
  requester is the only material participant; application and
  `fee_concessions_sod_check` agree exactly. No DB change needed.
- **Drift:** IMMUTABLE_TERMS (no fingerprint). Targeted amounts are still
  re-validated under the Charge lock at approval (G1 capacity).
- **Order (one transaction):** cached capability floor -> School
  operational -> concession FOR UPDATE -> state -> current authority ->
  maker/checker -> transition -> adjustment + journal (approve, via
  `FeeAdjustmentService`/`LedgerService`, P1 intact) -> audit with
  provenance -> commit. Any refusal rolls everything back; there is no
  approved-but-unexecuted state.
- **MFA (unchanged):** approve needs a fresh code (web and API, recorded as
  `freshMfa: true`); reject does not (`freshMfa: false`).
- **Reasons (unchanged):** none on decisions (owner decision M); no
  free text in audit.
- **Retries/races:** decisions are not keyed; the locked state transition
  plus the DB trigger make a repeat a 409 conflict with no second adjustment
  or journal. Two-process races: approve vs approve (existing), approve vs
  reject both orders, approve vs withdraw both orders -> one terminal
  decision, an adjustment only if approval won; a revocation of the
  approver's grants committed first -> the waiting approval re-reads and is
  refused (403), nothing written.
- **Direct service calls** enforce the cached floor, current authority,
  maker/checker, state and validity (no reliance on route middleware). Web
  and API converge on the same service.
- **Queue:** `finance.fee_concessions.view`, School predicate, unknown and
  malformed ids not-found, ids/terms/status only (no Student profile).
- **Whole-School:** keys unchanged, not adopted, `CAMPUS_INCOMPATIBLE`; a
  Campus grant never counts. Holders unchanged (request: school_admin,
  accountant; approve: school_admin). No principal, cashier or platform
  authority.
- **Higher-order gates:** a disabled `fees` module and a suspended School
  refuse at decision time even behind warm caches; no legal gate changes
  (E30–E32 still block production use).
- **Outside the pilot (G-3, LOW, open):** revoke and adjustment cancellation
  keep their current semantics (no SoD, optional reason).

**58.4 Registry and completeness guard (`ApprovalWorkflowRegistryTest`).**
Registered: fee concessions (shared, pilot), Communications approval
(adapter), marks corrections (as-is), Leave (special rules), payroll runs
(adapter, G-2), erasure cases (not applicable). It fails when a
decision-shaped column (`*_by_user_id` of decided/approved/requested/
reviewed/rejected/prepared/previewed/submitted) is undeclared, a
request/approve/decide/review capability is unclassified or Campus-adopted,
a registered table/column/backstop/service/method vanishes, a workflow other
than the registered shared one uses `MakerCheckerPolicy`, the shared
workflow decides without the policy/authority/provenance, a fresh-MFA proof
is minted elsewhere, or the toolkit touches business data or a generic
`approval_requests` table appears. Marks isolation (ADR 0068 §19.2) is
kept: the registry names the marks-correction table and columns but never a
marks class (the closed consumer set is unchanged), and
`StudentMarkArchitectureGuardTest` lists the registry beside the other
name-only registries (retention, role verifier).

**58.5 Compatibility (analysis only; no behaviour change).**

    workflow                participants today                    verdict        later need
    Communications          requester (service only, no DB check) ADAPTER        announcement creator as participant; DB backstop; capability below controller
    marks corrections       requester (+ DB check)                AS-IS          capability below the route
    Leave                   requester Employee (trigger-derived)  SPECIAL RULES  Employee identity, manager relationship, admin path, self withdraw/cancel
    payroll runs            run creator only (+ DB check)         ADAPTER        G-2: override/delta authors and recalculators (ORG.8B)
    erasure cases           data subject (not a User)             NOT APPLICABLE —

**58.5a Security review** (independent): no HIGH or MEDIUM. LOW fixed: the
fresh-MFA proof guard now catches fully qualified, aliased and reflective
construction; the registry guard's column and capability patterns were
widened (Leave's Employee decision columns registered) and every declared
decision method must call the shared check; the fresh-read docblock no
longer claims locking for the disabled flag and entitlement. LOW accepted:
a decision service still accepts a missing MFA proof (fresh MFA stays a
transport control, rule 95; provenance then records `freshMfa: false`);
revoke/cancel stay outside the pilot (G-3); the revocation race uses a plain
grant UPDATE, not `StaffAccessService` (lock order argued in 58.2); a
membership share lock briefly delays other updates of that row. New LOW
G-7 (pre-existing, recorded for the owner): a staff member who is also the
Student's linked Guardian can approve a colleague's concession for that
Student -- a beneficiary-relationship participation class is a later
decision, not part of 8A.

**58.6 Unchanged / open.** G-2 (payroll participation) MEDIUM, next phase
ORG.8B; G-3 LOW; G-4..G-6, OD-8.1..OD-8.9; ORG.7 residuals; LC-5–LC-10
paused; ORG.9 parity.

## 59. ORG.8A-R1 — Guardian beneficiary conflict and Guardian self-administration guard (as built, 2026-10-21)

Owner decision: OPTION 1 (self-link / self-unlink guard). Option 2
(relationship history) not authorized; option 3 (accept the residual)
rejected. Baseline `origin/main` = `f2b04e22` (§58). Closes ORG.8A G-7.

**59.0 Status.** G-7 CLOSED for account-linked Guardians (the owner's
contract); the unlinked-identity residual M-1 is recorded in 59.7a for an
owner policy decision.

**59.1 Reproduction (before R1, committed tests failing first).** A
non-requesting approver linked as a Guardian of the Student approved the
concession; the same approver unlinked their own Guardian↔Student
relationship and relinked it with no refusal.

**59.2 Identity and relationship model (structural, School-scoped).**
User → `school_memberships` (one per User and School) →
`student_guardian_account_links` (Guardian kind: `guardian_id` set;
`active`/`revoked`, never deleted; revoked = unlinked, no error marker, so
it is history of ownership) → `guardians` → `student_guardian_relationships`
(any type; hard DELETE on unlink, no history, no actor column). RLS forced
on all of them. One Guardian may relate to several Students and a Student
to several Guardians; one membership may be both staff and Guardian, so
persona never matters.

**59.3 Rule B — no self-administration (Guardians).**
`GuardianSelfAdministrationGuard` refuses link, update, set-primary and
unlink in `StudentGuardianRelationshipService` (the only application writer;
web, API and Admissions conversion all pass the acting User — pinned) when
the Guardian identity belongs to the actor: any account link of their
membership in that School, active or revoked
(`GuardianAccountOwnership`, a Guardians port answered by Identity's
`GuardianAccountOwnershipReader`, bound in `AppServiceProvider` — Guardians
never depends on Identity). 403 `GUARDIAN_SELF_ADMINISTRATION`, fixed text.
Another authorized administrator, and the actor on unrelated Guardians, are
unaffected; `guardians.manage`/`students.manage`, `GuardianStudentScope` and
portal authority are unchanged. A null actor is a trusted internal path.

**59.4 Rule A — beneficiary conflict (Fees).** In the locked decision,
after fresh authority and the shared maker/checker,
`GuardianBeneficiaryRelationship::isGuardianOf()` refuses approve AND reject
when any Guardian identity that belongs to the decider (active or revoked
link) has a CURRENT relationship to the concession's Student: 403
`FEE_CONCESSION_DECISION_CONFLICT`, generic text, nothing written (no
decision, adjustment, journal or success audit). Per Student; requesting is
unaffected; an unrelated approver decides. `MakerCheckerPolicy` is
unchanged (no Guardian semantics); no generic conflict-of-interest
framework; the rule is the concession's own (`relationshipConflict:
guardian_of_student_beneficiary` in the registry, pinned).

**59.5 Database backstop (Rule A).** `fee_concessions_beneficiary_guardian_guard`
(BEFORE UPDATE OF status, decided_by_user_id; pending -> approved/rejected)
refuses a decision whose RECORDED decider is such a Guardian — the same
recorded-actor pattern as `fee_concessions_sod_check` (kept). SECURITY
INVOKER, pinned search_path, not PUBLIC-executable; reads only the decision's
own School. Proven as the runtime role: approve and reject refused (revoked
link included), requester still refused by the SoD check, an unrelated
decider stands, another School's link never counts. Rollback + re-apply
IDENTICAL (65 schema lines); `verify-database` failed=0.

**59.6 Rule B at the database: application-enforced, stated plainly.** A
relationship row records no actor and the repository has no trusted
database actor context, so a database self-administration rule would need a
caller-supplied actor (spoofable) — not built. Revoking runtime INSERT/DELETE
would need a definer function with the same spoofable parameter. The
runtime role can therefore still write relationships by raw SQL; that is the
same trust boundary as every recorded-actor rule here (raw runtime SQL could
equally record a different `decided_by_user_id`), and it gives a raw-SQL
actor nothing beyond what raw SQL already has. Every application path goes
through the guarded service (pinned by `GuardianSelfAdministrationTest`).

**59.7 Concurrency.** An independent administrator's unlink held open:
the uncommitted DELETE is still the committed relationship, so the Guardian
approver is refused; once it commits, the current-relationship contract
applies and the approver may decide (two-process test). The approver's own
unlink is refused, so no race they control lifts the conflict. No new lock;
ORG.8A races (approve/approve, approve/reject, approve/withdraw, grant
revocation) unchanged.

**59.7a Security review** (independent): no HIGH. MEDIUM M-1, recorded
for the owner, a limit of the approved structural contract (no name or
contact matching, §59.2). Rule A sees a decider's Guardian identity only
through an account link of their membership. A staff parent can stay
invisible by:
- never linking their account to their Guardian identity, or deciding
  before they link it;
- using a separate User account for the portal;
- creating a duplicate Guardian record for another child (refused only if
  that record is linked to their account; one active link per membership).

What R1 does close: the owner's G-7 sequence (unlink -> decide -> relink for
an account-linked Guardian) and every self-administration of an owned
identity. The detective control for the rest is the audit trail: the same
actor creating or linking the Guardian and later deciding. Closing it
structurally needs a policy (e.g. staff must link their own Guardian
identities, or a declared-relationship register) — an owner decision, not
built.

LOW fixed: the "every caller names the acting User" pin now finds every
receiver of the service (typed variables, properties, inline container
resolution).

LOW accepted:
- A revoked link counts forever and has no correction path. An
  administrator could link and revoke a colleague to disqualify them, which
  is audited; the owner chose revoked = owned.
- A null actor (internal path) skips Rule B; every application caller
  passes one (pinned).
- revoke and adjustment cancellation stay outside Rule A (G-3).
- A Student-kind account link (a staff member who is the Student) is not
  treated as a beneficiary.

**59.8 Unchanged.** MFA (approve fresh code), fresh authority, entitlement,
lifecycle, Campus (none), roles, capabilities, `CampusScopeCatalogue`,
G-3 (revoke/cancel), G-2 (Payroll), ORG.8-P1 ledger. Queue unchanged: a
conflicted approver may still see the pending item; the decision refuses
(future UX: disable it). Module directions: Fees → Guardians (new, no cycle);
Guardians ← Identity through the port.

## 60. ORG.8B — Payroll approval participation (as built, 2026-10-22)

Owner authorization: ORG.8B, closing ORG.8 G-2 on the ORG.8A foundation.
Baseline `origin/main` = `260a7c4b` (§59). No capability, role, Campus,
delegation, threshold or multi-level change; M-1 and G-3 untouched.

**60.1 Reproduction (before 8B, committed tests failing first).** The creator
was refused; a User who recalculated another preparer's run approved it, and
so did a User who recorded a manual override on it.

**60.2 State machine (unchanged).** `draft -> calculated -> approved ->
posted`, forward only (transition trigger). Create (`payroll.runs.prepare`,
no MFA); calculate/recalculate (`.prepare`, replaces the whole result set,
draft or calculated only); manual override / correction delta (`.prepare`,
append-only `payroll_adjustments`, draft or calculated only); approve
(`.approve`, fresh MFA, results/HRX inputs frozen); post and reverse
(`.post`/`.reverse`, fresh MFA, Payroll-owned journals, P1). No reopen,
cancel or payment release. Holders: prepare = school_admin, payroll_officer;
approve/post/reverse = school_admin.

**60.3 Material participants (structural, per run, monotonic).**
- creator: `payroll_runs.prepared_by_user_id`;
- every calculator and recalculator: NEW `payroll_run_calculations`
  (append-only, School- and run-bound, RLS forced, `calculated_by_user_id`
  RESTRICT; a calculation replaced the results and recorded no actor, so
  nothing durable named a calculator before);
- every override and delta author: `payroll_adjustments.actor_user_id`
  (append-only; an override is never edited or deleted, a new value is a
  new row).
Viewers, posters and HR editors of master data are not participants:
compensation is read at calculation time, so the calculator is the
participant who materialises it. No system job calculates or approves
payroll. `PayrollRunService::participants()` is the one set; no caller
supplies it.

**60.4 Decision (shared foundation).** `PayrollRunAdministrationService::approve`
locks the run, takes the approver's CURRENT authority
(`ApprovalDecisionAuthority`: operational School, `payroll.runs.approve`
whole-School now, Payroll module), then `PayrollRunService::approve` (run
locked) refuses any participant through the shared `MakerCheckerPolicy`
(`SelfApprovalNotAllowedException`) and refuses stale results: no recorded
calculation, or an adjustment recorded after the latest one
(`RunRecalculationRequiredException`, 409). Approval provenance (participants,
decider, `school`, fresh-MFA fact) is added to `payroll.run.approved` — ids
only, no amounts. A direct core call still enforces maker/checker and
staleness (the authority read is the authorized entry's, like every other
Payroll capability).

**60.5 Database backstop.** `payroll_runs_participant_approval_guard`
(BEFORE UPDATE of status/approved_by, calculated -> approved; every role):
the recorded approver is no participant (creator, any calculation, any
adjustment) and the results are not stale. `payroll_runs_sod_check` stays.
`payroll_run_calculations` refuses a row for a decided run and an
`adjustment_count` that is not the run's real count. Proven as the runtime
role. `payroll_run_calculations` is in `verify-database`'s no-runtime-UPDATE/DELETE
lists. Rollback + re-apply IDENTICAL (see 60.8a); `verify-database`
failed=0. A raw-SQL calculation that skips recording its calculator is the
recorded-actor trust boundary of §59.6 (the database has no actor context).

**60.6 Concurrency (two processes, contender observed blocked).**
calculate, every adjustment and approve lock the run row, so: a recalculator
waiting to approve is refused once their recalculation commits; a
recalculation or override waiting on an approval finds the run decided; an
approval waiting on an override needs a recalculation; two approvals make
one. No approval ever covers results a concurrent change alters. Lock order
(run, then the approver's membership/grants FOR SHARE) shares no row with
staff revocation (membership, grants) or posting (run after approval); no
deadlock.

**60.7 Existing runs.** No backfill: earlier calculators are not recorded
anywhere structural. A run calculated before 8B fails closed (approval needs
a recorded recalculation). Development data: one calculated demo run, no
adjustments; no production data exists.

**60.8a Security review** (independent): no HIGH.
- **MEDIUM, fixed (M1, pre-existing, made reachable by the staleness gate).**
  A recalculation of a calculated run that left an employment unresolved
  kept the status (no calculated -> draft), so a payroll missing that
  employment was approvable. Now each calculation records a per-run
  `ordinal` and whether it was `complete` (every employment resolved, at
  least one result). Approval requires the LATEST calculation to be complete
  and of every recorded adjustment, in the service and in the database.
- **LOW, fixed:**
  - Who approved, and when, is frozen once the run is decided
    (`payroll_runs_freeze_approval`).
  - The calculation guard and a new adjustment guard share-lock the run and
    refuse anything but a run being prepared.
  - The new table is in the `verify-database` lists.
  - An architecture pin makes `PayrollRunAdministrationService` the only
    application caller of the core approval, with a race through that
    entry.
  - A correction-delta author is tested.
- **LOW, recorded:**
  - Statutory calculation (`StatutoryPayrollCalculationService`) has no
    route or CLI and is legally gated. When it is wired up, its actor must
    join the participant set and statutory results must count toward
    staleness.
  - Compensation editors are not participants: the approved output is the
    frozen calculation, and the calculator is the one who materialised it.
  - `payroll_run_hrx_inputs` is missing from the verifier lists
    (pre-existing).

Rollback + re-apply IDENTICAL after the fixes (268 schema lines, on a clean test database).

**60.8 Unchanged / open.** Approver may also post (OD-8.4); posting and
reversal have no participant rule (OD-8.4, a later Payroll follow-up);
payroll stays whole-School; MFA unchanged (approve, post, reverse need a
fresh code; create, calculate and adjustments do not); legal gates HRX-L1..L4,
E45/PAY-L1 unchanged (technical only, no production effect); registry: Payroll
now `shared` with its participant sources and backstop.

## 61. ORG.8C — Payment correction approval (as built, 2026-10-22)

Owner authorization: ORG.8C (second brief), owner decision OD-8.1 **Option 1**:
the Guardian-beneficiary conflict applies uniformly to reallocation,
reversal and recorded refund, for approve and reject (refund-only and
"accept the residual" rejected). Baseline `origin/main` = `f2242d5e` (§60).
The first ORG.8C brief stopped on its related-party stop condition; this one
authorizes the conflict.

**61.1 Reproduction (before 8C).** At `f2242d5e` an accountant reallocated
and a School Admin reversed and refunded a Payment directly, alone, from
the Payment page (`PaymentCorrectionWebTest`, 7 green tests = the evidence
of single-actor execution).

**61.2 Workflow.** `payment_correction_requests` (Payments-owned, FORCE RLS,
no runtime DELETE): `pending -> approved | rejected | withdrawn`, final.
- **Request** (`finance.payment_corrections.request`, no MFA): the full
  correction terms (kind, charge, destination, amount, refund account /
  method / date / reference, reason 5–500), the requester, a server-issued
  idempotency key (unique per School; same User + same terms replays,
  anything else `PAYMENT_CORRECTION_REQUEST_KEY_CONFLICT`) and
  `prior_correction_count` = the Payment's corrections, read under the
  Payment's correction lock. The page sends the count it showed
  (`correction_count`); a request made from a page older than a recorded
  correction is refused as stale, so a request never binds a Payment state
  its requester did not see. A reversed Payment cannot be requested
  against; a charge or account outside the School is a form error.
- **Approve** (`.approve`, fresh MFA via `FreshMfaRequirement`, after the
  capability, outside the transaction): request FOR UPDATE → pending →
  `ApprovalDecisionAuthority::requireCurrent` (operational School,
  whole-School capability and Fees module read fresh) → shared
  `MakerCheckerPolicy` over `participants()` (the requester, the only author
  of the frozen terms) → the beneficiary conflict → executes through
  `PaymentCorrectionService::executeApproved()` (idempotency key = request
  id; corrected_by = approver) → records the decision and the correction id
  → audit with `ApprovalProvenance`. One transaction: any refusal (stale,
  capacity, account, date, reversed) rolls the decision back; nothing is
  ever approved without its correction.
- **Reject** (`.approve`, no MFA): same checks, nothing executes.
- **Withdraw** (`.request`): the requester only, recorded as
  `withdrawn_by_user_id` (`payment_correction_requests_withdrawer_check`).
- **No executor capability**, no thresholds, delegation, multi-level or
  separate execution step.

**61.3 Direct execution removed.** `POST /app/finance/payments/{payment}/
corrections` and its controller (`App\Http\Controllers\App\Finance\
PaymentController`, whose only action it was) are deleted. The Payment page
form is "Request correction"; requests and decisions show on the Payment
page; Finance → Payment corrections is the whole-School queue (Payment
amount, kind, amount, requester, status; no Student, Guardian or charge
data). Pinned: only `PaymentCorrectionRequestService` holds the correction
core or calls `executeApproved()`; every payment-correction route resolves
to `PaymentCorrectionRequestController`; the Finance HTTP guard lists the
exact Payment mutation routes. `PaymentCorrectionService` stays the only
writer of `payment_corrections` (its per-kind `correct()` remains a trusted
internal core for fixtures, with no product caller).

**61.4 Capabilities and roles.** New `finance.payment_corrections.view`,
`.request`, `.approve` (class `financial`, module `fees`): `accountant` view
+ request; `school_admin` all three. No change to `cashier`, `principal`,
Campus Finance, teachers or platform roles (snapshot updated). Not
Campus-adopted; `CampusScopeCatalogue` and `FinanceCampusAccess` unchanged
(see 61.9).

**61.5 Beneficiary conflict (Option 1).** Nobody whose account has any
Guardian-kind link (active or revoked) to a Guardian currently related to
ANY affected Student decides, for every kind, approve and reject. Affected
Students = the Students of every Charge in the Payment's allocations, its
earlier correction lines, and the request's own source/destination Charges
(`affectedStudentIds`, and the same set in SQL:
`payment_correction_request_student_ids`). The refusal text is generic
(`PAYMENT_CORRECTION_DECISION_CONFLICT`, no relationship oracle). Reuses the
R1 structural model (`GuardianBeneficiaryRelationship::isGuardianOfAny`, the
`GuardianAccountOwnership` port, `fees_concession_decider_is_beneficiary_guardian`);
Rule B (§59) keeps the decider from editing the relationship. A Guardian may
request.

**61.6 Payment-state binding.** Correction rows are append-only and
allocations immutable, so the Payment's correction count is its allocation
generation. Under the Payment advisory lock `executeApproved()` refuses a
count other than `prior_correction_count` (`PAYMENT_CORRECTION_REQUEST_STALE`,
409); Charge state is still revalidated by the existing plans under Charge
locks. Several pending requests may coexist; once one executes the rest are
stale (they can still be rejected or withdrawn).

**61.7 Database backstop** (recorded columns; the database has no actor
context):
- `payment_correction_requests_sod_check`: decider ≠ requester;
- `payment_correction_requests_guard`: born pending; every term, the
  requester, Payment, generation and key immutable; non-pending is final;
- `payment_correction_requests_approval_guard` (pending → approved): names a
  `payment_corrections` row of the same School, Payment and kind, keyed by
  the request id (amount equal where set), and the Payment has exactly
  `prior_correction_count + 1` corrections;
- `payment_correction_requests_beneficiary_guardian_guard` (pending →
  approved/rejected): the recorded decider is no Guardian of any affected
  Student;
- CHECKs: kind shape, status shape, reason 5–500, INR.
All functions SECURITY INVOKER, pinned `search_path`, no PUBLIC execute.
- `payment_correction_requests_withdrawer_check`: the recorded withdrawer
  is the requester (status shape requires one on withdrawal).
Proven as the runtime role (`PaymentCorrectionRequestDbTest`).

**61.8 Concurrency** (two processes, contender observed blocked):
approve/approve → one correction; approve (held) vs reject → decided;
withdraw (held) vs approve → nothing executes; approve refund 1 (held) vs
approve refund 2 on the same Payment → stale; core reversal (held) vs
approve → refused (reversed). Lock order: request row → (correction key
advisory) → Payment advisory → Charges ascending; decisions on different
requests meet only at the Payment lock; no deadlock path.

**61.9 Unchanged / residual.**
- ORG.8-P1 untouched: correction journal entries stay subledger-owned.
- M-1 (§59.7a) now also bounds Payment correction decisions.
- The new keys are not in `CAMPUS_INCOMPATIBLE` (catalogue changes not
  authorized). A Campus grant of them is inert: they are checked only by
  the whole-School Gate, which never counts a Campus grant (rule 101), and
  both holders already carry incompatible keys.
- Rule 29: approve/reject/withdraw are not `idempotent`-routed; a retried
  decision finds the request decided (409), never a second execution.
- Retention: an unexecuted request leaves with its Payment/Charge
  (cascade); an approved one keeps its correction (RESTRICT), and a
  corrected Payment never expires (§3 of ADR 0031's amendment).

**61.10 Security review** (independent): no HIGH, no MEDIUM. LOW fixed:
- the generation is bound to the requester's page (`correction_count`,
  stale at request time) and read under the Payment correction lock;
- a resubmitted key with a changed refund reference fails closed instead of
  replaying;
- withdrawal records its actor and the database checks it is the requester;
- an unknown or cross-School charge/account on a request is a form error
  (422), not a 500 from the composite key.

LOW accepted:
- `PaymentCorrectionService::correct()` (the per-kind trusted core) has no
  database "must come from an approved request" backstop: it has no
  product caller (architecture-pinned), and a raw runtime-role insert is
  the recorded-actor trust boundary every domain shares (§59.6).
- An unexecuted (rejected/withdrawn/pending) request leaves with either
  Charge it names under Finance retention (cascade); it is history of that
  Payment/Charge only, and executed history is kept by RESTRICT.
- The requester is not Guardian-checked (owner contract: the conflict binds
  the decider; the approver is the control), as in the concession pilot.

Rollback + re-apply IDENTICAL (344 schema lines removed; `verify-database`
failed=0) on a clean test database, after the fixes.

## 62. ORG.8D — HR Leave approval authority (as built, 2026-10-22)

Owner authorization: ORG.8D (contract reconciliation + narrow Leave
implementation). Baseline `origin/main` = `fa13aef5` (§61). OD-8.8 is NOT
decided (62.3); only contract already frozen in ADR 0065, ADR 0076 §42.7 and
the ORG.8 v1 contract (§56) was implemented.

**62.1 What G-4 and OD-8.8 are (repository truth).**
- **G-4** (LOW, §56.25, matrix §3): `hr.leave.manage` holders could
  allocate, adjust, assign or end a leave policy, submit on behalf, and
  administratively withdraw or cancel for their OWN employment; only
  approve/reject were self-guarded (Employee identity, service + CHECK).
  Recommended handling: extend the HR self-administration rule (rule 95) to
  own leave writes.
- **OD-8.8** (§42.7, §53.16, §56.25, §56.29): two open owner decisions --
  whether a Campus-only principal (a Campus-scoped grant of
  `hr.leave.approve`) still approves through the reporting relationship, and
  how cross-Campus managers are treated.

**62.2 The v1 contract (frozen, ADR 0065 §6, §11, §23.5–§23.7).**
- Request: self-service `hr.leave.self` (ActingEmployee owns the
  employment) or administrative `hr.leave.manage` on the Employee's behalf.
- Decide (approve/reject), single level, either path final:
  - **manager path**: `hr.leave.approve` AND the acting Employee is the
    requester's CURRENT manager -- `ReportingLine::holdManagerOf` (FOR
    SHARE, at decision time): the requester's assignment open today (the
    primary one when several) → its `manager_assignment_id`, also open
    today → that assignment's Employee. Never snapshotted; a former manager
    gets the private 404 at once. Structural only (no department, title,
    role name or Campus).
  - **administrative path**: `hr.leave.manage`, any request of the School
    (no manager, manager absent or separated, escalation). It is the
    fallback; it is not an "override" of a manager decision (a decided
    request is final; an approved one can only be cancelled).
- Withdraw (submitted) and cancel (approved; self-service only before it
  starts, administrative at any time) are not decisions.
- No multi-level, delegation, proxy or acting manager (out of v1). One
  manager per request (the primary assignment's line).
- No fresh MFA on Leave decisions (`NONE`, §56.25); unchanged.

**62.3 OD-8.8: STOP (owner decision), current behaviour pinned.** The
capability check on both paths is the whole-School Gate, and the fresh
re-read (`holdsInSchoolNow`) counts whole-School grants only, so today:
- a whole-School `hr.leave.approve` holder who is the current reporting
  manager decides a report on ANY Campus (no Campus logic) -- YES;
- a Campus-scoped grant (e.g. `principal` at Campus A) confers no Leave
  decision authority on either path, even to the report's reporting manager
  -- NO (fail closed);
- principal status itself never grants anything; the seeded `principal`
  role holds `hr.leave.approve`, which only counts when granted whole-School.
Options for the owner (none built):
- **A — keep (recommended for v1):** Leave decisions need whole-School
  grants; Campus-scoped principals route Leave to a whole-School manager or
  HR.
- **B — relationship-sufficient:** a Campus grant of `hr.leave.approve`
  counts on the manager path only, whenever the actor is the current
  reporting manager, whatever the report's Campus.
- **C — intersection (§42.7):** a Campus grant counts on the manager path
  only when the actor is the reporting manager AND the report's current
  operational Campus set (`CurrentEmployeeAssignments`) lies in the grant's
  Campuses; a Campus-granted cross-Campus manager is refused.
B and C need a relationship-scoped capability path in `CapabilityResolver`
(not the whole-School Gate) and a `CampusScopeCatalogue` decision.

**62.4 Built.**
- **Shared foundation for decisions.** `LeaveRequestService::decide()`, the
  single decision core behind approve / approveAsManager / reject /
  rejectAsManager, runs under the request lock: ownership (manager line or
  private 404) → Employee self-decision → state → `assertMayDecide`:
  `ApprovalDecisionAuthority::requireCurrent` (the path's whole-School
  capability, Leave module, operational School, read fresh) → shared
  `MakerCheckerPolicy` over `participants()` → domain validity (balance,
  years, calendar, attendance conflict) → decision → status → audit with
  `ApprovalProvenance` (scope `reporting_line` or `school`).
- **Participants.** The submitter (`submitted_by_user_id`): the Employee
  through self-service, or the administrator who entered it. A submitter
  never approves or rejects (`LEAVE_PARTICIPANT_DECISION`); withdrawal stays
  theirs. The requester Employee stays excluded by identity (persona-proof:
  the decider's Employee is derived from the User link, service and CHECK).
- **G-4 closed.** The administrative path refuses the actor's OWN
  employment (`LEAVE_SELF_ADMINISTRATION`, via
  `HrSelfAdministrationGuard::ownsEmployment`): explicit allocation,
  adjustment, policy assign and end, submit on behalf, administrative
  withdraw and cancel. Self-service (submit, withdraw, cancel before start)
  stays theirs. The annual allocation run and the year close are
  policy-driven and exempt (recorded).
- **Administrative withdraw/cancel** also re-read authority fresh.
- **Database.** `leave_decisions_participant_guard` (BEFORE INSERT, after
  the Employee-deriving guard): an approved/rejected decision recorded for
  the request's submitter is refused. With `leave_decisions_no_self_decision`
  (Employees derived by trigger) and the existing one-decision indexes,
  conditional transitions, append-only decisions and evidence trigger.
  The manager relationship is application-enforced (the database has no
  actor context; a raw runtime insert with path `manager` and a forged
  decider is the recorded-actor trust boundary of §59.6).
- **Registry.** `leave_requests` is now `shared`
  (`special_rules`; decisions `decide`; no step-up).

**62.5 Races (two processes, contender observed blocked).** Existing:
approve/approve (consume once), last-units, approve vs reject vs withdraw,
calendar, cancel/cancel, year close. New: a reporting change (held) vs the
former manager's approval → private 404, nothing decided; the current
manager's approval (held) vs a reporting change → the change waits and lands
after; history keeps who decided. Lock order unchanged (§23.10): School →
request → HR rows (ActingEmployee, reporting line FOR SHARE) → membership
and grants FOR SHARE (fresh authority) → Leave locks. `setManager` takes
Employees FOR SHARE, then the two assignments FOR UPDATE in id order, and
never a request or membership lock. One pre-existing (HRX.2) edge remains
(62.7): a decision share-locks the subordinate's assignment and then its
manager's, so an inverted or repeated `setManager` on the same pair whose
manager row sorts first can deadlock with it; PostgreSQL aborts one side
(fail safe, no integrity effect).

**62.6 Assignment changes.** Authority follows the requester's primary open
assignment's line only; a second (non-primary) assignment on another Campus
changes nothing (tested); only `ReportingHierarchyService::setManager`
(audited, self-administration-guarded) moves it.

**62.7 Security review** (independent): no HIGH, no MEDIUM.
- **LOW fixed:** the registry names the decision core `decide()`; a test now
  pins that every public method that approves or rejects goes through it,
  that only `recordDecision()` writes a decision, and that
  `trg_leave_decisions_participant_guard` is installed.
- **LOW recorded (pre-existing, HRX.2):** the `setManager` / decision
  lock-order edge in 62.5 -- a deadlock abort (a 500 for one side), never a
  wrong decision. A fix (no-op early return and cycle check before locking
  in `ReportingHierarchyService`, or id-ordered locking in `ReportingLine`)
  is an HR follow-up.
- **LOW accepted:** G-4 is application-enforced (`ownsEmployment`, an
  unlocked read); the ledger and policy-assignment actor columns are
  caller-recorded, so a database rule would sit on the recorded-actor trust
  boundary (§59.6). Self-made allocations or policies from before ORG.8D are
  not re-checked (development data only).
- **LOW accepted:** fresh authority is re-read for decisions and for
  administrative withdraw/cancel; other administrative Leave writes keep the
  Gate floor (a revocation clears the cache; only an in-flight race is
  exposed).

**62.8 Unchanged.** Capabilities and role templates (approve: school_admin,
principal; manage: school_admin, principal, hr_officer; self:
staff_self_service, school_admin); `CampusScopeCatalogue`; MFA (NONE);
Payroll/ORG.8B, Payment corrections/ORG.8C, fee concessions/R1, Finance
roles; HRX-L1..L4 and other HR legal gates (development/UAT only, no
production effect); M-1 (Finance only).

**62.9 DDEV smoke (real HTTP through nginx/php-fpm, `ddev demo-reset --build`
on the candidate tree; logins with the demo personas; local demo data
only).**
- **Deferred ORG.8C smoke (closes §61's evidence gap), 12/12:**
  - the accountant opens a Payment (200) and requests a reversal without a
    code (pending);
  - the old `/corrections` URL is 404;
  - the queue lists the request with no Student fields; principal 403;
  - the accountant cannot approve (403) and withdraws (withdrawn);
  - the School Admin rejects (nothing recorded); approving without a code
    is refused; approving with a fresh code records the reversal;
  - with the School Admin's account linked to a Guardian of the second
    Payment's Student, approve and reject are both refused with the generic
    "must be decided by someone else" text, and nothing is recorded.
- **ORG.8D, 4/4** (Leave configured for the demo teacher through the real
  services first; the demo seeds no Leave types): the HR officer opens
  Leave requests (200) and enters leave for the teacher; the HR officer
  cannot approve it (`LEAVE_PARTICIPANT_DECISION`); the School Admin
  approves it on the administrative path.

**62.10 OD-8.8 decided (owner, 2026-10-22, recorded in ORG.8E).** Option A
adopted: Leave decisions require whole-School authority; a Campus-scoped
Leave grant (e.g. principal at one Campus) never becomes decision
authority. This is exactly the behaviour 62.3 pinned; no code change.

## 63. ORG.8E — High-impact direct action assurance (as built, 2026-10-22)

Owner authorization: ORG.8E. Baseline `origin/main` = `1b331263` (§62).
Owner decisions frozen in the brief:
- **8E-D1** Student lifecycle has no request/approve workflow in v1
  (OD-8.7 CLOSED: no `students.status.request/.approve`).
- **8E-D2** Student Attendance correction stays direct; no approval and no
  time window (OD-8.6 CLOSED).
- **8E-D3** Library fine void stays a direct `library.fines.void` action; no
  librarian void-request workflow (OD-8.5 CLOSED).
- **OD-8.8** CLOSED as Option A (62.10).

**63.1 Inventory and classification.**

| Operation | Entry points | Class |
|---|---|---|
| Student status active ⇄ inactive (`StudentService::changeStatus`) | web, API | HIGH_IMPACT_LIFECYCLE |
| Enrollment withdraw / cancel | web (current + closed-year placement), API | HIGH_IMPACT_LIFECYCLE |
| Enrollment complete; closed-year bulk completion; rollover | web, API, bulk service | INITIAL/ORDINARY (normal end of a placement) |
| Section transfer; Campus transfer (ORG.7F-3) | web, API | TRANSFER (unchanged) |
| Elective (subject) enrollment withdraw / cancel | web, API | NOT_APPLICABLE (not a Student lifecycle) |
| Student Attendance register submission | web, API (admin + teacher) | INITIAL (an existing session/slot is refused, never overwritten) |
| Student Attendance correction (`AttendanceCorrectionService::correct`) | admin web/API, teacher web/API | CORRECTION (no bulk path exists) |
| Library fine assessment (at check-in) | system | SYSTEM_ONLY |
| Library fine void (`LibraryFineService::void`) | web, API | HIGH_IMPACT (financial, error correction only) |

Staff Attendance is untouched (its own closed-reason design, HRX.3).

**63.2 Student lifecycle (8E-D1).**
- **Capabilities and roles unchanged:** `students.manage` /
  `enrollments.manage` (school_admin, principal), used whole-School only.
- **Assurance, in the services** (`StudentService::changeStatus`,
  `StudentEnrollmentService::withdraw/cancel`):
  - a closed reason per action (`StudentLifecycleReason`):
    - deactivate: left_school, transferred_out, completed_studies,
      duplicate_record, entered_in_error;
    - reactivate: returned_to_school, deactivated_in_error;
    - withdraw: left_school, transferred_out, guardian_request;
    - cancel: did_not_join, duplicate_placement, entered_in_error;
    - no `other` and no free text;
  - a `FreshMfaProof` for the actor (required, so no caller skips it);
  - the actor's CURRENT whole-School authority
    (`ApprovalDecisionAuthority::requireCurrent`: capability, module,
    School, read fresh) under the row lock. A Campus grant (e.g.
    principal@Campus) never reaches these: whole-School only, as before;
  - one append-only `student_lifecycle_events` row (action, reason, actor,
    `occurred_at` = the transaction timestamp) and the audit event
    (previous/new status, reasonCode, `freshMfa`; never DOB or contact).
- **Transport:** web needs reason + explicit confirmation + fresh code
  (`FreshMfaRequirement`); the API needs reason + fresh code (no
  confirmation flag: explicit action endpoints).
- **Idempotency:** a no-op status change is refused
  (`STUDENT_STATUS_UNCHANGED`, nothing recorded); a non-active placement
  cannot be withdrawn/cancelled again (existing transition rule).
- **Locks:** status change takes the Student row FOR NO KEY UPDATE
  (rule 101), which conflicts with the FOR SHARE every placement writer
  takes first; placements keep Section → Enrollment ordering.
- **Database:** `student_lifecycle_events` (FORCE RLS, append-only, reason
  CHECK per action, same-School keys; School/User RESTRICT; Student and
  Enrollment cascade -- the event is part of the D7 core record and is
  declared handled by the Student core purge). Trigger
  `student_enrollments_lifecycle_evidence`: an Enrollment becomes
  withdrawn/cancelled only with its event in the same transaction.
  `students.status` has no database evidence rule (test fixtures and other
  modules write it directly; a raw runtime UPDATE is the recorded-actor
  trust boundary, §59.6).

**63.3 Student Attendance correction (8E-D2).**
- Initial entry = a submitted register (refused if the session/slot already
  exists); correction = `AttendanceCorrectionService::correct` on an
  existing record. Only corrections need a reason.
- Reasons (`AttendanceRecordCorrection::REASONS`): entered_in_error,
  late_information, administrative_review -- why the RECORD changed, never
  why a Student was absent (the AttendanceArchitectureGuardTest privacy
  rule: no reason/note/medical/evidence field; the guard now admits exactly
  this closed code); the Staff Attendance set.
- Each correction writes an append-only `attendance_record_corrections` row
  (from, to, reason, who); trigger `attendance_records_correction_evidence`
  refuses a status change without its row. Audit adds `reasonCode`.
- Authority unchanged: Tier 1 `attendance.manage` (Campus-adopted, ORG.7D-1),
  Tier 2 teacher ownership (TCH.4). The reason narrows an authorized edit;
  it grants nothing. No MFA, no approval, no window.

**63.4 Library fine void (8E-D3).**
- A void cancels an erroneous fine's unpaid Charge through FEE's canonical
  source seam (unchanged; a paid or concession-adjusted fine is refused).
  Waivers are Fees concessions and never a void reason. No Charge deletion,
  no journal edit; ORG.8-P1 intact.
- Now: a closed `reason_code` (assessed_in_error, loan_record_corrected,
  returned_before_due, other) plus the existing bounded note (1-255, never
  audited), a `FreshMfaProof`, and current whole-School `library.fines.void`
  (school_admin) under the loan lock. `library_fine_voids_require_reason_code`
  (BEFORE INSERT) refuses a void without a code; voids before ORG.8E keep a
  NULL code. At most once (existing unique), races unchanged.
- E34 unchanged (development/UAT only).

**63.5 Registry and unchanged scope.** The three services are declared
in `ApprovalWorkflowRegistry::DIRECT_ASSURED` (service => capability,
action), NOT in `WORKFLOWS`: `ApprovalWorkflowRegistryTest` lets them use
`ApprovalDecisionAuthority` only, and fails if one uses maker/checker,
participants or approval provenance, or drops the fresh-MFA proof or the
current-authority read.
Unchanged: capabilities, role templates, `CampusScopeCatalogue`, the
registered workflows, M-1, G-3,
OD-8.2..OD-8.4, Payroll (8B), Payment corrections (8C), Leave (8D), Staff
Attendance, legal gates (E34, Attendance/teacher, Student privacy, E21).

**63.6 Security review** (independent): no HIGH, no MEDIUM.
- **LOW fixed:**
  - the fine-void migration's `down()` now refuses while ORG.8E reason codes
    exist (like the other two);
  - web withdraw/cancel look the placement up before spending a fresh code;
  - a mislabelled `DatabaseRoleVerifier` comment;
  - an explicit two-School raw-SQL isolation test for both new tables
    (`LifecycleEvidenceRlsIsolationTest`, rule 28).
- **Recorded trust boundaries** (the database has no actor context; it
  proves evidence exists, never who was authorized, §59.6):
  - `students.status` has no database evidence rule;
  - a withdrawn/cancelled placement INSERTED directly carries no event (the
    rule is on the status UPDATE; ~40 test fixtures insert terminal rows;
    no product path does);
  - the runtime role can insert an event, correction or void row naming any
    acting User (`voided_by_user_id` stays nullable for pre-ORG.8E rows);
  - within one transaction a correction row satisfies a later raw UPDATE of
    the same from/to (`now()` is constant).
  Every application path goes through the services, which check authority,
  reason and the fresh code.
- **Notes:** Attendance correction keeps no fresh-authority re-read or MFA
  (owner decision 8E-D2; the Campus and teacher guards run inside the
  transaction); withdraw/cancel check status and dates before authority
  (error ordering only).

## 64. ORG.8F — Approval programme closure audit (read-only, 2026-10-22)

Owner authorization: ORG.8F, documentation only. Baseline `origin/main` =
`e43b1a94`; latest code `9e10de3d` (exact-SHA CI: 9,357 cases, 9,356
passed, 1 ESI-12 skip). No production code, schema, capability, role,
catalogue or legal gate changed. Runtime-role probes ran in the isolated
test database inside rolled-back transactions; the probe was never
committed. Historical sections (§56 audit, §56.29 "recorded, open") stay
as written; this section supersedes their status labels.

**64.1 Phase verification.** All published, each with a green exact-SHA
CI gate: ORG.8-P1 `db9a654a` (§57, G-1 closed), ORG.8A `f2b04e22` (§58),
ORG.8A-R1 `260a7c4b` (§59, G-7 closed for account-linked Guardians),
ORG.8B `f2242d5e` (§60, G-2 closed), ORG.8C `fa13aef5` (§61, OD-8.1
closed), ORG.8D `1b331263` (§62, G-4 closed, OD-8.8 closed as Option A),
ORG.8E `9e10de3d` + docs `e43b1a94` (§63; OD-8.5/8.6/8.7 closed).
Recorded here for §63, which omitted them: ORG.8E's DDEV smoke passed
15/15 over real HTTP (status change, reactivation, withdrawal, Attendance
correction, fine void, each refused without its reason, confirmation or
code; evidence rows and audit `reasonCode`/`freshMfa` read back), and its
exact-SHA CI is the run above (the first candidate failed two
`HistoricalPlacementClosureUiTest` cases, fixed before publication).

**64.2 Final v1 architecture (confirmed).**
- **Domain-owned workflows.** Each domain owns its request rows, states,
  queue, decision and execution transaction.
- **Small shared toolkit**, used only where proven: `MakerCheckerPolicy`,
  `ApprovalParticipants`, `ApprovalDecisionAuthority` (fresh, whole-School,
  module and School read via `CapabilityResolver::holdsInSchoolNow`),
  `ApprovalProvenance`, `FreshMfaProof` (minted only by
  `FreshMfaRequirement`), `ApprovalWorkflowRegistry`. Stateless; there is
  no `approval_requests` table (`ApprovalWorkflowRegistryTest`).
- **DIRECT_WITH_ASSURANCE** (`ApprovalWorkflowRegistry::DIRECT_ASSURED`):
  Student status, Enrollment withdraw/cancel, Library fine void. They may
  use `ApprovalDecisionAuthority` only.
- **No generic workflow engine** and no tenant-defined approval topology,
  thresholds, delegation or multi-level approval; none is needed for v1.

**64.3 Workflow inventory.**

| Workflow | Requester | Decider | Executor | Scope | Maker/checker | Related party | Fresh MFA | Reason | DB backstop | Legal gate | v1 |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Fee concessions | `finance.fee_concessions.request` (school_admin, accountant) | `.approve` (school_admin) | approval posts the adjustment, same txn | whole-School (CAMPUS_INCOMPATIBLE) | shared | Guardian of the Student refused (service + `fee_concessions_beneficiary_guardian_guard`) | approve, revoke, cancel-adjustment | none on decisions (owner decision M); category closed | `fee_concessions_sod_check`, transition/finality trigger | E32 | COMPLETE (G-3 LOW) |
| Payment corrections | `finance.payment_corrections.request` (school_admin, accountant) | `.approve` (school_admin) | `executeApproved`, same txn | whole-School | shared | Guardian of any affected Student refused (service + DB) | approve | free text 5-500 (DB) | `_sod_check`, `_withdrawer_check`, `_guard`, `_approval_guard`, `_beneficiary_guardian_guard` | E30 (receipts) | COMPLETE (M-1) |
| Communications approval | `communications.announce` | `communications.approve` (school_admin, principal) | publish re-checks the fingerprint | whole-School | domain, service only | none | emergency publish only | reject: free text required | status/fingerprint checks; no SoD CHECK (G-10) | general | COMPLETE |
| Marks corrections | `examinations.marks.correction.request` | `.approve` (school_admin, principal) | approval applies, same txn | whole-School | domain (Marks isolation) | none | approve, reject, lock | closed `reason_code` | `student_mark_corrections_maker_checker_check`, guard triggers | E36/E37 (refused in code) | COMPLETE, production-refused |
| Leave | `hr.leave.self` / `.manage` | `hr.leave.approve` + current reporting line, or `.manage` | approval writes consumption, same txn | whole-School only (OD-8.8 A) | shared | requester Employee and submitter refused | none (by design) | closed rejection/closing codes | `leave_decisions_no_self_decision`, `trg_leave_decisions_participant_guard` | HRX-L1, HRX-L3 | COMPLETE (G-9 LOW) |
| Payroll runs | `payroll.runs.prepare` (school_admin, payroll_officer) | `.approve` (school_admin) | `.post` separate (school_admin) | whole-School | shared (creator, calculators, adjustment authors) | none (OD-8.4) | approve, post, reverse | override/delta free text; reverse optional | `payroll_runs_sod_check`, `payroll_runs_participant_approval_guard`, approval freeze | HRX-L4, E45 | COMPLETE (OD-8.4 production policy) |
| Erasure cases | data-subject request | operator CLI | executing -> completed | School / platform | n/a | n/a | CLI confirmation | closed decision reason | `erasure_cases_shape_check` | E21 | COMPLETE (outside ORG.8 business scope) |

Direct-assured: Student status (`students.manage`), Enrollment
withdraw/cancel (`enrollments.manage`), both school_admin and principal,
whole-School grants only, fresh MFA, closed reason, lifecycle event; Student
Attendance correction (`attendance.manage` Campus-adopted / `attendance.teacher`
ownership), closed neutral reason and correction row, no MFA; Library fine
void (`library.fines.void`, school_admin), closed `reason_code` + note,
fresh MFA, E34.

**64.4 Capabilities and Campus.** Holders (seeder): concession and
correction `.approve`, `payroll.runs.approve/.post/.reverse`,
`library.fines.void`, `finance.ledger.reverse` = school_admin only;
`hr.leave.approve`, `communications.approve`, marks correction keys,
`students.manage`, `enrollments.manage`, `attendance.manage` = school_admin +
principal; `finance.charges.manage`, `finance.ledger.post` = school_admin +
accountant. Platform roles hold only `platform.*` keys, structurally
(`trg_role_capabilities_scope`). No approval key is Campus-adopted
(`ApprovalWorkflowRegistryTest`); `ApprovalDecisionAuthority` counts only
whole-School grants, so principal@Campus decides no Leave and no lifecycle
action, and a cross-Campus manager decides Leave only with a whole-School
`hr.leave.approve`. Attendance keeps its ORG.7D-1 Campus/teacher ownership;
`CampusScopeCatalogue` was not changed by ORG.8. `finance.payment_corrections.*`
is not in CAMPUS_INCOMPATIBLE (inert: both holders are already
incompatible; §61.9).

**64.5 Owner-decision register (one state each).** OD-8.1 CLOSED (8C).
OD-8.2 DEFERRED (future Finance hardening; needs a structural
`posted_by_user_id` first). OD-8.3 TECHNICAL_FOLLOW_UP_REQUIRED (G-8).
OD-8.4 PRODUCTION_POLICY_PREREQUISITE. OD-8.5, OD-8.6, OD-8.7 CLOSED (8E).
OD-8.8 CLOSED (Option A). OD-8.9 PRODUCTION_POLICY_PREREQUISITE. M-1
PRODUCTION_POLICY_PREREQUISITE. Current registers now say so (matrix §6,
AUTHORIZATION.md, module docs).

**64.6 G-3 (concession revoke / adjustment cancel).** Initial decisions
(approve/reject) use the shared foundation. After approval: revoke
(standing concessions; stops future application, no ledger effect) and
adjustment cancellation (reversal through Fees' own `reverseById`, accepted
by the ORG.8-P1 deferred guard) need `.approve` (school_admin only) and a
fresh code, but read authority from the cache, have no maker/checker, no
provenance and only an optional note (cancel). Both move value towards the
School, not a beneficiary. **Classification: A, LOW acceptable v1
residual**, with a future Finance hardening option (current-authority
re-read, closed reason; SoD stays an owner decision).

**64.7 M-1 (related-party completeness).** Scope frozen: fee concession
decisions and Payment correction decisions (and, if OD-8.3 adopts the
recommendation, Charge cancel/void). Protection: an account link of the
decider (active or revoked) to a Guardian currently related to the affected
Student refuses the decision, in service and database. Limit: an
undisclosed relationship (a staff parent who never links) cannot be
inferred, and must not be inferred from PII (names, phones, addresses).
**PRODUCTION_POLICY_PREREQUISITE.** The eventual decision is the governed
source of truth: (a) a mandatory staff related-party declaration, recorded
as a structural link reviewed by a second person (recommended), or (b)
mandatory Guardian account linkage for staff parents, or (c) a formal
related-party register maintained by HR. Any option feeds the existing
structural check; none needs a new framework.

**64.8 OD-8.2 (manual ledger).** Post: `finance.ledger.post`
(school_admin, accountant), fresh MFA, required description, audited.
Reverse: `finance.ledger.reverse` (school_admin), fresh MFA, optional text,
audited; ledger-native entries only (ORG.8-P1). The poster is NOT stored on
`journal_entries` (audit event only); the same actor may post and reverse.
Maker/checker would need the column first. **DEFERRED, LOW**: fresh MFA on
both sides, the subledger guard and the audit trail bound v1.

**64.9 OD-8.3 (Charge cancel / void) → new G-8, MEDIUM.**
`ChargeAdministrationService::cancel`, fee-assessment item void and
late-fee void use `finance.charges.manage` (school_admin, accountant;
CAMPUS_INCOMPATIBLE, whole-School) through the cached Gate, with no fresh
MFA, an optional free-text reason (not DB-enforced) and no canceller column
(audit only). Accounting is sound: Fees reverses its own recognition entry
(ORG.8-P1 accepts it) and the database refuses cancelling a Charge with
allocations, late fees, adjustments or a fee-run origin through this path.
**Gap:** there is no Guardian-beneficiary refusal, so an account-linked
staff Guardian can cancel or void their own child's unpaid Charge alone;
the concession and correction paths refuse exactly this. Recommended v1
contract: **DIRECT_WITH_ASSURANCE**, not a workflow: a closed code-owned
reason, fresh MFA, `ApprovalDecisionAuthority::requireCurrent` under the
Charge lock, and the existing Guardian-beneficiary check. Needs the owner
decision OD-8.3, then a narrow slice. Admissions accept/reject is separated
from OD-8.3: Campus-scoped `admissions.manage`, no money effect, optional
unaudited note, no status CHECK or decider column — LOW (G-10).

**64.10 OD-8.4 (Payroll).** (A) The approver may post: post/reverse need
`payroll.runs.post/.reverse` (school_admin) and fresh MFA but no
participant check and no fresh-authority read; reverse takes optional free
text. (B) Own pay: nobody sets their own compensation, overrides or deltas
(rule 95), and adjustment authors cannot approve, but nothing refuses
approving or posting a run that contains one's own result.
**PRODUCTION_POLICY_PREREQUISITE** (Payroll production is already gated by
HRX-L4/E45). Recommended v1 policy: approver != poster, refuse approval of a
run with the approver's own result, closed reverse reason.

**64.11 OD-8.9 / G-5 (Group governance).** Create, rename, archive, add or
remove School, grant and revoke Group roles: root-only
(`platform.school_groups.manage`, `.school_group_grants.manage`), audited
on the platform ledger, but no fresh MFA, confirmation or reason, and the
self-grant refusal is an unaudited 422 (database CHECK backs it). A Group
grant confers School elevation, and every comparable platform mutation
already steps up. Platform roles hold no tenant business capability.
**PRODUCTION_POLICY_PREREQUISITE** (platform hygiene), not an ORG.8
technical blocker.

**64.12 G-6 — runtime-database reproduction and classification.** As
`school_os_app`, under the School's RLS context:
- `students.status` active -> inactive with no lifecycle event: ACCEPTED;
  `students.status` = an arbitrary value: ACCEPTED (no CHECK).
- INSERT a `withdrawn` placement with no event: ACCEPTED; INSERT or UPDATE
  `student_enrollments.status` to an arbitrary value: ACCEPTED (no CHECK).
- UPDATE active -> withdrawn / cancelled with no event, or with the wrong
  action's event: REFUSED (`student_enrollments_lifecycle_evidence`).
- An event naming an unrelated User, then withdraw: ACCEPTED (actor
  identity is the caller's claim).
- UPDATE/DELETE of `student_lifecycle_events`: REFUSED (append-only).
- A `student_deactivated` event with no status change: ACCEPTED.
- Reopening an ended placement: REFUSED (retention eligibility guard).
- active -> completed / transferred with no event: ACCEPTED (ordinary ends,
  by design).

Application writers: only `StudentService` writes `students.status` and only
`StudentEnrollmentService` writes terminal placement states; the only
placement INSERT writes `active`. So raw SQL reaches states no canonical
service can create (an evidence-less inactive Student, unknown status
values, an evidence-less terminal placement, a terminal placement with no
end date). PostgreSQL can require the evidence row and the status domains
without knowing who acted — the enrollment trigger already does. Only the
acting User's identity (any User id is accepted in the actor column) is a
genuine actor-context boundary. ORG.8E's reason for omitting the
`students.status` rule (test fixtures write it directly) is a fixture
convenience, not an architectural boundary. **Classification: MEDIUM
integrity gap** (structural invariants enforceable without an actor;
rule 11's Student-status audit can be skipped by a direct write; no
canonical path is affected). Not exploitable through the application;
it is a defence-in-depth gap of the kind this repository otherwise closes
in the database (rule 25). **ORG.8 is not cleanly technically closed until
the narrow follow-up (ORG.8G) lands**: status-domain CHECKs on both tables,
an evidence trigger for `students.status` changes (the enrollment pattern),
evidence or refusal for terminal INSERTs, and a terminal state requiring
`ends_on`. Fixture impact (to scope it): ~26 files update
`students.status` directly, ~83 sites insert inactive Students, ~36 insert
terminal placements.

**64.13 Student temporal state (G-6b, LOW).** Service rule: `ends_on` >=
`starts_on` only (`StudentEnrollmentService`; the DB CHECK
`student_enrollments_date_range_check` agrees). Reproduced: the canonical
withdraw accepts an `ends_on` far after the academic year end (2099) and one
backdated to the start. Raw SQL additionally accepts a completed placement
with `ends_on` after the year end and a terminal placement with a NULL
`ends_on`, and an INSERT of a placement starting after its year ends. The
retention guard already refuses an end on a non-terminal row, a changed
start and reopening. Effects: the retention exit date is the later of
`ends_on` and the recorded date, so a backdated end never shortens retention
but a far-future one defers it (over-retention); date-based fee targeting
and manual Charge Campus attribution treat the placement as running until
`ends_on`. **LOW (data quality)**; bound `ends_on` to the placement's year
(optionally inside ORG.8G).

**64.14 G-9 `setManager` deadlock (LOW).** A manager-path Leave decision
locks the subordinate's assignments FOR SHARE then the manager assignment;
`setManager` locks the two assignments FOR UPDATE in id order. A repeated
or inverted `setManager` on exactly the pair a pending decision is reading
can form a cycle; PostgreSQL aborts one transaction (40P01). Everything,
including audit and outbox, rolls back; the user sees a generic 500; the
idempotency key is released so a retry is safe; no state can be corrupted.
HR post-v1 hardening (no-op early return or consistent lock order; optional
409 translation). Not an ORG.8 blocker.

**64.15 Raw-SQL trust-boundary register.**

| Domain | Raw SQL can | Application can | DB backstop | Acceptable? | Severity / follow-up |
|---|---|---|---|---|---|
| Recorded actor columns (all workflows) | name any User as requester/decider/actor | no | maker/checker CHECKs compare the recorded ids | yes: the DB has no trusted actor context | LOW, accepted |
| Guardian self-administration (8A-R1 Rule B) | change a relationship of one's own Guardian | no | none (relationship rows record no actor) | yes: needs the acting User | LOW, accepted |
| Payroll calculator provenance (8B) | record a calculation under any actor | no | append-only calculations; participant guard on the recorded ids | yes | LOW, accepted |
| `PaymentCorrectionService` core (8C) | call the internal core shape directly | only via `executeApproved` | request guards; approval guard binds count | yes | LOW, accepted |
| Leave relationship authority (8D) | decide as a non-manager | no | self/submitter guards only | yes: reporting authority needs the acting User | LOW, accepted |
| Student lifecycle (8E) | change `students.status` with no event; any status value; INSERT terminal placement with no event | no | enrollment UPDATE evidence trigger only | **no**: structural, enforceable without actor | **MEDIUM G-6 → ORG.8G** |
| Attendance correction (8E) | reuse one correction row for a second raw UPDATE in the same transaction | no | evidence trigger, shape CHECK, append-only | yes: same-transaction `now()` | LOW, accepted |
| Library fine void (8E) | name any voider | no | reason trigger, at most once | yes | LOW, accepted |
| Communications approval | decider = requester | no (service) | none | **no**: both columns exist; a CHECK is actor-free | LOW G-10, future |
| Charge cancel | (application path, not raw) | yes, by a related party | none | — | MEDIUM G-8 → OD-8.3 / ORG.8G |

**64.16 Database privilege principle.** Prefer database enforcement for any
structural invariant PostgreSQL can check without the human actor; keep
only actor-identity checks in the application. Violations found: G-6
(Student status domain, evidence, terminal shape) and G-10 (Communications
SoD CHECK, Admissions status domain). No privilege change was made.

**64.17 Fresh authority.** Uncached (`requireCurrent`): concession
approve/reject, Payment correction approve/reject, Payroll approve, Leave
decisions and administrative Leave writes, Student status,
Enrollment withdraw/cancel, Library fine void. Still cached: ledger post
and reverse, period close, Charge cancel/voids (G-8), concession revoke and
adjustment cancel (G-3), Payroll post/reverse (OD-8.4), Communications
approve, Marks decisions, Attendance correction (Campus/teacher guards in
the transaction), Admissions, Group governance (G-5). LOW except where
listed under G-8.

**64.18 Fresh MFA.** Required: concession approve, revoke,
adjustment cancel; Payment correction approve; Payroll approve, post,
reverse, compensation; Marks approve, reject, lock; ledger post, reverse;
period close; Student status, Enrollment withdraw/cancel; Library fine
void; emergency publish; manual payment record. No step-up: concession
reject/withdraw, correction request/reject/withdraw, Leave decisions,
Communications approve, Attendance correction (owner decision 8E-D2),
Charge cancel/voids (G-8), Admissions, Group governance (G-5).
Inconsistencies needing owner decisions: G-8 (OD-8.3), G-5 (OD-8.9).

**64.19 Reasons.** Structured code-owned: Student lifecycle, Attendance
correction, Library fine void (+ bounded note), Leave rejection/closing,
Marks corrections, erasure. Mandatory free text: Payment correction
(5-500, DB), Communications rejection, Payroll overrides/deltas, ledger
post description. Optional note: ledger reverse, Payroll reverse,
adjustment cancel, Charge cancel/voids, Admissions accept/reject. None:
concession decisions (owner decision M), concession revoke, Group
governance. Remaining high-risk direct actions without an adequate reason:
Charge cancel/voids (G-8); lower: ledger and Payroll reverse (OD-8.2,
OD-8.4), Group governance (G-5).

**64.20 Registry completeness.** `WORKFLOWS`: fee_concessions,
payment_corrections, leave_requests, payroll_runs (shared);
communication_approvals, student_mark_corrections, erasure_cases (domain).
`DIRECT_ASSURED`: the three ORG.8E services. `NOT_WORKFLOWS`: four
preview/submission columns. The test catches every decision-shaped actor
column and every request/approve-shaped capability; it cannot see
single-actor decisions without an actor column (admission decisions,
employee certification verification). Those are direct actions, not
workflows, and are recorded here. No direct-assured service uses
`MakerCheckerPolicy`, `ApprovalProvenance` or `ApprovalParticipants`.

**64.21 Concurrency.** Real two-process tests: concessions (decision
races, concession vs application lock), Payment corrections (stale count
binding under the Payment lock), Payroll (run-row locks; calculate vs
approve), Leave (decision races, manager change, G-9 noted), Student
lifecycle (double deactivation, withdraw vs transfer both orders, withdraw
vs cancel), Attendance (expected-status compare-and-set), fine void (at
most once). No HIGH/MEDIUM race missing.

**64.22 UAT.** `docs/product/APPROVAL-AUTHORITY-UAT.md` now has concrete
cases for every built flow: FC1-FC8 (concessions, Rules A/B), PR1-PR7
(Payroll), PC1-PC14, LV1-LV11, DA1-DA13. DDEV evidence: ORG.8C 12/12,
ORG.8D 4/4 (§62.9), ORG.8E 15/15 (§64.1). UAT-ready for local/UAT use;
production blocked as in 64.23.

**64.23 Production gates (unchanged).** The authoritative register is ADR
0058 §6 (E01-E47). For ORG.8: E30 (receipts/corrections) and E31 (late
fees), E32 (concessions/RTE), E34 (fine void), E21, E02/E15, E16, O1 block
production with no code refusal; E33/TCH-L1 is determined with conditions
(production teacher grants after re-verification); E36/E37 refused in code
outside local/testing, E42 blocks design; HRX-L1..L4 and E45 gate Leave
and Payroll money/statutory effects; E46 refused in code; E47 owner call.
M-1, OD-8.4, OD-8.9 are owner production prerequisites. Recorded drift for
the owner's gate register (not changed here): ADR 0058 §6's E16 row still
names the 2026-10-11 decision (the current one is OWNER-0O-E16-2026-10-16,
valid through 2026-11-12); §53.15 groups E42 with "refused in code" and
says E45 makes statutory payroll unreachable (the register: E42 blocks
design; E45 only the ESI disability branch); ADR 0058 §7 rows on manual
payment corrections and subledger reversals are superseded by ORG.8C and
ORG.8-P1.

**64.24 LC-5..LC-10** stay paused; none depends on ORG.8 closure (§56.33).
ORG.8E delivered parts of LC-8 STU-14 (Student status confirmation) and
LC-6 ATT-06 (correction reason); LC-5 "undo deactivation" now goes through
the reactivation assurance.

**64.25 ORG.9 handoff (not started).** Inputs, to be received only:
`Menu_Permissions_Master.xlsx` (§31.8, owner-held), the reference ERP and
HR MIS screenshots, and the candidates already recorded (§31.8, §53.16,
§56.33): offline-payment approval, bank reconciliation, voucher and petty
cash approvals, bulk refunds, report-card publish controls, transfer
certificates, bulk Campus transfer, staff Attendance modification
approval, approval ageing. Each needs its own contract against this
section's model (domain-owned workflow or DIRECT_WITH_ASSURANCE).

**64.26 Findings.** HIGH: none. MEDIUM: G-6 (structural Student
invariants), G-8 (Charge cancel/void related party and assurance). LOW:
G-3, G-5, G-6b, G-9, G-10, OD-8.2, fresh-authority cache on 64.17's list,
the stale `LeaveRequestService` docblock on OD-8.8 (code comment, left for
the next code slice), M-1 (production policy).

**64.27 Verdict.** ORG.8 is **not yet technically closed**: two MEDIUM
items remain. Required follow-up, one narrow slice (ORG.8G, owner
authorization): G-6 structural Student invariants (no decision needed) and,
once the owner decides OD-8.3, G-8 Charge cancel/void assurance. ORG.9 waits
for that slice and the owner's approval.
