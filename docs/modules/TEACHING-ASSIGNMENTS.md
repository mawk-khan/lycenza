# Teaching Assignments (TCH.2)

`App\Domain\TeachingAssignments` holds one fact: **which Employee owns which
Section + required SubjectOffering teaching context, for which dates.** It
is the ownership half of ADR 0063's owned-teacher-access rule:

```text
owned teacher resource access
    = verified ActingEmployee (TCH.1)
      AND an owned-scope capability (TCH.3 onward)
      AND a TeachingAssignment covering the resource on the day (TCH.2)
```

**It grants nothing by itself.** It is one of the three facts that owned
teacher access requires, read through `TeachingOwnership` (§8), for
**Curriculum Delivery** (TCH.3, ADR 0063 §31) and **Attendance** (TCH.4,
§32). LMS and Timetable are still admin-only. Production enablement of the
teacher Attendance surface is blocked by the open legal/compliance
determination TCH-L1 (ADR 0063 §26).

ADR 0063 (§7–§10, §15, §19–§23, §30) is the decision record. This page
describes the as-built module.

## 1. Dependencies

- **Depends on** HR (the Employee; `EmploymentCoverage` for the employment
  check) and Academic Structure (Section, SubjectOffering, AcademicYear), by
  composite foreign key and through tenant-scoped reads.
- **Never depended on** by HR or Academic Structure.
- **Two consumers,** both through `TeachingOwnership`/`OwnedTeachingPeriod`
  only:
  - Curriculum Delivery (TCH.3), on the delivery's dates;
  - Attendance (TCH.4), on the register's `attendance_date`.

  Timetable, LMS, Syllabus and Examinations do not reference it.
