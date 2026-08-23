# School OS — Domain / Bounded-Context Map

Status: as of Phase 0D, **Platform**, **Identity & Access**, **Tenancy**,
**Schools**, **Campuses**, and **Academic Structure** (Layer 0-1) are
implemented — see `docs/modules/ORGANIZATION.md` and
`docs/modules/ACADEMIC-STRUCTURE.md`. As of Phase 1A/1A.2/1A.3/1A.4,
**Students/SIS** and **Guardians** (Layer 2) are partially implemented:
permanent identity, the Student<->Guardian relationship, Guardian
contact information (with a searchable-encrypted-PII architecture, ADR
0028), and `students.*`/`guardians.*` authorization capabilities plus
their Application-layer mutation services — no addresses, API, or UI
yet — see
`docs/modules/STUDENT-GUARDIAN-IDENTITY.md`. Every other module below
remains unimplemented. This is the ownership and dependency map future
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
                            Hostel · Health · Visitor/Safety · Payroll
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
| **Students/SIS** | Student master record, enrollment status, academic history | Academic Structure, Schools, Campuses | **Partially implemented (Phase 1A/1A.4)**: permanent identity (`App\Domain\Students\Infrastructure\Student`) plus its supported mutation service (`App\Domain\Students\Application\StudentService`) and `students.view`/`students.manage` capabilities — no enrollment/academic-history state yet, see `docs/modules/STUDENT-GUARDIAN-IDENTITY.md`. The record most other modules eventually reference; does not depend on any module that references it. |
| **Guardians** | Guardian/parent records, guardian-student relationships | Students/SIS | **Partially implemented (Phase 1A/1A.2/1A.3/1A.4)**: identity (`App\Domain\Guardians\Infrastructure\Guardian`), the Student<->Guardian relationship (`StudentGuardianRelationship`) and its mutation service (`StudentGuardianRelationshipService`), Guardian contact information (`GuardianContact`, encrypted at rest with a keyed exact-match lookup digest, ADR 0028), and `guardians.view`/`guardians.manage` capabilities — no addresses yet, see `docs/modules/STUDENT-GUARDIAN-IDENTITY.md`. |
| **Admissions** | Admission leads, applications, admission workflow → produces a Student record via Students/SIS's Application contract | Academic Structure, Schools, Students/SIS | Admissions calls into SIS to create a student; SIS never calls into Admissions. |
| **HR** | Employee master record, roles/designations, employment lifecycle | Schools, Identity & Access | Independent of the Students track except where a specific Layer 3 module needs both (e.g. Academics needs teachers). |

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
| **Transport** | Routes, vehicles, driver assignment, student transport mapping | Students/SIS, HR | |
| **Library** | Catalogue, circulation | Students/SIS, HR | |
| **Inventory** | Stock, procurement, costing | Finance, HR | |
| **Canteen** | Menus, orders, billing | Students/SIS, Fees, Inventory | |
| **Hostel** | Room allocation, hostel fee linkage | Students/SIS, Fees | |
| **Health** | Health records, incident logs | Students/SIS, Guardians | One of the highest-sensitivity data owners — see `docs/security/DATA-CLASSIFICATION.md`. |
| **Visitor/Safety** | Visitor logs, gate passes, safety incidents | Schools, Campuses, HR, Students/SIS | |
| **Payroll** | Salary structures, payroll runs, statutory deductions | HR, Finance | India-specific statutory compliance (PF/ESI/TDS, etc.) is a Layer 5 (Compliance) concern layered on top, not owned here — see the legal-review flag in `docs/security/DATA-CLASSIFICATION.md`. |

## Layer 4 — Cross-cutting

| Module | Owns | Depends on | Notes |
|---|---|---|---|
| **Documents** | File/document records and access control (ADR 0012) | Identity & Access, Tenancy | Depended on by any Layer 2–3 module that attaches files; itself depends on nothing above Layer 0. |
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
