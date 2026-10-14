# ADR 0073: Staff Web Surface Completion Contract (SWC.0)

- Status: **Accepted — SWC.0 contract (2026-10-14, documentation only).
  Owner decisions X1–X5 frozen (2026-10-14, §16). SWC.1 NAVIGATION SHELL &
  PRODUCT LANDING SURFACES — COMPLETE (2026-10-14, §17). SWC.2 ACADEMIC
  STRUCTURE WEB SURFACES — COMPLETE (2026-10-14, §18). SWC.3 OPERATIONAL FEE
  WEB SURFACES — COMPLETE (2026-10-14, §19). SWC.5 NEXT.**
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

## 16. Owner decisions X1–X5 (frozen 2026-10-14, at SWC.1 authorisation)

These answer §15. They change no ADR 0058 row.

| # | Decision |
|---|---|
| X1 | **Elective Groups — IN for SWC.2.** They get a proper browser/HTTP configuration surface over `ElectiveGroupService`, under `academics.subjects.manage` (§4.3), because the existing elective-enrolment flows depend on them. Not in SWC.1. |
| X2 | **School-wide Library fine list — OUT of SWC.3.** No new School-wide fine query is created for SWC. Fines stay visible in the Library/loan context unless separately authorised later. |
| X3 | **Production refusal for E30–E34-gated fee/fine features — DEFERRED TO PRE-PRODUCTION.** SWC.1–SWC.3 neither add nor remove production-refusal logic to resolve E30–E34. The existing legal/readiness state is preserved. |
| X4 | **User-facing product name: Lycenza.** Stale `School OS` / `Phase 0A` / `Phase 0B` presentation is replaced where SWC.1 owns the surface. No repository-wide rename of classes, environment keys, historical ADR wording or technical identifiers (§17.7). |
| X5 | **Duplicate-submission protection is required before SWC.3 browser exposure.** SWC.3 audits the carry-forward and fine-policy publish mutations and reuses the existing idempotency/concurrency mechanisms, or adds the smallest compatible protection (§4.5). No SWC.3 form may expose a mutation known to be unsafe under duplicate submission. |

§15's "open" column is superseded by this table.

## 17. SWC.1 — Navigation Shell & Product Landing Surfaces — COMPLETE (2026-10-14)

**Baseline.** `origin/main` = `28ec8bce` (the SWC.0 publication), green
integration and `main` `CI gate`, ruleset 24909660 active with no bypass
actor, drift check `ok`.

### 17.1 Previous architecture (replaced)

- **No shell.** `AccountLayout.vue` rendered only an account bar. All
  navigation was one flat list in `Dashboard.vue`.
- **Flags.** `DashboardController` sent about 39 `nav.*` booleans, plus
  `platform.*` and `groups.*` maps, each a `canInSchool`/`canPlatform` check.
- **Back-links.** 66 pages hard-coded a `← Dashboard` back-link.
- **Unlinked.** The nine modules in §2.3 had working, capability-gated pages
  and no link.

### 17.2 The catalogue

- **Entries.** `App\Support\Navigation\StaffNavigation` is the one
  catalogue (§6.1). Each entry is a `NavigationEntry`: key, group, label,
  route name, scope (`school`/`platform`/`group`/`account`), an exact /
  any-of / all-of requirement on EXISTING capability keys, an optional
  section, active-route patterns, an optional availability guard, and
  `platformHostOnly`.
- **Builder.** `NavigationBuilder::forRequest()` evaluates it:
  - School entries use the active School from `TenantContext`, through one
    `CapabilityResolver::schoolCapabilities()` read (its existing
    School-keyed cache; no new cache key);
  - platform entries use `platformCapabilities()`;
  - the Group entry uses `groupsWith()` (uncached, rule 84);
  - Guardian-portal entries also need `PortalAvailability::isAvailable()`
    and a live ActingGuardian.
- **Prop.** `HandleInertiaRequests` shares the result as `navigation`
  through a closure. It is evaluated when the response is built, after the
  School resolvers and `school-context` have run. It is `null` for guests.
  The shared `product` prop carries the product name.
- **Active state.** Decided on the server from the current route name. The
  most specific matching pattern wins; an exact route name beats a
  wildcard. Examples: Leave approvals rather than Leave; Grade scales keeps
  Examinations active.
- **Empty groups** are never sent.

### 17.3 Groups and entries (65 entries)

**Order:** Guardian portal · My work · School setup · Academics · People ·
Finance · Operations (sections: Transport, Hostel, Library, Inventory,
Canteen, Visitors) · Communications · Reports · Administration · School
Groups · Platform. The two account entries (Account security; API tokens,
platform host only) render in the account bar.

Every requirement equals the capability the destination page itself checks:
- **Exact keys:** e.g. `attendance.view`, `examinations.definitions.view`,
  `hostel.directory.view`, `library.catalogue.view`, `transport.routes.view`,
  `visitor.directory.view`, `syllabus.view`, `curriculum.delivery.view`,
  `lms.content.view`, `lms.assignments.view` (the nine previously unlinked
  modules).
