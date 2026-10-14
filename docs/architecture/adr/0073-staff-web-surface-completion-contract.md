# ADR 0073: Staff Web Surface Completion Contract (SWC.0)

- Status: **Accepted — SWC.0 contract (2026-10-14, documentation only).
  SWC — CONTRACT PUBLISHED / IMPLEMENTATION NOT STARTED.** SWC.1 starts
  only after the owner reviews this published contract.
- Date: 2026-10-14
- Programme: **SWC — Staff Web Surface Completion** (`MASTER-ROADMAP.md`,
  post-foundation programme 8).
- Baseline: `origin/main` = `ce2fa22dc8fcd9dc76550864416b35d3b68da86c`
  (equal to local `main` and `HEAD`; clean tree; one worktree; the
  historical stash untouched; ruleset `main-protection` 24909660 active with
  no bypass actor; `Main protection drift` green; integration and `main`
  `CI gate` green on that exact SHA).
- Evidence: the *Post-Development Roadmap & Pre-Production Readiness Audit*
  (2026-10-14, read-only, baseline `ce2fa22d`), revalidated against the code
  for this ADR (§2).
- Reaffirms, unchanged:
  - ADR 0005 (Inertia web console; Laravel owns routing, authorization and
    data loading per page; the console does not consume `/api/v1`);
  - ADR 0045 as amended by ADR 0070 §8.2 (four authorization scopes);
  - ADR 0044 and CLAUDE.md rule 83 (elevation never opens a School page);
  - ADR 0054 §8.2 (the closed `SchoolHostSurface`);
  - ADR 0071 (the fixed staff role catalogue; no capability changes);
  - ADR 0072 §28.8 and §31 (remote exact-SHA sharded suite as the canonical
    regression for a code-lane candidate);
  - CLAUDE.md rules 3, 6, 19, 24, 29, 74, 76 and 82.
- Amends nothing. It records which already-built contracts the staff browser
  surface must reach, and the boundary SWC may not cross.

## 1. Problem statement

The seven approved post-foundation programmes (FEE, TCH, HRX, OPF, RES, POR,
SR) are development-closed. Their domain and Application layers are
implemented and tested. There is no eighth previously approved product
programme.

**Gap.** A staff member cannot operate a School through the web application
alone. Already-built functionality is either:

- reachable only through the token-authenticated `/api/v1` surface (a
  personal API token and direct HTTP calls), or
- built as an Inertia page that nothing links to, so it can be found only by
  typing its URL.

**Where it shows:**

- **Academic Structure:** Terms, Sections, Academic Departments and Rooms
  have no page. Nothing can be edited or deactivated in the browser, Campus
  included. Subject Offerings cannot be created or changed (§2.1).
- **OPF configuration:** fee-head mappings, the Library fine policy and
  Library fines are API-only. Their side effects already fire, invisibly,
  from existing pages (§2.2).
- **Navigation:** nine implemented staff modules have no link (§2.3).
- **Stale framing:** the public `/` page and the Dashboard still present
  development-phase framing to every user (§2.4).

**Not the problem.** Domain architecture is not missing. The problem is
browser operability over contracts that already exist.

## 2. Evidence (revalidated at `ce2fa22d`)

### 2.1 Academic Structure

**Shared shape:**
- **API:** every entity has a complete API under `/api/v1/schools/{school}`
  (`routes/api.php:243-369`). It runs behind `auth:sanctum` and
  `school-membership`, plus `capability:*` and
  `throttle:school-api-mutations` on writes.
- **Web:** the School Setup web group (`routes/web.php:501-525`,
  `App\Http\Controllers\App\SchoolSetupController`) covers Profile,
  Campuses, Academic Years, Grade Levels and Subjects only.
- **Recorded deferral:**
  - `SchoolSetupController.php:34-39` says the other entities "do not yet
    have a dedicated screen".
  - `MASTER-ROADMAP.md` (Phase 0D) says "only the Inertia page is pending".

| Entity | Application layer | API | Browser today | View / manage capability | Browser gap |
|---|---|---|---|---|---|
| Campus | inline (`Api\V1\CampusController`) | list, create (`idempotent`), show, PATCH (fields + `status`) | list, create (name, code) | `school.campuses.view` / `.manage` (manage: `school_admin` only) | edit, deactivate/reactivate, full fields |
| Academic Year | `AcademicYearService` create/activate/close; PATCH inline (name, code) | full | list, create, activate, close | `academics.years.view` / `.manage` | rename; domain conflicts not rendered as form errors (§2.5) |
| Academic Term | `AcademicTermService::create`; PATCH inline (rename only) | list per year, create, show, PATCH | **none** | `academics.years.view` / `.manage` | whole surface |
| Section | inline (`SectionController`: transaction, audit, `SectionCreated`) | list per year, create (`idempotent`), show, PATCH (name, code, capacity, status) | **none** (read-only pickers only) | `academics.structure.view` / `.manage` | whole surface |
| Academic Department | inline | list, create, show, PATCH (+ status) | **none** | `academics.structure.view` / `.manage` | whole surface |
| Room | inline | list per campus, create, show, PATCH (+ status) | **none** (timetable picker only) | `academics.structure.view` / `.manage` | whole surface |
| Grade Level | inline | full + PATCH (+ status, `education_stage`) | list, create (no `education_stage`) | `academics.structure.view` / `.manage` | edit, deactivate, `education_stage` |
| Subject | inline | full + PATCH (type, short name, department, status) | list, create (name, code) | `academics.subjects.view` / `.manage` | edit, deactivate, full fields |
| Subject Offering | create inline; update only through `SubjectOfferingService::update` (ADR 0069) | list per year, create (`idempotent`), show, PATCH | roster/enrolment workspace only (`/app/subject-offerings`) | `academics.subjects.view` / `.manage` | create, edit, deactivate |
| Elective Group | `ElectiveGroupService` create / assignOffering / removeOffering | **none** | **none** | prescribed `academics.subjects.manage` (Phase 1F.3 doc §1, "a future controller authorizes `academics.subjects.manage`") | whole surface; the elective-enrolment UI already depends on groups (§4.3) |

