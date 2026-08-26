# Phase 1D.1 — Admissions Domain & Schema Foundation

This checkpoint implements the schema foundation decided in
`docs/modules/ADMISSIONS.md` and hardened in
`docs/admissions/PHASE-1D-0-ADMISSIONS-ARCHITECTURE.md`: the
`Applicant` and `AdmissionApplication` Eloquent models, their
migrations, factories, and PostgreSQL integrity/RLS tests. **No
Application-layer service, authorization, domain event, API route, or
Vue/UI code exists yet** — this is schema only, exactly as scoped.

## 1. Tables

### 1.1 `applicants`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | `uuid` | no | primary key, UUIDv7 (`GeneratesUuidV7`) |
| `school_id` | `uuid` | no | FK → `schools(id)` cascade-on-delete, auto-filled by `BelongsToSchool` |
| `first_name` | `string` | no | |
| `middle_name` | `string` | yes | |
| `last_name` | `string` | yes | |
| `date_of_birth` | `date` | no | |
| `created_at`, `updated_at` | `timestamp` | yes | |

Indexes/constraints: `(id, school_id)` unique (the composite-FK
target every child table references). No contact/PII fields exist —
see `ADMISSIONS.md` §4/§9.

RLS: `TenantRls::enable('applicants')` — enabled and forced.

### 1.2 `admission_applications`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | `uuid` | no | primary key, UUIDv7 |
| `school_id` | `uuid` | no | FK → `schools(id)` cascade-on-delete |
| `applicant_id` | `uuid` | no | composite FK → `applicants(id, school_id)`, restrict-on-delete |
| `academic_year_id` | `uuid` | no | composite FK → `academic_years(id, school_id)`, restrict-on-delete |
| `campus_id` | `uuid` | no | composite FK → `campuses(id, school_id)`, restrict-on-delete |
| `grade_level_id` | `uuid` | no | composite FK → `grade_levels(id, school_id)`, restrict-on-delete |
| `status` | `string` | no | default `draft`; app-level guard, not a DB CHECK — see §2.1 |
| `decision_note` | `text` | yes | |
| `converted_student_id` | `uuid` | yes | composite FK → `students(id, school_id)`, restrict-on-delete |
| `converted_student_enrollment_id` | `uuid` | yes | composite FK → `student_enrollments(id, school_id)`, restrict-on-delete |
| `converted_at` | `timestamp` | yes | |
| `created_at`, `updated_at` | `timestamp` | yes | |

Indexes: `(id, school_id)` unique; `(school_id, status)`;
`(school_id, applicant_id)`; single-column indexes on
`academic_year_id`, `campus_id`, `grade_level_id`.

RLS: `TenantRls::enable('admission_applications')` — enabled and
forced.

## 2. Constraints

### 2.1 Conversion provenance CHECK

```sql
ALTER TABLE admission_applications ADD CONSTRAINT admission_applications_conversion_provenance_check
CHECK (
    (status = 'converted' AND converted_student_id IS NOT NULL
      AND converted_student_enrollment_id IS NOT NULL AND converted_at IS NOT NULL)
  OR
    (status <> 'converted' AND converted_student_id IS NULL
      AND converted_student_enrollment_id IS NULL AND converted_at IS NULL)
)
```

Bidirectional: `converted` requires complete provenance, and
non-`converted` forbids any provenance value — an arbitrary
non-`converted` status can never carry a `converted_student_id` etc.
Matches `ADMISSIONS.md` §5's proposed invariant exactly.

`status`'s own allowed-value list (`draft`, `submitted`, `accepted`,
`rejected`, `withdrawn`, `converted`) is deliberately **not** a
database CHECK — per `ADMISSIONS.md` §6A, this repository's
consistent precedent (confirmed by reading the `students`,
`student_enrollments`, and `student_subject_enrollments` migrations)
is that status *values* are an application-layer concern and only
cross-column *consistency* invariants become CHECK constraints. A
future lifecycle-service checkpoint (1D.2) owns the application-level
guard; this checkpoint ships no such service.

### 2.2 Open-application uniqueness

```sql
CREATE UNIQUE INDEX admission_applications_one_open_per_context
ON admission_applications (applicant_id, academic_year_id, campus_id, grade_level_id)
WHERE status IN ('draft', 'submitted', 'accepted')
```

At most one *open* (`draft`/`submitted`/`accepted`) application may
exist for a given `(applicant, year, campus, grade)` tuple at a time.
Once an application reaches a terminal status (`rejected`, `withdrawn`,
`converted`), the partial index no longer counts it, so the same
applicant may open a new application in the identical context —
verified by three dedicated reapplication tests (§4).

### 2.3 No uniqueness on `converted_student_id`/`converted_student_enrollment_id`

