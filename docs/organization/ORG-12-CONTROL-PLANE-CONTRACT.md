# ORG.12 — Organization Control Plane contract

Status: **ORG.12A implemented (2026-10-28)** — contract, code-owned setup
registry, readiness contracts and providers. **ORG.12B implemented
(2026-10-28)** — the Setup & Readiness Center (§14). Decision record:
ADR 0080 (§7 for ORG.12B).
Reference evidence: `ORG-12-REFERENCE-CAPABILITY-MAP.md` (515-screen
Organisation pack, 38 families).

ORG.12 is an **additive expansion**. The previous Organization programme's
local closure (ORG.LOCAL-CLOSE, ADR 0076 §82) stays valid. ORG.12 reopens
neither School tenancy, the Campus definition nor the Group governance
boundary.

## 1. Objective

Give School administrators one coherent place to understand how the ERP is
configured — profile, Campuses, academic year and structure, people
records, attendance, finance, admissions, operations, communications,
portal, compliance and integrations — and what still needs attention.

The Control Plane **observes and links**. It never owns, copies or writes
another module's configuration.

## 2. Ownership

| The Control Plane owns | The Control Plane never owns |
|---|---|
| the setup catalogue (`OrganizationSetupRegistry`) | Academic years, grades, sections, subjects |
| setup navigation metadata (route names per area) | Students, enrollments, Guardians |
| readiness aggregation (`OrganizationSetupReadService`) | Staff employment, leave configuration |
| (12B) the School/Campus setup overview | Attendance registers, Charges, fee structures |
| (12D) a future Network overview — not built | Admissions applications, Library, Transport, Inventory stock |
| | Announcements, events, payment-gateway credentials, device events |

## 3. Tenancy (frozen)

- **School is the only tenant.** No `tenant_id`, `organization_id` or
  `organisation_id`; no `organizations`/`tenants` table above School.
- **Campus is the canonical Branch.** No Branch table.
- **School Groups / Networks are governance only**, never tenant containers.
- Readiness is evaluated for **one School per call**, inside
  `TenantContext::withSchool()`, with every query scoped to that School.
  ORG.12A aggregates nothing across Schools.

Supported customer shapes, all on this model: single School (one Campus);
multi-Campus School; multi-School operator (Group → Schools); franchise
network (Group → owned/franchise/managed Schools); multi-Campus franchise
School. Ownership model, franchise status, region and zone (ORG.12D) are
descriptive Group attributes, never boundaries.

## 4. Security (frozen)

Role ≠ scope. Menu ≠ permission. Module entitlement ≠ permission. Platform
authority ≠ School authority. Group authority ≠ School business-data
authority. Effective authorization keeps the established order:

```
platform/environment policy → legal availability → School lifecycle
→ module entitlement → role capability → assignment scope
→ ownership/self/recipient → request assurance
```

Readiness never enters this chain: a setup status, a dependency or a
"configured" flag is never authority.

## 5. Registry

`App\Support\Setup\OrganizationSetupRegistry::DEFINITIONS` is a code-owned
constant. There is no table, setting, request input or runtime registration
path; a change is a reviewed code change. Each definition
(`SetupDefinition`) carries:

| Field | Meaning |
|---|---|
| `key` | stable `<area>.<subject>`; never renamed or reused |
| `title`, `description` | plain language for School staff |
| `owner` | the `ModuleCatalogue` key that owns the configuration |
| `category` | `SetupCategory` (closed vocabulary, §5.1) |
| `scope` | `SetupScopeLevel` (closed vocabulary, §5.2) |
| `entitlement` | optional modules that must all be entitled; `[]` = core |
| `route` | the owning domain's configuration page (a link, never authority) |
| `view` | School capabilities required besides the setup floor (§9) |
| `manage` | capabilities the owning page itself checks (informational) |
| `dependsOn` | setup keys that should be configured first (§8) |
| `order` | explicit, unique; every dependency has a lower order |
| `reference` | `RF-nn` families of the reference map |
| core / optional | derived: core exactly when `entitlement` is `[]` and the owner module is core |
| readiness provider | the `SetupReadinessProvider` registered for the key (§7) |

