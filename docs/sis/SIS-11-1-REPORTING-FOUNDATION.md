# SIS 11.1 — Unified SIS Reporting Foundation & Operational Read Models

Status: **IMPLEMENTED (local DDEV)** · 2026-10-30 · branch `integration/sis-11-1`

This checkpoint adds to the Student Information System. It does **not** reopen
the core SIS result (96/100, `docs/product/SIS-FINAL-LOCAL-UAT-2026-10-28.md`)
or the closed SIS expansion programme (`SIS-EXPANSION-CLOSURE.md`).

## 1. Baseline reconciliation (owner decision, 2026-10-30)

The brief named `main = b80785bb` as its baseline. `main` had already moved 29
commits to `c5e3dd23` (the SIS.EXP programme: SIS.EXP-0…5, R1, HARDEN-1,
CLOSE). That programme had used the same 318-screen pack (42 families,
`SIS-REFERENCE-CAPABILITY-MAP.md`) and had already built the Student
directory filters (EXP-1), the movement and exit register (EXP-2), documents,
certificates and import. The owner chose to **re-scope SIS 11.1 onto
`c5e3dd23`**. The new work is a closed report catalogue, a Reports hub and
reusable filter, column and sort contracts. Existing report pages are
registered in the catalogue rather than rebuilt, and one new report covers
what they did not: the lifecycle event list. Decisions closed by SIS.EXP stay
closed (no column picker over personal fields, no contact report, no
fee-gated promotion).

The unpublished `integration/hr-exp-0` branch (HR.EXP-0 draft audit) was left
untouched.

## 2. Report inventory from the reference pack

The inventory is at **family level**, taken from the committed reference map
(written from the pack in SIS.EXP-0). It was not re-read screen by screen in
this checkpoint. **28 of the 42 families carry a report, list, dashboard or
filtered search.**

| SR | Reference report | Purpose · visible filters / columns | Aggregates | Sensitive data | Owner | Lycenza state (after 11.1) | Class · 11.x? |
|---|---|---|---|---|---|---|---|
| SR-01 | "Look at students by" (16 lenses) | caste, religion, gender, mother tongue… per Section | heat map, totals | demographic profiling | Analytics | not built (D-15, ADR 0040) | **6.4 rejected** · never |
| SR-02 | Inactive-on-rolls / pending lists | exception lists | — | — | Students | Data Health checks | 6.1 · Data Health, never a report |
| SR-03 | Student directory | year/branch/class/section/gender; photo, age, contact | status KPIs | photo, age, gender | Students | **`students.directory`** + **`students.placements`** | **6.1 · implemented** (no KPI counts, gender, age or photo) |
| SR-08 | Email validity lists | Student/parent/staff emails | — | contact values | Communications | validation on entry | 6.3 · not SIS |
| SR-10 | Inactivation/reactivation dashboard | request, reason, effective date | pending counts | — | Students | **`students.lifecycle`** (record list); approval queue not adopted | **6.1 · implemented (list)** |
| SR-11 | Exit revenue impact | forgone fees by month | money by cohort | Finance | Analytics + Finance | not built | 6.2 · backlog (D-14) |
| SR-12 | Exit audit / registers | exits, reasons, reactivation register | — | dues | Students (+Fees) | **`students.movements`** + **`students.lifecycle`** | **6.1 · implemented** (dues checkpoint cross-module) |
| SR-13 | Contract / verification dashboards | pending verification, signed forms, IP | counts | IP, consent | Portal + Documents | not built | 6.3 · Portal, legal (OD-SIS-5) |
| SR-14 | Pre-admission document counts | per class | counts | — | Admissions + Documents | not built | 6.2 · backlog |
| SR-16 | Staff workload (part) | periods per teacher | totals | — | Timetable | not built | 6.3 · Timetable ERP |
| SR-17 | Untagged-student analysis (part) | boys/girls without a group | gender counts | gender | Groups / Analytics | not built | 6.4 · rejected (gender analytics) |
| SR-19 | Derived system tags | 25 family-circumstance cohorts | members | profiling of children | — | not built | 6.4 · rejected (DNB) |
| SR-21 | Document checklist grid | per class × document | — | health (immunisation), photo | Students / Documents | per-Student documents (EXP-3); no grid | 6.1 · future, not 11.x scope |
| SR-23 | Contact details report + column picker | DOB, national id, parent mobiles/emails | — | contact, identity | Students / Guardians | not built (ADR 0041) | **6.4 · privacy-constrained** (SIS-020 needs purpose, permission, review, audit) |
| SR-24 | Strength reports | opening/closing, gender/age matrices, capacity | person counts | gender, age | Analytics (+ Academic Structure) | not built | 6.2 · gated (ADR 0040, OD-SIS-6) |
| SR-25 | Sibling report | families, dues this/last year | — | dues | Students / Guardians (+Fees) | profile Family block (EXP-1) | 6.1 · School-wide list waits on its OD; dues D-14 |
| SR-26 | Strength audit (fees) | receivables by grade | money by grade | Finance | Fees + Analytics | not built | 6.2 · backlog (D-14) |
| SR-27 | Branch-wise strength | counts per branch per class | person counts | — | Analytics (cross-School) | not built | 6.3 · Network Analytics decision |
| SR-28 | Device punch / absence-message logs | biometric/RFID logs | — | biometrics | Attendance + Integrations | not built | 6.4 · legal (HRX-L2, E28) |
| SR-29 | Student attendance reports | daily/period/monthly %, late comers | rates | — | Attendance | registers, register status, Student history | 6.3 · Attendance (rates after the Analytics gate) |
| SR-31 | Parent leave lists | type, remarks, dates | — | illness (health) | Attendance + Portal | not built (D-16) | 6.3 · Attendance/Portal |
| SR-32 | Class / subject teacher lists | teacher per Section, contacts | — | staff contacts | Teaching Assignments | directory (V11.2) | 6.3 · existing |
| SR-33 | Data-change / activity logs | old → new values, IP | — | values, IP | Compliance | history (names only), audit log | 6.3 · existing |
| SR-35 | Suspension log | reason, remarks | — | discipline | — | not built | 6.4 · legal (DPO first) |
| SR-36 | Section / Campus movement history | shuffles, transfers in/out | — | parent contact | Students | **`students.movements`** | **6.1 · implemented** (no parent contact) |
| SR-37 | Promotion reports | promoted, pending with fee due | — | fees | Students (rollover) | **`students.movements`** (promotion / repeat) + rollover pages | **6.1 · implemented** (no fee gating) |
| SR-38 | Principal diary | daily activity | — | incidents | — | not built | 6.4 · rejected (DNB) |
| SR-39 | Admission analytics | revenue by class/source | money | Finance | Analytics (Admissions + Fees) | Admissions list with filters | 6.2 · operational list → **SIS 11.2**; analytics gated |