- **Not derived from anything else.** It never uses `TimetableEntry` (a
  weekly schedule, not authority) or `ActingEmployeeResolver` (the actor's
  identity, not the owner's).
- All of the above is enforced by `TeachingAssignmentArchitectureGuardTest`.

## 2. The record

Table `teaching_assignments` (School-owned, forced RLS, no runtime DELETE):

| Column | Meaning |
|---|---|
| `employee_id` | The owner. An Employee, never a User: an assignment can exist before the Employee has an account |
| `section_id`, `subject_offering_id` | The teaching context. The Offering must be **required** (electives are excluded, ADR 0063 D-05) |
| `academic_year_id`, `campus_id`, `grade_level_id` | Server-derived from the Offering; both composite foreign keys pin the Section and the Offering to this same context |
| `starts_on`, `ends_on` | School-local, **inclusive**. `ends_on` NULL is open-ended |
| `created_by_user_id` | The administrator who created it |
| `ended_at`, `ended_by_user_id`, `end_reason` | Set once, together, when ended. `end_reason` ∈ `completed`, `reassigned`, `employment_ended` |

Structural guarantees:
- `(employee_id, school_id)` → `employees`.
- The Section and the SubjectOffering each through their 5-column context
  key (`sections_context_unique`, `subject_offerings_context_unique`).
- All of these foreign keys are RESTRICT (CLAUDE.md rule 70).
- `trg_teaching_assignments_history` freezes every identity column: School,
  Employee, context, Section, Offering, `starts_on`, the creator and
  `created_at`.
- The only permitted UPDATE is the single end. It may shorten `ends_on` but
  never extend it, and `teaching_assignments_date_range_check` keeps
  `ends_on` on or after `starts_on`.
- An ended row is immutable.

## 3. Operations

`TeachingAssignmentService` is the only writer:
- **`create()`** requires `teaching.assignments.manage`, an operational
  School, and an open (draft or active) AcademicYear containing the dates.
  It also requires:
  - an active Section and an active required Offering of the same context;
  - an active Employee with a `pre_joining`, `active` or `notice_period`
    employment covering `starts_on` (HR's `EmploymentCoverage`, which also
    allows planning a future hire);
  - **no overlapping period for the same Employee, Section and Offering.**
- **`end()`** sets the last effective day and a closed reason, once.

Rules:
- There is no update, no repointing, no delete and no cancellation. A
  mistake is ended, at the earliest on its own start date, and a new row
  created.
- Co-teaching is allowed: another Employee on the same class is another key.

**Overlap.** Two periods overlap when
`existing.starts_on <= new.ends_on (or +∞) AND new.starts_on <=
existing.ends_on (or +∞)`, inclusive:
- Ended rows count with their final `ends_on`.
- There is deliberately **no** partial unique "one open row" index. It would
  block a future-dated replacement (ADR 0063 §10).
- There is no exact-duplicate unique index either. An exact duplicate *is*
  an overlap, and such an index would miss partial overlaps.

**Concurrency.** Every create and end of one key takes the transaction-scoped
advisory lock `teaching.assignment:{school}:{employee}:{section}:{offering}`
before checking. Row locks are taken in this order:

1. School (FOR SHARE);
2. the advisory lock;
3. Section and SubjectOffering (FOR SHARE);
4. Employee and the covering EmploymentRecord (FOR SHARE);
5. the assignment row (FOR UPDATE, for an end).

Proven with real two-process races in `TeachingAssignmentConcurrencyTest`.

## 4. Authorization

`teaching.assignments.view` / `.manage` are **administrative** (Tier 1)
capabilities, granted by default to `school_admin` and `principal`:
- They are checked on the route and again in both services.
- The administrator needs no Employee record and no ActingEmployee.
- There is no role-name check anywhere.

## 5. Surfaces

- **API** (`/api/v1/schools/{school}/teaching-assignments`):
  - list, show, create, and `POST …/{id}/end`;
  - no PATCH or DELETE (405);
  - `private-no-store`, and `idempotent` on writes;
  - an unknown or other-School id is the same 404.
- **Web:** `/app/teaching-assignments` for School Admin and Principal. It
  lists a year's assignments, creates and ends them, and is linked from the
  dashboard. Its pickers are manage-only and directory-tier (Employee id,
  number and name). There is no teacher portal and no "my classes" page.
- **Audit:** `teaching_assignment.created` and `teaching_assignment.ended`,
  ids, dates and the closed reason only. No domain or outbox event (there is
  no consumer).

## 6. Classification and retention

Sensitive (`docs/security/DATA-CLASSIFICATION.md`, "Teaching Assignments").
Rows are ended, never deleted. Retention follows the pending ADR 0058 E21
decision.

## 7. Known limitations

- **No cancellation of a future assignment.** ADR 0063 defines none. The
  earliest end is the start date, which still leaves one owned day. A real
  cancellation needs a later, explicit contract refinement.
- **No class-teacher/homeroom, electives or substitute entity.** Temporary
  cover is a short dated assignment (ADR 0063 D-02, D-05, D-15).
- **The Employee picker lists at most 1,000 active Employees,** ordered by
  name.

## 8. The ownership read (TCH.3)

`App\Domain\TeachingAssignments\Application\TeachingOwnership` is the only
way a consumer reads ownership:
- **`periods(School, employeeId)`** is a fresh read. It returns every
  period of one Employee (past, current and future; ended rows with their
  final `ends_on`) as `OwnedTeachingPeriod`, ids and dates only.
- **`hold(School, employeeId, sectionId, subjectOfferingId, date)`** runs
  inside the caller's transaction. It reads the one assignment of that key
  covering `date` `FOR SHARE`. Zero, or (corrupt) several, covering rows
  mean "not owned".

`TeachingAssignmentService::end()` takes the same row `FOR UPDATE`. So an
end either commits first, and `hold()` then finds no coverage for the dates
it removed, or waits for the consumer's write.

The Employee passed in is always the consumer's verified ActingEmployee.
Nothing here resolves identity, checks roles or is cached.

Teachers never get `teaching.assignments.view`/`.manage`. They see only
their own periods, through their consumer's projection ("My Curriculum
Delivery", "My Attendance").