**Roles holding these keys** (`CapabilityAndRoleSeeder`):
- `school_admin` holds every key.
- `principal` holds every key except `school.campuses.manage`.
- No other system role holds any of them.

### 2.2 OPF

**Recorded deferral.** ADR 0067 says "There is no Inertia page" for OPF.1,
OPF.2 and OPF.3 (lines 508, 652 and 784). It is silent for OPF.4, and the
§31 closure records no browser follow-up.

**Correction to the audit.** OPF is not entirely browser-absent. The existing
base-module web controllers call the same Application services, which call
the OPF hooks:

| Web action | Hook it fires |
|---|---|
| Transport assignment start/end (`TransportStudentAssignmentController`) | `TransportFeeSelectionService` |
| Hostel residency start/end (`HostelResidencyController`) | `HostelFeeSelectionService` |
| Admission conversion (`AdmissionApplicationController@convert`) | `AdmissionFeeSelectionService::recordForConversion` |
| Library check-in (`LibraryCirculationController`) | `LibraryFineService::assessOnCheckIn` |

**Effect.** A browser user already creates fee intent and fines. But:
- they do so only if a mapping or policy was configured through the API;
- no page shows the result.

**Admission fee choice.** Conversion has no fee choice, in the API or the
web, by design (ADR 0067 D7): the one School-wide admission fee head applies
automatically.

| Workflow | Application service (existing) | API (existing) | Browser gap |
|---|---|---|---|
| Transport fee selection | `TransportFeeSelectionService` (`setRouteFeeHead`, `routeFeeHead`, `carryForward`) | route fee head GET/PUT; assignment fee selections GET; carry-forward POST (`idempotent`) | route → fee-head mapping; per-assignment fee intent; carry-forward |
| Hostel fee selection | `HostelFeeSelectionService` (`setHostelFeeHead`, `setRoomFeeHead`, reads, `carryForward`) | hostel/room fee head GET/PUT; residency fee selections GET; carry-forward POST (`idempotent`) | hostel and room mappings; per-residency fee intent; carry-forward |
| Admission fee at conversion | `AdmissionFeeSelectionService` (`setFeeHead`, `feeHead`) | `/admission-fee-head` GET/PUT; application fee selection GET | School admission fee head; a converted application's fee intent |
| Library fines | `LibraryFineService` (`void`; assessment only at check-in) | loan fine GET; fine void POST (`idempotent`) | per-loan fine display; void. **No School-wide fine list exists in any layer** (§15 X2) |
| Library fine policy | `LibraryFinePolicyService` (`publish`, `current`, `versions`) | policy GET, versions GET, publish POST (`idempotent`) | view current and history; publish a version |
| Fine waiver | the existing FEE concession | — | none: Finance → Concessions already exists |

**Capabilities** (unchanged):
- **Keys:** `transport.routes.*`, `transport.assignments.*`,
  `hostel.directory.*`, `hostel.residency.*`, `admissions.*`,
  `library.fines.view` / `.manage` / `.void`.
- **Holders:**
  - all of them: `school_admin`;
  - transport, hostel and admissions keys (but no `library.fines.*`):
    `principal`;
  - their module keys: `transport_coordinator`, `hostel_warden`,
    `admissions_officer`;
  - `library.fines.view` only: `librarian`.

**Production.**
- **Gates in code:** none. No code-level refusal exists for OPF.
- **Gates by register:** production is gated by ADR 0058 E21, E30–E32 and,
  for Library fines, E34 (ADR 0067 §22, §30.8, §31.8).

### 2.3 Navigation and discoverability

**What exists today:**
- **Layout:** `AccountLayout.vue` is the only layout: an account bar with no
  module navigation.
- **Navigation:** all staff navigation is one flat list in
  `Pages/App/Dashboard.vue`.
- **How links are decided:** `DashboardController` sends about 39 `nav.*`
  booleans, each a `CapabilityResolver::canInSchool()` check. Never a role
  key ("UX only", controller comment).
- **Back-links:** 66 pages hard-code `← Dashboard` back-links.
- **Not linked:** the modules below have a working, capability-gated Inertia
  page and no link.

| Module | Pages (exist) | Capability that reveals it | Roles that hold it | Production gate in code |
|---|---|---|---|---|
| Attendance administration | `/app/attendance` | `attendance.view` | school_admin, principal | none (teacher `/app/my-attendance` is separate and already linked) |
| Examinations / grading | `/app/examinations`, papers, `/app/examinations/grade-scales` | `examinations.definitions.view` (papers `.papers.view`, scales `.grade_scales.view`) | school_admin, principal | **StudentMark has no page** (JSON-only, refused outside local/testing: `EnsureStudentMarksDevelopmentOnly`, `EnsureTeacherStudentMarksDevelopmentOnly`, `StudentMarkAvailability`) |
| Hostel | `/app/hostels`, `/app/hostel-residency` | `hostel.directory.view`, `hostel.residency.view` | school_admin, principal, hostel_warden | none |
| Inventory | `/app/inventory-items`, `-locations`, `-stock` | `inventory.directory.view`, `inventory.stock.view` | school_admin, principal, stores_officer | none |
| Library | `/app/library/titles`, `/app/library/circulation` | `library.catalogue.view`, `library.circulation.view` | school_admin, principal, librarian | none (fines: E34 by register only) |
| Transport | `/app/transport/routes`, `vehicles`, `operations`, `assignments` | `transport.routes.view`, `transport.vehicles.view`, `transport.assignments.view` | school_admin, principal, transport_coordinator | none |
| Visitor | `/app/visitor/directory`, `/app/visitor/visits` | `visitor.directory.view`, `visitor.visits.view` | school_admin, principal, front_office | none |
| Syllabus / Curriculum Delivery | `/app/syllabus`, `/app/syllabus-delivery` | `syllabus.view`, `curriculum.delivery.view` | school_admin, principal | none |
| Administrator LMS | `/app/learning-content`, `/app/assignments` | `lms.content.view`, `lms.assignments.view` | school_admin, principal | none (Submission cancelled; no page) |