### 2.1 Module ownership classification

| Class | Families | Count |
|---|---|---|
| 6.1 SIS-owned | SR-02, 03, 10, 12, 21, 25, 36, 37 | **8** |
| 6.2 Cross-module composite | SR-11, 14, 24, 26, 39 | **5** |
| 6.3 Other-ERP owned | SR-08, 13, 16, 27, 29, 31, 32, 33 | **8** |
| 6.4 Rejected / privacy-constrained | SR-01, 17, 19, 23, 28, 35, 38 | **7** |

## 3. Architecture

### 3.1 Before and after

**Before:** three separate report pages (Directory, Enrollment directory,
movement register), each with its own filters and fixed order. No catalogue,
no hub, and no list of lifecycle events.

**After:**

| Piece | Where | What it does |
|---|---|---|
| Closed catalogue | `App\Support\StudentReports\StudentReportRegistry` | Code-owned list. `RENDERED` read models plus the governed dedicated pages. Keys unique, never reused. |
| Definition | `StudentReportDefinition` | key, title, description, category, owner, authority (ALL required), scope policy, route, filters, columns, closed sorts (key → label), page size ≤ 50, reference families |
| Columns | `StudentReportColumn` | OPERATIONAL or RESTRICTED (`requires` extra capabilities). Prohibited keys refused by the constructor. `exportable` recorded, always false. |
| Filters | `StudentReportFilter` | closed vocabulary: entity (UUID, foreign = no match), choice (only offered values), date (Y-m-d School-local, `to ≥ from`), search (shared `StudentSearch`) |
| Read model contract | `StudentReport` | `definition()`, `options()`, `page()`, `hasAny()`. Implemented in the owning domain (the Data Health pattern). |
| Authority | `StudentReportAccess` | hub and report authorization from the definition, fresh per request |
| Hub + shared page | `StudentReportController`, `App/Students/Reports/{Index,Show}.vue` | `GET /app/students/reports`, `GET /app/students/reports/{report}` |
| First rendered report | `App\Domain\Students\Application\Reports\StudentLifecycleReport` | `students.lifecycle` |
| Directory sort | `StudentDirectoryOrder` | closed sort for `/app/students` (name, Student number) |