### 5.1 Categories

`ORGANIZATION`, `ACADEMIC`, `PEOPLE`, `OPERATIONS`, `FINANCE`,
`COMMUNICATIONS`, `INTEGRATIONS`, `PORTAL`, `COMPLIANCE`. No
tenant-defined category.

### 5.2 Scope levels

`SCHOOL`, `CAMPUS`, `SCHOOL_AND_CAMPUS`, and the reserved
`NETWORK_FUTURE` / `PLATFORM_FUTURE`. The registry refuses any definition
using a reserved level until a later checkpoint is authorized. In ORG.12A
every provider answers at School level; Campus-specific readiness is
ORG.12B.

### 5.3 Initial catalogue (21 areas)

| Order | Key | Category | Core | Depends on |
|---|---|---|---|---|
| 10 | `organization.school_profile` | ORGANIZATION | core | — |
| 20 | `organization.campuses` | ORGANIZATION | core | — |
| 30 | `academic.academic_year` | ACADEMIC | core | — |
| 40 | `academic.structure` | ACADEMIC | core | academic year, Campuses |
| 50 | `academic.timetable` | ACADEMIC | optional (`timetable`) | structure |
| 60 | `students.student_records` | PEOPLE | core | structure |
| 70 | `students.guardian_identity` | PEOPLE | core | Student records |
| 80 | `staff.staff_records` | PEOPLE | core | — |
| 90 | `staff.leave` | PEOPLE | optional (`leave`) | Staff records |
| 100 | `attendance.student_attendance` | OPERATIONS | optional (`attendance`) | Student records, timetable |
| 110 | `attendance.staff_attendance` | OPERATIONS | optional (`staff_attendance`) | Staff leave |
| 120 | `finance.fees` | FINANCE | optional (`finance`, `fees`) | structure |
| 130 | `admissions.admissions` | PEOPLE | optional (`admissions`) | structure |
| 140 | `transport.transport` | OPERATIONS | optional (`transport`) | Campuses, Staff records |
| 150 | `library.library` | OPERATIONS | optional (`library`) | Campuses |
| 160 | `inventory.inventory` | OPERATIONS | optional (`inventory`) | — |
| 170 | `communications.communications` | COMMUNICATIONS | optional (`communications`) | — |
| 180 | `communications.events` | COMMUNICATIONS | optional (`communications`) | Communications |
| 190 | `portal.guardian_portal` | PORTAL | optional (`guardian_portal`) | Guardians |
| 200 | `compliance.audit` | COMPLIANCE | core | — |
| 210 | `integrations.integrations` | INTEGRATIONS | optional (`integrations`) | — |

Timetable and Staff leave are added beyond the minimum list because the
module catalogue already makes Student Attendance depend on Timetable and
Staff Attendance on Leave.

## 6. Readiness statuses

| Status | Meaning |
|---|---|
| `NOT_APPLICABLE` | the area's optional module is not entitled; decided by the read service only, never by a provider; the area is never evaluated |
| `NOT_CONFIGURED` | none of the area's checks pass |
| `PARTIALLY_CONFIGURED` | some, not all, checks pass |
| `CONFIGURED` | every check passes, or the area needs no School setup |
| `BLOCKED` | a dependency is unsatisfied and the area is incomplete or has no setup of its own; or an environment/legal gate keeps it closed |
| `ATTENTION_REQUIRED` | configured, but a current condition needs review (the active year has ended, a dependency regressed, every Campus is inactive) |

There is **no production-ready status**. Configuration readiness and
production readiness are separate concepts.

A result carries: key, status, short code-owned explanation, optional
completed/total check counts, dependency blockers (setup **keys** only),
and the report's evaluation time — plus the definition's descriptive
fields.

## 7. Readiness providers

`SetupReadinessProvider` (`keys()`, `evaluate($key, $school)`), one per key,
implemented in the module that owns the configuration, wired in
`PlatformServiceProvider` (scoped). Providers are **read-only and
authorization-neutral**, run inside the School's tenant context, scope every
query to the School, and use bounded EXISTS / aggregate queries — never one
query per Student, Employee or record. Readiness logic stays code-owned:
no JSON predicates, stored SQL, expression language, workflow DSL or
School-editable rule.