**Every one belongs in navigation (§6).**
- Each has a real page, an existing view capability and no code-level
  production refusal.
- Their production holders are only administrative roles or the matching
  ADR 0071 operational role.

**Gated surfaces stay as they are:**
- **StudentMark:** no navigation (no page; refused in production code).
- **Statutory payroll:** reached only from Payroll under
  `payroll.statutory.view`, which no role holds (E45).
- **Guardian portal:** only while `PortalAvailability::isAvailable()` (E46).

### 2.4 Stale product framing

- **Public `/`** (`SystemStatusController`, `Pages/SystemStatus.vue`):
  - renders `phase: '0A - Architectural Foundation'` and "Planned modules
    (not yet implemented)", listing modules that are built;
  - discloses `APP_ENV` and the request id to anonymous visitors;
  - is admitted on School custom hosts (`SchoolHostSurface::EXACT`
    includes `''`).
- **Dashboard:** heading "School OS — Phase 0B" (`Dashboard.vue:101`);
  docblock "Phase 0B primitive … Deliberately not a real dashboard".
- **Other user-visible strings:**
  - `Communications/Analytics.vue:212` says "(not implemented)" for provider
    webhooks;
  - `Leave/YearClose.vue:109` says "not applied automatically yet".

  Both are reviewed in SWC.1. Each changes only if it is false today.

### 2.5 Web-surface defects found while revalidating (in SWC.2 scope)

**Missing rule 74 check.** The web `storeCampus`, `storeGradeLevel` and
`storeSubject` actions do not run `NormalizesCodeInput` + `Rule::unique`.
- The database constraints (`unique(school_id, code)`; grade `sequence`)
  still hold.
- So a duplicate submission probably surfaces as a 500, not a 422.

**Domain exceptions not handled.** The web Academic Year actions do not
catch `AcademicYearOverlapException`, `InvalidAcademicYearTransitionException`
or `ConcurrentActivationConflictException`. `bootstrap/app.php` renders these
as a 409 envelope only for `api/*`.

**No web-mutation tests.** No test posts to any `/app/school-setup/*` route.

**Stale test comment.** `ElevatedSchoolRouteDenialTest.php:88` says
`/app/school-setup` has "no capability check". Since POR.1 it requires
`school.profile.view`.

**Status.** These are concrete defects in existing web surfaces. Fixing them
is in SWC.2 scope (§10). They are not new behaviour.

## 3. Why this is development, not pre-production

- **Gap type.** The gap is unbuilt product surface over existing
  contracts, not environment, provider, legal or release evidence.
- **Blocks the audience.** Staging/UAT needs School staff to operate the
  product in a browser. A staging environment exposing API-only workflows
  would qualify nothing that a School could use.
- **Trigger not met.** The Pre-Production / Staging Readiness trigger
  ("core development materially complete") is not met while this gap stands.
- **No new semantics.** SWC introduces no business semantics. It needs no
  legal determination, provider, deployment or secret.

## 4. SWC architectural boundary

### 4.1 The rule

**SWC is UI over existing Application services.**

**Never added**, unless a concrete defect is found and recorded in a dated
amendment here:
- a domain table or business entity;
- a capability, or a change to what a capability means;
- an email purpose;
- a provider integration;
- an API business behaviour;
- a legal-data category;
- a production-role activation.

**Shape:**
- **Controllers:** stay thin (rule 3).
- **Authority:** existing Application services remain authoritative.
- **Data loading:** the console uses Inertia controllers that call the same
  Application services as the API controllers (ADR 0005). It never consumes
  `/api/v1` from the browser and never issues a personal token for itself.

### 4.2 Shared services: extract, never duplicate

Several Academic Structure writes live inline in their API controllers
(rule 76 allows this for one consumer). Examples are Section, Room,
Department, Grade Level, Subject, Campus, the Subject Offering create and the
inline PATCH paths. When a web surface needs one of them:

1. SWC moves that exact logic into an Application service in
   `app/Domain/AcademicStructure/Application/`.
2. Both the API and web controllers call that service.
3. The service keeps the same validation semantics, audit action names and
   metadata, domain events, transaction boundaries and exceptions.

Rules for this extraction:
- **Never copy** create-audit-event blocks into a second controller.
- **API response bodies** and status codes stay byte-identical. The existing
  API tests prove it, plus the PATCH and deactivate tests SWC.2 adds where
  none exist (§11).
- **This is a refactor, not new behaviour.** If extraction would change
  any API-observable behaviour, the slice stops and records the difference
  for owner decision.

### 4.3 Elective Groups (evidence-driven addition to SWC.2)

**What exists:**
- `ElectiveGroupService` (Phase 1F.3) is an existing, tested Application
  service with no HTTP surface.
- Its contract already names the authorizing capability:
  `academics.subjects.manage`.
- The existing elective-enrolment and rollover pages depend on groups. Today
  a group can be configured only through the demo seeder or tests.

**What SWC.2 adds** (no new capability, no service change):
- a web-only surface to create a group and to assign or remove an offering;
- no API, matching the service's recorded scope: no delete, no rename, no
  rules engine.

**Owner confirmation.** This is an SWC.0 scope addition, so the owner must
confirm it (§15 X1).

### 4.4 Authorization

