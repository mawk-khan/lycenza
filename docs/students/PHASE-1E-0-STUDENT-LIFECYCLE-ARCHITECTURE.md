# Phase 1E.0 — Student Lifecycle Architecture

> **"Phase 1E" is an informal, Students/SIS-module-local checkpoint
> label only** (like 1A/1B/1C/1D before it) — it is **not** a formal
> `docs/roadmap/MASTER-ROADMAP.md` phase. This document is
> architecture/discovery output for the next accepted Remaining-Phase-1
> priority ("Richer Student-Level Lifecycle"), produced after Phase 1D
> Admissions closed (merge `ed87ca8` on `origin/main`). It contains
> **no schema, route, capability, or Vue changes** — documentation
> only.

## 0. Scope and method

This checkpoint answers one question with repository evidence, not
assumption: **does the platform actually need a richer Student-level
lifecycle beyond the existing `active`/`inactive`, and if so, what
exactly?** Every claim below is sourced from the actual code and docs
at `origin/main` (`ed87ca8`) as inspected from this checkpoint's own
worktree — no speculative SIS-vocabulary import (CLAUDE.md rule 2).

## 1. Evidence: current `Student` model

- `apps/platform/app/Domain/Students/Infrastructure/Student.php` —
  `status` is a plain string column, `fillable`, cast is only on
  `date_of_birth`. No `active()`/`inactive()` query scope exists on
  the model itself.
- `apps/platform/database/migrations/2026_08_23_100000_create_students_table.php`
  — `$table->string('status')->default('active');` — **no database
  CHECK constraint**. `status` is enumerated at the **application
  layer only** (`StudentService::changeStatus()`,
  `InvalidStudentStatusException`), not the database. Adding a new
  status value therefore needs **no migration** — but does need every
  application-layer validator/enum listed below updated in lockstep.
- `StudentService::changeStatus()`
  (`apps/platform/app/Domain/Students/Application/StudentService.php:101-118`):
  `if (! in_array($status, ['active', 'inactive'], true)) { throw new InvalidStudentStatusException($status); }`
  — the single authoritative enum. `create()` always sets
  `'status' => 'active'` (line 61) — no caller-supplied initial
  status. Every write goes through `DB::transaction()` +
  `TenantContext::withSchool()` + `AuditRecorder::school()`, recording
  `student.status_changed` with `previousStatus`/`newStatus` metadata
  only (no name/DOB — `docs/security/DATA-CLASSIFICATION.md`).
- `StudentController`
  (`apps/platform/app/Domain/Students/Http/Controllers/StudentController.php`):
  `index()` accepts an **optional** `status` filter
  (`Rule::in(['active', 'inactive'])`) — when omitted, **both**
  statuses are returned (no active-only default anywhere). A
  dedicated `changeStatus()` action exists at `POST
  /schools/{school}/students/{student}/status`, gated by
  `students.manage`, validated against the same two-value
  `Rule::in`. This is already a constrained-enum command endpoint, not
  an arbitrary free-text PATCH.
- `docs/modules/STUDENT-GUARDIAN-IDENTITY.md` §"Status remains a plain
  string (not a new enum)" (lines 612-627) explicitly reasons that
  converting `status` to a real enum/state-machine "would be [a
  bigger] lifecycle redesign" and defers that decision — this is the
  exact deferred decision this checkpoint now resolves (§9 below).
- Vue: `resources/js/Pages/App/Students/Show.vue` already has a
  working "Mark inactive"/"Mark active" toggle (line 178-179, `POST
  .../status`) and a `StatusBadge` for both `student.status` and the
  Student's current Enrollment status, shown **separately**
  (lines 262, 304). The UI already treats these as two independent
  facts.

**Summary:** two values (`active`/`inactive`), app-enforced, no DB
CHECK, default `active`, no scopes, `students.manage` gates every
mutation, UI and API both already fully wired for exactly these two
values.

## 2. Evidence: `StudentEnrollment` lifecycle

`docs/modules/STUDENT-ENROLLMENT.md` (the authoritative Phase
1B.1-1B.7F spec) and
`apps/platform/app/Domain/Students/Application/StudentEnrollmentService.php`:

- Statuses: `active` → one of `completed` / `withdrawn` / `cancelled`
  / `transferred` (all terminal — none re-transitions, including back
  to `active`; a new Enrollment row is always created instead —
  "Historical record principle").
