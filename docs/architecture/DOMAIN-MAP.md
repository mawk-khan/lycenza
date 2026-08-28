# School OS — Domain / Bounded-Context Map

Status: as of Phase 0D, **Platform**, **Identity & Access**, **Tenancy**,
**Schools**, **Campuses**, and **Academic Structure** (Layer 0-1) are
implemented — see `docs/modules/ORGANIZATION.md` and
`docs/modules/ACADEMIC-STRUCTURE.md`. As of Phase 1A-1C (updated at the
Phase 1D.0 Admissions architecture checkpoint — this paragraph was
previously stale), **Students/SIS** and **Guardians** (Layer 2) are
implemented through: permanent identity, the Student<->Guardian
relationship, Guardian contact information (with a searchable-
encrypted-PII architecture, ADR 0028), time-varying academic placement
and rollover (`StudentEnrollment`, complete through API/UI), Student
Subject Enrollment (`StudentSubjectEnrollment`, required + elective
placement), `students.*`/`guardians.*`/`enrollments.*`/
`academics.subjects.*` authorization capabilities, their Application-
layer mutation services, an authenticated `/api/v1` administrative
surface, and a session-authenticated Vue/Inertia UI — no Student/
Guardian addresses yet — see
`docs/modules/STUDENT-GUARDIAN-IDENTITY.md`,
`docs/modules/STUDENT-ENROLLMENT.md`, and
`docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md`. As
of Phase 1D.0, **Admissions** (Layer 2) has an accepted architecture
(`docs/modules/ADMISSIONS.md`) but no implementation yet. Every other
module below remains unimplemented. This is the ownership and dependency map future
implementation must follow — see `apps/platform/app/Domain/README.md`
for the concrete code-layout convention (Academic Structure is the
first module to actually use its `Domain/Application/Infrastructure/Http`
layout), and ADR 0001 for why this is one modular monolith rather than
separate services.

## How to read this document

