# SIS 11.3 — Section Capacity, Vacancy & Placement Planning

Status: **IMPLEMENTED (local DDEV)** · 2026-10-31 · branch `integration/sis-11-3`

This checkpoint adds to SIS 11.1/11.2 and uses the same closed
`StudentReportRegistry`. The core SIS score stays 96/100.

## 1. OD-SIS-6 (owner decision, 2026-10-31)

> **Approved.** An operational Section headcount against advisory capacity
> may be shown outside the V11.1 Analytics gate.

The decision is limited to the following:

- **Allowed:** the configured capacity, the Section's strength today (a
  count), seats available, at capacity, over capacity (the actual
  overage) and not configured, shown wherever an operator plans or places.
- **Not allowed:** percentages, ratios, averages, trends, time series,
  month-on-month strength, demographic breakdowns, forecasts or dashboards.
  V11.1 / ADR 0040 still govern those.
- **Advisory only:** a full Section still accepts an otherwise valid
  placement. There is no hard cap, waitlist, automatic Section choice or
  overflow Section; a hard-cap mode needs its own owner checkpoint.

## 2. Existing capacity: reused (schema decision **A**)

`sections.capacity` (nullable integer, `create_sections_table`, "advisory
only") already existed. It is validated `min:1` by
`SectionAdministrationService`, audited (`section.updated` before/after),
edited on *School setup → Sections*, and exposed by the Section API. No
second field was added.

**What was missing, and is added by migration
`2027_01_20_090000_guard_section_advisory_capacity`:**

- **`sections_capacity_positive_check`:** NULL means not configured; a
  configured value is at least 1. `unsignedInteger` is a plain integer in
  PostgreSQL, so 0 or negative values were possible through a direct
  write.
- **`sections_capacity_closed_year_guard`** (trigger): a closed year's
  Section capacity can no longer change. The service refuses first, with a
  field error.

The migration is additive. Nothing is backfilled; existing Sections without
a value read "Capacity not configured". `down()` drops exactly these two
objects.

## 3. Ownership and lifecycle

- **Owner:** Academic Structure owns capacity (the Section). Students owns
  strength (the placement predicate). Admissions, Enrollment, rollover and
  reporting only read them.
- **Draft and active year:** editable by `academics.structure.manage`. It
  may be set below the current strength: the edit form says, before saving,
  "currently has 32 Students… will show as over advisory capacity by 2".
  The save is allowed and audited; no placement changes.
- **Closed year:** capacity is history and cannot be changed (service and
  trigger). Name/code edits keep their existing rules.
- **Concurrency:** the Section edit form had no stale-form token before, and
  none was added (the brief allows this). The last save wins, as for name
  and code. Placements are never serialized against capacity.

## 4. Strength (headcount) semantics

`StudentEnrollmentRosterReadService::sectionStrengthQuery()` uses the **one**
placement predicate (the Attendance roster's):

- `starts_on ≤ as-of ≤ ends_on` (or open-ended);
- the placement's year, Campus and Grade equal the Section's;
- counting **distinct Students**.

It is temporal, not status-based, exactly as the roster is.

| Case | Counted today? |
|---|---|
| Current placement | yes |
| Future placement (starts after today) | no, until its start date |
| Ended placement (withdrawn/completed/transferred) | no, after its end date |
| Placement cancelled before start (ADR 0078, `ends_on = starts_on − 1`) | **never** (empty interval) |
| Transfer effective today | destination only (the source ended yesterday) |
| Left and re-joined the same Section | once |
| Another Section / another School | no |

**As-of date:** the School-local today (`SchoolTimezone::today()`), clamped
into the Section's year.

- An **active** year counts today.
- A **draft** year that has not started counts its first day, so the
  placements already created for next year are its planned strength.
- A **closed** year has no strength: it shows capacity only, and its
  history stays on the placement reports.

Every display shows the date it counted.

**Seats:** `capacity − strength`.
- Positive: "N seats available" (1: "1 seat available").
- Zero: "At advisory capacity".
- Negative: "Over advisory capacity by N", never clamped.
- No capacity: "N Students · Capacity not configured".
- Always text, never colour alone.

## 5. Where it shows

| Surface | What |
|---|---|
| **Student reports → Section capacity** (`students.section_capacity`) | One row per Section: Section (link to its placements), Campus, year, "28 / 30", seats, counted-on date. Filters: year, Campus, Grade, state (available, at, over, not configured). Sorts: Year/Grade/Section, fewest seats first, most Students first. 25 per page; state filter and sorts run in SQL. |
| School setup → Sections | "Students today" column (whole-School `enrollments.view` only), "Not configured" capacity, advisory hint and below-strength warning on edit, capacity read-only for a closed year, "Section updated." confirmation. |
| Enrol a Student / same-Campus Section change | Each Section choice reads e.g. "Grade 5 · Section A · Main · 2026-27 — 28 / 30 · 2 seats available". |
| Campus transfer | The same, on each destination. |
| Admissions conversion | The same, on each compatible Section. A full Section stays selectable. |
| Rollover plan | Target Section choices show occupancy. An **"Advisory capacity (warning only)"** panel lists target Sections that already-placed plus planned (promote/repeat, not yet executed) would put over capacity: "…has an advisory capacity of 30; 26 already placed and 6 planned would make 32, over by 2." It never blocks validation or execution, and is separate from roll-number collisions (still the dry run's hard rule). |

The planned rollover target uses the execution precedence:

1. the item's own target Section;
2. else the Plan's Section-specific mapping;
3. else the Grade default.

This is one grouped query (`RolloverCapacityAdvisory`).

## 6. Authorization and scope

- **Report:** CAMPUS_AWARE on `enrollments.view`, through
  `StudentAccess::capacitySections()`.
  - Whole School: every Section of every year (closed ones capacity only).
  - Campus set: the active year's Sections on in-scope Campuses only. A
    Campus reader never sees a draft or closed year (rule 101), and a forged
    Campus or year matches nothing.
  - `students.view` or `academics.structure.view` alone does not open it.
- **Sections page:** unchanged authority (`academics.structure.view`). The
  strength column needs whole-School `enrollments.view` (placement data).
- **Placement choices:** shown to the actor who is placing, under that page's
  existing authority (`enrollments.manage`, `admissions.manage`, rollover
  manage). The option lists are not widened.
- **Privacy:** occupancy carries a count, never a name. The report row links
  to the Section's placements, which check their own authority.

## 7. Boundaries

- **Not built:**
  - no Data Health check (`section.over_capacity` is NOT a finding: advisory
    capacity may legitimately be exceeded);
  - no percentage, trend, average, demographic or time series (pinned by
    `SectionCapacityTest`);
  - no export;
  - no new capability, role, route or Campus adoption.
- **API:** the Section API already returns `capacity`; unchanged. Its writes
  now get the database guards too.

## 8. Coverage

- **SR-24 "Strength reports" (Analytics / Academic Structure):** its
  operational part is implemented — seat fill against sanctioned capacity,
  the per-class gap and vacancy (OD-SIS-6).
- **Stays gated:** opening/closing strength, month-wise movement, gender and
  age matrices and branch-wise counts (Analytics D-01..D-04, ADR 0040,
  D-15).
- **SIS-owned report subset:** unchanged (6/8 governed, 8/8 dispositioned).
  SR-24 is a cross-module family.
- **Advanced SIS screenshot reporting coverage:** the operational capacity
  and vacancy capability of SR-24 is now governed (`students.section_capacity`).

## 9. Verification

- `tests/Feature/StudentReports/SectionCapacityTest.php` (13).
- The updated registry/hub tests.
- The broad SIS regression.
- The migration rollback proof.
- Browser journeys A–M (completion report).