- **Placement-level, not Student-level, by explicit design.** Every
  method's docblock says so directly:
  - `withdraw()`: "Never touches `Student.status`... the current
    architectural default is `Student identity != Enrollment status`
    — no already-accepted Student-domain rule requires otherwise
    today" (line 434-436).
  - Rollover's terminal-grade path: "This checkpoint does **not**
    invent a Student `graduated`/`alumni` status... what happens to a
    Student's identity after their terminal Grade (alumni tracking,
    deactivation, etc.) is explicitly deferred to a later, separate
    checkpoint" (line 1332-1340) — **this is the exact question this
    checkpoint (1E.0) now has to answer**, and the repo already
    contains the pointer to it.
  - The Student model itself, quoted directly in the same file: "no
    `graduated`/`alumni`/`promoted` value exists" (line 1164-1167).
- At most one `active` Enrollment per Student per AcademicYear
  (DB-enforced partial unique index) — **per AcademicYear**, not
  globally, so a Student legitimately has concurrently-`active`
  Enrollments across different years (this is what makes "prepare next
  year while this year is still running" work, and it is exactly why
  Enrollment status cannot be collapsed into a single Student-level
  flag: there is no single "the" current Enrollment status once two
  years overlap in preparation).
- `StudentEnrollmentReadService::currentFor()` resolves "the active
  Enrollment for a Student within one AcademicYear" purely from
  `StudentEnrollment.status = 'active'` + the School's active
  AcademicYear — it **never reads `Student.status`** (lines 46-66).
  Student.status and "is currently enrolled" are already orthogonal
  facts in the shipped code, not just in theory.

**Conclusion (proven, not inferred): `StudentEnrollment` status
describes one academic-year placement. It is not, and was never
intended to be, a description of the Student as a person.**

## 3. Evidence: the closest existing precedent — HR's `Employee` / `EmploymentRecord`

This is the strongest evidence in the repository, because it is the
**same structural problem, already solved, in the same codebase**, one
phase earlier (Phase 8A):

- `Employee.record_status` (`active`/`archived`) — durable identity,
  exactly analogous to `Student.status`.
- `EmploymentRecord.status` (`draft`/`pre_joining`/`active`/
  `notice_period`/`separated`/`terminated`/`retired`/`deceased`) — a
  **separate table**, 1:N from Employee, temporal
  (`starts_on`/`ends_on`), exactly analogous to `StudentEnrollment`.
- `docs/modules/HR.md` line 2399-2413, verified directly against
  `EmploymentService`: `record_status` "has **no write path anywhere
  in this codebase**"; ending an Employment (even via a terminal
  status like `deceased`) does **not** archive the Employee, and
  archiving the Employee does not touch `EmploymentRecord`.
  `EmploymentService::end()` (line 165) only ever writes
  `employment_records.status` — never touches `Employee`.

The codebase already made this exact decision once, deliberately, and
kept the two statuses **structurally decoupled with independent write
paths**. `Student`/`StudentEnrollment` is architecturally the same
shape as `Employee`/`EmploymentRecord`. There is no principled reason
for Students to diverge from a pattern the same author-base already
chose for an identical problem one phase earlier.

## 4. Repository-wide search: candidate SIS vocabulary

Searched all of `docs/` for `graduat|alumni|withdrawn|transferred|
leaver|deceased|expell|suspend|archived|transfer certificate|TC|
re-admission|readmission|former student|school leaving`. Full results
inspected; relevant hits summarized in the evidence table below. No
hit anywhere proposes any of these as a **Student-level** status
except as an explicit "future/deferred" pointer.

### Candidate Student-level states — evidence table