- **Any-of** for the three hubs: Finance, HR and Payroll.
- **All-of** for Enrollment rollovers.

`NavigationDestinationsRenderTest` proves the requirement against the page:
an actor holding exactly that requirement, and nothing else, renders every
School, platform and account destination. For My class attendance, API
clients and the platform audit log, current MFA assurance is supplied
(rule 95).

### 17.4 Context behaviour (§6.2)

- **No active School:** no School entry.
- **Elevated session:** no School entry, even if a School were somehow in
  `TenantContext` (the builder checks `ElevationContext` itself).
- **School custom host:** no platform, Group or API-token entry. The
  Dashboard's "Enter a School (elevated access)" offer is also hidden there,
  because elevation is a platform-host flow (ADR 0054 §8.5).
- **School switch:** recomputed on the next response for the new School
  only. School A's entries never appear, and never authorise, in School B.

### 17.5 Shell, responsive behaviour and accessibility

**Shell.** `AccountLayout.vue` is the shared shell. For a signed-in request
it renders:
- a skip link to `#main-content`;
- the account bar: product home link, active School name, account
  `<nav aria-label="Account">`, Log out;
- the elevation banner;
- a side navigation with separate `<nav>` landmarks labelled Home, Guardian
  portal, School and Platform.

`Components/Navigation/NavigationGroupList.vue` renders groups:
- group labels are text, not headings, so each page's `<h1>` stays first;
- each list is named with `aria-labelledby`;
- sections nest;
- the active entry carries `aria-current="page"`.

**Responsive.**
- **From `md` up:** the navigation is persistent.
- **Below `md`:** a "Menu" `<button>` controls it (`aria-controls`,
  `aria-expanded`). Closed, it is `hidden`, so no link is focusable.
- **Closing:** Escape (a document-level listener) closes it and returns
  focus to the button; following a link closes it.

**Lint decision (§8.2).** `eslint-plugin-vuejs-accessibility` 2.6.0 is
adopted, with its `globals` peer 17.12.0. Both are pinned exactly and both
releases are more than two weeks old (2.6.1/2.6.2 and globals 17.13.0 were
newer).
- **Fit:** it supports ESLint 10, installs with `ignore-scripts`, and adds no
  `npm audit` finding. The one moderate advisory, `postcss-selector-parser`,
  already exists on the baseline lock.
- **Scope:** across the whole frontend it reported 800 existing findings in
  162 files, so it is scoped to the SWC files (`SWC_ACCESSIBILITY_FILES` in
  `eslint.config.js`; later slices add theirs). It runs in the existing
  `npm run lint` CI step.
- **Configuration:** `label-has-for` accepts a nested OR an id-associated
  label. Inertia's link component is imported as `InertiaLink` in these
  files, because the plugin reads a `<Link>` tag as HTML `<link>`.
- **Static test:** `NavigationAccessibilityBaselineTest` pins the shell's
  landmarks, disclosure state, skip link and lint scope.
- **No claim:** no WCAG claim is made.

### 17.6 Public `/` and Dashboard

**`/`.**
- `HomeController` (thin) replaces `SystemStatusController`, and
  `Pages/Home.vue` replaces `SystemStatus.vue`. The web route is now named
  `home`; it previously shared the name `system.status` with the API status
  route.
- **Guests** see the product name, one neutral sentence and "Sign in" on
  every host. The page no longer exposes the environment, the request id,
  phase or module-inventory text. The `X-Request-Id` header stays.
- **Signed-in visitors** are redirected to `/app`.
- CLAUDE.md rule 3 and ADR 0005 point to `HomeController`.

**Dashboard.**
- **Heading:** the product name, with the active School name beneath it.
  "School OS — Phase 0B" and "Phase 0B primitive" are gone.
- **Kept:** School selection, the notices and the elevation states (the
  elevated copy no longer says "yet").
- **Overview:** the shared navigation, rendered as a grouped overview.
- **Empty state:** "No modules are available to your account in this
  School."
- **Removed:** the controller's `nav`, `platform` and `groups` props.

### 17.7 Product name (X4)

**Decision:** the name is `App\Support\ProductIdentity::NAME = 'Lycenza'`.
It appears on:
- the shared `product` prop;
- the browser title (`resources/js/support/product.ts`, pinned equal by
  `ProductIdentityTest`);
- `app.blade.php`.

**Why not the configuration value.** `config('app.name')` was deliberately
not used. `APP_NAME` also derives the session cookie name and the cache and
Redis prefixes, so a branding change there would sign everyone out and
orphan cached state. Email keeps its own `email.from_name` (default
"Lycenza"). No single existing setting serves both without changing email
semantics, so configuration normalisation is deferred.