**Capability checks:**
- Every SWC page and action uses existing capability keys, through
  `AuthorizesCapability` / `capability:` / `Gate::authorize`.
- It never branches on a role name (rule 24).
- Server-side authorization is authoritative. Hidden navigation is never an
  authorization mechanism.

**Tenant context:**
- The active School comes only from `TenantContext` (session
  `ResolveSchoolContext` or a verified School host).
- A client-supplied School id is never trusted (rules 19, 20, 68).
- Every new route sits in the existing `auth` + `school-context` web group,
  so elevation keeps refusing it (rule 83).
- A School host serves only `SchoolHostSurface` paths (ADR 0054).

**Preserved requirements:**
- **Fresh MFA:** existing requirements stay
  (`FreshMfaRequirement::requireForAction()`, `mfa`, `mfa-page`;
  rule 95).
- **Inherited controls:** a web action over a service inherits every
  control the service or its API route enforces:
  - fresh MFA;
  - `SchoolOperationalGuard`;
  - HR self-administration refusal;
  - SR grant authority;
  - the ADR 0069 classification freeze.
- **Capability mutual exclusion and SR constraints:** unchanged.

**No weakening.** An API rule is never weakened because the browser needs to
call it. If the browser path cannot satisfy a control the API enforces, the
slice stops and records it.

### 4.5 Idempotency on browser mutations (rule 29)

**Precedent.** Inertia routes never carry the `idempotent` middleware. Its
replay returns JSON and cannot reproduce a redirect (the Phase 9.9 finding in
`routes/web.php`, Payroll block). Browser duplicate protection is therefore
one of these:

- **(a) Server-issued key.** A key issued with the form and claimed by the
  existing Application service, where the service already supports one.
  Precedents: `ManualPaymentController`, `FeeConcessionController`,
  `GuardianConversationController`.
- **(b) Natural guard.** A natural-key uniqueness or state-transition guard
  that makes a duplicate a clean 422 or no-op. Examples: unique codes, an
  already-closed year, an already-voided fine.
- **(c) Stop and record.** If an operation's API route carries `idempotent`,
  its duplicate is consequential, and neither (a) nor (b) exists in the
  service, the slice records the gap and stops for owner decision. It does
  not invent a new idempotency mechanism.

**Where this bites.** The known (c) candidates are the OPF carry-forward
POSTs and the fine-policy publish (§10 SWC.3).

**Every SWC mutation** records which of (a), (b) or (c) applies, with a test.

## 5. Owner scope decisions (recorded 2026-10-14)

| # | Decision | Status |
|---|---|---|
| OD-SWC-1 | **Accessibility baseline — IN** for every new or changed SWC page (§8). There is no WCAG certification claim and no repository-wide remediation. | Adopted |
| OD-SWC-2 | **Documents UI — OUT.** SWC.0 found no core staff workflow in §2 that needs the 0E Documents web UI to be completed. HR document *records* already have pages. It is recorded as an optional post-v1 web-surface item. | Adopted |
| OD-SWC-3 | **Webhook administration UI — OUT.** It is technical/operator functionality. No repository contract requires ordinary School staff to administer webhooks. `integrations.webhooks.*` stays API-only. | Adopted |
| OD-SWC-4 | **Student / Guardian CSV import — OUT.** This is onboarding/data-migration scope, registered as a future onboarding decision (§13.2). (Employee import already exists in HR and is unchanged.) | Adopted |
| OD-SWC-5 | **Opening / legacy fee balances — OUT.** This is new financial/domain migration scope, preserved as a separate owner decision for onboarding/migration (§13.2). | Adopted |
| OD-SWC-6 | **SWC is the final development programme** before Pre-Production / Staging Readiness, which starts only after SWC closure or an explicit owner override (§13). | Adopted |

## 6. Capability-driven navigation shell (SWC.1)

### 6.1 Model

**Properties.** Navigation is capability-driven, server-informed,
tenant-aware and authorization-safe.

**The catalogue:**
- **Where:** one server-side catalogue
  (`App\Support\Navigation\StaffNavigation`, a plain class; no package).
- **What each entry is:** a fixed list of entries `{key, group, label, route
  name, requirement}`.
- **Requirements:** exactly the existing view capability, or an any-of /
  all-of set of existing capabilities. The current Dashboard flags already
  use these: `canViewFinance`, `canViewHr`, `canViewPayroll` and
  `canViewEnrollmentRollovers`.
- **Route names:** resolve at build time. A test fails on any entry whose
  route does not exist.

**How it reaches the client:**
- Shared through `HandleInertiaRequests` as a **lazily evaluated** prop.
- The closure runs at response time, after `school-context` has resolved the
  School. Rule 61's ordering lesson applies: the prop never reads
  `TenantContext` eagerly in `share()`.

**What it contains:** only entries the current actor may see in the current
School:
- each check is `CapabilityResolver::canInSchool()`, through its existing,
  School-keyed cache (rule 22);
- no new cache key.

**What it never contains:**
- **Role names.** No entry tests a role key, and there are no role-specific
  menus.
- **Navigation-only capabilities.** None are added.
- **Raw School identifiers** beyond what the page already has.

**Client:** a navigation component in the shared layout renders only what
the server sent. It holds no capability logic of its own.

**Back-links:** the 66 hard-coded `← Dashboard` back-links can stay or be
replaced by layout navigation. Either is acceptable. Replacing them is not
required for SWC.1 completion.

### 6.2 Scopes and contexts

| Context | Navigation |
|---|---|
| Staff with an active School | School groups below, filtered by capability |
| Elevated platform actor (ADR 0044) | No School group. Elevation refuses every School route (rule 83), so advertising one would be a lie. The platform section is unchanged. |
| No active School / no membership | No School group; the School switcher / no-access state as today |
| School custom host | School groups only. Platform, Group, account-admin and API-token entries are never emitted on a School host (`SchoolHostSurface`). |
| Guardian persona | The existing Guardian portal section, only while `PortalAvailability::isAvailable()` and an ActingGuardian resolves. It is never mixed into staff groups. |
| Platform / School Group authority | The existing platform and Group sections, platform host only. They are evaluated with `canPlatform` / `canInGroup`, never `can()` (rule 84). |