| State | Repository evidence | Classification | Include in v1? |
|---|---|---|---|
| `active`/`inactive` | Already implemented, already the sole vocabulary (§1) | PLACEMENT-... no — **already Student-level, current** | Already included (unchanged) |
| `graduated` | `STUDENT-ENROLLMENT.md:1332-1340`: explicitly NOT invented at rollover's terminal-grade path; "deferred to a later, separate checkpoint" | DEFERRED (explicit repo pointer, no vocabulary decided) | **NO** |
| `alumni` | Same rollover passage; no other repo mention as a Student status; `docs/architecture/DOMAIN-MAP.md` names no Alumni capability/module | INFERRED ONLY (word never proposed as Student.status anywhere) | **NO** |
| `withdrawn` | `StudentEnrollment.status` value only (§2); `STUDENT-ENROLLMENT.md:438` states explicitly there is no Student-level equivalent | PLACEMENT-LEVEL ALREADY | **NO** |
| `transferred` | `StudentEnrollment.status` value only (same-year Section move, §2); `docs/architecture/adr/0020` mentions a "hypothetical transferred-student record in a **future module**" — future, not this one | PLACEMENT-LEVEL ALREADY | **NO** |
| `cancelled` | `StudentEnrollment.status` value only | PLACEMENT-LEVEL ALREADY | **NO** |
| `suspended` | Only exists today as `school_memberships.status` (account/access suspension, Phase 0B, unrelated identity) and `HR.md:2377` explicitly rules out reusing it for a disciplinary concept without "its own migration, not reused" | NOT APPROPRIATE (no discipline/behavior module exists; would be invented) | **NO** |
| `expelled` | Zero repository hits of any kind | NOT APPROPRIATE (no requirement, no precedent) | **NO** |
| `deceased` | Exists only as an `EmploymentRecord`/HR terminal status (`HR.md:512`, `2374`); zero Student-domain evidence | NOT APPROPRIATE for v1 — sensitive, no product requirement; HR precedent shows *if* ever needed it would live on a richer per-record table, not the identity row | **NO — defer** |
| `archived` (Student) | Only exists as `AcademicYear`/`Document`/`Employee`/`school` "archived" conventions elsewhere, never proposed for Student | NOT APPROPRIATE (no requirement; `inactive` already serves this role for Students, matching its own docblock: "same deactivate-don't-delete convention as every other reference/identity table") | **NO** |
| Re-admission | `ADMISSIONS.md:382-415` explicitly, repeatedly "explicitly deferred to a future re-admission checkpoint"; must not be solved as a side effect of `inactive → active` | DEFERRED (explicit, repeated repo statement) | **NO — and Phase 1E must not accidentally solve it (§9)** |

**No status vocabulary is inflated by this checkpoint.**

## 5. `active`/`inactive` semantics (as actually implemented today)

From §1-§2 evidence: `Student.status = 'active'` means **the identity
record is the School's current, operationally-visible persona for this
Student** — it is an administrative/roster-visibility flag on the
*person*, decoupled from any specific Enrollment. It does **not**
currently mean, and nothing in the shipped code makes it mean:

