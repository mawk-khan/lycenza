# Local Product Completion Audit — Final DDEV Development Reconciliation

- **Date:** 2026-10-17.
- **Kind:** audit only (inspect, reconcile, classify, prioritise). **No
  product code, schema, capability or configuration changed.**
- **Baseline:** `origin/main` = local `main` = `HEAD` =
  `f805aef8b21d3eeb7ac4e0d1fa0597b8fe046643` (PPR P2-F2).
  - The tree was clean, with one worktree and the historical stash only.
  - The last exact-SHA `CI gate` was green: 8597 cases, 8596 passed plus the
    ESI-12 skip. This is the remote full-regression checkpoint, so the
    counter is 0/5.
- **Owner direction:**
  - development continues **entirely on local DDEV** until the product is
    complete and satisfactory;
  - **staging/AWS execution is paused.** The PPR plan and its evidence are
    kept unchanged (§T).
- **Earlier records** (all unchanged):
  - [Local UAT](LOCAL-UAT-2026-10-15.md) and the
    [readiness reassessment](LOCAL-UAT-READINESS-REASSESSMENT-2026-10-16.md);
  - the [V11 closure](V11-CLOSURE-AUDIT.md) and the
    [SIS benchmark delta](V11-SIS-BENCHMARK-DELTA-AUDIT.md);
  - the [School Administrator Guide](SCHOOL-ADMINISTRATOR-GUIDE.md).

Paths are relative to `apps/platform/` unless they begin with `docs/`.

## A. Baseline

| Item | State |
|---|---|
| Product programmes | SWC, V11 (within approved scope), Local UAT and UAT-C1–C6 are closed. UAT-C5 is published |
| Product-side staging handoff | YES (unchanged, but no longer the next step) |
| PPR | F-D1, F-D1-2 and P2-F2 are published. **Paused by owner direction** |
| Legal gates | Unchanged (§H) |
| Open decisions | D-01–D-13 (V11.7); D-14–D-17 decided 2026-10-16 (ADR 0075 §22) |

## B. Audit method

1. **Repository inspection** on `main`, by six parallel read-only passes:
   - Admissions/SIS/academics/timetable;
   - attendance/teacher;
   - finance/canteen/hostel/transport;
   - HR/library/inventory/visitors;
   - examinations/LMS/communications/documents/portal/platform;
   - cross-cutting (API-only inventory, console-only operations, feature
     flags, single-School selection, money formatting, accessibility, table
     scroll containers, raw identifiers, navigation, search, error
     rendering).

   Every finding carries a `file:line` reference; the key ones are cited
   below. Each historical backlog item was re-checked on `main`.
2. **Browser crawl** on a fresh `ddev demo-reset`, with the assets rebuilt:
   - the Playwright harness signed in as 14 demo personas;
   - it followed every `/app` link reachable from Home and hubs (259 School
     Admin pages, 235 Principal pages, and every page of the operational
     roles);
   - per page it recorded status, 5xx responses, page errors, visible UUIDs
     and raw error text;
   - 616 page visits were repeated at **390 px** for page scroll width;
   - for each persona it recorded where sign-in lands before a School is
     chosen.
3. **Live confirmation** of each suspected server error, as the owning
   role on the demo.
   - Normal-looking inputs were used. The demo was reset afterwards.
   - The raw crawl and probe output is in the Claude scratchpad (`lpca/`),
     not committed.
4. **Reverse check** against the School Administrator Guide.

Benchmark material and real data were not used.

## C. Overall Local Product Completion Score: **83 / 100**

Staging infrastructure and legally gated production activation are
**excluded** (§H, §T).

| Dimension | Weight | Score | Basis |
|---|---|---|---|
| Functional breadth | 15 | 92 | 33 domain modules; the remaining absences are recorded deferrals or legal gates |
| End-to-end workflow completeness | 15 | 85 | Every guide journey passes in the browser, except two. The **annual timetable change-over** is blocked (M2), and a cashier has **no day's collections** (M4) |
| Browser operability | 12 | 78 | About 20 ordinary edits exist only in the API; enabling Automation needs SQL; the HR document form asks for a storage path |
| Error handling | 10 | 70 | **Six 500s confirmed live**, four of them from ordinary operator input; attendance-correction refusals are silent (M1) |
| Data integrity | 10 | 88 | Database-enforced invariants throughout; the timetable teacher/room slot uniqueness spans academic years (M2) |
| Tenant / security | 10 | 95 | RLS, capability checks and isolation suites green in CI; no crawl page leaked another School |
| UX | 8 | 78 | Single-School selection on every sign-in; canteen UUIDs; portal number formatting |
| Role usability | 6 | 82 | No teacher timetable or class list; accountants have no class column; self-service is a separate role |
| Accessibility | 5 | 65 | Pickers fixed (C4); about 800 lint findings remain outside the guarded files; 27 files use `window.confirm` |
| Responsive | 3 | 85 | 13 of 616 page visits overflow at 390 px; none is a daily office page, but two teacher pages are |
| Documentation / demo | 3 | 88 | The guide is accurate; Transport, Hostel, Visitors and Canteen have no procedures |
| Known backlog (inverse) | 3 | 70 | 17 historical items re-checked: 2 fixed, 1 partial, 14 open, all S2/S3 |
| **Weighted** | **100** | **82.9** | |