There is no generic query builder. Nothing from the request reaches SQL
except validated values: declared filters only, and a sort key mapped to a
fixed `ORDER BY`. Undeclared parameters are ignored.

### 3.2 The catalogue

| Key | Category | Owner | Authority | Scope | Route |
|---|---|---|---|---|---|
| `students.directory` | Students | Students | `students.view` | Campus-aware | `/app/students` |
| `students.placements` | Enrollment | Students (Enrollment) | `enrollments.view` | Campus-aware | `/app/enrollments` |
| `students.movements` | Enrollment | Students (Enrollment) | `enrollments.view` | whole School | `/app/students/movements` |
| `students.lifecycle` | Lifecycle | Students | `students.view` (+ `enrollments.view` for placement events/column) | whole School | `/app/students/reports/students.lifecycle` |

SIS 11.2 adds `students.siblings` (whole School, `students.view` +
`guardians.view`) and `admissions.applications` (Campus-aware,
`admissions.view`, `/app/admissions`) — `SIS-11-2-OPERATIONAL-REPORTS.md`.

`StudentReportRegistryTest` checks that each dedicated page entry declares
exactly the filters the page reads and the sorts its read model maps. It
also checks that a Campus-aware entry is a C6 Campus route and a
whole-School entry is a declared whole-School operation.

### 3.3 Permission model

- **No `reports.view`.** A report requires its underlying data capabilities
  (all of them). A multi-capability report follows the Data Health precedent.
- **Hub:** opens only when at least one report is visible, and lists only
  those. A hidden report is never named. Navigation: *People → Student
  reports*, shown on `students.view` or `enrollments.view`.
- **Shared page:** an unknown key, or a dedicated page's key, is NOT FOUND.
  Missing authority is 403.
- **Route coverage:** both routes are C3 (`StudentReportAccess::authorizeHub` /
  `authorizeReport`). The refusal sweep covers them.

### 3.4 School and Campus scope

- Every read runs inside `TenantContext::withSchool()` with an explicit
  `school_id` on every joined table (forced RLS underneath).
- **Campus-aware** reports (directory, placements) read through
  `StudentAccess`: current in-scope placements only, so a forged Campus id
  matches nothing.
- **Whole-School** reports (movements, lifecycle) are history across
  Campuses and years (rule 101). A Campus grant never opens them. The hub
  hides them, and the pages refuse them (403).
- **Teacher** ownership keys open nothing here. Teacher reporting needs its
  own design.

### 3.5 Privacy and column model

- **Operational:** Student number, name, status, placement, closed-code
  labels, dates.
- **Restricted:** lifecycle *Placement* needs whole-School `enrollments.view`.
  Without it the column (and the placement events) is absent from the
  payload.
- **Prohibited (refused in code):** date of birth, age, gender, photo,
  address, national/government id, passport, caste, religion, mother tongue,
  disability, medical, blood group, Guardian name/contact, email, phone,
  encrypted/HMAC values, credentials, IP/user agent, internal ids, acting
  user, metadata.
- **No actor.** The lifecycle report does not show *recorded by*. The audit
  log (`school.audit.view`) owns who acted, as with the movement register
  (rule 119). A "recorded by" column would need its own decision.

### 3.6 Filters, sorting, pagination

- Only declared filters render; options are cut to the viewer.
- Lifecycle filters: search, action, reason, Campus (placement events only),
  from and to (School-local recorded date).
- Directory: name, number, status, current Campus/Grade/Section, plus sort.
  Placements: year (every academic year of the School, closed ones included), Campus, Grade,
  Section, status, number, name, roll number. Movements: as SIS.EXP-2.
- Section options follow the chosen year, Campus and Grade, and an invalid
  combination is cleared before submit. Server-side it simply matches
  nothing.
- Filter state lives in the URL (copy, Back, pagination keep it). *Clear
  filters* is driven by the server's values, so it is never stale.
- Bounded pages: directory 20, placements 20, movements 25, lifecycle 25.

**Directory and placement semantics (decision).** The Student directory is
*current placement* (this academic year). *Placements by academic year* lists
every placement of a chosen year. With no year chosen it lists all years for
a whole-School reader, and only current placements for a Campus reader (the
existing ORG.7F-1 behaviour, kept rather than defaulting to the active year).

**Lifecycle dates (decision).** The lifecycle report shows the **recorded**
School-local date of each action. Effective placement dates are on the
movement register. A cancellation recorded with ADR 0078's empty interval
reads **"Cancelled before start"**.

## 4. Decisions and boundaries

