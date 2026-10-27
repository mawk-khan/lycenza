# SIS 9.1 — UX, Accessibility and Operator-Clarity Closure (2026-10-27)

Baseline `main` = `3b528903`. SIS-only, local DDEV. No schema or capability
change.

## 1. Findings register (every SIS UX / accessibility finding)

| # | Source | Finding | State at 9.1 start | Closed by | 9.1 action |
|---|---|---|---|---|---|
| 1 | SIS 1.1 §18 | Admissions transition error shows status keys | ALREADY FIXED | SIS 3.2 | — |
| 2 | SIS 1.1 §18 | Guardian self-administration message hidden | ALREADY FIXED | SIS 2.2 | — |
| 3 | SIS 1.1 §18 | `primary` concurrency error not shown | ALREADY FIXED | SIS 2.2 | — |
| 4 | SIS 1.1 §18 | Attendance register heading uses `sectionCode` | ALREADY FIXED | SIS 6.1 | — |
| 5 | SIS 1.1 §18 / DH-18 | Data Health "C (6C)" labels | ALREADY FIXED | SIS 8.1 | — |
| 6 | SIS 1.1 §18 | Applicant DOB printed as raw ISO | ALREADY FIXED | SIS 3.2 | — |
| 7 | SIS 1.1 §18 | Student history Guardian events have no name | OPEN | **9.1** | the event names the Guardian (guardians.view, as on the Guardian list) |
| 8 | SIS 1.1 §18 | Take/Show tables lack `overflow-x-auto` (re-verify) | ALREADY FIXED (verified 390 px) | SIS 6.1 | re-verified |
| 9 | SIS 1.1 §18 | SIS pages use a 32 px phone gutter | OPEN | **9.1** | `px-4 py-6 sm:p-8` on 35 SIS pages (16 px on phones, unchanged on desktop) |
| 10 | SIS 1.1 §18 / S-16 | a11y lint covers only SWC files | OPEN | **9.1** | Students, Guardians, Admissions, Enrollments, Rollovers, Attendance, Data Health pages + `StudentIdentityFields`, `DuplicateStudentWarning` added; 3 findings fixed (2 autofocus, 1 unlabelled rollover select); 0 errors |
| 11 | SIS 1.1 §18 / S-16 | `window.confirm` on SIS pages | OPEN (13 calls) | **9.1** | all 13 now the shared `ConfirmDialog` (`useConfirmation()`), with a title, the record and effect, and a button naming the action |
| 12 | SIS 1.1 §18 | Radio groups lack fieldset (Take, conversion Guardian mode) | ALREADY FIXED | SIS 6.1, SIS 3.2 | — |
| 13 | SIS 1.1 §18 | Unlabelled Guardian name inputs in conversion | ALREADY FIXED | SIS 3.2 | — |
| 14 | SIS 1.1 §18 | Missing middle/last-name errors on Add applicant | ALREADY FIXED | SIS 3.2 | — |
| 15 | SIS 1.1 §18 | Guardian search list is not a `SearchCombobox` | PARTIAL (labelled, works by keyboard) | — | NO ACTION: functional and labelled; a component swap is cosmetic |
| 16 | SIS 2.1 | Mobile hint with a space; error echoes the number | ALREADY FIXED | SIS 2.2 | — |
| 17 | SIS 4.1 | Conversion date `aria-describedby` | ALREADY FIXED | SIS 3.2 | — |
| 18 | SIS 2.2 | Applicant searches first OR last name only | OPEN | **9.1** | shared `NameSearch` (Applicant directory + Admissions applicant filter), escaping unchanged |
| 19 | SIS 2.2 | Communications Guardian search uses its own rule | OUTSIDE SIS | — | Communications backlog |
| 20 | SIS 6.1 | Times shown as `08:30:00–09:15:00` | OPEN | **9.1** | `formatClockTime` / `formatTimeRange` on Attendance and Timetable pages |
| 21 | SIS 6.1 | Student-history back link reads "Attendance exceptions" | OPEN | **9.1** | "← Attendance registers" (a neutral, true destination) |
| 22 | 9.1 sweep | Date-only values printed as raw ISO on SIS pages (Student, placements, years, terms, closed-year placements, Attendance lists) | OPEN | **9.1** | `formatCalendarDate` (11 pages) |
| 23 | 9.1 sweep | "← Dashboard" links to the page titled Home | OPEN (SIS pages) | **9.1** | "← Home" on SIS pages (other modules: finding) |
| 24 | 9.1 sweep | `hasFilters` computed once on Enrollments / Rollovers | OPEN | **9.1** | reactive (as SIS 3.2 / 2.2) |
| 25 | 9.1 sweep | Refusals quoting status keys: Enrollment / subject-enrollment / Academic Year transitions, Attendance no-op and closed-year | OPEN | **9.1** | operator wording, same codes |
| 26 | 9.1 sweep / UAT-UX-01 | Most SIS mutations show no success message | OPEN | **9.1** | `flash` on Guardian, relationship, Student, placement, onboarding and Admissions mutations |
| 27 | LC S-15 | Phone overflow on non-SIS pages | OUTSIDE SIS | — | — |
| 28 | SIS 8.1 | Stale rollover plan has no "cannot run" notice | RESIDUAL | — | Rollover residual (owner decision) |

28 findings reconciled; 13 open at the start of 9.1 (7, 9, 10, 11, 18, 20–26), all closed.

## 2. Evidence

- **390 px sweep** (DDEV, real Chromium): 26 SIS pages. All had `scrollWidth`
  = `clientWidth`, 0 unlabelled controls, 0 raw ISO dates, 0 `HH:MM:SS`
  and 0 UUIDs in visible text. At 125 % text, 3 dense pages showed no
  overflow.
- **Keyboard:** a Guardian contact deactivated and reactivated without the
  mouse.
  - The dialog opens with focus inside (Cancel), and Escape returns focus
    to the opener.
  - Tab reaches the named confirm button; the success flash is shown.
- **Refusals:** a malformed id is 404 on 5 SIS routes; a teacher gets 403
  on the Student directory.

## 3. Decisions still open (not defects)

- **Mark all present** (ATT-08): owner decision.
- **Guardian status reason catalogue:** owner decision.
- **Student-number case policy** (case-insensitive uniqueness or
  canonicalization): owner decision. The Data Health warning stays.
- **Teacher "My classes"** (SIS 6.3): optional. A teacher reaches every
  Attendance task from My Attendance (proved in SIS 6.1 and 9.1).
- **Rollover residual:** bulk Repeat / Manual Review, bulk explicit roll
  numbers, and a stale-plan notice or close. Optional.