**Tenant switch.** Switching School re-evaluates the catalogue on the next
response, because it is built per request from the new `TenantContext`.

### 6.3 Information architecture

**Group order:**
1. **My work** — My leave, My staff attendance, My payslips, My curriculum
   delivery, My attendance, My learning content, My assignments
   (self-service and teacher-owned capabilities).
2. **School setup** — School profile & setup, Campuses, Academic years &
   terms, Grade levels, Sections, Academic departments, Rooms, Subjects,
   Subject offerings & elective groups.
3. **Academics** — Timetable periods, Timetable schedule, Teaching
   assignments, Syllabus, Curriculum delivery, Attendance, Examinations,
   Learning content, Assignments.
4. **People** — Admissions, Students, Guardians, Enrollments, Enrollment
   rollovers, HR, Leave, Leave approvals, Staff attendance.
5. **Finance** — Finance (fees, charges, payments, ledger), Payroll.
6. **Operations** — Transport, Hostel, Library, Inventory, Canteen, Visitor.
7. **Communications** — Communication Hub.
8. **Reports** — Analytics, Audit log.
9. **Administration** — School settings, Staff accounts, Automation,
   Domains, API clients.

**Rules:**
- **Labels:** product language, never phase or ADR names.
- **Group headings:** a heading is shown only if at least one entry
  survives.
- **Empty state:** if no group survives, the shell shows one neutral "No
  modules are available to your account in this School" message.

**Placement:**
- **Primary navigation:** each group's module entry points.
- **Secondary navigation:** sub-pages stay in the module's own page or
  sub-nav. Examples: Grade scales under Examinations, Statutory under
  Payroll, the Communications `HubNav`.

**The Dashboard:** it becomes a landing page. It holds the School
switcher/context and product heading (§7) and the same catalogue rendered as
a grouped overview. It no longer keeps its own `nav.*` flag list.
- **Migration:** existing tests pin `nav.*` keys, and
  `MyAttendanceUiTest.php:103` pins the exact set. SWC.1 migrates them to
  the catalogue with equivalent assertions.
- **No silent loss:** each existing flag must map to a catalogue entry, or
  be recorded as intentionally retired.

### 6.4 Gated modules

| Surface | Navigation rule |
|---|---|
| StudentMark (E35–E37 / RES-L1) | Never in navigation. It has no page, and its routes are refused outside local/testing. Examinations shows definitions, papers and grade scales only. |
| Statutory payroll (E45) | No primary entry. It stays a Payroll sub-link gated by `payroll.statutory.view` (no role holds it). |
| Guardian portal (E46) | Guardian section only under `PortalAvailability` (refused outside local/testing). |
| Teacher Attendance (E33 conditions) | "My attendance" under `attendance.teacher`, with the page's existing `mfa-page`. Production grants stay withheld by process (ADR 0063 §40–§44). Navigation is not the gate. |
| OPF / Library fines (E21, E30–E32, E34) | Shown under existing capabilities. Their production gate is the ADR 0058 register and release qualification, not navigation. SWC adds no code-level production refusal (§15 X3). |
| Results, report cards, transcripts, Lesson Planning, Student accounts | No pages exist, so no entries. |

**Never:** navigation never exposes a capability that is deliberately
unavailable in production. An entry whose page is refused in the current
environment by an availability guard is never emitted.

### 6.5 Responsive and keyboard behaviour

**Wide viewports:** a persistent side navigation.

**Narrow viewports (< 768 px):**
- a disclosure button with `aria-expanded` / `aria-controls` opens it;
- Escape closes it and returns focus to the button;
- it must not trap focus when closed.

**Keyboard and semantics:**
- every entry is reachable and operable by keyboard in DOM order;
- the active entry carries `aria-current="page"`;
- active state is derived server-side from the current route name, never
  from string-matching URLs on the client;
- landmarks: one `<nav aria-label="…">` per navigation region, with distinct
  labels for School, Guardian portal and platform.

**No horizontal page scroll** at phone width.

## 7. Landing-page decision (SWC.1)

### 7.1 `/`

**Decision: a neutral, login-directed product entry.** Rejected: a marketing
site and a status page.

**Behaviour:**
- **Guest:** `200` with a neutral page showing:
  - the product name, which is the deployment's configured `config('app.name')`;
  - one sentence describing it as a school management system;
  - a "Sign in" link to `/login`.
- **Authenticated:** redirect to `/app`.

**What it must not expose:**
- no phase, roadmap, "planned modules" or engineering terminology;
- no `APP_ENV`, request id or build information;
- no School-specific data before sign-in, on any host. The page is
  host-neutral because `''` is admitted on School custom hosts.

**Unchanged:**
- **Request id:** the `X-Request-Id` response header stays (observability
  middleware), but it is no longer rendered.
- **Caching:** guest `/` keeps its current caching/`200` behaviour
  (`PostLogoutHistoryPrivacyTest`).

**Controller:**
- `SystemStatusController` (the rule 3 / ADR 0005 reference shape) is
  replaced by a thin `HomeController`, or kept under its name, with no
  business logic either way.
- CLAUDE.md rule 3's reference is updated in the same slice if the class is
  renamed.
- `SystemStatusPageTest` is rewritten to the new contract.

### 7.2 Authenticated Dashboard

**Heading:**
- "School OS — Phase 0B" becomes the configured product name plus the
  active School's name. Without an active School, the product name alone.
- The docblock's "Phase 0B primitive" framing is retired.

