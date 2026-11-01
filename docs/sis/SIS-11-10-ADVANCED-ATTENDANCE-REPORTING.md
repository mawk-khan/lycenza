# SIS 11.10 — Advanced Attendance Operational Reporting

Status: **IMPLEMENTED (local DDEV)** · 2026-11-01 · branch `integration/sis-11-10`

| Item | Value |
|---|---|
| Baseline | `main = 721dcd9a` (SIS 11.8) |
| Reference family | SR-29 Student attendance reports (25 screens); SR-07, SR-28, SR-30, SR-31 classified |
| New reports | 2 rendered (`attendance.registers`, `attendance.corrections`) + 2 existing pages governed |
| Schema | **none** |
| Capabilities | **none** (all on the existing `attendance.view`) |
| Owner decision recorded | **OD-SIS-10A** — the register recorder's name may be shown (below) |
| Core SIS score | 96/100, not rescored |

## 1. Current-state audit (before any change)

| Capability | Existing | Partial | Missing | Action in 11.10 |
|---|---|---|---|---|
| Take a register (office) — `/app/attendance/take` | ✓ | | | unchanged |
| Teacher-owned registers — My attendance (TCH.4, `attendance.teacher`) | ✓ | | | unchanged; Teachers gain nothing |
| Register list — `/app/attendance` (one date filter) | | ✓ | | kept; the new register history covers ranges |
| Register detail with corrections — `/app/attendance/{session}` | ✓ | | | drill-down target |
| Registers not taken — `/app/attendance/registers` (ORG.9C-1, SB-01) | ✓ | | | **governed** as `attendance.register_status`; `date` added to its filter state |
| One day's absent/late/excused — `/app/attendance/exceptions` (V11.2) | ✓ | | | **governed** as `attendance.exceptions` (filter key `section_id`) |
| One Student's history — `/app/attendance/students/{id}` (ATT-07, audited) | | ✓ (range only) | | **status and Subject filters** added |
| Register history over a range (class, subject, period) | | | ✓ | **new** `attendance.registers` |
| Who recorded a register | | | ✓ | **new** "Recorded by" column + filter (OD-SIS-10A) |
| Correction report | | ✓ (per register, per Student) | | **new** `attendance.corrections` |
| Session/period model | ✓ (`attendance_sessions`: timetable entry, Offering → Subject, Period, start/end times, submitter) | | | used as is |
| Reports in the SIS registry | | | ✓ (none) | new `attendance` category |
| Campus scope | ✓ (`attendance.view` Campus-adopted, `AttendanceAdministrationAccess`) | | | reused |
| Export / print | | | ✓ | **browser-only** (§6) |
| Student 360 Attendance card | ✓ | | | unchanged (no pin change) |

Before 11.10 there were **six** distinct Attendance read surfaces (list,
detail, registers not taken, exceptions, Student history, 360 card), plus
My attendance and the development-only portal. **Two** new reports were
needed; nothing was rebuilt.

## 2. OD-SIS-10A — the recorder's name

The SIS 11.1 record (§3.5) said a "recorded by" column "would need its own
decision". This brief makes it.

> The name of the User who **submitted** a register, or **corrected** a
> record, may be shown on the Attendance operational reports to
> `attendance.view` holders, for register accountability.

- The display name only; never a User id, email, MFA or audit data.
- People filters are closed choices cut to the registers or records the
  viewer may see. A User outside them is a 422; nothing widens.
- It is the operator, **never the timetable's teacher column**. Attendance
  still never filters or authorizes by `teacher_id`
  (`TeacherAttendanceArchitectureGuardTest`); this is not an HR or workload
  report.
- Other SIS reports keep "no actor" (rule 123 amended for this exception
  only).

## 3. The reports

All under `attendance.view`, CAMPUS_AWARE. The Campus scope is applied in
SQL by each register's or record's **stored** Campus through
`AttendanceAdministrationAccess`; a filter only narrows.

### `attendance.registers` — Attendance register history

One report covers three families: class register history, subject and
period registers, and recording activity.
- **Source:** submitted `attendance_sessions`, joined once.
- **Filters:** academic year (closed years included and never forced to the
  current one), Campus, Grade, Section, Subject, from, to, recorded by,
  corrected (yes/no).
- **Columns:** date, period ("09:00–10:00"), class, Subject, recorded by,
  recorded at (the School-local instant, "31 Aug 2026, 10:00"), corrected
  (Yes/No — never how many), and an "Open register" link.
- **Sorts (closed):** newest, oldest, class, Subject, most recently recorded.
  50 per page.

### `attendance.corrections` — Attendance corrections

- **Source:** one row per append-only `attendance_record_corrections` row.
  Evidence is never rewritten; a second correction is a second row.
- **Columns:** Student (links to the audited Student history), register
  date, period, class, Subject, change ("Absent → Present"), reason (the
  closed, absence-neutral code as text), corrected by, corrected at, and the
  register link.
- **Filters:** Student name or number (shared `StudentSearch`), year,
  Campus, Grade, Section, from, to, reason, corrected by.
- **Excluded:** free text, health detail and raw audit payload.

### Governed existing pages (unchanged behaviour)

- **`attendance.register_status`, "Registers not taken":** submitted,
  pending or not submitted for one School day, with the Open and Take links.
  ATT-07 / SB-01 are preserved.
- **`attendance.exceptions`, "Absent, late and excused":** one day.
- **Student history:** reached from every Student link (no hub entry, since
  it is per Student). It gains **Status** and **Subject** filters, applied in
  SQL and audited as codes. The one-year range limit and the per-read audit
  are unchanged.

## 4. Semantics and safety

- **Dates:** `attendance_date` is a School-local date compared as a date,
  never through UTC. Instants (submitted, corrected) are shown in the
  School's timezone, and times read "08:30–09:15".
