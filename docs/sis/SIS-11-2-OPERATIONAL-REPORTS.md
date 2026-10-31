# SIS 11.2 — Operational Student Reports & Remaining SIS-Owned Report Families

Status: **IMPLEMENTED (local DDEV)** · 2026-10-31 · branch `integration/sis-11-2`

This checkpoint adds to SIS 11.1 (`SIS-11-1-REPORTING-FOUNDATION.md`) and uses
the same closed `StudentReportRegistry`. The core SIS score stays 96/100.

**Baseline.** The brief named `main = 5ccebade`. `main` had since moved to
`3a54892c`, the owner-authorized HR.EXP-0 documentation publication: five
files under `docs/hr/`, no code. SIS 11.2 is built on `3a54892c`.

## 1. The three remaining SIS-owned families

The definitions below come from `SIS-REFERENCE-CAPABILITY-MAP.md` (Table A),
`SIS-EXPANSION-CLOSURE.md` and `SIS-EXP-R1-POST-P1-AUDIT.md`. All three were
classified at family level from the 318-screen pack (SIS.EXP-0). The pack
was not re-read screen by screen in this checkpoint.

### SR-02 — Roll-integrity exception lists (2 screens)

- **Reference:** two lists: "Inactive but still on rolls" and "promotion
  pending fee clearance". No filters or summaries are recorded beyond the
  list itself.
- **Lycenza:** Data Health
  - `InactiveStudentWithActiveEnrollmentCheck`;
  - `ActiveStudentWithoutCurrentPlacementCheck`;
  - and the other read-only checks (`/app/data-health`), each opening on its
    source capability.
- **Disposition: ALREADY EQUIVALENT** (Data Health, by design). Data Health
  reports integrity conditions and is deliberately not a registry report
  (SIS 11.1 §4, rule 123).
- **The fee-gated half is REJECTED BY DESIGN:** promotion is never gated on
  fees (SR-37).

### SR-21 — Student documents and checklist (4 screens)

- **Reference:** a per-class grid of birth certificate, leaving certificate,
  immunisation certificate, photograph and report card, plus a document
  viewer.
- **Lycenza:** per-Student documents (SIS.EXP-3), Highly Sensitive.
  - Four closed categories; current MFA to read.
  - A Student 360 Documents card (counts per category).
  - No class grid.
- **Disposition: owner and legal deferred (decision recorded 2026-10-31:
  "Keep deferred").** A class-wide grid across Highly Sensitive documents
  comes close to the School-wide file directory that rule 120 forbids. Its
  main reference categories (immunisation = health, photograph = image
  consent, birth certificate as statutory identity) are legally gated
  anyway. Not counted as governed.

### SR-25 — Sibling report (5 screens)

- **Reference:**
  - families grouped by parent, with dues for this and last year;
  - "no siblings" and "all" tabs;
  - residential category.
- **Lycenza before:** the whole-School profile Family block (SIS.EXP-1).
  The School-wide list was open as an owner decision (SIS-019 / PV1-17).
- **Owner decision (2026-10-31): approve a names-only list.**
- **Disposition: PARTIAL → EXTEND.** New rendered report `students.siblings`
  (§3).
- **Not adopted:**
  - dues (D-14, Finance/Analytics);
  - residential category;
  - the "no siblings" tab, which is every other Student and adds nothing a
    directory does not;
  - counts.

## 2. `admissions.applications` — the Admissions application list

The existing Campus-aware list (`/app/admissions`, ORG.7D-3) is extended and
registered. It is not duplicated.

| Aspect | Contract |
|---|---|
| Authority | `admissions.view`; Campus-aware through `AdmissionApplicationAccess::directory()`. A Campus officer sees their Campuses only; a forged Campus matches nothing; counts, search and empty state are scoped. |
| Model | Applicant ≠ Application ≠ Student. Each row is an **application**. Year, Campus and Grade are the application's own, never the converted Student's current class. |
| Statuses | The canonical six (draft, submitted, accepted, rejected, withdrawn, converted) with the existing Admissions labels. No reporting status. |
| Filters | Applicant name (shared `NameSearch`: every word, any order, case-insensitive, wildcards literal), status, academic year, Campus, Grade, **Created from / Created to**. |
| Dates | **Created** is the only lifecycle date with a column for every application. There is no submitted or decided timestamp; `converted_at`/`terminal_at` exist only for some states. Created dates are School-local calendar days, turned into that day's UTC bounds, so the index is still used. |
| Sort | Closed `AdmissionApplicationReadService::SORTS`: newest first, oldest first, Applicant name, status. |
| Columns | Applicant, applying for (Grade · Campus · year), status, created date, and a converted **Student** link only for whole-School `students.view` holders (the Profile is whole-School; it re-checks). The list never carries the Student's name or number, date of birth, contacts or fees. |
| Inactive Campus | A historical application on a Campus that became inactive stays listed as "Name (inactive)", in the rows and in the Campus filter. |
| Page | 20 per page, bounded. *Clear filters* added (server values; the sort is not a filter). |