| Item | Decision |
|---|---|
| Admissions report (`admissions.applications`) | **Deferred to SIS 11.2** (delivered there). The Admissions list already filters by year, Campus, Grade and status. Registering it needs its own Campus-scope review of `admissions.view`. |
| Saved views | **Option B: later enhancement.** No preference store exists for report filters, and no schema is added. URL filters already cover copy and bookmark. |
| Export | **None.** `exportable` is false on every column (pinned). Before any CSV/XLSX export (D-11): a governed export contract, then **formula-injection escaping**. Any cell starting with `=`, `+`, `-`, `@`, a tab or a carriage return is prefixed with `'` (and quoted), on every column including names. Rules 103–104 stay authoritative. |
| Print / PDF | None. Browser print is not an official report. |
| Analytics | Operational listing only. The only number shown is the paginator total of rows the viewer may see (ADR 0042 §11). No rate, trend, percentage, breakdown or person count (ADR 0040, V11.1 D-01..D-04). |
| Demographics | None (D-15). Prohibited column keys are refused in code. |
| Data Health | Kept separate. It reports integrity conditions; reports list records. |
| Cross-module truth | None duplicated. Lifecycle reads SIS-owned tables only. Finance, Timetable, Examinations and Communications reports are backlog (§5). |
| Schema | **None.** No migration, table, capability, role or Campus adoption. |

## 5. Cross-module report backlog

| Report | Owner | Gate |
|---|---|---|
| Admissions applications (operational list) | Admissions | SIS 11.2 |
| Admission revenue, fees charged/collected, discounts, refunds, exit revenue | Finance + Analytics | D-14, D-01..D-04 |
| Strength, capacity fill, branch-wise counts | Analytics (+ Academic Structure) | ADR 0040, OD-SIS-6, ADR 0080 §13.4 |
| Attendance rates, late comers, period/day views | Attendance | Analytics gate; daily model decision |
| Staff workload, timetable master, practical batches | Timetable ERP | Timetable programme |
| Examination attendance | Examinations | E40 |
| Report → message actions (WhatsApp, bulk) | Communications | E28; Communications architecture |
| Family contact list | Students / Guardians | SIS-020: purpose, permission, privacy review, audit (ADR 0041) |

## 6. Performance (measured, tests)

- Lifecycle: **one count and one page query** (names joined in SQL).
  `StudentReportsTest` pins exactly two lifecycle queries per request, and an
  equal warm total for a 3-event and a 30-event history (14 queries per
  request, including session, membership, capabilities, navigation and
  brand).
- The directory and placements keep their SIS.EXP-1 measurements (constant,
  no N+1). The movement register keeps its constant projection.

## 7. Verification

- Tests: `tests/Feature/StudentReports/StudentReportsTest.php` (9),
  `StudentReportRegistryTest.php` (9). Authorization coverage guard and
  refusal sweep, navigation, Students, App, Data Health, Admissions,
  Attendance, Academic Structure, Guardians, Enrollment, Accessibility,
  Architecture, Setup, Demo and Tenancy suites all green locally (§8 of the
  completion report).
- Browser journeys A–I on DDEV (Playwright, after `ddev demo-reset`):
  **52/52**. The lifecycle history for journeys D/E was written through the
  real `StudentEnrollmentService` / `StudentService` paths on the local demo
  database: a future placement cancelled before start, then marked inactive
  and active again. The fresh-MFA proof was constructed directly, as the test
  fixtures do.

## 8. Advanced SIS Screenshot Coverage (new measure)

This is separate from the core SIS score (96/100, unchanged) and from the
SIS.EXP reference coverage (39.2 % / 29.7 %, unchanged: no family changes
class).

**Report subset** = the 8 SIS-owned report families (§2.1). A family counts
when an operational report for it is **governed by the closed catalogue**.

| | Governed families | Coverage |
|---|---|---|
| Before 11.1 | 0 (four had dedicated pages, none catalogued) | **0 / 8 = 0 %** |
| After 11.1 | SR-03, SR-10, SR-12, SR-36, SR-37 | **5 / 8 = 62.5 %** |

Remaining:
- SR-02 stays Data Health by design.
- SR-21 (document checklist grid) and SR-25 (School-wide sibling list) wait
  on their decisions.

Across all 28 report-bearing families: 5 / 28 = 17.9 % governed. 13 of the 28
are outside SIS ownership or rejected.

## 9. Next

**Recommended:** SIS 11.2 — Operational Student Reports. It starts with the
Admissions applications list (Campus review of `admissions.view`), then
SIS-owned reports on the same contracts. It is not started; owner
authorization is required.