- "currently enrolled" — `currentFor()` never reads it (§2).
- "eligible for a new Enrollment" — `StudentEnrollmentService::enroll()`
  never checks it (`STUDENT-ENROLLMENT.md:230-238`, "Deferred Student
  status eligibility" — explicitly, deliberately not invented).
- "included in rollover" — the rollover eligibility matrix
  (`STUDENT-ENROLLMENT.md:1262-1270`) is keyed entirely on
  **Enrollment** status (`active`/`completed` eligible;
  `withdrawn`/`cancelled`/`transferred` not); `Student.status` is not
  a rollover input anywhere in `EnrollmentRolloverDryRunService`/
  `EnrollmentRolloverExecutionService`.
- "excluded from Communications rosters" — grep of
  `app/Domain/Communications/` for any `Student`-status read returned
  zero hits; Communications audiences resolve from
  `StudentEnrollment`/academic-cohort facts (Phase 5B), never from
  `Student.status` directly.

**Compatibility matrix (all four combinations are currently possible
and none is currently treated as an inconsistency by any service):**

| Student.status | Enrollment state | Currently possible? | Currently valid? |
|---|---|---|---|
| active | active Enrollment (current year) | Yes | Yes — the ordinary case |
| active | no active Enrollment (e.g. between years, or never enrolled) | Yes | Yes — e.g. a Student record created but not yet enrolled, or mid-rollover-gap |
| inactive | only historical (non-active) Enrollments | Yes | Yes — the intended "no longer attending" shape |
| inactive | still has an `active` Enrollment | Yes (nothing prevents it today) | **Data-quality smell, not a hard inconsistency** — no invariant currently links the two (§3's decoupling precedent). Flagged as an OPEN QUESTION, not fixed here (§13). |

## 6. Admissions conversion interaction

`AdmissionConversionService` (line 131) calls
`$this->studentService->create($locked->school, [...])` — the exact
same `StudentService::create()` every other caller uses, which always
sets `status => 'active'` (§1). **Confirmed: no change needed or
made.** Admissions continues to produce standard `active` Students
through the existing, unmodified `StudentService`.

## 7. Guardian / Documents / login boundaries

- **Guardians**: `guardianRelationships()`/`guardians()` are plain
  Eloquent relations with no status-conditional logic in `Student.php`
  or `StudentGuardianRelationshipService`. Nothing in this checkpoint
  proposes touching them; Guardian history remains available
  regardless of Student status (unchanged, confirmed by inspection).
- **Documents**: `docs/architecture/DOMAIN-MAP.md` line 113 states
  Student/Guardian Document owner columns "exist structurally...
  but have no activated capability/API surface — a deliberate,
  separate future decision." There is **no live Student-Documents
  integration to interact with today** — this checkpoint has nothing
  to couple to and does not attempt to.
- **Login/User account**: `Student.php`'s own docblock: "Never linked
  to `users` directly." No lifecycle status change proposed here
  touches authentication in any way — there is no portal-login
  concept on Student to disable.

## 8. Delete policy

`StudentController` has no `destroy()` action; `Student` has no soft-
delete trait. Deactivation via `status` is already the only
sanctioned lifecycle-negative operation (matching the `active`/
`archived` "deactivate, don't delete" convention CLAUDE.md rule 73
establishes for every other reference/identity table). No delete
capability is introduced or implied by this checkpoint.

## 9. Architecture decision (DECIDED)

**Chosen option: A — keep `Student.status = active/inactive` exactly
as implemented, unexpanded.** (Of the brief's four options, this maps
to Option A, "Keep Student.status = active/inactive only.") Rejected
alternatives and why:

- **Option B (expand to `active/inactive/graduated`, or a larger set)**
  — rejected. No candidate state in §4's evidence table clears
  "SUPPORTED BY REPO REQUIREMENT" except `active`/`inactive`
  themselves; every one is either PLACEMENT-LEVEL-ALREADY (belongs on
  `StudentEnrollment`, which already carries it) or DEFERRED/NOT
  APPROPRIATE (no product requirement, would be invented). Adding
  `graduated` in particular would contradict `STUDENT-ENROLLMENT.md`'s
  own explicit, already-committed deferral of that exact question.
- **Option C (a separate `StudentLifecycleEvent`/history table)** —
  rejected for now. `AuditLog` (`student.status_changed`, already
  shipped, already captures `previousStatus`/`newStatus`/actor/
  timestamp/subject per CLAUDE.md rule 11) already satisfies every
  history requirement §12 could identify with current evidence. A
  dedicated table would duplicate what audit already provides with no
  additional query need demonstrated (CLAUDE.md rule 2).
- **Option D (no new status; a School-leaving/transfer coordinating
  service)** — partially adopted in spirit (§10 below), but not built
  now: the HR `Employee`/`EmploymentRecord` precedent (§3) shows the
  codebase's own prior, deliberate answer to "should ending a
  person's placement automatically change their identity status?" is
  **no** — the two remain independently writable, with no atomic
  cross-aggregate transaction. Following that precedent, Student
  lifecycle needs **no new coordinating service either** — staff (or a
  future workflow) call `StudentEnrollmentService`'s existing
  terminal-transition methods and `StudentService::changeStatus()`
  independently, exactly as `Employee`/`EmploymentRecord` already do
  in production HR code today.

### Vocabulary

`active` / `inactive` — unchanged. No new value.

### Meaning of each (now made explicit, not redefined)

- **`active`**: the Student identity is the School's current,
  operationally-visible persona for this person. Does not assert
  anything about a specific Enrollment.
- **`inactive`**: the Student identity is not currently an
  operationally-visible persona for the School (e.g., no longer
  attending, administratively deactivated). Historical Enrollments,
  Guardian relationships, and audit history remain fully intact and
  queryable — `inactive` is a visibility/roster flag, never a
  deletion or history-hiding mechanism.

### Transition model

- Allowed: `active → inactive`, `inactive → active` (both already
  implemented via `StudentService::changeStatus()`).
- Disallowed: no third value; `InvalidStudentStatusException` already
  enforces this.
- **Reactivation (`inactive → active`) is already supported and
  already does not fabricate a `StudentEnrollment`** — confirmed by
  inspection: `changeStatus()` and `StudentEnrollmentService` share no
  code path. Re-admission (a former Student re-enrolling) remains
  exactly as deferred as `ADMISSIONS.md` already states — reactivating
  the identity is not, and must never be presented as, equivalent to
  re-enrolling them.

## 10. Enrollment relationship (DECIDED)

- No new invariant is added linking `Student.status` to
  `StudentEnrollment.status`, matching the HR precedent (§3, §9). All
  four cells of the compatibility matrix (§5) remain valid; the
  "inactive Student with an active Enrollment" cell is a data-quality
  signal an operator can act on manually (e.g. via the existing
  Enrollment withdraw/complete actions), not a system-enforced
  contradiction.
- Enrollment withdrawal/completion/transfer continue to never touch
  `Student.status` (unchanged, already correct per §2).
- **Graduation is NOT included as a Student-level operation in v1.**
  No repository evidence (staff workflow, Exam/AcademicRecord
  dependency, or explicit product requirement) defines what
  "graduated" should mean beyond the already-shipped rollover
  `TERMINAL_GRADE` outcome (a Student reaching a GradeLevel with no
  configured promotion target). If a School wants to mark such a
  Student `inactive` afterward, that is today's existing, independent
  `changeStatus()` call — not new domain modeling.
- **Transfer-out/leaving is NOT included as a new Student status in
  v1.** `StudentEnrollment.transferred` already exists and is reused
  as-is for same-year Section moves; a Student leaving the School
  entirely is represented, if a School chooses to represent it at all
  today, by the existing `active → inactive` transition — independent
  of whichever Enrollment lifecycle method (if any) staff also call.
  No `StudentEnrollmentService` method is duplicated or reimplemented.
- **Re-admission is NOT included.** Explicitly and repeatedly deferred
  by `ADMISSIONS.md` already; this checkpoint does not narrow that
  scope or redefine it via a side door.

## 11. History and audit (DECIDED)

- No new `StudentLifecycleEvent`/history table.
- `App\Support\Audit\AuditRecorder` / `SchoolAuditEvent`
  (`student.status_changed`) already records actor, School, subject,
  timestamp, and `{previousStatus, newStatus}` metadata — PII-minimal
  by construction (no name/DOB, matching `StudentService`'s own
  documented audit design). This is sufficient for every history need
  identified with current evidence.
- **No `reason_code`/`effective_date`/free-text `note` field is added.**
  No business requirement for either was found in repository evidence,
  and `changeStatus()`'s existing instantaneous-current-state model
  (mirroring `AcademicYearService::activate()`/`close()`, which are
  also instantaneous, not date-scheduled) is sufficient for what is
  actually implemented today. Marked as an explicit OPEN QUESTION
  (§13), not silently decided against forever — a future graduation/
  leaving workflow, if one is ever specified with a real requirement,
  may need one; it is not invented speculatively now (CLAUDE.md
  rule 2).

## 12. Persistence, RLS, authorization, API, UI (DECIDED)

- **Schema change required: NO.** No migration.
- **New table: NO.**
- **RLS**: not applicable — no new tenant-owned table.
- **Authorization**: `students.manage` already covers "changing
  Student status" per its own seeded label
  (`CapabilityAndRoleSeeder.php:85`, "Manage Students (create, update,
  status, Guardian links)"). **No new capability
  (`students.lifecycle.manage` or similar) is introduced** — no
  evidence supports the blast-radius separation that would justify
  one; the transition set (two values, symmetric, no cross-aggregate
  side effect) is not qualitatively different from any other
  `students.manage` action.
- **API**: the existing `POST /schools/{school}/students/{student}/status`
  endpoint, `Rule::in(['active', 'inactive'])`-validated, is retained
  as-is. It is not an unconstrained generic status PATCH (already
  enum-validated), so it does not violate the "no generic arbitrary
  PATCH" principle in practice — but see §15 for a small proposed
  naming clarification, not a behavior change.
- **UI**: `Students/Show.vue`'s existing "Mark inactive"/"Mark active"
  toggle and `StatusBadge` already implement the full v1 UI surface.
  No new UI work identified.

## 13. Open questions (explicitly marked, not hidden in prose)

1. **OPEN — "inactive Student with an active Enrollment" data-quality
   signal.** Should this ever surface as a warning to staff (e.g. on
   the Student detail page)? No requirement found; not decided here.
2. **OPEN — reason/effective-date fields for a future graduation/
   leaving workflow**, if one is ever specified (§11). Not decided;
   revisit only against a real requirement.
3. **OPEN — Alumni.** Whether Alumni ever becomes a lifecycle status,
   a separate read model, or a distinct future capability/persona is
   explicitly left open — no roadmap evidence exists to decide it
   either way (brief §20). Not proposed as Student.status now or
   later without new evidence.
4. **OPEN — Deceased.** Treated with the caution the brief requires;
   zero repository evidence of a product requirement. If one ever
   emerges, the HR precedent (a distinct terminal value only on the
   richer, non-identity record — here that would be a hypothetical
   future Enrollment-adjacent or dedicated record, not `Student.status`
   directly) is the model to evaluate against, not decided now.

No item above rises to "critical unresolved business semantic
blocking this checkpoint" — every DECIDED section above rests on
direct repository evidence, so this checkpoint concludes with a
decision, not an escalation.

## 14. External module impact matrix

| Module | Impact | Why |
|---|---|---|
| Admissions | NO IMPACT | Continues calling unmodified `StudentService::create()` (§6) |
| StudentEnrollment | NO IMPACT | No new invariant added (§10); its lifecycle is unchanged |
| Rollover | NO IMPACT | Eligibility already keyed on Enrollment status only (§5); `TERMINAL_GRADE` outcome unchanged |
| StudentSubjectEnrollment | NO IMPACT | No `Student.status` dependency found in `SubjectOfferingRosterReadService`/`StudentSubjectEnrollmentService` |
| Communications | NO IMPACT | Confirmed zero `Student.status` reads in `app/Domain/Communications/` (§5) |
| Guardians | NO IMPACT | Relations unconditional on Student status (§7) |
| Documents | NO IMPACT (nothing to couple to yet) | Student Document owner surface not yet activated (§7) |
| Attendance | DEFERRED | Module not implemented; out of scope (brief §51) |
| Timetable | DEFERRED | Module not implemented; out of scope |
| Exams | DEFERRED | Module not implemented; out of scope |
| Fees | DEFERRED | Module not implemented; out of scope |

## 15. Proposed next implementation slice (recommended, not built here)

Because the architecture decision is "the current model is already
correct," there is **no schema or invariant gap to close**. The one
concrete, evidence-bounded improvement identified:

**Phase 1E.1 — Student Lifecycle Service Naming Clarity (small, SERVICE
FOUNDATION).**

- Replace `StudentService::changeStatus($student, $status)`'s single
  generic setter with two explicit named methods,
  `activate(Student $student, ?User $actor)` /
  `deactivate(Student $student, ?User $actor)`, thin wrappers around
  the identical existing transaction/audit logic — matching the
  `AcademicYearService::activate()`/`close()` and
  `EmploymentService::end()` naming convention already established
  elsewhere in this codebase, rather than a bare string setter.
- Add an explicit docblock on `StudentService` cross-referencing
  `EmploymentService`'s decoupling precedent (§3), so a future
  contributor does not "discover" the missing Student↔Enrollment
  coupling and add it as an unreviewed side effect.
- Add a regression test asserting `changeStatus()`/`activate()`/
  `deactivate()` never write to `student_enrollments` (a decoupling-
  boundary test, mirroring how `HR.md` documents its own equivalent
  boundary today).
- **No schema change, no new capability, no new route** — the existing
  `POST .../status` endpoint continues to work unchanged (controller
  calls the new named methods instead of the old generic one).

This is optional polish, not a blocking gap — it may be scheduled
whenever convenient, including bundled into an unrelated Students/SIS
checkpoint, rather than requiring its own dedicated integration cycle.

## 16. Findings

- **P0**: none.
- **P1**: none.
- **P2**: none.
- **P3**: §13's four OPEN questions — track, do not action without a
  real requirement.
- **P4**: §15's naming-clarity polish — optional, low priority.

Known test-infrastructure backlog (Flutter SDK verification, etc.) is
carried separately per `apps/mobile/README.md` and is unrelated to
this checkpoint.