## 3. `students.siblings` — the sibling list (SR-25)

- **Rule:** a family is one **active** Guardian linked to two or more
  Students of the School. This is exactly `StudentSiblingReader`'s rule: no
  household table, and flags are ignored. Two Guardians of the same children
  are two rows, as on the reference.
- **Authority:** whole-School `students.view` AND `guardians.view` (rule
  118: siblings are never on a Campus surface). A Campus grant opens
  nothing.
- **Current class:** each Student's current class needs whole-School
  `enrollments.view`. Without it the class is absent and a class filter
  matches nothing, so a guess reveals nothing.
- **Content:** Guardian name, then each Student's name, number and
  (inactive) mark. No contact, relationship flag, date of birth, address,
  residential category, dues or count beyond the row total.
- **Filters:** search (Guardian name, or a linked Student's name or
  number), current Campus, Grade and Section. Section options name their
  Campus and Grade, so the page offers no impossible pair.
- **Read model:** `App\Domain\Guardians\Application\Reports\SiblingReport`
  (Guardians owns the sibling rule; Guardians → Students is the existing
  direction).
- **Bounded:** one count, one page query, one query for the families'
  Students and one eager-loaded current-placement read.

## 4. Shared-page improvements (all rendered reports)

- Validation errors are shown under each filter (`aria-invalid` and
  `aria-describedby`).
- An end date cannot be picked before its start.
- A select filter with no choices for the viewer is hidden.
- An option may name another filter's key, so dependent choices narrow, and
  an impossible pair is cleared before submit.

## 5. The eight SIS-owned report families

| Family | Exact name | Before 11.2 | After 11.2 | Registry key / equivalent | Status |
|---|---|---|---|---|---|
| SR-02 | Roll-integrity exception lists | Data Health | Data Health | `/app/data-health` (not a report by design) | ALREADY EQUIVALENT; fee-gated half rejected |
| SR-03 | Student directory | governed | governed | `students.directory`, `students.placements` | implemented |
| SR-10 | Inactivation / reactivation dashboard | governed | governed | `students.lifecycle` | implemented (list; approval queue not adopted) |
| SR-12 | Exit audit / registers | governed | governed | `students.movements`, `students.lifecycle` | implemented |
| SR-21 | Student documents and checklist | per-Student documents | unchanged | Student documents page | owner/legal DEFERRED (decision 2026-10-31) |
| SR-25 | Sibling report | profile Family block | **governed** | **`students.siblings`** | implemented (names only) |
| SR-36 | Section / Campus movement history | governed | governed | `students.movements` | implemented |
| SR-37 | Promotion reports | governed | governed | `students.movements` + rollovers | implemented (no fee gating) |

**Advanced SIS Screenshot Coverage, SIS-owned report subset:**
- **6 / 8 governed** (5 → 6, SR-25 added);
- **8 / 8 dispositioned**, with no family unexplained.

SR-02 is equivalent through Data Health by design, and SR-21 is deferred by
owner decision. Neither is counted as governed.

**Also implemented:** `admissions.applications` covers the operational list
inside **SR-39** (Admission analytics), a cross-module family whose revenue
and analytics half stays gated (D-14, D-01..D-04).

SIS 11.3 adds `students.section_capacity` (`SIS-11-3-SECTION-CAPACITY.md`).

## 6. Boundaries

- **Not built:** export, print, analytics, conversion rate, funnel,
  demographics, Finance figures (application or registration fee, discount,
  collected, refund — the Finance/SIS backlog in SIS 11.1 §5), contacts and
  date of birth.
- **No schema change.** No migration, capability, role, Campus adoption or
  new route; both reports use the 11.1 routes.
- **`WebRouteParametersGuardTest`** (with the rest of `tests/Feature/Routing`)
  ran locally before the first push, even though no route changed. The
  local gate list now includes it (§7). CI already runs it in the canonical
  suite, so no CI change.

## 7. Verification

- **Tests:**
  - `AdmissionApplicationsReportTest` (9);
  - `SiblingReportTest` (7);
  - the updated `StudentReportRegistryTest` / `StudentReportsTest`.
- **Local focused suites:** Routing, Authorization (coverage, refusal sweep,
  Campus), Navigation, Admissions, Guardians, Students, App, Enrollment,
  Data Health, Attendance, Academic Structure, Tenancy, Postgres,
  Architecture, Accessibility, Setup and Demo.
- **Local pre-push gate list, now including Routing (rule 82 practice):**
  - the focused suites above, with `tests/Feature/Routing` always included;
  - `.claude/skills/publish-gate`;
  - the frontend gates.
- **Browser journeys:** recorded in the completion report.