**Elevated sessions:** the copy "no School page is available under elevated
access yet" keeps its meaning, reworded in product language. Elevation still
opens no School page (rule 83).

**Product name:** SWC.1 does not rename the product. `APP_NAME` stays a
deployment value. The `.env.example` default is "School OS" and the email
sender name defaults to "Lycenza". Whether to align them is a naming decision
(§15 X4); SWC.1 renders whatever is configured.

## 8. Accessibility baseline (OD-SWC-1)

### 8.1 Scope

Every page or component that SWC creates or materially changes must meet the
baseline:

- **Forms:** every control has a programmatic label (`<label for>` or
  wrapping label). Placeholder is never the only label.
- **Validation:** errors are associated with their field
  (`aria-describedby` to the message id, `aria-invalid="true"`). A form-level
  error summary sits at the top for multi-field failures. Focus moves to the
  first invalid field or the summary after a failed submit.
- **Keyboard:** every interactive element is reachable and operable by
  keyboard. No click-only `div`/`span` controls: buttons are `<button>` and
  navigation is `<a>`/Inertia `<Link>`.
- **Focus:** focus is visible on every interactive element (Tailwind
  `focus-visible` ring) and never removed without a replacement.
- **Dialogs and menus:**
  - confirmations (deactivate, void, publish, activate/close year) use a
    native `<dialog>` or an equivalent with `role="dialog"`/`aria-modal`;
  - focus moves into the dialog and is contained while it is open;
  - Escape closes it, and focus returns to the trigger.
- **Headings:** one `<h1>` per page and no skipped heading levels in new
  content.
- **Tables:** data tables use `<table>`, `<th scope>` and a caption or
  `aria-label`. Sort/filter controls are real buttons/selects with labels.
  Status is never conveyed by colour alone (`StatusBadge` carries text).
- **ARIA:** only where native semantics are insufficient.

### 8.2 Tooling

- **Plugin.** SWC.1 evaluates `eslint-plugin-vuejs-accessibility`
  (flat-config `recommended`) against the repository's ESLint 10 / `eslint-plugin-vue`
  10 toolchain:
  - **If it integrates cleanly** (supported peer range, `npm ci` from the
    lock, `.npmrc` `ignore-scripts`, a clean `npm audit` under the current
    E16 decision, no `--force`):
    - it is added as a dev dependency under rule 87;
    - it is enabled for files SWC creates or changes;
    - existing violations elsewhere are not remediated by SWC, and the rule
      may be scoped by file glob.
  - **If it does not integrate cleanly,** SWC.1 records why and keeps the
    PHP-level and manual checks below.
- **No browser test framework.** SWC adds no Playwright, axe or Vitest. The
  repository has no frontend test runner, and rule 2 forbids speculative
  infrastructure.
- **Automated checks** run in the existing PHP Inertia tests:
  - required props for labels and errors;
  - the navigation catalogue's structure;
  - `aria-current` derivation inputs.
- **Manual checks:** each slice's DDEV review covers keyboard-only operation
  of its pages.
- **Certification:** no WCAG conformance is claimed. A qualified target
  belongs to Pre-Production (§13).

## 9. Explicit exclusions

**SWC must not implement or activate:**
- StudentMark / teacher marks UI (E35, E36, E37);
- statutory payroll role activation (E45);
- production Guardian / Student portal (E46), and Student accounts;
- RES-L4 to RES-L7: results, report cards, transcripts and Student/Guardian
  marks access;
- Lesson Planning;
- real AI providers;
- new email purposes;
- SendGrid qualification, E17, E19 and E20;
- a payment gateway;
- SMS, WhatsApp or push;
- SSO;
- data import (Student/Guardian), opening balances;
- new production legal authorizations;
- the Documents UI (OD-SWC-2) and the webhook administration UI (OD-SWC-3);
- tenant-custom roles (ADR 0071 T3);
- a School-wide Library fine list (no read model exists; §15 X2);
- any code-level production refusal or availability flag not already
  present (§15 X3).

**Navigation must never expose** a capability that is currently and
deliberately unavailable in production.

## 10. Slice plan

| Slice | Scope | Not in scope |
|---|---|---|
| **SWC.1 — Navigation Shell & Product Landing Surfaces** | §6 catalogue + shared layout navigation (all nine unlinked modules + existing entries); Dashboard as landing/overview; §7 `/` replacement and Dashboard heading; stale user-visible strings in §2.4 corrected where false; `eslint-plugin-vuejs-accessibility` evaluation (§8.2); nav-key test migration; CLAUDE.md rule 3 reference updated if `SystemStatusController` is renamed; `apps/platform/app/Domain/README.md`, the `CapabilityAndRoleSeeder` payroll-grant comments and the stale elevation test comment corrected (§12.2) | any new page for a module; any capability change |
| **SWC.2 — Academic Structure Web Surfaces** | Terms (list per year, create, rename); Sections (list per year with campus/grade filter, create, edit, deactivate/reactivate); Academic Departments, Rooms (per campus) (list, create, edit, deactivate/reactivate); Campus edit/deactivate + full fields; Academic Year rename + domain-conflict rendering; Grade Level and Subject edit/deactivate + missing fields; Subject Offering create, edit (through `SubjectOfferingService::update`, `SUBJECT_OFFERING_CLASSIFICATION_LOCKED` rendered as an expected outcome), deactivate; Elective Group create/assign/remove (§4.3, pending X1); §2.5 defects; §4.2 service extraction | DELETE of anything; Academic Year date edits or reopen; Elective Group rename/delete; per-Campus board; Curriculum |
| **SWC.3 — OPF Web Surfaces** | Transport route → fee-head mapping and per-assignment fee intent display; Hostel hostel/room mappings and per-residency fee intent; School admission fee head and a converted application's fee intent; Library fine policy (current, history, publish) and per-loan fine display + void on Circulation; carry-forward actions for Transport/Hostel **only** under §4.5 (a)/(b) — otherwise recorded as (c) for owner decision; links to Finance → Concessions for waivers | admission-fee choice per conversion (ADR 0067 D7: none exists); fine assessment outside check-in; a fine list; source attribution in Finance pages; any new OPF rule |
| **SWC.4** | **Omitted.** SWC.0 found no item outside SWC.1–3 that browser-operable v1 requires. Elective Groups fit SWC.2. Re-opened only by a dated owner amendment here. | — |
| **SWC.5 — Closure Audit & Demo Review** | Browser-operability audit against §13.1; navigation completeness (every catalogue destination renders for a holder; every in-scope page has an entry); capability/authorization and cross-School review; DDEV demo walkthrough with production-role personas; `DDEV-DEMO-REVIEW.md` §13–§15 rewritten; deferred documentation drift (§12) closed; Pre-Production handoff (§13) | starting Pre-Production |