Modules are grouped into layers. **A module may depend on modules in a
lower or equal-numbered layer, never a higher one** — this is the
"dependency direction" the root `CLAUDE.md` rule ("no bidirectional
module coupling") refers to. A dependency means: calling another
module's Application-layer service or subscribing to its domain events
(ADR 0010) — **never** reading another module's Eloquent models or
tables directly (ADR 0002, and the convention in
`app/Domain/README.md`).

Layers 5 and 6 (oversight/aggregation and external-facing) are
**consumers only** — they may read from any lower-layer module (via
explicit read contracts or domain events), but no module in Layers 0–4
may depend on them. This is what keeps Compliance, Analytics,
Automation, Integrations, and the AI Platform from becoming a hidden
requirement for core ERP function.

```
Layer 0  Foundation        Platform · Identity & Access · Tenancy
Layer 1  Org structure     Schools · Campuses · Academic Structure
Layer 2  People             Students/SIS · Guardians · Admissions · HR
Layer 3  Core operations    Finance · Fees · Payments · Academics ·
                            Attendance · Timetable · Examinations · LMS ·
                            Transport · Library · Inventory · Canteen ·
                            Hostel · Health · Visitor · Safety · Payroll
Layer 4  Cross-cutting      Documents · Communications
Layer 5  Oversight          Compliance · Analytics · Automation
Layer 6  External-facing    Integrations · AI Platform · Multi-School
                            Management
```

## Layer 0 — Foundation

| Module | Owns | Depends on | Notes |
|---|---|---|---|
| **Platform** | Shared kernel: request lifecycle, audit primitives (ADR 0017), domain-event infrastructure (ADR 0010), config | — | Not a business module; every other module depends on it. |
| **Identity & Access** | User accounts (all actor categories, see `docs/security/AUTHORIZATION.md`), authentication, capability/permission grants | Platform | No module may implement its own authentication or ad hoc permission checks — all authorization goes through this module's policies/capabilities. |
| **Tenancy** | Tenant (School) resolution and isolation mechanics (ADR 0004): RLS session context, tenant-aware queue/cache/log plumbing | Platform | Distinct from the "Schools" *business* module below — this module is pure isolation infrastructure. |

## Layer 1 — Organizational structure

| Module | Owns | Depends on | Notes |
|---|---|---|---|
| **Schools** | School profile, settings, branding, academic-year calendar | Platform, Identity & Access, Tenancy | **Implemented (Phase 0D)**: profile fields on `App\Models\School` + `App\Http\Controllers\Api\V1\SchoolProfileController`. The tenant's business-facing identity, as opposed to Tenancy's isolation mechanics. |
| **Campuses** | Campus records within a School (ADR 0004: sub-tenant dimension, not a separate isolation boundary) | Schools | **Implemented (Phase 0D)**: `App\Models\Campus` (Phase 0B) + `App\Http\Controllers\Api\V1\CampusController`. |
| **Academic Structure** | Grades/standards, sections, subjects, academic-year/term taxonomy | Schools, Campuses | **Implemented (Phase 0D)**: `app/Domain/AcademicStructure/*` — AcademicYear, AcademicTerm, GradeLevel, Section, Subject, AcademicDepartment, Room, SubjectOffering, plus the platform-level EducationBoard catalog. See `docs/modules/ACADEMIC-STRUCTURE.md`. Reference data most Layer 2–3 modules depend on; itself has almost no dependencies, deliberately. |

## Layer 2 — People

| Module | Owns | Depends on | Notes |
|---|---|---|---|
| **Students/SIS** | Student master record, enrollment status, academic history | Academic Structure, Schools, Campuses | **Implemented through Phase 1C** (updated at the Phase 1D.0 Admissions architecture checkpoint — this row was previously stale): permanent identity (`App\Domain\Students\Infrastructure\Student`) plus its supported mutation service (`App\Domain\Students\Application\StudentService`) and `students.view`/`students.manage` capabilities (see `docs/modules/STUDENT-GUARDIAN-IDENTITY.md`); time-varying academic placement (`App\Domain\Students\Infrastructure\StudentEnrollment`) referencing Academic Structure (AcademicYear/Campus/GradeLevel/Section) via composite FKs, with `App\Domain\Students\Application\StudentEnrollmentService` as the sole sanctioned write path (creation + terminal lifecycle transitions: complete/withdraw/cancel/same-year transfer), `App\Domain\Students\Application\StudentEnrollmentReadService` as the canonical read layer, `enrollments.view`/`enrollments.manage` capabilities, and both an authenticated `/api/v1` administrative surface and a session-authenticated Vue/Inertia UI (Phase 1B.5/1B.6); cross-Academic-Year rollover/promotion is now complete end-to-end (dry-run, execution, bulk/resumable execution, API, and UI — `EnrollmentRolloverPlan`/`EnrollmentRolloverMapping`/`EnrollmentRolloverItem`, Phase 1B.7-1B.7F), with only queue-backed execution still deferred; Student Subject Enrollment (required-derived + explicit-elective placement, `App\Domain\Students\Infrastructure\StudentSubjectEnrollment`, `App\Domain\Students\Application\SubjectOfferingRosterReadService`) is also complete (Phase 1C, plus the 1C.1A inactive-offering correction) — see `docs/modules/STUDENT-ENROLLMENT.md` and `docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md`. The record most other modules eventually reference; does not depend on any module that references it. |
| **Guardians** | Guardian/parent records, guardian-student relationships | Students/SIS | **Partially implemented (Phase 1A/1A.2/1A.3/1A.4)**: identity (`App\Domain\Guardians\Infrastructure\Guardian`), the Student<->Guardian relationship (`StudentGuardianRelationship`) and its mutation service (`StudentGuardianRelationshipService`), Guardian contact information (`GuardianContact`, encrypted at rest with a keyed exact-match lookup digest, ADR 0028), and `guardians.view`/`guardians.manage` capabilities — no addresses yet, see `docs/modules/STUDENT-GUARDIAN-IDENTITY.md`. |
| **Admissions** | Admission leads, applications, admission workflow → produces a Student record via Students/SIS's Application contract | Academic Structure, Schools, Students/SIS | Admissions calls into SIS to create a student; SIS never calls into Admissions. |
| **HR** | Employee master record, roles/designations, employment lifecycle | Schools, Identity & Access | Independent of the Students track except where a specific Layer 3 module needs both (e.g. Academics needs teachers). **In progress (Phase 8A, resequenced ahead of `MASTER-ROADMAP.md`'s Phase 0J — see ADR 0028)**: `docs/modules/HR.md`. |

## Layer 3 — Core operations

| Module | Owns | Depends on | Notes |
|---|---|---|---|
| **Finance** | Core ledger, chart of accounts, financial-correctness primitives (`docs/architecture/ARCHITECTURE.md` §10) | Schools, Identity & Access | Foundational for Fees and Payroll; itself has no dependency on either. |
| **Fees** | Fee structures, invoices, dues | Students/SIS, Academic Structure, Finance | |
| **Payments** | Payment records, gateway reconciliation (ADR 0018) | Fees, Finance | Owns the inbound-webhook idempotency requirement from ADR 0018. |
| **Academics** | Curriculum delivery, lesson planning, syllabus tracking | Academic Structure, Students/SIS, HR | |
| **Timetable** | Class/period scheduling | Academic Structure, HR | |
| **Attendance** | Student and staff attendance records | Students/SIS, HR, Academic Structure, Timetable | |
| **Examinations** | Exam scheduling, grading, report cards | Academic Structure, Students/SIS, Academics | |
| **LMS** | Learning content, assignments, submissions | Academic Structure, Students/SIS, HR | |
| **Transport** | Routes, ordered Stops, vehicles, driver assignment, student transport mapping | Students/SIS, HR, Campuses | **Implemented (Phase 10B)**: Routes + ordered Stops (`App\Domain\Transport\Infrastructure\{TransportRoute,TransportStop}`), Vehicles (`TransportVehicle`), the historical Route↔Vehicle↔Driver operational assignment (`TransportRouteAssignment`, `App\Domain\Transport\Application\TransportRouteAssignmentService`, auto-replace semantics) referencing HR's `Employee` by id (never a duplicated driver identity), and Student Transport assignment (`TransportStudentAssignment`, `App\Domain\Transport\Application\TransportStudentAssignmentService`, explicit-end-required semantics) with a database-enforced Stop-belongs-to-Route composite FK and one-active-per-Student invariant, `transport.routes.*`/`transport.vehicles.*`/`transport.assignments.*` capabilities, `/api/v1` administrative surface, and a session-authenticated Inertia UI. See `docs/modules/TRANSPORT.md`. GPS/live-tracking, bus boarding/attendance, Transport fees, Documents integration, and Guardian/Student-facing views remain deliberately deferred — not part of this closure. |
| **Library** | Catalogue, circulation | Students/SIS, HR | **Implemented (Phase 10A)**: bibliographic Titles + physical Copies (`App\Domain\Library\Infrastructure\{LibraryTitle,LibraryCopy}`), circulation/Loan lifecycle with a database-enforced single-active-loan-per-Copy invariant (`App\Domain\Library\Application\LibraryLoanService`), `library.catalogue.*`/`library.circulation.*` capabilities, `/api/v1` administrative surface, and a session-authenticated Inertia UI. See `docs/modules/LIBRARY.md`. Fines/reservations/renewals, Documents integration, and Guardian/Student-facing views remain deliberately deferred — not part of this closure. |
| **Inventory** | Stock, procurement, costing | Finance, HR | |
| **Canteen** | Menus, orders, billing | Students/SIS, Fees, Inventory | |
| **Hostel** | Hostel/Room/Bed structure, Student residency assignment | Students/SIS, Campuses | **Implemented (Phase 10D)**: Hostel directory belonging to exactly one Campus (`App\Domain\Hostel\Infrastructure\Hostel`), HostelRoom (`HostelRoom`, capacity always derived from active Bed rows, never stored), HostelBed (`HostelBed`, occupancy always derived from its active residency assignment, never a stored occupant column), and Student residency assignment (`HostelResidencyAssignment`, `App\Domain\Hostel\Application\HostelResidencyService`, explicit-end-required semantics) with database-enforced composite FKs at every level (all RESTRICT — see `docs/modules/HOSTEL.md` §17) and two database-enforced one-active-per-Bed/one-active-per-Student invariants proven under real concurrency with a documented deterministic Student-then-Bed lock order, `hostel.directory.*`/`hostel.residency.*` capabilities, `/api/v1` administrative surface, and a session-authenticated Inertia UI. See `docs/modules/HOSTEL.md`. **`HostelRoom` is a deliberately independent model, not a reuse of Academic Structure's `Room`** — that model is a teaching-space concept (`room_type: classroom|laboratory|auditorium|library|sports|other`) unrelated to residential capacity/occupancy; the two share no relationship. Hostel fee linkage, warden/staff management, meal plans, and Guardian/Student-facing views remain deliberately deferred — not part of this closure; a future Fees module would integrate by referencing `hostel_residency_assignments` by id, never by adding financial columns to Hostel's own tables. |
| **Health** | Health records, incident logs | Students/SIS, Guardians | One of the highest-sensitivity data owners — see `docs/security/DATA-CLASSIFICATION.md`. |
| **Visitor** | Visitor directory, check-in/check-out Visit lifecycle | Schools, Campuses, HR | **Implemented (Phase 10C)**: Visitor directory reference records (`App\Domain\Visitor\Infrastructure\Visitor`), check-in/check-out Visit lifecycle (`VisitorVisit`, `App\Domain\Visitor\Application\VisitorVisitService`, explicit-checkout-required semantics) against a Campus with an optional HR `Employee` host (referenced by id, never duplicated), a database-enforced one-active-Visit-per-Visitor invariant proven under real concurrency, `visitor.directory.*`/`visitor.visits.*` capabilities, `/api/v1` administrative surface, and a session-authenticated Inertia UI. See `docs/modules/VISITOR.md`. Government-ID/biometric/photo capture, blocklist/watchlist/risk-scoring, public kiosk/self-registration, billing, and Guardian/Student-facing views remain deliberately deferred — not part of this closure. |
| **Safety** | Safety/incident reports, gate passes | Schools, Campuses, HR, Students/SIS | **Not started.** Deliberately kept separate from Visitor (Phase 10C) rather than combined as originally sketched — "incident records" may fall under Health's own unresolved `[LEGAL REVIEW REQUIRED]` gate (`docs/security/DATA-CLASSIFICATION.md`), so Safety requires its own legal/security readiness decision before implementation, ideally alongside Health's. |
| **Payroll** | Salary structures, payroll runs, statutory deductions | HR, Finance | India-specific statutory compliance (PF/ESI/TDS, etc.) is a Layer 5 (Compliance) concern layered on top, not owned here — see the legal-review flag in `docs/security/DATA-CLASSIFICATION.md`. |

## Layer 4 — Cross-cutting

| Module | Owns | Depends on | Notes |
|---|---|---|---|
| **Documents** | File/document records and access control (ADR 0012) | Identity & Access, Tenancy | Depended on by any Layer 2–3 module that attaches files; itself depends on nothing above Layer 0. **Implemented (Phase 0E.1–0E.7)**: schema, write path (`App\Domain\Documents\Application\DocumentService`), authorized read/streamed content (`DocumentReadService`), owner-scoped listing (`DocumentListingService`), and HTTP/API transport (`DocumentController`, six routes) are all complete — generic Documents infrastructure is closed. Production owner activation is **Employee-only**; Student/Guardian owner columns exist structurally (composite FKs, exclusive-arc CHECK) but have no activated capability/API surface — a deliberate, separate future decision per owner type, not a gap in this closure. `employee_documents` (HR, Phase 8A.7) remains a permanently separate, HR-specific metadata table per ADR 0029 — never merged into `documents`. See `docs/modules/DOCUMENTS.md`. |
| **Communications** | Message templates, delivery (SMS/email/WhatsApp/push), delivery logs | Identity & Access, Guardians, Students/SIS, HR | Depended on by most Layer 3 modules for notifications; must not depend back on them — it receives *what* to send via events/explicit calls, not by reaching into their tables. |

## Layer 5 — Oversight (read-mostly; consumers, never dependencies)

| Module | Owns | Depends on | Notes |
|---|---|---|---|
| **Compliance** | Regulatory reporting, statutory record-keeping | Reads from any Layer 1–4 module via explicit read contracts/events | Must remain a consumer — no Layer 1–4 module may require Compliance to function. |
| **Analytics** | Cross-module reporting, dashboards | Reads from any Layer 0–4 module | Same rule as Compliance. |
| **Automation** | Rules/workflow engine reacting to domain events (ADR 0010) | Subscribes to events from any module; calls back only through modules' Application-layer contracts | Automation acting on a module is indistinguishable, from that module's perspective, from any other authorized caller — it does not get a special bypass. |

## Layer 6 — External-facing

| Module | Owns | Depends on | Notes |
|---|---|---|---|
| **Integrations** | Third-party adapters (payment gateways, SMS/WhatsApp providers, government/board systems) — ADR 0018 | Calls into the owning module's Infrastructure-layer adapter (e.g. Payments' gateway adapter), never bypasses it | |
| **AI Platform** | AI Gateway, agents, tools (ADR 0013, 0014) — lives in `services/ai`, outside the Laravel monolith entirely | Calls Laravel only through explicitly exposed AI-tool contracts | The one module that is architecturally a separate service, not a Laravel module. |
| **Multi-School Management** | Group/Trust entities, cross-school administration, group-level reporting | Schools, Identity & Access (for the explicit elevated-access grants ADR 0004 describes) | Sits "above" the tenant boundary, not inside it. |

## Rules this map enforces

1. **No bidirectional coupling.** If Module A appears in Module B's
   "Depends on" column, Module B must never appear in Module A's.
   Reviewers should check this table when approving a PR that adds a
   cross-module call.
2. **Layers 5 and 6 are never a dependency of Layers 0–4.** Core ERP
   function must work with Compliance, Analytics, Automation,
   Integrations, and the AI Platform entirely unavailable.
3. **Cross-module reads/writes go through the target module's
   Application layer or domain events — never its Eloquent models.**
   This is what makes the dependency arrows above meaningful; without
   it, "Module A depends on Module B" would be true whether or not the
   table says so.
4. This table is a living document — as modules are actually
   implemented, this file should be updated in the same PR as the first
   commit that adds a dependency not yet reflected here.