## 8. Dependencies

Dependencies are informational setup guidance, **never authorization**.
Evaluation is one deterministic pass in catalogue order. A dependency is
satisfied by `CONFIGURED`, `ATTENTION_REQUIRED` or `NOT_APPLICABLE` (an
unentitled module never blocks its dependents). With an unsatisfied
dependency, an incomplete area — or one with no setup of its own — is
`BLOCKED`; an area configured by its own checks is `ATTENTION_REQUIRED`.
The registry refuses unknown, self, repeated and circular dependencies
and any dependency not ordered first; cycle detection is linear in the
catalogue size.

## 9. Authorization and visibility

- **Active School only** (rule 86): a suspended, provisioning or closed
  School is refused, even to its administrator.
- **Floor:** `school.profile.view` in the School, through an active
  membership — the capability the existing School setup progress already
  uses (ADR 0070 §9.6). Holders today: School Admin and Principal. No new
  capability is introduced.
- **Per area:** an applicable area is shown only to a viewer holding all of
  its `view` capabilities. `NOT_APPLICABLE` areas are shown to any floor
  holder (which optional modules are entitled is School configuration).
- **No Campus-adopted key** is ever a `view` requirement: readiness is a
  whole-School view and is not a declared whole-School hub, so
  `CampusScopeCatalogue` is unchanged (rule 101). Where a domain's only read
  keys are Campus-adopted (Student records, Staff records, Student
  attendance, Admissions) the floor alone governs; those answers are EXISTS
  facts, never a record or a count of people. Each such area names those
  keys (`adoptedRead`), and a guard refuses any system role that holds the
  floor without them.
- **Platform:** a platform role, the Platform Manager, the Platform Auditor
  and an elevation hold no School capability, so they cannot evaluate a
  School's readiness. The existing platform Registry readiness (ADR 0076
  §9.3: administrator, Campus, active year, domain) is unchanged and is
  separate.
- **Groups:** a Group grant confers no School capability; ORG.12A has no
  cross-School readiness. A Group/Network readiness summary is ORG.12D and
  needs its own contract under ADR 0048's one-School-at-a-time rule.

## 10. Privacy

Readiness returns facts ("1 active Campus", "Active Students are on record
and placed"), never Student, staff or Guardian names, account emails,
phone numbers, financial amounts, record ids, credentials or a count of
people (ADR 0040). Response shape is fixed and tested.

## 11. Performance

Measured on ORG.12A (isolated test database, all modules entitled, every
provider on its longest path): **21 definitions, 51 queries per full
evaluation** (constant in the number of Students/Guardians — no N+1),
**~31 ms**. Slowest provider: `academic.structure` (7 queries, ~3.9 ms).
Budget pinned at **60 queries** (`OrganizationSetupReadServiceTest`).
Readiness is never cached; the capability and entitlement readers it calls
keep their existing tenant-namespaced 60 s caches.

## 12. Domain boundaries and guards

`OrganizationSetupArchitectureTest` and
`OrganizationSetupRegistryValidationTest` pin: the reviewed key set; a
const catalogue with no runtime registration and no SQL; routes,
capabilities and modules consistent with their catalogues; no
Campus-adopted view key; one provider per key, living in its owning
module, read-only, reading only its own module's models (Students data
only through Students); no setup-state table, parallel tenant column or
Branch/Organization/Tenant entity; no navigation coupling.

## 13. Future checkpoints (not started)