**Order.** SWC.1 → SWC.2 → SWC.3 → SWC.5. SWC.2 and SWC.3 are independent
of each other, but both render inside the SWC.1 shell. Each slice is
published before the next starts (rule 82).

## 11. Test obligations

**Where tests run.** Every SWC implementation slice ships tests in the
existing PHP Feature suite (`tests/Feature/App/*UiTest.php` pattern,
real PostgreSQL).

**Page rendering:**
- An authorized user renders the page (Inertia component + props).
- An unauthorized user gets 403. A holder losing the capability loses the
  page on the next request.
- Missing or invalid tenant context fails closed: no active School, a
  suspended membership, an elevated session (403, rule 83), and a School
  custom host for a non-member.

**Mutations:**
- An authorized mutation succeeds and records the existing audit action and
  domain event.
- An unauthorized mutation fails (403). A cross-School target fails (404, or
  the existing not-found contract). Validation is enforced (422-equivalent
  session errors).
- Stale or inactive entities behave per the existing contract: inactive
  parents refused where the API refuses them, the classification freeze,
  year transitions.
- Duplicate submission behaves per its §4.5 classification.
- Where §4.2 extraction occurs: the existing API tests stay green unchanged,
  plus new API PATCH/deactivate tests where none exist.

**Navigation:**
- An entry is visible with its capability and absent without it.
- Any-of / all-of requirements hold exactly.
- Gated modules (§6.4) are absent when their availability guard refuses.
- A tenant switch changes the catalogue.
- No role-name dependency: an architecture guard test fails if the catalogue
  or its builder references a role key.
- Every catalogue route name resolves. Every advertised destination renders
  200 for a holder of its requirement (one data-driven test).
- Elevated and School-host contexts emit no forbidden entries.

**Accessibility baseline:** where tooling permits —
- the lint rule (§8.2) runs in the existing `npm run lint` CI step;
- PHP tests assert the label/error props the baseline depends on;
- manual keyboard review is recorded in the slice report.

**Regression:**
- Targeted tests during development.
- Each publishable code-lane candidate's `CI gate` is its full regression
  (ADR 0072 §28.8, §31; rule 82). The four-shard coverage guarantee is
  never weakened, and no test is dropped to balance a shard.
- Each slice report states its position in the regression counter.

## 12. Documentation drift

**Rule.** Corrections touch current-status statements only and preserve
history: each is a dated note beside the stale text, never a rewrite of the
record.

**SWC.0 stays docs-lane only.** Files under `apps/platform/**` classify into
the platform lane (`infrastructure/ci/lycenza_ci/classify.py`). Their
corrections therefore ride with SWC.1/SWC.2 code.

### 12.1 Corrected in SWC.0

| File | Correction (dated note) |
|---|---|
| `docs/roadmap/MASTER-ROADMAP.md` | SWC row 8 and SWC checkpoints block. Pre-Production / Staging Readiness recorded (Phase Zero block and Phase 0O status block). E16 lead text, FEE row, FEE closure, SR row and SR closing bullet now point to the current E16 decision (through 2026-11-09) and E03 CLOSED. Phase 0D "only the Inertia page is pending" now points to SWC.2. Phase 0K now points to OPF and SWC.3. |
| `docs/architecture/PHASE-0O-READINESS.md` | header status note (O2–O16 resolved, O1 not satisfied, E01–E47, E03 complete); §10 marked historical; §46 "current" table marked superseded; new §61 |
| `docs/architecture/DOMAIN-MAP.md` | one note under the status paragraph: Admissions implemented; HR/Payroll/HRX; E33 determination; OPF in Transport/Hostel/Library; Documents owner arms; Phase 0M closed |
| `docs/operations/README.md` | E16 decision and expiry; `main` protected; E18/E17 state; register E01–E47 and open mandatory rows |
| `docs/product/README.md` | Phase 0A framing marked historical |
| `docs/modules/STUDENT-ENROLLMENT.md` | rollover status (1B.7C–1B.7F implemented; queue execution deferred); downstream consumers |
| `docs/development/DDEV-DEMO-REVIEW.md` | §13 note (SWC schedule plus known drift); §14 role/member-management bullet |
| `docs/architecture/adr/0067-operational-fee-integrations-contract.md` | amendment note: browser surfaces assigned to SWC.3; §30.4 `librarian` holds `library.fines.view` |

### 12.2 Catalogued, correction deferred