Deliberately **not added**, per the task's own conservative
instruction to omit it unless clearly justified. Reasoning recorded
inline in the migration docblock: no v1 caller can produce a
collision (`StudentEnrollmentService::enroll()`, per `ADMISSIONS.md`
§8, always creates a fresh `StudentEnrollment` row), and re-admission
compatibility (a former Student one day linked to a second
conversion) is explicitly deferred (`ADMISSIONS.md` §10) rather than
foreclosed by a premature constraint.

## 3. Models

`App\Domain\Admissions\Infrastructure\Applicant` and
`AdmissionApplication` — both use `BelongsToSchool`, `GeneratesUuidV7`,
`HasFactory`. `AdmissionApplication`'s `convertedStudent()`/
`convertedStudentEnrollment()` relations and `isConverted()` helper
are **provenance-only**: no service sets them, no observer hooks
Student/SIS creation, and no lifecycle/authorization/search behavior
exists on either model — all deferred to future checkpoints exactly
as scoped (`ADMISSIONS.md` §17, 1D.2+).

## 4. Test coverage

`tests/Feature/Postgres/AdmissionApplicationIntegrityTest.php` — 22
tests, 31 assertions, proven against real PostgreSQL under the
unprivileged `school_os_app` runtime role, independent of Eloquent:

- RLS enabled + forced on both tables (1 test)
- No-context fail-closed: zero rows visible with no `TenantContext`
  set (1 test)
- School A cannot read School B's `Applicant`/`AdmissionApplication`
  (1 test)
- Cross-School UPDATE/DELETE affect zero rows (1 test)
- School A cannot INSERT a row assigned to School B, for both tables
  — RLS's own `WITH CHECK` (2 tests)
- Six cross-School composite-FK-isolation tests, one per FK
  (`applicant_id`, `academic_year_id`, `campus_id`, `grade_level_id`,
  `converted_student_id`, `converted_student_enrollment_id`), each
  isolated via the `pgsql_admin` connection (bypassing RLS entirely)
  so the failure is provably the FK, not RLS
- Five conversion-provenance CHECK scenarios: complete provenance
  allowed; missing student/enrollment/`converted_at` each blocked;
  non-`converted` status carrying provenance blocked
- Five open-application-uniqueness scenarios: a second simultaneously
  open application blocked; reapplication allowed after `rejected`,
  `withdrawn`, and `converted`; an open application in a *different*
  academic year allowed alongside an existing open one

### 4.1 A cross-connection visibility bug found and fixed during this checkpoint

`Tests\TestCase` wraps every test in `DatabaseTransactions`, one
uncommitted transaction per connection. The FK/CHECK/uniqueness
isolation tests above deliberately issue their decisive statement on
the `pgsql_admin` connection (bypassing RLS to isolate a non-RLS
failure) — but their first draft built supporting fixture rows via
the ordinary `pgsql` connection's Eloquent factories. Under real
PostgreSQL MVCC, a row inserted by one connection's still-open
transaction is invisible to a different connection's session; a
`pgsql_admin` FK/uniqueness check waiting to learn whether that
`pgsql`-connection row will commit or abort can never resolve within
a single test method — a genuine, reproducible deadlock (confirmed via
`pg_stat_activity`/`pg_locks`, not guessed). Fixed by adding
`buildAdminContext()`/`buildAdminConvertedTarget()` helpers that
create every supporting fixture row (School included, since
`admission_applications.school_id` also carries a plain FK to
`schools(id)`) via `->connection('pgsql_admin')` on each factory, so
every pgsql_admin-driven test's fixtures and decisive statement share
one session throughout. This is a test-file-only fix — no production
code changed.

## 5. Explicitly out of scope for this checkpoint

Per `ADMISSIONS.md` and the 1D.1 task brief: no
`ConvertAcceptedAdmission` or any other Application-layer service, no
`admissions.view`/`admissions.manage` capability seeding or
authorization checks, no domain events/outbox rows, no API routes or
controllers, no Vue/Inertia pages. `Student`, `Guardian`,
`AcademicYear`, `Campus`, `GradeLevel` production code is untouched —
Admissions only reads their identity (via composite FKs) at the
schema level.

## 6. Recommended next checkpoint

**Phase 1D.2 — Application Lifecycle Service**: the
`draft → submitted → accepted/rejected/withdrawn` transitions
(`ADMISSIONS.md` §6), each as a dedicated Application-layer method
(never a generic `update(status:)`), an application-level status
guard exception (mirroring `InvalidStudentStatusException`), audit
events per §15, and `admissions.view`/`admissions.manage` capability
seeding + authorization tests (allow and deny). `Phase 1D.3
— Conversion` (`ConvertAcceptedAdmission`, including the required
forced-failure atomicity test per `ADMISSIONS.md` §11) is the
logical checkpoint after that, once lifecycle transitions exist to
reach `accepted` in the first place.
