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
| **Academic Structure** | Grades/standards, sections, subjects, academic-year/term taxonomy | Schools, Campuses | **Implemented (Phase 0D)**: `app/Domain/AcademicStructure/*` — AcademicYear, AcademicTerm, GradeLevel, Section, Subject, AcademicDepartment, Room, SubjectOffering, plus the platform-level EducationBoard catalog. **Phase 1F.1 adds `ElectiveGroup`** (schema/model foundation only — no configuration service yet): a named group of mutually-exclusive `SubjectOffering`s within one AcademicYear/Campus/GradeLevel context, owned here exactly like every other Academic Structure entity; Students/SIS enforces the resulting participation exclusivity (see `docs/students/PHASE-1F-0-ELECTIVE-MUTUAL-EXCLUSIVITY-ARCHITECTURE.md`). See `docs/modules/ACADEMIC-STRUCTURE.md`. Reference data most Layer 2–3 modules depend on; itself has almost no dependencies, deliberately. |

## Layer 2 — People

| Module | Owns | Depends on | Notes |
|---|---|---|---|
| **Students/SIS** | Student master record, enrollment status, academic history | Academic Structure, Schools, Campuses | **Implemented through Phase 1C** (updated at the Phase 1D.0 Admissions architecture checkpoint — this row was previously stale): permanent identity (`App\Domain\Students\Infrastructure\Student`) plus its supported mutation service (`App\Domain\Students\Application\StudentService`) and `students.view`/`students.manage` capabilities (see `docs/modules/STUDENT-GUARDIAN-IDENTITY.md`); time-varying academic placement (`App\Domain\Students\Infrastructure\StudentEnrollment`) referencing Academic Structure (AcademicYear/Campus/GradeLevel/Section) via composite FKs, with `App\Domain\Students\Application\StudentEnrollmentService` as the sole sanctioned write path (creation + terminal lifecycle transitions: complete/withdraw/cancel/same-year transfer), `App\Domain\Students\Application\StudentEnrollmentReadService` as the canonical read layer, `enrollments.view`/`enrollments.manage` capabilities, and both an authenticated `/api/v1` administrative surface and a session-authenticated Vue/Inertia UI (Phase 1B.5/1B.6); cross-Academic-Year rollover/promotion is now complete end-to-end (dry-run, execution, bulk/resumable execution, API, and UI — `EnrollmentRolloverPlan`/`EnrollmentRolloverMapping`/`EnrollmentRolloverItem`, Phase 1B.7-1B.7F), with only queue-backed execution still deferred; Student Subject Enrollment (required-derived + explicit-elective placement, `App\Domain\Students\Infrastructure\StudentSubjectEnrollment`, `App\Domain\Students\Application\SubjectOfferingRosterReadService`) is also complete (Phase 1C, plus the 1C.1A inactive-offering correction) — see `docs/modules/STUDENT-ENROLLMENT.md` and `docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md`. The record most other modules eventually reference; does not depend on any module that references it. |
| **Guardians** | Guardian/parent records, guardian-student relationships | Students/SIS | **Partially implemented (Phase 1A/1A.2/1A.3/1A.4)**: identity (`App\Domain\Guardians\Infrastructure\Guardian`), the Student<->Guardian relationship (`StudentGuardianRelationship`) and its mutation service (`StudentGuardianRelationshipService`), Guardian contact information (`GuardianContact`, encrypted at rest with a keyed exact-match lookup digest, ADR 0028), and `guardians.view`/`guardians.manage` capabilities — no addresses yet, see `docs/modules/STUDENT-GUARDIAN-IDENTITY.md`. |
| **Admissions** | Admission leads, applications, admission workflow → produces a Student record via Students/SIS's Application contract | Academic Structure, Schools, Students/SIS | Admissions calls into SIS to create a student; SIS never calls into Admissions. |
| **HR** | Employee master record, roles/designations, employment lifecycle | Schools, Identity & Access | Independent of the Students track except where a specific Layer 3 module needs both (e.g. Academics needs teachers). **Implemented (Phase 8A.0–8A.16, resequenced ahead of `MASTER-ROADMAP.md`'s Phase 0J — see ADR 0028; closure correction complete)**: `docs/modules/HR.md` — Employee/EmploymentRecord/EmployeeAssignment lifecycle (including rehire and reporting hierarchy), Department/Position/EmployeeCategory reference data, personal details/addresses/emergency contacts/qualifications/experience/certifications/notes, employee document metadata, the full administrative Inertia UI and JSON API mutation transport, Employee Import, and all 10 domain events. The domain may receive further extensions over time. Payroll, Attendance, Leave, and Recruitment remain separately-scoped `MASTER-ROADMAP.md` Phase 0J work and are **not** implied complete by this entry — not part of this closure. |

## Layer 3 — Core operations

| Module | Owns | Depends on | Notes |
|---|---|---|---|
| **Finance** | Core ledger, chart of accounts, financial-correctness primitives (`docs/architecture/ARCHITECTURE.md` §10) | Schools, Identity & Access | Foundational for Fees, Payments, and Payroll; itself has no dependency on any of them. **Foundation implemented (Phase 0G complete; future Finance/Fees/Payments extensions not yet started)**: true double-entry ledger (ADR 0030, `docs/modules/FINANCE.md`) — the persistence kernel (`ledger_accounts`/`journal_entries`/`journal_lines`, RLS-protected, no float), `LedgerService` (post/reverse/reverseById, Application-layer validation, audit + outbox), `finance.ledger.view`/`.post`/`.reverse` capability authorization (`LedgerAdministrationService` wrapping `LedgerService`, `LedgerReadService` for accounts/journal history/detail), thin HTTP transport (`App\Domain\Finance\Http\Controllers\LedgerAccountController`/`JournalEntryController`, `docs/modules/FINANCE.md` "0G.6 as-built") calling only the authorized facade/read service, never `LedgerService` directly, and a session-authenticated Inertia UI (`App\Http\Controllers\App\Finance\LedgerAccountController`/`JournalEntryController`, "0G.7 as-built") calling the SAME facades — all exist; still no dependency on Fees/Payments/Payroll/Students/SIS — Fees and Payments (below) call Finance's Application layer, never the reverse. |
| **Fees** | Fee structures, invoices, dues | Students/SIS, Academic Structure, Finance | **Foundation implemented (Phase 0G complete; future Finance/Fees/Payments extensions not yet started)**: `App\Domain\Fees` (`docs/modules/FINANCE.md` "0G.4/0G.5/0G.6/0G.7 as-built") — `charges` (the receivable obligation entity; no `invoices`/fee-definition entity yet), `ChargeService` (assess/cancel/lockChargeForAllocation, posts through Finance's `LedgerService`, never writes `journal_entries`/`journal_lines` directly), `ChargeAdministrationService`/`ChargeReadService` (`finance.charges.view`/`.manage` capability authorization), thin HTTP transport (`App\Domain\Fees\Http\Controllers\ChargeController`), and a session-authenticated Inertia UI (`App\Http\Controllers\App\Finance\ChargeController`) — both calling only the authorized facade/read service. Neither transport layer reads Students/SIS', Academic Structure's, or Finance's Eloquent models for BUSINESS logic (same-School membership is proved by `charges`' own composite foreign keys); the Inertia UI controller does read `Student`/`AcademicYear` directly, read-only, purely to resolve a display name for its pages — the same established cross-module read-for-display pattern `CommunicationAudienceSearchController` already uses, not an Application-layer dependency. `lockChargeForAllocation()` (0G.5) is the ONE sanctioned way the Payments module (below, which depends on Fees) may observe/lock a Charge — a typed `ChargeAllocationSnapshot`, never the raw model; Fees' Application layer itself gained no new dependency (still Students/SIS, Academic Structure, Finance only). |
| **Payments** | Payment records, provider-event idempotency, payment-to-charge allocation (ADR 0018) | Fees, Finance | **Foundation implemented (Phase 0G complete; future Finance/Fees/Payments extensions not yet started)**: `App\Domain\Payments` (ADR 0031, `docs/modules/FINANCE.md` "0G.5/0G.6/0G.7 as-built") — `payment_provider_events` (durable inbound-callback idempotency, `(school_id, provider, provider_event_id)` unique — the inbound analog of `webhook_deliveries`, never the same table), `payments` (immutable settlement fact, created only at settlement), `payment_allocations` (immutable payment-to-charge join, structural composite FK against Fees' `charges(id, school_id)`). `PaymentProviderEventService::recordSettlement()` posts through Finance's `LedgerService::post()` and calls Fees' `ChargeService::lockChargeForAllocation()` — never reads `charges`/`journal_entries`/`ledger_accounts` directly. A trigger owned by this module's own migration, physically attached to Fees' `charges` table (`charges_payment_allocation_guard_trigger`), is the structural mechanism that blocks cancelling an allocated Charge — the same "structural DB constraint crossing a module boundary" pattern the composite FK above already uses, not a reverse Fees→Payments Application-layer dependency. Owns the inbound-webhook idempotency requirement from ADR 0018. `finance.payments.view` only (no `.manage` — provider ingestion is a trusted system boundary); read-only HTTP transport (`App\Domain\Payments\Http\Controllers\PaymentController`) and a read-only session-authenticated Inertia UI (`App\Http\Controllers\App\Finance\PaymentController`) both exist, each calling only `PaymentReadService` — no human Payment mutation route exists anywhere, in either transport. No signature verification/provider adapter, refunds, or provider-callback HTTP. |
| **Academics** | Curriculum delivery, lesson planning, syllabus tracking | Academic Structure, Students/SIS, HR | |
| **Timetable** | Class/period scheduling | Academic Structure, HR | |
| **Attendance** | Student and staff attendance records | Students/SIS, HR, Academic Structure, Timetable | |
| **Examinations** | Exam scheduling, grading, report cards | Academic Structure, Students/SIS, Academics | |
| **LMS** | Learning content, assignments, submissions | Academic Structure, Students/SIS, HR | |
| **Transport** | Routes, ordered Stops, vehicles, driver assignment, student transport mapping | Students/SIS, HR, Campuses | **Implemented (Phase 10B)**: Routes + ordered Stops (`App\Domain\Transport\Infrastructure\{TransportRoute,TransportStop}`), Vehicles (`TransportVehicle`), the historical Route↔Vehicle↔Driver operational assignment (`TransportRouteAssignment`, `App\Domain\Transport\Application\TransportRouteAssignmentService`, auto-replace semantics) referencing HR's `Employee` by id (never a duplicated driver identity), and Student Transport assignment (`TransportStudentAssignment`, `App\Domain\Transport\Application\TransportStudentAssignmentService`, explicit-end-required semantics) with a database-enforced Stop-belongs-to-Route composite FK and one-active-per-Student invariant, `transport.routes.*`/`transport.vehicles.*`/`transport.assignments.*` capabilities, `/api/v1` administrative surface, and a session-authenticated Inertia UI. See `docs/modules/TRANSPORT.md`. GPS/live-tracking, bus boarding/attendance, Transport fees, Documents integration, and Guardian/Student-facing views remain deliberately deferred — not part of this closure. |
| **Library** | Catalogue, circulation | Students/SIS, HR | **Implemented (Phase 10A)**: bibliographic Titles + physical Copies (`App\Domain\Library\Infrastructure\{LibraryTitle,LibraryCopy}`), circulation/Loan lifecycle with a database-enforced single-active-loan-per-Copy invariant (`App\Domain\Library\Application\LibraryLoanService`), `library.catalogue.*`/`library.circulation.*` capabilities, `/api/v1` administrative surface, and a session-authenticated Inertia UI. See `docs/modules/LIBRARY.md`. Fines/reservations/renewals, Documents integration, and Guardian/Student-facing views remain deliberately deferred — not part of this closure. |
| **Inventory** | Item catalogue, Location directory, quantity stock lifecycle (receive/issue/transfer) | Campuses | **Foundation implemented (Phase 10E complete; procurement/costing/asset-custody extensions not yet started)**: `App\Domain\Inventory` (`docs/modules/INVENTORY.md`) — `InventoryItem`/`InventoryLocation` (reference catalogue, Location's Campus optional), `InventoryStockBalance` (the AUTHORITATIVE current-quantity resource, one row per Item x Location, mathematically reconstructible from `stock_movements` — proven never to drift), `StockMovement` (immutable append-only receipt/issue/transfer ledger), `App\Domain\Inventory\Application\InventoryStockService` (the sole stock writer — receive/issue/transfer, a concurrency-safe missing-balance-row primitive, a database-enforced non-negative-stock invariant, and a deterministic ascending-balance-id transfer lock order, all proven under real two-process concurrency), `inventory.directory.*`/`inventory.stock.*` capability authorization, `/api/v1` administrative surface (command-style receive/issue/transfer, never a generic movement-creation endpoint), and a session-authenticated Inertia UI — all exist; still no dependency on Finance/Fees/Payments/HR/Students in either direction. This row's earlier sketch ("Stock, procurement, costing \| Finance, HR") predates Phase 10E and was narrower/broader in the wrong places — procurement and costing are explicitly NOT implemented (see `docs/modules/INVENTORY.md` §12, §25 for the deferred Finance-costing seam and procurement boundary), and HR is not a current dependency (custody/Employee association is deferred, `docs/modules/INVENTORY.md` §14). Individually tracked assets, custody, procurement, and costing remain deliberately deferred — not part of this closure. Phase 10F (Canteen, row below) added one small, additive, backward-compatible extension: `InventoryStockService::issueMany()`, a fourth public method (receive/issue/transfer are unchanged) issuing stock for MULTIPLE Items against ONE Location as a single atomic, deadlock-safe operation (deterministic ascending-balance-id lock order generalizing `transfer()`'s own two-balance pattern to N balances) — this is Canteen's only Inventory dependency; Inventory itself gained no dependency on Canteen. See `docs/modules/CANTEEN.md` §5 and `docs/modules/INVENTORY.md` §5.1. |
| **Canteen** | Outlet directory, menu Item catalogue + recipe, Student order lifecycle (place/fulfill/cancel), billing configuration | Students/SIS, Fees, Inventory | **Foundation implemented (Phase 10F complete)**: `App\Domain\Canteen` (`docs/modules/CANTEEN.md`) — `CanteenOutlet` (backed by exactly one InventoryLocation, immutable after creation), `CanteenItem` (menu catalogue, current price), `CanteenItemInventoryRequirement` (the recipe, evaluated AT FULFILLMENT time, never snapshotted at placement), `CanteenOrder`/`CanteenOrderLine` (price/location snapshotted at placement, immutable after — no update/delete route exists), `CanteenBillingConfiguration` (School-wide singleton naming two validated `ledger_accounts`), `CanteenOrderStockConsumption` (links a fulfillment to the `stock_movements` rows it caused). `App\Domain\Canteen\Application\CanteenOrderService::fulfill()` is the cross-domain orchestration boundary — calls `InventoryStockService::issueMany()` (§below) and `ChargeService::assess()` directly with no internal capability re-check, inside one outer transaction, four concurrency scenarios proven under real two-process concurrency. Reads `Student`/`LedgerAccount` directly, read-only, for eligibility checks and the settings UI's account picker — never a write to either sibling module's tables. `canteen.directory.*`/`canteen.orders.*`/`canteen.settings.*` capability authorization, `/api/v1` administrative surface, and a session-authenticated Inertia UI — all exist; still no dependency on HR/Payments in either direction, and neither Students/SIS, Fees, nor Inventory has gained any dependency on Canteen. Wallets/prepaid balances, dietary/allergen/medical data, refunds/financial reversal, and Guardian/Student-facing ordering all remain deliberately deferred — not part of this closure, see `docs/modules/CANTEEN.md` §16. |
| **Hostel** | Hostel/Room/Bed structure, Student residency assignment | Students/SIS, Campuses | **Implemented (Phase 10D)**: Hostel directory belonging to exactly one Campus (`App\Domain\Hostel\Infrastructure\Hostel`), HostelRoom (`HostelRoom`, capacity always derived from active Bed rows, never stored), HostelBed (`HostelBed`, occupancy always derived from its active residency assignment, never a stored occupant column), and Student residency assignment (`HostelResidencyAssignment`, `App\Domain\Hostel\Application\HostelResidencyService`, explicit-end-required semantics) with database-enforced composite FKs at every level (all RESTRICT — see `docs/modules/HOSTEL.md` §17) and two database-enforced one-active-per-Bed/one-active-per-Student invariants proven under real concurrency with a documented deterministic Student-then-Bed lock order, `hostel.directory.*`/`hostel.residency.*` capabilities, `/api/v1` administrative surface, and a session-authenticated Inertia UI. See `docs/modules/HOSTEL.md`. **`HostelRoom` is a deliberately independent model, not a reuse of Academic Structure's `Room`** — that model is a teaching-space concept (`room_type: classroom|laboratory|auditorium|library|sports|other`) unrelated to residential capacity/occupancy; the two share no relationship. Hostel fee linkage, warden/staff management, meal plans, and Guardian/Student-facing views remain deliberately deferred — not part of this closure; a future Fees module would integrate by referencing `hostel_residency_assignments` by id, never by adding financial columns to Hostel's own tables. |
| **Health** | Health records, incident logs | Students/SIS, Guardians | **Not implemented.** Blocked on the `docs/security/DATA-CLASSIFICATION.md` [LEGAL REVIEW REQUIRED] gate for Health data (medical conditions, allergies, incident records) — one of the highest-sensitivity data owners. See `docs/modules/PHASE-10-CLOSURE.md` for the deferred-scope record and exact reopening criteria. |
| **Visitor** | Visitor directory, check-in/check-out Visit lifecycle | Schools, Campuses, HR | **Implemented (Phase 10C)**: Visitor directory reference records (`App\Domain\Visitor\Infrastructure\Visitor`), check-in/check-out Visit lifecycle (`VisitorVisit`, `App\Domain\Visitor\Application\VisitorVisitService`, explicit-checkout-required semantics) against a Campus with an optional HR `Employee` host (referenced by id, never duplicated), a database-enforced one-active-Visit-per-Visitor invariant proven under real concurrency, `visitor.directory.*`/`visitor.visits.*` capabilities, `/api/v1` administrative surface, and a session-authenticated Inertia UI. See `docs/modules/VISITOR.md`. Government-ID/biometric/photo capture, blocklist/watchlist/risk-scoring, public kiosk/self-registration, billing, and Guardian/Student-facing views remain deliberately deferred — not part of this closure. |
| **Safety** | Safety/incident reports, gate passes | Schools, Campuses, HR, Students/SIS | **Not implemented.** Deliberately kept separate from Visitor (Phase 10C) rather than combined as originally sketched — "incident records" may fall under Health's own unresolved `[LEGAL REVIEW REQUIRED]` gate (`docs/security/DATA-CLASSIFICATION.md`), so Safety requires its own dedicated legal/security readiness decision before implementation, ideally alongside Health's — no such decision exists yet. See `docs/modules/PHASE-10-CLOSURE.md` for the deferred-scope record and exact reopening criteria. |
| **Payroll** | Salary structures, payroll runs, statutory deductions | HR, Finance | **In progress (Phase 9, ADR 0032)** — not implied complete by this entry. Resolves this row's own prior tension: Payroll owns and calculates statutory deductions (PF/ESI/TDS) itself, gated on the `[LEGAL REVIEW REQUIRED]` flag in `docs/security/DATA-CLASSIFICATION.md` — a future Layer 5 Compliance module consumes Payroll's already-calculated results read-only for regulatory reporting, but never gates or is required by Payroll's own calculation (Layer 5 modules are read-mostly consumers by this document's own definition, so Payroll cannot functionally depend on one for a core output). See `docs/modules/PAYROLL.md`. |

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