| File | Stale subject | Corrected in |
|---|---|---|
| `apps/platform/app/Domain/README.md:7` | "No business modules exist yet — this is Phase 0A" | SWC.1 (platform lane) |
| `apps/platform/database/seeders/CapabilityAndRoleSeeder.php` | payroll-grant comments: `payroll.compensation.sensitive.*` "nobody receives them" (since SR.3, `payroll_officer` holds them); `payroll.statutory.manage` "no functional implementation" (9.6 is implemented; still unheld); payslips "not yet built" (built) | SWC.1 (comment-only; platform lane) |
| `apps/platform/tests/Feature/Platform/Elevation/ElevatedSchoolRouteDenialTest.php:88` | "no capability check at all" | SWC.1 |
| `apps/platform/app/Http/Controllers/App/SchoolSetupController.php:34-39` | "do not yet have a dedicated screen" | SWC.2 |
| `docs/development/DDEV-DEMO-REVIEW.md` | §13–§15 rewrite: rows; "no student- or guardian-facing capability" (§10); "Documented but not implemented": StudentMark (development-only now), portal logins, 0O | SWC.5 (after SWC.1–3 change the surface) |
| `docs/roadmap/MASTER-ROADMAP.md` | the 2026-09-25 Phase 0O "Only 0O.1 … ready to start" sequence; the 2026-09-29 "only mandatory code tail left is E18 … can start now: E03" block | SWC.5 (dated notes) |
| `docs/architecture/PHASE-0O-READINESS.md` | §8 O1 row "E01–E29 … protected `main`"; O16 row's superseded decision id | SWC.5 (dated notes) |
| `docs/modules/STUDENT-ENROLLMENT.md:1` | title phase list stops at 1B.7B | SWC.5 |

SWC.5 re-verifies both lists against the code at closure. It does not rely
on these tables alone.

## 13. Completion criteria and Pre-Production handoff

### 13.1 SWC complete when

1. **Browser-only operation.** Every approved v1 staff workflow in §2 can be
   reached and completed through the web application. That means signing
   in, choosing a School and operating the workflow, with no personal API
   token and no direct HTTP call. Excluded and gated surfaces (§9, §6.4) are
   not part of this.
2. **Navigation completeness.** Every in-scope page has a navigation entry
   or a documented secondary entry point. Every entry renders for a holder.
3. **No stale framing.** No user-visible phase, roadmap or "planned module"
   framing remains.
4. **Tests and evidence.** §11 obligations met for each slice. Each slice
   published through protected `main` with a green exact-SHA `CI gate`.
5. **Closure.** The SWC.5 closure audit passes, with a DDEV walkthrough
   recorded.
6. **Drift.** §12.2 drift is closed or explicitly re-deferred with a reason.

### 13.2 Next programme (recorded, not started)

**Pre-Production / Staging Readiness.**
- **When it starts:** only after SWC closure or an explicit owner override.
- **Its own contract:** it starts with a contract audit that consolidates
  existing readiness requirements and registers the untracked ones.

**Expected scope**, subject to that audit:
- the staging/environment contract and `APP_ENV` semantics;
- the staging data policy;
- the Mailpit / external-email policy, with PPE email readiness (E17–E20,
  ADR 0060 §31.7);
- E05, E07, E08, E09, E10;
- the E11 restore drill, E12, E13–E15;
- the E16 successor, E21–E23, E29, E30–E34;
- E02 / O16 final qualification;
- production secrets and observability;
- release and rollback evidence;
- the final qualification regression.

**Untracked readiness risks to register there** (not solved by SWC):
- the performance/scale envelope and load qualification;
- latency alerting and 5xx-rate alerting;
- an incident-response plan and a personal-data-breach response;
- a tenant-support runbook;
- `APP_KEY` rotation and AI signing-key rotation;
- DSAR / data-subject access;
- tenant export at contract end;
- an accessibility qualification target;
- the onboarding/data-import decision, covering Student/Guardian import
  (OD-SWC-4) and opening balances (OD-SWC-5);
- whether E30–E34 may be met by keeping features out of production (§15 X3);
- E45's lack of a code-level production refusal (gated by capability
  non-seeding only, §2.3).

## 14. E16 calendar dependency

- **Current decision.** `OWNER-0O-E16-2026-10-11` is valid **through
  2026-11-09**.
- **When publication blocks.** From **2026-11-10**, the `supply-chain`
  lane's `qualify audit --fail-on-blocking` fails. No candidate's
  `CI gate` can then be green, and `publication-check` refuses. **Every
  SWC publication is blocked until a successor decision is published.**
- **Successor cycle.** It should begin **around 2026-10-30**: a fresh scan
  through `qualify all`, then a new owner decision.
- **Parallel work.** It may run in parallel with SWC. It is not SWC product
  scope and never ships inside an SWC slice.
- **Dependencies.** An SWC.1 accessibility lint dev dependency (§8.2) is
  scanned under the current decision. It must not introduce a finding that
  needs a new exception.

## 15. Open owner decisions

| # | Question | Recommendation |
|---|---|---|
| X1 | Confirm Elective Group configuration (create; assign/remove offering) in SWC.2 under `academics.subjects.manage` (§4.3) | Confirm: it is an existing service with a prescribed capability, and existing pages depend on it |
| X2 | A School-wide Library fine list needs a new read query. No such query exists in any layer. Add a read-only `LibraryFineService` query (not new business behaviour, but a new read model), or keep per-loan display only? | Per-loan only in SWC.3. Revisit with the librarian workflow at SWC.5. |
| X3 | E30–E34-gated OPF/FEE features have no code-level production refusal. Should production builds refuse them in code (as `PortalAvailability` does), or stay gated by the O1 register? | Decide in Pre-Production. SWC adds no refusal (§9). |
| X4 | Product display name: `.env.example` `APP_NAME` "School OS" vs email sender default "Lycenza" | Owner naming decision; SWC renders `config('app.name')` |
| X5 | For OPF carry-forward / fine-policy publish, if §4.5 (c) applies: accept natural guards, or extend the service with a server-issued key? | Decided at SWC.3 with evidence |

None of X1–X5 prevents SWC.1 from starting. X1 must be answered before SWC.2
freezes its scope. X2 and X5 must be answered before SWC.3.