- **Teacher scope:** the reports need `attendance.view`. A Teacher
  (`attendance.teacher`) is refused on every report, the register status
  and the Student history; their own registers stay on My attendance.
- **Closed years** stay readable; the reports have no write control.
- **Tenant isolation** was tested with a second School of identical shape
  (same Campus, Section and Subject names, same dates): no row, option or
  correction crosses.
- **Malformed and forged filters:**
  - a malformed id is a 422, never a 500;
  - a foreign Campus, Section or Subject matches nothing;
  - a foreign "recorded by" is a 422;
  - an end date before its start is a 422.
- **Performance:** one joined page query; 10 registers on 5 dates run no
  more queries than 2.

## 5. Boundaries

| Item | Decision |
|---|---|
| Percentages, monthly averages, rates, trends, charts | **Analytics-gated** (ADR 0040). None introduced, and pinned: no `percent`, rate, avg, count or sum in the read models |
| Demographic breakdowns (gender, transport, residential views) | Not adopted |
| Biometrics / RFID / device logs (SR-28) | Legal/security gated; nothing added |
| WhatsApp absence messages (SR-28) | Communications; nothing added |
| Student Leave / excused-by-leave (SR-31) | Gated; statuses unchanged |
| Examination attendance | Examinations ERP |
| Professional / higher-education cohorts (SR-07) | `DO_NOT_BUILD` (K-12 scope); existing filters cover cohort needs |
| Manual monthly totals (SR-30) | `DO_NOT_BUILD` |
| Timetable (periods, schedules, rooms, workload) | Timetable ERP; the reports read the register's stored period only |
| HR (staff attendance, workload, payroll) | HR; "recorded by" is not an HR measure |
| Daily (non-period) attendance, "mark all present" | Attendance owner decision PV1-19 / ATT-08, unchanged |
| Data Health | Unchanged (no new finding) |

## 6. Export and print review

**Browser-only for all four Attendance entries.**
- Attendance is Sensitive (ATTENDANCE.md §4). A file would need its own
  dataset capability (an `attendance.export`) and a grant review.
- SIS 11.4's print view is bound to the same per-dataset export capability.
- `students.export` is **not** stretched to Attendance.
- None is in `StudentReportRegistry::EXPORTERS`; export and print return 404.

## 7. Reference reconciliation (SR-29 and neighbours)

| Reference feature | Status |
|---|---|
| Period-wise / session attendance | **Implemented** (register history by period and Subject) |
| Student attendance report | **Implemented** (Student history with date, status and Subject) |
| Class / Section attendance | **Implemented** (register history by year, Campus, Grade, Section) |
| Subject / session attendance | **Implemented** (Subject filter on the register's own Offering) |
| Staff marking compliance / recording activity | **Implemented** (recorded by, recorded at; OD-SIS-10A) |
| Attendance not marked — class list | **Equivalent/stronger** (registers not taken, with pending and Open/Take) |
| Correction history | **Stronger** (closed reasons, append-only, report + per register + per Student) |
| Late comers / absentees for a day | **Equivalent** (exceptions page) |
| Monthly report, register grid, consolidated daily | Partial: listings exist; the monthly grid with totals is analytics-gated |
| Percentages, overall/term-wise %, averages | Deferred (analytics gate) |
| Gender / transport / residential views | Not adopted (demographic) |
| Biometrics / RFID | Blocked (legal/security) |
| Examination attendance | Other ERP |
| Professional cohort report | Not adopted (K-12) |
| WhatsApp absence messaging | Other ERP (Communications) |
| Export | Deferred (browser-only) |
| Print | Deferred (bound to export capability) |

SR-29 stays **PARTIAL** — the remaining gap is the analytics-gated rate and
monthly-grid half — but every operational listing is now covered.

## 8. Evidence

- **Tests:** `AttendanceReportsTest`, 11 tests:
  - governance and browser-only;
  - history columns, recorder and School-local time;
  - filters, multi-date pagination and the query bound;
  - corrections;
  - Campus scope and forged filters;
  - Teacher/Librarian refusal, malformed and forged ids;
  - a Teacher who OWNS a register (role, linked Employee, current teaching
    assignment) still reaches no report, register status, register or
    Student history, for the owned class or any other;
  - a closed year stays reportable, is labelled "(closed)" and is never
    forced to the current year;
  - a same-shaped second School;
  - Student history filters;
  - the read-model architecture pin.
- **Updated pins:** the registry order and sorts, the hub list, and the
  Student history filter state.
- **Browser journeys (DDEV demo, fresh `npm run build`):** 58/58 checks.
  - **A:** the hub.
  - **B–C:** register history by year, Campus, Grade, Section, dates and
    Subject, including URL state through reload, sort, Back, a copied URL
    and Clear filters.
  - **D:** Student history status and Subject filters.
  - **E:** recorded-by (no email or HR field, no rate column).
  - **F:** registers not taken, Open and Take.
  - **G:** a correction through the real register workflow, reflected in
    the register, the corrections report, register history and Student
    history.
  - **H:** a Campus principal on the Early Years Campus sees nothing, and
    forged Main Campus and Section filters stay empty.
  - **I:** Teacher and Librarian refused.
  - **J:** the Annexe admin sees nothing, and Demo ids are 404.
  - **L:** no page overflow at 390 px on six pages.
  - **M:** keyboard filters and apply; labels; an associated date error; a
    malformed id is a 422.
  - **K (closed year):** the demo has no closed-year registers, so it is
    proven by PHPUnit only.
  - The registers-not-taken journey backdated the demo timetable's
    `created_at` (the page lists only classes that existed on the chosen
    day, and a reset recreates them); the demo was reset afterwards.