**Reading the score.** It is not comparable with the earlier figures (93.6
post-C6 UAT readiness; V11.7's 58 % commercial readiness): those measured
UAT pass rates and go-live readiness. This one measures product completeness
for a School running on DDEV.
- **What keeps it from 90+:** a small number of real defects (M1, M2) and
  operability gaps (M3–M5). Missing features are not the reason.
- **Expected after M1–M5:** about 90–91. After the SHOULD slices: about 94.

## D. Per-domain scorecard

| Domain | Score | Evidence | Main remaining gap | More local work? |
|---|---|---|---|---|
| Platform / tenancy / identity | 93 | RLS everywhere; MFA; isolation tests; host classification; the hardened guards (F-D1-2, P2-F2) | Single-School selection every sign-in, and deep links lost twice (`ResolveSchoolContext.php:90-96`; `SchoolSwitchController.php:76-81`). Feature flags need SQL | Yes, small (UX-03) |
| Admissions | 82 | Applicant → application → decision → conversion with Guardian create/link and placement; admission fee head | **No applicant or application edit** (`ApplicantService.php:43` has only `create`), contrary to ADMISSIONS.md §6 | Yes (M5) |
| SIS / Students / Guardians | 86 | Directory, profile edit, Guardians, contacts (confirmed actions), history, placement, transfer, closed-year closure | Transfer offers Sections the service refuses (ENR-01); one-click inactivation (STU-14); a contact value cannot be edited | Yes, small |
| Academics (structure, rollover, timetable, assignments) | 80 | Annual cycle browser-operable without SQL; rollover with bulk decisions | **Timetable year boundary** (M2); year/term dates cannot be corrected; period edit is API-only; inactive entries cannot be reactivated | Yes (M2, M5) |
| Attendance | 80 | Period registers, corrections, exceptions, teacher register (MFA), School-date handling | Silent correction refusals (M1); registers-not-taken list (SB-01); no future-date limit (ATT-09); no mark-all (ATT-08, needs a decision); no per-Student history (ATT-07) | Yes |
| Finance (fees, payments, ledger) | 84 | Setup, fee runs with overlap review, charges, concessions, late fees, manual payments, receipts, corrections, outstanding, statements, period close | **No day's collections / payments list by date** (M4); canteen UUID descriptions (M3); leavers (SB-03); account rename API-only | Yes (M3, M4) |
| HR / staff accounts / leave / payroll | 78 | Employees, employment, assignments, link, manager picker, invitations, leave, staff attendance, payroll runs | **500 on overlapping or invalid employment** (M1); sub-record and master-data edits API-only; document form asks for a storage path; leave allocation not signposted (HR-12) | Yes |
| Teacher experience | 70 | My curriculum delivery, register (MFA), learning content, assignments, leave, payslips | No own timetable or class list (ADR 0063 authorization decision); Home has links only; payslip shows a run UUID; two pages overflow at phone width | Partly (decision first) |
| Guardian portal (local) | 78 | Messages, attendance and fees (MFA), child switching, conversations | No thousands separators (UX-04); a "Back" loop on a single-child page; browser-locale dates; canteen UUIDs (FIN-22) | Yes, small |
| Library | 86 | Catalogue, copies, checkout/return with keyboard pickers, fines, School-date due dates | Loans list has no Student number (LIB-05); title edit and copy lost/withdrawn API-only; no overdue filter | Yes, small |
| Inventory / Stores | 76 | Items, locations, receive, issue, transfer, movements | **500 on an oversized quantity** (M1); no correction/adjustment movement; no rename; no movement filters; issue has no recipient (INV-06) | Some |
| Canteen | 68 | Outlets, items, recipes, orders, fulfilment, billing to charges | **Orders list and detail show Student and Outlet UUIDs; charge description is "Canteen order <uuid>"** (M3); **500 on recipe quantity 0** (M1); deactivated items vanish | Yes (M3) |
| Transport | 74 | Routes, stops, vehicles, operations, Student assignments, fee intent | Rename/edit API-only; active-only lists (one-way deactivation); no ended-assignment history | Yes (M5, SHOULD) |
| Hostel | 74 | Hostels, rooms, beds, residency, fee intent | Rename API-only; active-only lists; no bed transfer (end + create) | Yes (M5, SHOULD) |
| Front office / Visitors | 76 | Directory, check-in with pickers, check-out | No date filter; visitor details not editable in the UI; no visit detail page | SHOULD |
| Data Health | 85 | 8 domain-owned, read-only checks with repair links | Next-year placement reads as "no class" (DH-12); "C (6C)" label (DH-18); SB-02 and SB-03a candidates | Yes, small |
| Search / UX / accessibility | 72 | SearchCombobox (31 uses, guard test); Student search by fragment; name-only Guardian search (ADR 0041) | About 800 a11y lint findings; `window.confirm` in 27 files; raw ids on payroll and automation pages | Subset |
| Reporting / operational visibility | 70 | Lists with totals, exceptions, outstanding fees, statements, Student history, curriculum coverage | Collections day-book (M4); per-Student attendance history; people-count analytics excluded (V11.1, legal) | Yes (M4) |
| Documentation / demo | 86 | Guide matches the browser; demo personas coherent; DOC-01 fixed | No procedures for Transport, Hostel, Visitors or Canteen; a little drift (§O) | Yes, with each slice |

## E. Must complete locally

These are the items the owner should not sign off local development without.
There are five.

| ID | Business problem | Current behaviour (evidence) | Expected | Why before final UAT | Scope | Dependencies / constraints |
|---|---|---|---|---|---|---|
| **M1** | Ordinary operator mistakes and stale tabs end in a server error, or in nothing at all | **Live 500s, see the table below.** Why: domain exceptions extend `RuntimeException`, the friendly envelope covers only `api/*` (`bootstrap/app.php:248-251`), and about 23 mutating web controllers neither catch nor use `MapsDomainRefusals`. Attendance correction refusals are dropped: `Attendance/Show.vue` renders no errors | Every refusal from a normal form becomes a field error or flash. A malformed id gives a 404. Correction refusals are shown | A final UAT that finds 500s in four modules cannot pass; fixing it centrally also prevents the next ones | Medium: a central web/Inertia refusal renderer (or trait coverage plus a guard test), UUID route constraints, error display in `Show.vue`, `Take.vue` `records.*`, plus validation for recipe and stock quantity | None. Never change what is refused, only how it is shown |
| **M2** | The School cannot prepare or switch to next year's timetable | Teacher and room slot uniqueness spans **all years** (`2026_09_12_090300…:126,141`, `(school_id, teacher_id, day_of_week, period_id) WHERE status='active'`). Closing a year never deactivates its timetable. Entries and periods can only be reached while active (`TimetableEntryController.php:57`, `TimetablePeriodController.php:35`), so a deactivated one cannot be reactivated. Take lists active entries of **any** year for the weekday (`AttendanceController.php:101-105`) | Slot uniqueness per academic year; Take and register lists scoped to the active year; inactive entries and periods listed and reactivatable; a period editable in the browser | The guide's annual cycle (§16) fails at the timetable step | Medium: one rollback-safe migration (indexes include `academic_year_id`), list and Take scoping, an inactive filter, a web period edit | Concurrency tests (`TimetableEntryConcurrencyTest`) must be extended; ADR/TIMETABLE.md note |
| **M3** | Canteen and Finance staff, and parents, read UUIDs instead of names | Crawl: canteen orders list and detail show Student and Outlet as UUIDs (`Canteen/Orders/Index.vue:137`, `Show.vue:80-82,141`). Charges, journal entries, the staff statement and the parent statement read "Canteen order 01a1…" (`CanteenOrderService.php:266`). Smaller cases: the payslip shows a run UUID (`Payslips/Show.vue:138`), Automation shows `subjectId` (`Automation/Index.vue:231`) | Names, order numbers and dates; descriptions built from meaning, e.g. "Canteen: Lunch combo ×2, 17 Oct" | Canteen is demo-linked from Home and appears on parent statements (FIN-22) | Small–medium. A readable description for **new** charges; posted descriptions are not rewritten (ledger immutability). Orders pages show names | Existing charges keep their text; at most a display-side lookup |
| **M4** | A cashier cannot reconcile the day's collections | The payments list filters only by provider reference and shows Received/Source/Amount, with no Student, receipt number, method, date filter or total (`PaymentController.php:70-77`, `Payments/Index.vue:112-135`). The V11.7 closure listed collection/day-book as P1 | Finance → Payments: date range, method, Student, receipt number, row total (ADR 0042 §11 style) and receipt links. Effective-allocation semantics are unchanged | Recording cash without a daily list is incomplete core Finance. D-14 already classes an **ungrouped** School collection list as an operational Finance list | Small–medium, read-only, no schema | Effective allocation (UAT-C3); no export (D-11) |
| **M5** | A typing mistake or a deactivation cannot be undone in the browser | Applicant and application cannot be edited (ADM-14; `ApplicantService.php:43`). Draft academic year and term **dates** cannot be corrected (`SchoolSetupController.php:199`). Deactivated canteen items and outlets, hostels/rooms/beds, transport routes/vehicles and timetable entries/periods disappear from their lists (`CanteenItemController.php:42`, `HostelController.php:43`, `TransportRouteController.php:41`, …), so deactivation is one-way in the browser | Edit applicant and draft/submitted application; edit **draft** year/term dates (validated against existing structure); an "inactive" filter with Reactivate on each list | A School will make these mistakes in its first week | Medium; existing services or small additions; no schema | Activation date rule (UAT-C1) unchanged; ADMISSIONS.md §6 already promises applicant edit |

*(2026-10-17: **M2 CLOSED by LC-2**, ADR 0073 amendment. Teacher and room slot uniqueness is per academic year; Take and register lists show the active year only; inactive classes and periods are listed and reactivatable; periods and classes are editable in the browser. ATT-09's other-year registers are closed with it.)*

**M1 confirmed live** (2026-10-17, fresh demo, owning role). *(2026-10-17: **M1 CLOSED by LC-1**, ADR 0073 amendment. Every case below now returns a refusal message, the shared banner, or a 404, with nothing written.)*

| Action | Role | Result |
|---|---|---|
| Add a period overlapping an existing one | School Admin | **500** |
| Add an employment while one is open | HR & Payroll | **500** |
| End an employment before its start | HR & Payroll | **500** |
| Canteen recipe quantity `0` | School Admin | **500** |
| Receive `100000000000` units | School Admin | **500** |
| `GET /app/attendance/not-a-uuid` | School Admin | **500** |
| `GET /app/communications/settings` (a typo; it falls through to `/{thread}`) | School Admin | **500** |
| `/app/library/circulation/not-a-uuid` | School Admin | 404 (guarded) |

## F. Should complete locally

These are meaningful improvements, not sign-off blockers.

| ID | Item | Evidence | Sev | Note |
|---|---|---|---|---|
| S-01 | **UX-03** single-School auto-select, and keep the intended URL | Every one of 10 single-School personas lands on "Select a School". Deep links are lost at `RequireSchoolContext.php:170-187` and `SchoolSwitchController.php:76-81` | S3, but every user on every sign-in | Low risk: select only when exactly one active membership exists, and re-validate per request as today (rule 20) |
| S-02 | **SB-01** registers not yet taken for a date | Only `alreadySubmitted` on Take (`AttendanceController.php:96-117`) | S2 | Moved from post-staging P2: a daily office control, read-only |
| S-03 | **ATT-09** future dates and other-year registers on Take | No `max` (`Take.vue:255-261`); staff `?date=` accepted | S3 | Overlaps with M2's year scoping |
| S-04 | **ATT-06** confirmation on a correction | One click (`Show.vue:128-138`) | S3 | Confirmation only. A reason is the Health-tier/legal question (ATTENDANCE.md §3–4) |
| S-05 | **ATT-08** "mark all present" | Not present (`Take.vue:195-197`) | S2 | **Owner decision first**: ATTENDANCE.md §5 forbids a default-to-present. An explicit, confirmed bulk action needs a §5 note |
| S-06 | **ATT-07** one Student's attendance over a date range | Exceptions are one day only (`AttendanceExceptionReadService.php:36`) | S2 | One Student's own records is an operational list, not a rate. No percentages |
| S-07 | **SB-03b** leaver and campus filters on Outstanding fees | `OutstandingFeesController.php:47-72` | S2 | Moved from post-staging P2; fits beside M4 |
| S-08 | **SB-02 / SB-03a / DH-12 / DH-18** Data Health exit consistency and wording | No Attendance or Fees checks (`PlatformServiceProvider.php:110-117`); DH-12 wording `ActiveStudentWithoutCurrentPlacementCheck.php:36,68`; DH-18 `AssignmentOnInactiveConfigurationCheck.php:65` | S2/S3 | Read-only, domain-owned, no stored findings (ADR 0075 §10) |
| S-09 | Operations edits that are API-only today | Timetable entry, library title/copy, transport route/vehicle/stop, hostel/room, inventory item name, ledger account name, HR position/department/category, leave type, HR sub-records, visitor details, canteen recipe quantity (`api.php` refs in §L) | S3 | One slice per module family; web forms over existing services |
| S-10 | **UX-04** portal money and dates, plus the "Back" loop | `Portal/Fees/*.vue` raw amounts; `toLocaleString` in portal communications; `Attendance/Show.vue:50`, `Fees/Show.vue:57` | S3 | Use `formatMoney` and `datetime.ts`; parent-facing |
| S-11 | **LIB-05** Student number on loans; gate the hidden "Void fine" dialog | `LibraryCirculationController.php:219`; `Circulation/Show.vue:127-135` | S3 | Small |
| S-12 | **ENR-01** Section pickers: current year first; Transfer limited to the placement's year and grade | `StudentEnrollmentController.php:202,259` | S3 | Small; it offers choices the service refuses |
| S-13 | **STU-14** confirmation on Mark inactive (reason stays D-13) | `Students/Show.vue:223-226` | S3 | Same for the Guardian status toggle |
| S-14 | **HR-12** signpost leave allocation after assigning a policy | `LeaveAdminController.php:199-205` | S3 | Copy + link |
| S-15 | High-value phone pages | Overflow at 390 px: `/app/my-learning-content`, `/app/my-assignments` (teacher), `/app/canteen-orders`, `/app/communications`, `/app/settings/staff` | S3 | The other 8 (configuration, analytics, automation) are optional |
| S-16 | Accessibility on daily workflows | The lint covers only the SWC files (`eslint.config.js:43-72`), about 800 findings elsewhere; `window.confirm` 48 calls | S3 | Extend the lint scope to attendance, payments, students and library pages; replace confirms there with `ConfirmDialog` |
| S-17 | Operator command for School feature flags | No UI or command; Automation needs SQL (`FeatureFlagResolver.php:29-47`; `Automation/Index.vue:113`) | S3 | Platform-operator CLI, audited; no School UI |
| S-18 | HR document form | It asks for "Storage path" and "Size" (`HR/Employees/Show.vue:1798-1860`); a Highly Sensitive record vanishes from the list | S3 | Hide or relabel until OD-SWC-2 (Documents UI) is decided |

## G. Optional polish

- **Timetable:** copy to next year, a weekly grid view, a conflict overview.
- **Rollover:** mapping delete and plan cancel (no service operation exists).
- **Teaching assignments:** edit (end and re-create works).
- **Elective groups:** rename or deactivate.
- **Inventory:** stock correction/adjustment movement (schema); issue recipient/purpose (INV-06, schema); movement filters.
- **Finance:** trial balance / account balances page; class column for the
  Accountant (needs `enrollments.view`, a role-design question).
- **Visitors:** date filter and visit detail page.
- **Canteen:** order-fulfilment issues distinguishable from manual issues.
- **Teacher Home:** "today" content (classes, registers due, leave balance).
- **Phone width:** the 8 remaining configuration, analytics and automation pages.
- **Small fixes:**
  - Guardian name search escapes `%`/`_` and matches full names;
  - `formatMoney(x, '')` trailing space in Payroll (17 calls);
  - hard-coded `'INR'` in four files.

## H. Legal / privacy / decision blocked

Production activation is not counted against local completeness.

| Area | Implemented locally? | Development allowed? | Production | Gate |
|---|---|---|---|---|
| Teacher role (Attendance, curriculum, LMS) | Yes | Yes | Grants wait for the deployment re-verification checklist | E33 (approved with conditions) |
| Marks (admin and teacher) | Service + JSON endpoints, **no page** (ADR 0073 §6.4) | Yes (JSON only) | Refused in code | E35–E37, RES-L0–L2 |
| Results, report cards, transcripts, Student/Guardian access to marks | Not built | Blocked for design and development | — | E39–E42, E44 |
| Guardian portal | Yes (MFA) | Yes | Refused in code | E46 / POR-L1 |
| Student portal | No | Blocked | — | E46 |
| Statutory payroll | Built; no role holds it | Demo only | Not offered | E45 / PAY-L1 (ESI-12) |
| Retention | Built | Yes | Configuration pending | E21 |
| Receipts/GST, late fees, RTE, library fines | Built | Yes | Each needs its answer or a code refusal | E30–E34 |
| People-count analytics / dashboards | Fail-closed | — | — | V11.1 (D-01–D-04) |
| Guardian contact search | Name only | — | — | ADR 0041 (SRCH-02, D-06d) |
| Teacher own timetable / class list | Not built | Owner authorization decision | — | ADR 0063 |
| "Mark all present" | — | Owner decision (ATTENDANCE.md §5) | — | S-05 |
| Student/Guardian import, opening balances | Not built | Owner decision | Needed before a first real School | OD-SWC-4/5, OD-PPR-20 |

## I. Post-v1 / integration deferrals (definitive register)

| Item | Why it stays deferred |
|---|---|
| Global search | D-12: per-result-type authorization architecture; module searches cover daily use |
| Student Leave | D-16 (deferred until a pilot defines semantics and notifications) |
| Houses, clubs, activities, groups | D-17 (no generic framework) |
| Demographic fields and analytics | D-15 |
| Biometric / RFID attendance | Integration and legal (HRX-L2; ADR 0065 §10) |
| V11.1 dashboards | D-01–D-04 legal/privacy |
| Advanced communications (SMS, WhatsApp, push, staff event notifications, sender overrides) | E28; D-07/D-08; ADR 0055 |
| Room and facility modelling (building, floor, home room) | D-09 |
| LMS submissions and grading | Cancelled by owner (LMS.md §0) |
| Documents UI and Student documents | OD-SWC-2 |
| SIS export | D-11 |
| Lifecycle reason / effective date / approval | D-13 |
| Online payments | E28 / post-v1 |
| School-wide announcement to the Guardian inbox | ADR 0070 §28.7 known debt |

## J. Remaining Local-UAT backlog reconciliation

| Item | Status on `f805aef8` | Evidence | Class | Sev now |
|---|---|---|---|---|
| UX-Phone | OPEN: **13** of 616 visits overflow (subject offerings, syllabus, syllabus delivery, learning content, assignments, canteen outlets, canteen orders, communications, curriculum coverage, staff accounts, automation, exam papers, templates; plus two teacher pages) | crawl | SHOULD (5 pages) / OPTIONAL | S3 |
| UX-03 | OPEN | §F S-01 | SHOULD | S3 (daily) |
| UX-04 | OPEN | §F S-10 | SHOULD | S3 |
| FIN-22 | OPEN (plus canteen orders UUIDs) | §E M3 | **MUST** | S2 |
| SRCH-02 | By design (ADR 0041) | `GuardianController.php:44-54` | DECISION | — |
| ATT-08 | PARTIAL: labels fixed (C4); no mark-all; Show heading still `sectionCode` | `Show.vue:93` | SHOULD + DECISION | S2 |
| ADM-14 | OPEN | §E M5 | **MUST** | S2 |
| ATT-06 | OPEN | §F S-04 | SHOULD | S3 |
| ATT-07 | OPEN | §F S-06 | SHOULD | S2 |
| ATT-09 | OPEN | §F S-03 | SHOULD | S3 |
| ENR-01 | OPEN | §F S-12 | SHOULD | S3 |
| STU-14 | OPEN | §F S-13 | SHOULD | S3 |
| HR-12 | OPEN | §F S-14 | SHOULD | S3 |
| LIB-05 | OPEN (names); "Void fine" button gated, but its dialog is always mounted | §F S-11 | SHOULD | S3 |
| INV-06 | OPEN | §G | OPTIONAL | S3 |
| DH-12 | OPEN | §F S-08 | SHOULD | S3 |
| DH-18 "(6C)" | OPEN | §F S-08 | SHOULD | S3 |
| STF-05, HR-23 | **FIXED** (UAT-C6) | `web.php:1349-1354` | NO ACTION | — |
| FIN-20 | Overlap review **FIXED** (C6); no proration (owner D1) | `FeeRunOverlapDetector.php` | NO ACTION | — |

## K. SB-01 to SB-03b reassessment

These were sequenced "post-staging P2" under the staging-first strategy. With
the owner's local-completion strategy, they are reconsidered **before** final
local sign-off.

| Item | New class | Why |
|---|---|---|
| SB-01 registers not taken | **SHOULD** (S-02) | Daily office control; read-only, no schema |
| SB-02 attendance after a back-dated end | **SHOULD** (S-08) | Data Health, read-only |
| SB-03a later-period charges after exit | **SHOULD** (S-08) | Data Health (Fees-owned) |
| SB-03b leaver / campus filters | **SHOULD** (S-07) | Sits beside the collections list (M4) |

None is MUST: each is visible per record today and corrupts nothing.

## L. Browser / API / SQL operability gaps

These are ordinary supported workflows that still need technical
intervention.

| Workflow | Today | Class |
|---|---|---|
| Fix a typo in an applicant | Impossible (no route) | MUST (M5) |
| Correct a draft year/term date | Impossible (rename only) | MUST (M5) |
| Reactivate a deactivated canteen item/outlet, hostel/room/bed, transport route/vehicle, timetable entry/period | API, or a hand-typed URL | MUST (M5) |
| Edit a timetable period (code/name/times) | API (`api.php:1692`) | MUST (M2) |
| Edit a timetable entry | API (`api.php:1726`); deactivate + re-create works | SHOULD (S-09) |
| Edit a library title; mark a copy lost or withdrawn | API (`api.php:1032,1041`) | SHOULD |
| Rename a transport route/vehicle/stop, a hostel/room, an inventory item, a ledger account, an HR position/department/category, a leave type | API | SHOULD |
| Edit HR addresses, contacts, notes, employment, qualifications, experience, certifications | API (`api.php:621-746`); delete + re-add works | SHOULD |
| Change a canteen recipe quantity | API (`api.php:1625`); remove + re-add works | SHOULD |
| Turn on Automation for a School | **SQL** | SHOULD (S-17, operator CLI) |
| Release a suppressed email; reset a password when recovery is off; first School administrator | Operator console, by design (ADR 0055, 0056, 0059) | NO ACTION |
| Enter marks | JSON only, legally gated | LEGAL |
| Upload an HR document file | API (OD-SWC-2) | DECISION (S-18 hides the confusing form) |

## M. UX, accessibility and responsive findings

- **Error handling:** see M1. There is no Inertia error page: a server error
  shows a bare page (in DDEV, the debug page).
- **Raw identifiers:** see M3. The audit log, platform pages and the
  "enter School by id" page show ids **by design**.
- **Phone (390 px):** see J. Daily office pages fit: attendance, finance
  lists and forms, Students, Library and the timetable.
- **Accessibility:**
  - picker work (C4) holds, guarded by `SearchPickerGuardTest`, 31 uses;
  - the a11y lint scope is limited to the SWC files;
  - about 800 findings remain;
  - native `confirm()` is used in 27 files. It is accessible, but it is
    inconsistent with `ConfirmDialog`.
- **Naming:** the teacher sees two headings called "My attendance" (class
  register and own staff attendance).

## N. Role / persona completeness

| Persona | Main jobs | Usable? | Gaps |
|---|---|---|---|
| School Admin | Everything | Yes | M1–M5 |
| Principal | Academics, Students, attendance, communications, Data Health | Yes | M2 |
| Teacher (+ Staff Self-Service) | Registers, curriculum, learning content, own leave/payslips | Yes; production grants process-gated (E33) | No own timetable or class list (decision); two phone overflows; payslip UUID |
| Accountant | Fees, charges, payments, outstanding, statements | Yes | M4; no class column (no `enrollments.view`); reverse/refund admin-only by design |
| Librarian | Catalogue, circulation, fines | Yes | LIB-05; title edit API-only |
| Canteen + Stores | Orders, items, inventory | **Partly** | M3 (unreadable orders); recipe-0 500; one-way deactivation |
| Front Office | Visitors | Yes | No date filter; no edit |
| Transport | Routes, stops, vehicles, assignments | Yes | Rename API-only; active-only lists |
| Hostel | Hostels, rooms, beds, residency | Yes | Same pattern |
| HR & Payroll | Employees, leave, staff attendance, payroll | Yes | M1 (employment 500s); sub-record edits API-only |
| Communications | Announcements, templates, conversations | Yes | Phone overflow; a typed URL can 500 (M1) |
| Guardian | Messages; attendance and fees (MFA) | Yes (development only, E46) | S-10 |
| Student | Sign-in only | By design (E46) | — |
| Multi-School / Annexe | School switching, isolation | Yes | Isolation verified in UAT and CI |

**No role needs a broader permission to do its job.** Teacher self-service
continues to need the separate Staff Self-Service role (documented in
guide §6).

## O. Documentation and demo consistency

- **Guide reverse check.** Every procedure in guide §2–§19 maps to a
  browser path. Two places describe workarounds rather than features:
  - §21 Known limitations still lists single-School selection, mark-all and
    portal formatting (still true);
  - §16 Year rollover does not warn about the timetable year boundary.
    That is the M2 defect, not a documentation error.
- **Not documented:** Transport, Hostel, Visitors and Canteen have no
  procedures (guide §19). Add them with M3 and M5.
- **Drift (reported, not changed):**
  - `docs/product/LOCAL-UAT-2026-10-15.md:467,490` still say fee-run
    duplicate detection is out of scope. That is a historical record,
    superseded by UAT-C6.
  - ADR 0063 §44 says the teacher role has "exactly four owned keys", but
    the seeder also holds `examinations.marks.teacher` (documented in
    §46).
  - TIMETABLE.md does not mention that slot uniqueness spans years (M2).
- **Demo.** The fresh demo works for all 14 personas, with no 5xx on any
  crawled page.
  - The demo hints are correct (DOC-01 fixed).
  - An evaluator can follow Home without developer help, except that the
    canteen pages are unreadable (M3).

## P. Security and tenant state

- **CI:** the last exact-SHA run (`f805aef8`) ran the full suite, including
  the tenancy, RLS, authorization and isolation suites: 8596 green plus the
  ESI-12 skip.
- **Crawl:** no page served another School's data. The Annexe administrator
  could open only account pages for the Demo School.
- **Hardened boot:** F-D1-2 and P2-F2 added guarded Redis TLS and lookup-key
  checks.
- **No security regression found.** M1 is an error-presentation defect: the
  refusals themselves hold and nothing is written.

## Q. Proposed implementation slices (local completion series "LC")

Each slice is small, has one outcome, has its own tests and is
browser-verified.

| Slice | Outcome | Contains | Schema |
|---|---|---|---|
| **LC-1** | No ordinary action ends in a server error or a silent refusal | M1: central web refusal rendering (or trait coverage + an architecture guard), UUID route constraints, `Show.vue`/`Take.vue` error display, recipe/stock quantity validation | none |
| **LC-2** | The annual timetable change-over works in the browser | M2 + ATT-09's year scoping: per-year slot indexes, active-year Take/register lists, inactive filter + reactivate, period edit | 1 migration (rollback-proven) |
| **LC-3** | Canteen and payroll read in names, not ids | M3 | none (new descriptions only) |
| **LC-4** | A cashier can reconcile the day | M4 + SB-03b (S-07) | none |
| **LC-5** | A mistake can be corrected and a deactivation undone | M5 | none expected |
| **LC-6** | Daily attendance operations | SB-01, ATT-09 rest, ATT-06 confirm, ATT-07 per-Student history, ATT-08 (after the owner's §5 decision) | none |
| **LC-7** | Data Health exit consistency | SB-02, SB-03a, DH-12, DH-18 | none |
| **LC-8** | Sign-in, portal and small UX | UX-03, UX-04 + portal dates + back loop, LIB-05, ENR-01, STU-14, HR-12 | none |
| **LC-9** | Operations edits in the browser | S-09 (one sub-slice per module family), S-17, S-18 | none |
| **LC-10** | Responsive and accessibility, daily subset | S-15, S-16 | none |

- **Order:** LC-1 → LC-2 → LC-3 → LC-4 → LC-5 complete the MUST list. LC-6
  to LC-10 follow in any order the owner prefers.
- **Before LC-6:** the owner's ATT-08 decision.
- **Optional:** a teacher "my timetable" slice after the ADR 0063
  authorization decision.

## R. Comprehensive Final Local UAT: prerequisites

1. LC-1 to LC-5 published, each with its own browser verification.
2. Whichever SHOULD slices the owner selects, published.
3. A full regression (remote exact-SHA, or `bin/safe-test`) green on the
   candidate.
4. The School Administrator Guide updated for M2–M5, including Transport,
   Hostel, Visitors and Canteen procedures.
5. A fresh demo, rebuilt assets, all 14 personas.
6. A UAT matrix covering every guide procedure, the annual cycle including
   the timetable, a cashier's day, canteen ordering through to the parent
   statement, and the phone and keyboard spot-checks.

## S. Definition of "Local development complete"

- **No S0 or S1 open,** with M2 closed.
- **The MUST items are closed:** M1–M5.
- **The SHOULD items** are either closed, or explicitly accepted or deferred
  by the owner, item by item.
- **Browser-only operation:** every supported workflow in the guide runs in
  the browser; no ordinary School workflow needs API, SQL or console.
  Operator-only actions are documented as such.
- **No server error** from any ordinary input, as shown by the final UAT's
  monitor.
- **Green regression,** including tenancy, authorization and isolation, on
  the final candidate.
- **Legal-gated functions** are visibly and code-enforced as gated, and
  listed in guide §20.
- **The guide and the demo are coherent** with the product.
- **The Comprehensive Final Local UAT passes,** with its residual items
  accepted by the owner.

## T. Staging / PPR state

> **PAUSED BY OWNER DIRECTION — not part of current development sequence.**

- **Published hardening stays:**
  - F-D1: Redis TLS proof and the readiness fix;
  - F-D1-2: the hardened Redis guard;
  - P2-F2: the lookup-key guard and the `.dockerignore` correction.
- **Kept as written:** R-00a (still blocked), R-00b, R-01–R-20, Terraform,
  E10/E11, observability, staging email and E16-R2. The E16 approval runs
  through 2026-11-12. While development stays local, publication to `main`
  still depends on it, so a successor decision is due before then whatever
  the staging plan.

## U. Recommended next checkpoint

**LC-1: Web refusal handling and malformed-id hardening** (closes M1).
- **What's wrong today:** server errors occur on ordinary input in four
  modules, and silent refusals in Attendance. Both are confirmed live.
- **Why first:**
  - the fix is mostly central rather than per page;
  - every later slice's browser verification depends on refusals showing as
    errors instead of 500s.
- **Constraints:**
  - no schema;
  - no change to what is refused.

Not started; it needs owner authorisation.