**Deferred "School OS" strings** (outside SWC.1's surfaces):
- the account-link copy on Students/Guardians pages;
- Conversations and Channels copy;
- Automation and API tokens copy;
- the invitation acceptance page and the Guardian invitation email
  (subject and body);
- four exception messages and two validation messages.

They are catalogued for SWC.5.

### 17.8 Flag migration (no silent loss)

Every former `nav.*` / `platform.*` / `groups.*` flag maps to a catalogue
entry with the same capability rule:

| Former flag | Entry key |
|---|---|
| `canViewSchoolSettings` | `school-settings` |
| `canViewSchoolSetup` | `school-setup` |
| `canViewStudents` / `canViewGuardians` | `students` / `guardians` |
| `canViewEnrollments` / `canViewEnrollmentRollovers` | `enrollments` / `enrollment-rollovers` |
| `canViewAdmissions`, `canViewFinance`, `canViewHr`, `canViewPayroll` | `admissions`, `finance`, `hr`, `payroll` |
| `canViewSubjectOfferings` | `subject-offerings` |
| `canViewCommunications` | `communications` |
| `canViewCanteenDirectory` | `canteen-outlets` + `canteen-items` |
| `canViewCanteenOrders` / `canViewCanteenSettings` | `canteen-orders` / `canteen-settings` |
| `canViewTimetablePeriods` / `canViewTimetableSchedule` | `timetable-periods` / `timetable-schedule` |
| `canViewTeachingAssignments` | `teaching-assignments` |
| `canViewLeave` / `canUseLeaveApprovals` / `canViewStaffAttendance` | `leave` / `leave-approvals` / `staff-attendance` |
| `canUseMy*` (7 flags) | `my-leave`, `my-staff-attendance`, `my-payslips`, `my-curriculum-delivery`, `my-attendance`, `my-learning-content`, `my-assignments` |
| `canViewAnalytics` / `canViewAuditLog` / `canViewAutomation` | `analytics` / `audit-log` / `automation` |
| `canViewApiClients` / `canViewDomains` / `canViewStaffAccounts` | `api-clients` / `domains` / `staff-accounts` |
| `canViewGuardianPortal` | `portal-communications` + `portal-conversations` |
| `canViewGuardianAttendance` / `canViewGuardianFees` | `portal-attendance` / `portal-fees` |
| `canManagePlatformSchools`, `platform.canManageSchools` | `platform-schools` |
| `platform.canViewAuditLog` / `platform.canGovernRoles` | `platform-audit-log` / `platform-roles` |
| `groups.canViewOwn` / `groups.canGovern` | `school-groups` / `platform-groups` |
| `canManageSchoolSettings` | **retired**: never rendered; the Settings page decides manage rights itself |

**Test migration:**
- 26 test files were migrated to `self::nav()` / `navOffers()` /
  `navExactly()` (`Tests\Concerns\AssertsNavigation`).
- `MyAttendanceUiTest`'s exact teacher set is now
  `navExactly(['my-assignments', 'my-attendance', 'my-curriculum-delivery',
  'my-learning-content'])`.
- The navigation source is now the catalogue alone. No legacy booleans and
  no role checks remain.

### 17.9 Security review

| # | Finding | Disposition |
|---|---|---|
| S-1 | Navigation reads one School's capabilities (the active one) and is rebuilt per request; it never serialises another School's entries. | Verified (School-switch test) |
| S-2 | Unauthorised destinations are not serialised: entries are filtered server-side before the prop is built. | Verified |
| S-3 | Elevated sessions receive no School entry; School hosts receive no platform/Group/API-token entry. | Verified (both tests) |
| S-4 | Anonymous `/` no longer exposes `APP_ENV` or the request id in the page. | Fixed |
| S-5 | No role name is consulted. A test scans the catalogue values and the navigation classes' source for every seeded role key. | Verified |
| S-6 | No capability is added, granted or widened. Seeder changes are comments only. | Verified |
| S-7 | Before SWC.1 the account bar offered "API tokens" on School custom hosts, where the route is never served (fixed 404). | Fixed (platform-host-only entry) |
| F-1 | The Finance, HR and Payroll hub pages render for any active School member. They show no data, only links to the sub-areas the actor holds, and every sub-page checks its own capability. Navigation never offers them without the hub's any-of requirement. | Pre-existing; not changed in a navigation slice; recorded for the SWC.5 authorization review |

### 17.10 Deferred drift corrected here (§12.2)

- `app/Domain/README.md`: "no business modules" marked historical.
- `CapabilityAndRoleSeeder` payroll comments, as dated comment notes: the
  `payroll_officer` sensitive grants; 9.6 implemented with
  `payroll.statutory.manage` still held by no role; payslips built.
- `ElevatedSchoolRouteDenialTest.php:88` comment.

### 17.11 Verification

- **Targeted:**
  - `tests/Feature/Navigation` (catalogue integrity, visibility/context,
    destinations, accessibility);
  - `HomePageTest`;
  - `ProductIdentityTest`;
  - `DashboardLandingTest`;
  - the 26 migrated files.
- **Broad set:** the App UI, Portal, Platform, Tenancy, Custom domains,
  Auth, Compliance, Automation, Navigation and Identity suites.
- **Gates:** Pint, Larastan, `vue-tsc`, ESLint (with the scoped
  accessibility rules), Prettier and the production build.
- **Canonical regression:** the candidate's sharded exact-SHA `CI gate`
  (ADR 0072 §28.8, §31).
- **DDEV smoke** (`ddev demo-reset --build`, HTTP-level; no browser
  automation exists in the repository):
  - **`/` (guest):** 200 `Home`; props `auth`, `elevation`, `errors`,
    `navigation` (null), `product` only; no phase, module, environment or
    request-id text.
  - **Personas:** School Admin, Principal, Multi-school Admin, Teacher,
    Librarian, Hostel Warden, Guardian and Platform Admin each received
    exactly their capability-derived groups.
  - **Destinations:** every advertised destination returned 200 with its own
    entry marked active, except the MFA-assured pages. My class attendance,
    API clients, the Guardian Attendance/Fees/Conversations pages and the
    platform audit log render the existing `App/Platform/MfaRequired` page
    (403) for demo accounts without an enrolled factor (rule 95; documented
    persona limitation).
- **Counts:** recorded in the SWC.1 commit.

**Not in SWC.1:** none of SWC.2 (Academic Structure screens, Elective
Groups) and none of SWC.3 (OPF screens). No new destination was invented.

## 18. SWC.2 — Academic Structure Web Surfaces — COMPLETE (2026-10-14)

**Baseline.** `origin/main` = `beb56c29` (SWC.1), with green integration and
`main` `CI gate`, ruleset 24909660 active with no bypass actor, and the
drift check `ok`.

**Scope delivered.** Browser-complete administration of every existing
Academic Structure contract:
- new screens: Terms, Sections, Academic departments, Rooms;
- Campus, Grade Level and Subject gain edit and deactivate/reactivate;
- Academic Years gain rename;
- Subject Offerings gain create, settings and deactivate;
- Elective Groups (X1) gain create, assign and remove.

**Unchanged:** no migration, no capability, no email or provider behaviour.

### 18.1 Contract audit (verified before coding)

| Entity | Authority before SWC.2 | Capability (view / manage) | Deactivation | Idempotency (API) |
|---|---|---|---|---|
| Campus | inline in `Api\V1\CampusController` | `school.campuses.view` / `.manage` | `status`, reversible; no reference block | `idempotent` on store |
| Academic Year | `AcademicYearService` create/activate/close; rename inline | `academics.years.view` / `.manage` | none: draft → active → closed, no reopen | `idempotent` on store, activate, close |
| Academic Term | `AcademicTermService::create`; rename inline | `academics.years.*` | none (no status column) | none |
| Grade Level | inline | `academics.structure.*` | `status`, reversible | none |
| Section | inline | `academics.structure.*` | `status`, reversible | `idempotent` on store |
| Academic Department | inline (no transaction) | `academics.structure.*` | `status`, reversible | none |
| Room | inline (no transaction) | `academics.structure.*` | `status`, reversible | none |
| Subject | inline | `academics.subjects.*` | `status`, reversible | none |
| Subject Offering | create inline; update via `SubjectOfferingService` (ADR 0069 freeze) | `academics.subjects.*` | `status`, reversible | `idempotent` on store |
| Elective Group | `ElectiveGroupService` (authorization-neutral) | prescribed `academics.subjects.manage` | none (no rename, delete or status in the contract) | — (no HTTP surface) |

**Deactivation.** No database rule blocks deactivating a referenced row.
Deactivation is a reversible `status` flag. Inactive rows stay listed and
historical references are untouched.
- **Web:** pickers for new Sections, Offerings and Elective Groups offer
  active parents only.
- **API:** the API, and the services, accept an inactive parent exactly as
  before.
- **No new rule:** SWC added no "inactive parent" refusal, because none
  exists in the contract.

### 18.2 Shared Application services (§4.2)

**One implementation per write.** The `/api/v1` controllers and the web
controllers call the same service:

| Entity | Shared service |
|---|---|
| Campus | `Domain\Campuses\Application\CampusAdministrationService` |
| Grade Level | `GradeLevelAdministrationService` |
| Subject | `SubjectAdministrationService` |
| Academic Department | `AcademicDepartmentAdministrationService` |
| Room | `RoomAdministrationService` |
| Section | `SectionAdministrationService` |
| Academic Year | `AcademicYearService::createFromInput()` / `rename()` |
| Academic Term | `AcademicTermService::createFromInput()` / `rename()` |
| Subject Offering | `SubjectOfferingService::create()` / `updateFromInput()` |

**Shared concern** (`Concerns\AdministersReferenceData`):
- re-checks the capability for the acting User (both controllers check
  first, so the API's authorize-before-validate order is unchanged);
- validates raw input after normalizing `code` with the one
  `App\Support\CodeNormalizer` (both existing traits now delegate to it);
- `writeUnique()` translates a unique violation that raced past validation
  into the same field error.

**What is preserved:**
- validation rules, messages, status codes, response bodies, audit actions
  and metadata, and domain events;
- the tenant-scoped lookups (another School's row or parent is the same 404).

**What changed:**
- **New transactions:** Room and Department creates, and every update, now
  run inside a transaction with their audit row (atomic, not observable).
- **API defects fixed** (each pinned by `AcademicStructureSharedServiceApiTest`):
  - (a) a duplicate Section code on `PATCH` reached the constraint as a
    500; it is now a 422 on `code`.
  - (b) a duplicate Subject Offering on `POST` was a 500; it is now a 422
    on `subject_id`.
- **Architecture guard:** `SubjectOfferingClassificationArchitectureGuardTest`
  now pins `SubjectOfferingService` as the ONLY writer of a classification,
  stricter than before. Both the API and web update paths must call
  `updateFromInput()`.

### 18.3 Web defects fixed (§2.5)

**Duplicate-code 500s.**
- **Root cause:** Phase 0D's web `storeCampus`, `storeGradeLevel`,
  `storeSubject` and `storeAcademicYear` uppercased the code by hand but ran
  no `Rule::unique()`. A case-variant duplicate reached the stored-form
  unique index as an unhandled `UniqueConstraintViolationException` (500).
- **Fix:** they now go through the shared services and return a field error
  on `code` (and `sequence` for Grade Levels), with no write.
- **Tests:** `SchoolSetupStructureUiTest` reproduces the database failure
  directly and then proves the form error.

**Academic Year conflicts.**
- **Root cause:** `AcademicStructureException` has a status and error code,
  but `bootstrap/app.php` renders that envelope for `api/*` only. On a web
  request the overlap, stale-transition and concurrent-activation refusals
  became 500s.
- **Fix:** `MapsDomainRefusals` (the house pattern of
  `StudentSubjectEnrollmentController`, generalized) turns them into field
  errors: `starts_on` for create, `academic_year` for activate/close.
- **Unchanged:** the refusal itself and its no-write guarantee.

### 18.4 Screens

**New pages:**
- `SchoolSetup/{Terms, Sections, Departments, Rooms}`;
- `ElectiveGroups/{Index, Show}`.

**Rewritten pages:** `SchoolSetup/{Campuses, AcademicYears, GradeLevels, Subjects}`.

**Patterns:**
- tables with `<caption>` and `<th scope>`;
- an edit panel that takes focus;
- deactivate and Year activate/close behind `ConfirmDialog` (native modal
  `<dialog>`), replacing `window.confirm`;
- shared `Form/{TextField, SelectField, CheckboxField, ErrorSummary}`, with
  errors associated through `aria-describedby`/`aria-invalid`.

**Subject Offerings:** the large existing pages gained one component each
(`SubjectOfferings/CreateOfferingForm`, `OfferingSettingsForm`). Their
existing content was not rewritten.

**Facts the screens follow:**
- **Terms:** rename only after creation, because dates and order are
  create-time invariants.
- **Sections:** the Year, Campus and Grade are fixed.
- **Classification lock:** the settings form says the lock exists. The
  server returns it as an `is_required` error on a direct request too
  (`SubjectOfferingAdminUiTest`).

### 18.5 Elective Groups (X1)

**Routes:** `GET|POST /app/elective-groups`, `GET /app/elective-groups/{id}`,
`POST …/{id}/offerings`, `DELETE …/{id}/offerings/{offering}`.

**Authorization:** view `academics.subjects.view`; manage
`academics.subjects.manage`.

**Rules come from the service:**
- no required Offering;
- no other (Year, Campus, Grade) context;
- no change once Students participate;
- duplicate code per context;
- assigning an already-assigned Offering, or removing an unassigned one, is
  a no-op.

**Cross-School:** another School's group, Offering or parent is a 404.

**Candidates:** the elective Offerings of the same context, active only in
the picker.

**Contract note (reported, not changed).** The brief said "do not allow an
inactive Offering". `ElectiveGroupService` has no such rule, and adding one
would be new elective semantics (§4.1). *(2026-10-14: superseded by the
owner decision recorded in §19.1 -- the server now refuses it.)*
- **Web:** the picker offers active Offerings only.
- **Server:** the service accepts an inactive one exactly as before.
- **Next step:** a server-side refusal needs an owner decision.

**Not in the contract:** rename, delete and deactivate, so none were added.

### 18.6 Capabilities, navigation, idempotency

**Capabilities:** existing keys only (§18.1); new capabilities: 0.

**Navigation:** the School setup group gains Campuses, Academic years,
Terms, Grade levels, Sections, Academic departments, Rooms, Subjects and
Elective groups, each under the capability its page checks. That makes
74 catalogue entries. `NavigationDestinationsRenderTest` proves each renders
for an exact-requirement holder.

**Duplicate submissions (rule 29; no `idempotent` web middleware, the Phase
9.9 precedent):**
- **Creates:** protected by School-scoped unique codes (Offering: unique per
  Year/Campus/Grade/Subject); a repeat is a field error and writes no second
  row.
- **Updates and deactivations:** set values; a repeat converges.
- **Year activate/close:** state transitions; a stale repeat is a field
  error.
- **Elective assign/remove:** idempotent in the service.

### 18.7 Tests and verification

**Tests:**
- **`AcademicStructureSharedServiceApiTest`** (API compatibility and the two
  API corrections): PATCH shapes and audit actions for all eight entities;
  unchanged validation, status and authorization; the race-translation
  mechanism against a real unique violation; a source guard that every
  shared write uses `writeUnique()`; cross-School 404s.
- **`SchoolSetupStructureUiTest`** (14): the four defect regressions, the
  Year transitions, edit/deactivate/reactivate, the new screens, viewer
  denial, missing School context, cross-School parents and rows,
  client-supplied `school_id` ignored, and list pages with no query per row.
- **`SubjectOfferingAdminUiTest`:** create, duplicate, the lock on a direct
  request, deactivate, viewer and cross-School.
- **`ElectiveGroupUiTest`:** create, list, show, assign and remove, the rules,
  viewer and cross-School, and the elective workspace on the configured
  group.

**Race test limitation:** the two-process race was not run. A competing
session cannot see the test transaction's School, so its insert would fail
the foreign key first. The mechanism is proven against a real violation,
and the source guard pins its use.

**Attendance identity guard.** `SubjectOfferingIdentityGuardTest` is
strengthened, not weakened. The Offering update path takes an explicit
allow-list (`SubjectOfferingService::MUTABLE_FIELDS`) in both controllers,
and the service validates and writes only those fields. An identity column
(Year, Campus, Grade, Subject) can never be updated, which Attendance's
subject provenance relies on.

**DDEV workflow proof** (`ddev demo-reset --build`, School Admin, session web
routes only, no personal API token). Every step passed:
1. School setup reachable from navigation (11 entries);
2. Academic department created, code normalized;
3. a case-variant duplicate is a field error, with no second row;
4. a new Academic year created;
5. an overlapping year is refused as a form error (no 500, no row);
6. a Term created;
7. a Section created;
8. a Room created;
9. two elective Subject Offerings created;
10. Offering settings saved;
11. an Elective group created;
12. both Offerings assigned;
13. the elective-enrollment workspace shows the group;
14. the navigation marks it active.

**Proof limitation:** HTTP/Inertia level. No browser automation exists in
the repository, so the rendered layout was not visually reviewed.

**Verification:** the counts are recorded in the SWC.2 commit.

**Not in SWC.2:** none of SWC.3 (OPF screens); no portal, marks or results
work; no Documents or webhook UI.

## 19. SWC.3 — Operational Fee Web Surfaces — COMPLETE (2026-10-14)

**Baseline.** `origin/main` = `2c8e902c` (SWC.2), with green integration and
`main` `CI gate`, ruleset 24909660 active with no bypass actor, and the
drift check `ok`.

**Owner decisions (frozen at SWC.3 authorisation):**
- **X2, a School-wide Library fine list:** OUT. No fine query was added; a
  fine is seen on its loan.
- **X3, production refusal for E30–E34:** DEFERRED TO PRE-PRODUCTION. No
  refusal logic was added or removed.
- **X5, duplicate-submission protection:** REQUIRED before browser exposure
  (§19.3, §19.4).
- **Elective Group inactive Offering:** the server refuses a NEW assignment
  (§19.1).

**Unchanged:** no migration, no capability, no email or provider work, and
no fee, formula, proration, deposit, refund, tax, waiver or payment
semantics.

### 19.1 Elective Group: an inactive Offering is never newly assigned

**The rule.** `ElectiveGroupService::assignOffering()` refuses an Offering
whose locked row is not `active`, with
`InactiveSubjectOfferingGroupAssignmentException` (422,
`INACTIVE_SUBJECT_OFFERING_GROUP_ASSIGNMENT`). It is checked after the
existing no-op (already in this group) and before the participation-history
rule.

**Unchanged behaviour:**
- an Offering already in a group stays in it when later deactivated, and
  stays listed with its status;
- re-submitting that existing membership is still a no-op;
- removal follows the unchanged participation-history rule.

**Tests:** `ElectiveGroupUiTest` covers the refusal on a direct request and
through the service.

### 19.2 Contract audit (verified before coding)

| Operation | Authority (existing) | API | Capability | Protection |
|---|---|---|---|---|
| Route fee head set/clear | `TransportFeeSelectionService::setRouteFeeHead` | `PUT …/transport-routes/{r}/fee-head` | `transport.routes.manage` (view `.routes.view`) | route row lock; one mapping per route; audit only on change |
| Transport fee intent | `TransportFeeSelection` rows (read) | `GET …/transport-student-assignments/{a}/fee-selections` | `transport.assignments.view` | read |
| Transport carry-forward | `TransportFeeSelectionService::carryForward` | `POST …/transport-fee-selections/carry-forward` (`idempotent`) | `transport.assignments.manage` | per-assignment row lock + existing-link check; `transport_fee_selections_one_per_year`; `fee_optional_selections_one_active` |
| Hostel / room fee head | `HostelFeeSelectionService::setHostelFeeHead/setRoomFeeHead` | `PUT …/hostels/{h}/fee-head`, `…/hostel-rooms/{r}/fee-head` | `hostel.directory.manage` | row lock; room override, else Hostel default (OPF.2 precedence) |
| Hostel carry-forward | `HostelFeeSelectionService::carryForward` | `POST …/hostel-fee-selections/carry-forward` (`idempotent`) | `hostel.residency.manage` | as Transport; `hostel_fee_selections_one_per_year` |
| Admission fee head | `AdmissionFeeSelectionService::setFeeHead` | `PUT …/admission-fee-head` | `admissions.manage` (view `admissions.view`) | one School mapping; no per-conversion choice (D7) |
| Conversion fee intent | `AdmissionFeeSelection` row (read) | `GET …/admission-applications/{a}/fee-selection` | `admissions.view` | read |
| Fine policy current / versions | `LibraryFinePolicyService::current/versions` | `GET …/library-fine-policy[/versions]` | `library.fines.view` | read; versions immutable |
| Fine policy publish | `LibraryFinePolicyService::publish` | `POST …/library-fine-policy/versions` (`idempotent`) | `library.fines.manage` | per-School advisory lock; `library_fine_policies_one_version` |
| Loan fine | `LibraryFine` row (read) | `GET …/library-loans/{l}/fine` | `library.fines.view` | read |
| Fine void | `LibraryFineService::void` | `POST …/library-fines/{f}/void` (`idempotent`) | `library.fines.void` | loan row lock; `library_fine_voids_one_per_fine`; already-voided refusal; cancels the charge (refused if paid or under concession) |

**Findings:**
- **No extraction was needed:** the OPF API controllers are already thin over
  these services.
- **New read seam:** one small Fees read class,
  `App\Domain\Fees\Application\Sources\FeeHeadDirectory::forSchool()`,
  returning id, code, name and active. It lets other modules label and offer
  fee heads without reading a Fees table (rule 4). It is deliberately not a
  method of `FeeSourceSelectionService`: that seam's caller allow-list (ADR
  0067 §8, `OperationalFeeSourceArchitectureGuardTest`) stays exactly the
  three OPF services. A label read is not a selection.
- **Exceptions:** each module's domain exception carries a status and code.
  The SWC.2 trait is generalized to `MapsDomainRefusals` (renamed from
  `MapsAcademicStructureErrors`): 4xx → field error, 404 → 404.

### 19.3 X5: carry-forward needed no change

**Already safe by construction.**
- **Concurrency:** each assignment or residency is row-locked in its own
  transaction, and an existing link for that year is skipped.
- **Unique indexes:** `*_fee_selections_one_per_year` refuses a second link,
  and `fee_optional_selections_one_active` refuses a second selection. A lost
  race is caught and reported as `already_linked`.
- **Effect of a repeat:** a repeated or concurrent run records no second
  link, selection or charge. It only appends one more summary audit row
  (`*.fee_selection.carried_forward`), which is not a financial effect.

**Proof (`OperationalFeeUiTest`):** a double submit gives linked 1, then
already-linked 1. Exactly one link and one selection exist per student-year,
and no charge.

**Limitation:** a two-process race was not re-run. The OPF.1/OPF.2
`*ConcurrencyTest` suites already prove it with real processes.

### 19.4 X5: fine-policy publish needed the smallest guard

**Finding.** Every publish appended version n+1 under a per-School advisory
lock. Concurrency was already safe: `UNIQUE (school_id, version)` means one
current policy. But a double-clicked form added a duplicate version, and a
stale form silently superseded a newer one.

**Fix.** `publish()` takes an optional `expectedCurrentVersion` (0 = none
yet), checked under the same advisory lock. A mismatch throws
`LibraryFinePolicyChangedException` (409, `LIBRARY_FINE_POLICY_CHANGED`).
- **Web:** the form always sends the version it showed, as
  `expected_version`.
- **API:** it does not send one, so its behaviour is unchanged. It keeps its
  `idempotent` replay protection.

**Proof (`LibraryFineUiTest`):** a repeated publish is a form error with no
new version; a stale one (after an API publish) is refused; a correctly
based one succeeds. Versions run 3, 2, 1, with one audit row each.

**Unchanged:** no migration.

### 19.5 Screens

**Transport:**
- **Route page:** a "Transport fee head" panel to set, show and clear it
  (`PUT /app/transport/routes/{id}/fee-head`).
- **Fees page:** `GET /app/transport/fees` (navigation Operations → Transport
  → Fees) shows fee intent per active assignment and per year (head, reason,
  result, date). Carry-forward is offered for OPEN years only, with a
  confirmation and a result summary.

**Hostel:**
- **Hostel page:** the Hostel's default fee head.
- **Room page:** the room override, the Hostel default, and which governs
  ("New residencies record: …").
- **Fees page:** `GET /app/hostel-residency/fees` shows fee intent with its
  mapping scope, plus carry-forward.

**Admissions:**
- **Fee head page:** `GET|PUT /app/admissions/fee-head`, linked from the
  Admissions index.
- **Application page:** shows the School head and, once converted, the
  intent the conversion recorded. There is no fee field in the conversion
  form, and a test pins that.

**Library:**
- **Fine policy page:** `GET|POST /app/library/fine-policy` (navigation
  Operations → Library → Fine policy) shows the current version, the full
  history and the publish form.
- **Loan page:** `GET /app/library/circulation/{loan}`, linked from each
  Circulation row. It shows the loan and, with `library.fines.view`, its
  fine: the stored amount, days, policy version, status and void reason.
  With `library.fines.void`, a reasoned void sits behind a confirmation.
- **Check-in:** a check-in that assessed a fine now lands on that loan's
  page. Without a fine it returns to the list as before.

**Copy everywhere:** fee intent is "not a charge or a payment — Finance
creates charges when it assesses fees". Waivers stay on Finance →
Concessions.

**Implementation:**
- **Components:** `Components/Fees/{FeeHeadPanel, RoomFeeHeadPanel,
  AdmissionFeePanel, FeeIntentTable, CarryForwardForm}` and
  `support/feeIntent.ts` (labels only).
- **Existing pages:** each gained one component or one link.
- **Read side:** one panel presenter, `OperationalFeePanels`.
- **Write side:** one web controller, `OperationalFeeController`, plus
  `LibraryFineWebController`.

### 19.6 Money, tenancy, capabilities, gates

**Money.** Amounts are the stored `NUMERIC` strings, never cast or computed
in PHP or Vue. A test pins a fine's displayed amount to the stored value and
to its charge amount. The rate and cap are entered as strings and validated
by the service.

**Tenancy.** Every page and mutation uses TenantContext:
- another School's route, hostel, room, application, loan or fine is a 404;
- another School's fee head is the service's "not selectable" field error;
- a client-supplied School id is never read.

**Capabilities:** existing keys only (§19.2); new capabilities: 0.
**Navigation:** 77 catalogue entries.

**E30–E34:** browser availability is not production approval.
- Transport, Hostel and Admission fee intent stay gated by E21 and E30–E32,
  and Library fines by E34, exactly as in ADR 0058 and ADR 0067 §22.
- No production refusal was added or removed (X3); Pre-Production decides.

### 19.7 Tests and verification

**New tests:**
- `OperationalFeeUiTest` (7): mappings, precedence, intent, idempotent
  carry-forward with no charge, refusals, capabilities, cross-School, no
  conversion fee choice, and no query per row on the fee pages.
- `LibraryFineUiTest` (4): policy, the X5 publish guard, the check-in →
  loan page, stored amounts, void, repeat-void and capabilities;
  cross-School 404s; and no School-wide fine route.
- An `ElectiveGroupUiTest` addition (§19.1).

**Verification:** the counts are recorded in the SWC.3 commit.

**DDEV proof (`ddev demo-reset --build`, web routes only, School Admin):**
- **Setup:** the demo seeds no fee heads or fee structures. Two heads, and
  per-grade 2026-27 structures with an optional Transport line, were created
  through the existing Finance → Fee setup pages.
- **Admission fee head:** set; an unknown head is a form error.
- **Transport:** route fee head set. Carry-forward into the open draft year
  2027-28 reports "not applicable" ×8 (no structure there). Into 2026-27 it
  links 8; the repeat links 0 and reports 8 already linked. The Fees page
  then shows 8 intent rows (`carry_forward`, `created`).
- **Hostel:** default → room override → cleared, with precedence shown each
  time. Repeat carry-forward links nothing.
- **Fine policy:** v1 published (rate 5.00, cap 100.00 INR). The
  double-submitted form is refused, leaving one version. A Librarian sees it
  read-only and gets 403 on publish.
- **Loan page:** renders.
- **Limitation:** an overdue fine cannot be produced in the demo without
  moving the clock (a checkout's due date is never before its checkout), so
  fine display and void are proven by `LibraryFineUiTest`, not by DDEV.

**CI-exposed test defect (fixed).** The new tests shifted the
deterministic shard boundaries, and the first `CI gate` run failed shard 2.
- **Cause:** `StaffRoleGrantorRaceTest` cleaned up by deleting every User
  created since one second before it started. In the new layout it runs about
  a second after `PayrollIdempotencyRealConcurrencyTest`, which deliberately
  leaves a committed payroll run (and its preparer) behind. The sweep hit
  that User's foreign key.
- **Fix:** the race test now deletes only the Users it created. No product
  code changed.
- **Proof:** shard 2/4 re-run locally, 2068 cases green.

**Deferred to SWC.5:** a returned-loan lookup. An older returned loan's
fine is reachable from the check-in redirect and the loan URL, but no list
shows returned loans. That would be a loan list, not a fine register; the
decision is left to SWC.5.
