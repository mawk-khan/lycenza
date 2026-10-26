# ADR 0077: Draft Academic Calendar Correction

- Status: **Accepted and implemented (2026-10-26).** Closes SIS Must-Complete
  **MC-3** (`docs/product/SIS-CURRENT-STATE-AUDIT-2026-10-26.md` §22), SIS 5.1.
- Date: 2026-10-26
- Module: Academic Structure (owner of `academic_years`, `academic_terms`), with
  a dependency port implemented by Students, TeachingAssignments, Examinations,
  LMS and Fees.
- Amends: the E21.3D "Academic Year dates are immutable" position (ADR 0058,
  note of 2026-10-02; `docs/security/E21-RETENTION-DETERMINATION.md` §5.8;
  migration `2026_11_13_090000_add_academic_operations_retention`). The retention
  clock it protects is unchanged for every year that has left draft.

## 1. Owner decision

> Draft Academic Year and Term dates may be corrected while the year remains
> draft, subject to dependent-data integrity. Active and closed calendar
> boundaries remain immutable.

There is no reopening: a year never returns to draft, and activation and
closure are unchanged.

## 2. Problem (confirmed at `6e239815`)

- `academic_years_freeze_dates` refused every date change, for every status and
  every role (even the schema owner).
- Terms had no database freeze, but no application path changed their dates.
  `rename()` takes name and code only.
- Years cannot be deleted, and the overlap check covers every status. So one
  mistyped draft year also blocked creating the correct one.
- The only repair was owner SQL around the trigger.

## 3. Database (migration `2027_01_10_090000_allow_draft_academic_year_date_correction`)

1. **`academic_years_freeze_dates`** lets a date change through only when the
   row is `draft` both before and after the UPDATE. These stay refused:
   - an active or closed year;
   - activating and re-dating in one statement;
   - marking a year draft and re-dating it in one statement.

   The trigger itself (`BEFORE UPDATE OF starts_on, ends_on`, only when a date
   really changes) is unchanged.
2. **`academic_years_no_return_to_draft`** (new) refuses any UPDATE that sets
   `status` back to `draft` once a year has left it. Without it, "mark draft,
   then re-date" in two statements would bypass (1). `academic_years` had no
   status-transition guard before. Nothing in the application ever returns a
   year to draft.
3. **Rollback** restores the absolute freeze and drops (2). Dates corrected
   meanwhile stay as corrected; no row is rewritten either way.
4. **Retention.** The freeze existed so that retention clocks anchored on
   `ends_on` (E21.3D) cannot move. For a draft year this still holds, for two
   reasons:
   - a draft year has never been operational: Attendance and curriculum
     deliveries require an active year;
   - since E21-RH.7, every retention delete also requires each row's
     database-recorded `retention_recorded_at` to predate the cutoff.

   So re-dating a draft year can never make a row eligible before its full
   period has run from recording. Communications retention maps a date to the
   one year containing it. Ambiguity (zero or two years) keeps the row, so
   that direction fails safe.

## 4. Application

- **`AcademicYearService::correctDraftDates()`** changes dates only. Name,
  code, status and School are never taken from the input. All of these run
  under the year's `FOR UPDATE` lock:
  - The year must still be draft.
  - The form's `expected_updated_at` must match; otherwise the correction is
    refused as stale, never applied over a newer state.
  - Creation's rules apply: start before end, and no overlap with another year
    (itself excluded).
  - Every Term must stay inside the new dates. Otherwise: "Correct the Term
    dates first: N Terms would fall outside…".
  - Every `AcademicYearDateDependency` must report zero records outside. The
    dependencies are tagged `academic_structure.year_date_dependencies` and
    registered in `AppServiceProvider` (see the table below).
  - Nothing is moved, truncated or ended for the operator. The refusal names
    the kind and count, never an id or a person.
- **`AcademicTermService::correctDraftDates()`** changes a Term's dates only,
  while its year is draft. It holds the year's `FOR UPDATE` lock, which
  serializes it with the year's own correction, its activation and other Term
  corrections. The new dates must lie inside the year and not overlap a sibling
  (a shared boundary day is allowed, as at creation). Term creation now reads
  the year `FOR SHARE`.
- **Activation** (`AcademicYearService::activate()`) re-reads the draft row
  `FOR UPDATE` and repeats its status and start-date checks there. So a
  correction that committed first is seen, and one still running makes the
  activation wait.
- **Audit:** `academic_year.dates_corrected` / `academic_term.dates_corrected`
  record actor, School, subject, changed field names and the before/after dates
  (dates are not sensitive; the existing `academic_year.updated` convention
  logs before/after values).
- **Web only** (SIS 3.1 precedent):
  - School setup → Academic years → **Correct dates** (draft years), and
    Terms → **Correct dates** (Terms of a draft year).
  - Each opens its own page. An active or closed year has no page: requesting
    one redirects with a refusal, and a forged PUT is refused (LC-1).
  - Capability: the existing `academics.years.manage`.
  - The API keeps its rename-only `PATCH`.

## 5. Dependencies (what must stay inside a corrected year)

| Owner | Records | Rule |
|---|---|---|
| Academic Structure | Terms | inside the year |
| Students | placements | start inside (SIS 4.1); end not after the year; `completed` ends on its last day |
| TeachingAssignments | required and elective teaching assignments | start inside; end not after |
| Examinations | examinations (papers lie inside them) | inside |
| LMS | Assignment due dates | inside the Offering's year |
| Fees | instalment periods; standing concession windows | inside; immutable once in force |

**Not dependencies:**
- Attendance sessions and curriculum deliveries cannot exist in a draft year.
- Timetable entries, Sections, Subject Offerings, Admissions applications,
  charges and selections reference the year by id only.
- Student subject enrollments carry no year bound.

**Concurrency.** Every writer of a dependent record now reads the year
`FOR SHARE` inside its own transaction:
- placements (SIS 4.1);
- teaching assignments, elective teaching assignments;
- examination create/update (the year check moved inside the transaction);
- LMS Assignment create/update (likewise);
- fee instalment schedules, standing concessions;
- Term creation.

So against a correction (which holds `FOR UPDATE`), either the record is
committed first and the correction is refused, or the correction is committed
first and the writer's date check sees the new dates. Proven with two real
processes:
- activation vs correction, both orders;
- placement vs correction, both orders.

## 6. Not changed / not done

- Active and closed calendars; activation and closure; no reopen path.
- Closed-year Section and Term creation (SIS 5.2 / 4.2 findings).
- Rollover target-year status (SIS 5.2).
- Placement history (frozen).
- Timetable model.
- Year name: free text, never derived from dates, so a correction does not
  rename it.