| Checkpoint | Scope | Boundary |
|---|---|---|
| **ORG.12B** Setup & Readiness Center — **IMPLEMENTED** (§14) | checklist, category grouping, progress, next action, blockers, entitlement awareness, deep links | UI over this read service; replaced the 5-flag School setup progress. Campus-specific readiness deferred (§14.6) |
| **ORG.12C** Rich School & Campus profiles | legal identity, registration/tax/affiliation references, contact, website, social, timezone, currency, branding; Campus code, name, address, phone, email, coordinates, service profile (day school, early years, boarding, meals, transport), branding override | fields audited first; service profile is descriptive, never entitlement, capability or authority |
| **ORG.12D** Network / franchise governance | Group/Network, region, zone, owned/franchise/managed, internal code, operating status; governance readiness; safe aggregate reporting | never a tenant boundary; no implicit School business-data access |
| **ORG.12E** Role, access & sensitive-data administration | read-only role-template, capability, module, scope, sensitive-flag, assurance and effective-user views; sensitive-field projection | no tenant role or capability creation |
| **ORG.12F** Navigation & branding presentation | ordering, grouping, optional visibility, labels, icons, banners, School branding, Portal presentation | effective visibility = entitlement AND authorization AND scope AND presentation preference |
| **ORG.12G** Integration & device readiness hub | email, SMS, payment gateway, biometric, RFID, VTS/GPS, webhooks, AI Gateway: family, owner, School/Campus, configured, enabled, healthy, last safe health event | no credential or secret ever in Organization |
| **ORG.12H** Reports & reference re-audit | setup-completion and configuration-gap reports, safe Network readiness summaries, final classification against the 515-screen pack | Network summaries one School at a time |

Each checkpoint needs explicit owner authorization. Two future audits reuse
the reference map: **ORG.REAUDIT-1** (after SIS, HR, Attendance, Admissions,
Finance, Portal and Integrations mature) and **ORG.REAUDIT-FINAL** (before
final product / staging acceptance).

## 14. Setup & Readiness Center (ORG.12B)

**14.1 Where.** Organization → School setup → **Setup & readiness**
(`/app/school-setup/readiness`). Entry: `school.profile.view` in an active
School through an active membership (School Admin, Principal). Teachers,
Campus-only grants, platform operators and Group Admins are refused.

**14.2 Page.** Four regions, all from the server:
1. **Overview** — School name, overall state, Core setup progress, Enabled
   modules progress, counts of areas needing attention, blocked areas and
   optional modules not enabled.
2. **Next actions** — up to three areas the viewer can configure.
3. **Setup areas** — the applicable areas grouped by category
   (Organization, Academics, People, Operations, Finance, Communications,
   Integrations, Portal, Audit & compliance); categories still needing work
   open by default.
4. **Optional modules not enabled** — listed apart, never counted.

Each card shows title, description, owning module, scope badge (School /
Campus / School & Campus — what the setup describes, never the viewer's
authority), optional-module badge, status (icon + words, never colour
alone), explanation, check counts, prerequisites and its action.

**14.3 Status words.** Configured · Partially configured · Not configured ·
Blocked · Needs attention · Not enabled. Machine keys are not shown.

**14.4 Progress and next actions.** ADR 0080 §7.4 and §7.6. Nothing is
persisted; a refresh shows current state.

**14.5 Actions.** ADR 0080 §7.5: Configure / View / none, resolved on the
server from the capabilities the destination checks (`open`, `manage`).
There is no "save all" form and no configuration on this page.

**14.6 Known limitations.**
- School-level summary only; per-Campus readiness needs providers that take
  a Campus (ORG.12C or later).
- Progress covers the areas the viewer can see; a Principal sees fewer
  areas than a School Admin where an area needs a key they lack.
- Whole-School link resolution is conservative: a viewer whose only domain
  access is Campus-scoped gets no link here (the module's own navigation
  still works).
- No route gap: every area has a destination page today. A future area
  without one must show no action rather than an unrelated link.
- Communications, School events, Student/Staff attendance, Admissions and
  Audit need no School setup of their own; their status follows their
  prerequisites.

**14.7 ORG.12C forward contract (not authorized).** Rich School & Campus
profiles: audit then add legal identity, registration/tax/affiliation
references (tax and statutory values stay behind RF-22's legal decision),
contact, website, social links, timezone, currency, branding, Campus code,
address, phone, email, coordinates and a descriptive service profile (day
school, early years, boarding, meals, transport — never entitlement or
authority). Each new field extends the `organization.school_profile` /
`organization.campuses` providers, so the Setup Center reflects it without
a second catalogue.
