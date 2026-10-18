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
