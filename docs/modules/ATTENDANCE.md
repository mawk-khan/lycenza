# Attendance (Phase 0H.2 — Student Attendance Foundation)

## 1. Checkpoint scope

Phase 0H ("Academic Operations") covers Attendance, Timetable,
Academics, and Examinations. This checkpoint — **Phase 0H.2: Student
Attendance Foundation** — implements Attendance only. Timetable was
built in Phase 0H.1 (`docs/modules/TIMETABLE.md`); **Academics and
Examinations remain not started**, so Phase 0H as a whole is **not**
complete after this checkpoint (`docs/roadmap/MASTER-ROADMAP.md` is
authoritative).

**"Attendance" here means Student class attendance, and nothing else.**
Staff/Employee attendance is a Phase 0J/HR concern and appears nowhere
in this module. Section 16 lists everything else deliberately excluded.

**Amended by TCH.4 (ADR 0063 §32).** An owned teacher (Tier 2) path now
exists alongside the administrative one — see section 18. The "admin-only"
and "no teacher self-service" statements below describe Phase 0H.2 and are
superseded for that path only. **Teacher Attendance functionality is
implemented but production enablement remains blocked by TCH-L1 until the
required legal/compliance determination is recorded.**

## 2. The two entities

| Entity | What it is | Mutability |
|---|---|---|
| `AttendanceSession` | The **immutable header of one submitted class register** — "Section A's Mathematics class in Period 1 on 31 August 2026 was taken, by this user, at this time". | **Every column is immutable after INSERT.** There is no status column, no draft, no partial save, no replace and no delete. A Session either exists (the register was submitted) or it does not. |
| `AttendanceRecord` | One StudentEnrollment's status inside that register. | Only `status` and `corrected_at` ever change, and only through the explicit expected-status correction command. Never hard-deleted. |

There is deliberately no `student_id` column on `attendance_records`:
Student identity is derived **through** the StudentEnrollment, so there
is only ever one answer to "whose attendance is this".

## 3. Statuses

Exactly four, and no more:

`present` · `absent` · `late` · `excused`

Enforced by the database's own `attendance_records_status_check` CHECK
constraint, mirrored by `AttendanceRecord::STATUSES`.

**`excused` records the generic status and never why.** There is no
reason, note, remark, minutes-late, medical explanation or evidence
reference anywhere in this module, and none may be added — see §4.

## 4. Classification: Sensitive

Attendance is **Sensitive** (`docs/security/DATA-CLASSIFICATION.md`).
It contains no Health data, no medical detail, no government
identifier, no biometric data, no financial data and no Guardian PII.

That boundary is structural, not conventional:

- `attendance_records` has no reason/note/remark/minutes-late/evidence
  column, and `AttendanceArchitectureGuardTest` fails if one is added.
- Student projections are exactly `studentEnrollmentId` + `studentId` +
  `rollNumber` + `fullName` — never date of birth, Guardian data,
  contact details, address or admission history.
- Teacher projections are exactly `id` + `fullName` — never
  `work_email`/`work_phone` or any other HR field, matching
  `TimetableEntryController`'s identical rule.
- The record's structural context columns are never serialized at all.

Recording *why* a Student was excused would move this data into the
Health tier, which has its own unresolved **[LEGAL REVIEW REQUIRED]**
gate. It is therefore out of scope by design, not by omission.

## 5. The complete register

A register is submitted **complete or not at all**. The submitted record
set must equal the authoritative as-of-date roster **exactly**.

Rejected: an omitted roster member, an extra enrollment, an enrollment
from another Section or context, a duplicated enrollment id, an unknown
enrollment id, an empty roster, and an ambiguous historical membership.

There is **no partial save** and **no implicit default-to-present** — a
Student the marker forgot must never be silently recorded as present.
The marking UI leaves every Student unmarked until a human marks them
and disables submission while any remain unmarked.

## 6. The historical roster predicate

Owned by Students/SIS, in
`App\Domain\Students\Application\StudentEnrollmentRosterReadService`.
Attendance calls it and **never reproduces it** — StudentEnrollment
lifecycle semantics belong to that module, and a duplicated predicate
would drift.

The predicate is **temporal, not status-based**:

```
school_id       = <School>
academic_year_id= <Session's AcademicYear>
campus_id       = <Session's Campus>
grade_level_id  = <Session's GradeLevel>
section_id      = <Session's Section>
starts_on      <= attendance_date
(ends_on IS NULL OR ends_on >= attendance_date)
```

There is **no `status = 'active'` filter**. A placement that is today
`completed`/`withdrawn`/`transferred`/`cancelled` still belongs on a
register for a date its interval contains — a Student who transferred
out in October was genuinely in September's Section, and September's
register must say so. Filtering on current status would make every
historical roster silently change meaning as the year progresses.

The four context predicates are intentional defence-in-depth. A
StudentEnrollment's `section_id` already implies its
AcademicYear/Campus/GradeLevel, but that consistency is
application-enforced by `StudentEnrollmentService`, not structurally
guaranteed (see `create_student_enrollments_table`'s own docblock).
Matching all five columns means an internally inconsistent row is
excluded rather than admitted into permanent history — and it makes the
result set exactly what `attendance_records_enrollment_context_fk` will
accept.

### Inclusive placement dates

`ends_on` is **inclusive** — the last date a placement is effective,
never the day it stops. `StudentEnrollmentService::transferPlacement()`
sets the source row's `ends_on` to `effective_date - 1 day` and the
target row's `starts_on` to `effective_date`, so for a transfer
effective 20 September:

- 19 September → the **source** Section
- 20 September → the **target** Section

No gap, no overlap. Proven in
`StudentEnrollmentRosterReadServiceTest::a_transfer_boundary_resolves_to_the_source_then_the_target_section`.

### Ambiguous-history guard

`student_enrollments_one_active_per_student_year` is a PARTIAL unique
index (`WHERE status = 'active'`), so it constrains only currently-active
placements. Two *terminal* intervals for one Student could overlap
through bad or backdated data. When that happens the roster is genuinely
ambiguous and Attendance **fails closed** with
`AmbiguousHistoricalEnrollmentException` (HTTP 409). It never picks the
first row, never deduplicates, and never writes two AttendanceRecords
for one human Student.

## 7. StudentEnrollment provenance

`attendance_records.student_enrollment_id` means:

> The StudentEnrollment placement that qualified this Student for this
> AttendanceSession **at submission time**.

A later transfer, backdated transfer, withdrawal, completion,
cancellation or rollover **does not rewrite Attendance**. Attendance is
authoritative once submitted. In particular this column does **not**
assert that the referenced Enrollment's *current* `[starts_on, ends_on]`
interval still contains the register's `attendance_date`.

## 8. Timetable provenance and the immutable class snapshot

`attendance_sessions.timetable_entry_id` means:

> The TimetableEntry from which this AttendanceSession was instantiated
> at submission time.

**It is provenance, not authority.** Phase 0H.1's TimetableEntry is
fully mutable afterwards: `TimetableScheduleService::update()` can
change its SubjectOffering, Section, teacher, Room, Period and
day-of-week (and, via `deriveContext()`, all three context columns), and
can do so even while the entry is inactive. Resolving a historical
register by joining through `timetable_entry_id` would silently re-label
a Mathematics/Section A register as a Science/Section B one.

The Session therefore carries its own immutable snapshot, copied
server-side from the entry while it is held under `SELECT ... FOR
UPDATE`:

`academic_year_id` · `campus_id` · `grade_level_id` · `section_id` ·
`subject_offering_id` · `teacher_id` · `period_id` ·
`period_start_time` · `period_end_time`

**A historical register read must never dereference the current
TimetableEntry** for Section, AcademicYear, Campus, GradeLevel,
SubjectOffering, teacher, Period or weekday.

Deliberately **not** snapshotted:

- `room_id` — logistical, outside Attendance's historical identity.
  Attendance is not a room-usage ledger. Excluding it also keeps every
  composite-FK column `NOT NULL`, so PostgreSQL's MATCH SIMPLE
  "skip the FK check if any column is NULL" bypass (proven and
  trigger-patched in Phase 1F.1) cannot apply here.
- `day_of_week` — always derivable from `attendance_date`.
- `subject_id` — `subject_offering_id` is already a stable Subject
  anchor (§9).
- Any display string.

## 9. Period historical time vs Period label

`TimetablePeriodService::update()` rejects a `start_time`/`end_time`
change only while an **active** TimetableEntry references the Period.
`TimetablePeriodReferencedException`'s own docblock prescribes
"deactivate/reassign every referencing TimetableEntry first" as the
**supported** way to retime a Period. Once that happens, a historical
register joining `period_id → timetable_periods` would report the NEW
wall-clock time for a class that ran at the old one — routine (winter
timings, exam weeks, a shortened day), and silently wrong.

So:

| Field | Source | Meaning |
|---|---|---|
| `periodId` | Session snapshot | Stable Period identity |
| `periodStartTime` / `periodEndTime` | **Session snapshot** | **IMMUTABLE historical wall-clock times** |
| `periodCode` / `periodName` | Current `timetable_periods` row | **CURRENT human-facing label** |

These can legitimately disagree: a register may read
`"Period 1 · 09:00–10:00"` while Period 1 now runs `10:00–11:00`. That
is correct — the label follows current naming, the times are historical
fact. Do not "fix" it by re-joining times.

Subject and teacher follow the same identity-vs-label split: the
identity (`subject_offering_id`, `teacher_id`) is frozen, while
`subjectName`/`subjectCode`/`teacherName` resolve to the referenced
entity's **current** row, so a rename or a name correction correctly
propagates under an unchanged historical identity. No name or code is
ever snapshotted.

`subject_id` is not snapshotted because no supported write path can
mutate `subject_offerings.subject_id` (or its
`academic_year_id`/`campus_id`/`grade_level_id`) — the update endpoint's
`validate()` array simply does not accept them, and
`SubjectOfferingIdentityGuardTest` is a standing regression check on
that premise. If it ever fails, `subject_id` must be snapshotted (with a
same-School RESTRICT FK) **before** the offending write path ships.

## 10. Structural context foreign keys

Same-School FKs alone would prove only "this Session exists" and "this
Enrollment exists" — never "this Enrollment belongs to this Session's
Section". A raw INSERT could then mark a Section B student absent on a
Section A register.

`attendance_records` therefore carries **one physical copy** of
(`academic_year_id`, `campus_id`, `grade_level_id`, `section_id`), and
**both** composite foreign keys reference those same four columns:

```
(attendance_session_id, school_id, academic_year_id, campus_id, grade_level_id, section_id)
  -> attendance_sessions(id, school_id, academic_year_id, campus_id, grade_level_id, section_id)   RESTRICT

(student_enrollment_id, school_id, academic_year_id, campus_id, grade_level_id, section_id)
  -> student_enrollments(id, school_id, academic_year_id, campus_id, grade_level_id, section_id)   RESTRICT
```

A row exists only if the Session's context and the Enrollment's context
are byte-identical. **Divergence is not representable** — there is only
one copy of each value — which is why these columns cannot drift rather
than merely being unlikely to. They are structural ONLY: server-derived
from the just-created Session, never client input, never updated, never
serialized, and deliberately absent from `AttendanceRecord::$fillable`.

The Session's own two context FKs (Section and SubjectOffering, both
against the 5-column context keys Phase 0H.1 added) pin those two
parents to the identical AcademicYear/Campus/GradeLevel, so the
record-level pin transitively inherits a fully validated context.

`student_enrollments_context_unique` — a purely additive index added by
this checkpoint, mirroring that table's own two prior additive-index
precedents (Phase 1B.7A, Phase 1F.1) — is the parent key the enrollment
FK needs. It is trivially satisfied by every existing row (`id` is the
primary key) and changes no existing behaviour or query plan.

## 11. Session uniqueness and the wall-clock overlap invariant

Three layers, in increasing generality:

1. **`attendance_sessions_entry_date_unique`** `(school_id,
   timetable_entry_id, attendance_date)` — one register per source
   entry per date.
2. **`attendance_sessions_section_slot_unique`** `(school_id,
   section_id, period_id, attendance_date)` — one register per cohort
   per Period identity per date, **independent of which TimetableEntry
   instantiated it**. Needed because deactivating an entry immediately
   frees its slot (every Timetable double-booking index is scoped
   `WHERE status = 'active'`), so a recreated entry could otherwise
   double-register one cohort.
3. **The historical wall-clock overlap rule** — within one School +
   Section + `attendance_date`, no two submitted registers may have
   overlapping half-open `[period_start_time, period_end_time)`
   intervals. Needed because Period *identities* churn too: a retired
   Period P and a newer Period Q can both denote 09:00–10:00 under
   different ids, which layer 2 cannot see.

Adjacent intervals (`09:00–10:00` then `10:00–11:00`) do **not** overlap
and are allowed. Overlapping ones (`09:00–10:00` and `09:30–10:30`) are
rejected with `ATTENDANCE_SESSION_TIME_OVERLAP`.

Layer 3 is a **service-level invariant enforced under the Section row
lock**, exactly the mechanism `TimetablePeriodService` already uses for
its own Period-range non-overlap rule — deliberately not a PostgreSQL
EXCLUDE constraint, which would mean enabling `btree_gist` for this one
rule (CLAUDE.md rule 2). Because every sanctioned submission for a
Section acquires that same Section lock before checking, two concurrent
submissions cannot both pass.

**This is why `AttendanceSubmissionService` must remain the only writer
of `attendance_sessions`** — a second writer would silently bypass an
invariant no database constraint expresses.
`AttendanceArchitectureGuardTest` enforces that.

There is **no replace-register operation** in v1. A second submission is
a typed conflict, never a silent success; changing an already-submitted
register is the correction command's job.

## 12. Section serialization and the global lock order

Attendance submission acquires, in this order and never inverted:

```
1. TimetableEntry     SELECT ... FOR UPDATE on (id, school_id)
2. AcademicYear       SELECT ... FOR SHARE
3. Section            SELECT ... FOR UPDATE   <-- shared with Students/SIS
4. StudentEnrollment  rows, ascending id order
5. attendance_sessions / attendance_records writes
```

**Step 1** makes the snapshot coherent: every immutable context column
is read off the entry *while it is locked*, so a concurrent
`update()`/`deactivate()` happens entirely before or entirely after —
never producing a torn snapshot built from two versions.

**Step 2 is a SHARED lock, not `lockForUpdate()`, and that matters.**
Both halves of that choice are proven empirically against real
PostgreSQL, not taken from documentation: a child INSERT's implicit
`FOR KEY SHARE` on the parent row proceeds immediately under a held
`FOR SHARE` (so no deadlock), while a status `UPDATE`'s
`FOR NO KEY UPDATE` blocks until the holder commits (so a concurrent
close is still correctly serialized). The second half is pinned
permanently by
`Tests\Feature\Attendance\AttendanceVersusAcademicYearCloseConcurrencyTest`,
whose two branches -- Attendance-wins and close-wins -- both occur in
practice.
Using `FOR UPDATE` here caused a real, reproducible deadlock (SQLSTATE
40P01), caught by this checkpoint's own mandatory race before any of
this shipped: every INSERT into `student_enrollments` takes an implicit
`FOR KEY SHARE` lock on its referenced `academic_years` row, so
Attendance held that row exclusively while waiting for the Section,
while a concurrent `enroll()` held the Section and waited for the
AcademicYear key-share. `FOR SHARE` is compatible with the FK's
`FOR KEY SHARE` (so an enrollment INSERT never blocks on it) while
still conflicting with the `FOR NO KEY UPDATE` that
`AcademicYearService::activate()`/`close()` takes — the invariant is
fully preserved.

**Step 3** is the synchronization point shared with
`App\Domain\Students\Application\StudentEnrollmentService`, which Phase
0H.2 changed to take the SAME Section lock before **any** membership
mutation. Holding it across roster derivation, exact-set validation and
the write is what makes "complete register" actually mean complete.

**Step 4**'s ascending-id ordering (never request order) is a stable
global order, so two concurrent submissions can never deadlock on
overlapping rosters. The roster is derived, its rows locked, and then
**re-derived**, so the authoritative exact-set comparison is made
against locked rows.

### The rollover ordering exception (audited, proven acyclic)

`EnrollmentRolloverItemExecutionService` is the one runtime path that
still acquires locks in the opposite order: it takes a
`lockForUpdate()` on the SOURCE StudentEnrollment for drift detection,
and only afterwards calls `StudentEnrollmentService::enroll()`, which
locks the TARGET Section. That is Enrollment -> Section.

It cannot cycle against Attendance, because rollover is inherently
CROSS-AcademicYear: the Enrollment it holds belongs to the SOURCE
year's Section, while the Section it waits for belongs to the TARGET
year. Attendance only ever holds ONE Section and locks only the
Enrollment rows of that same Section AND AcademicYear (the roster
predicate filters on both), so it can never simultaneously hold
rollover's source Enrollment and rollover's target Section.

Proven, not merely argued, by
`Tests\Feature\Attendance\AttendanceVersusRolloverConcurrencyTest`,
which contends both paths on the same target Section with two real OS
processes. This ordering was left as-is deliberately: changing
rollover's lock sequence is a Students/SIS design change with its own
idempotency/reconciliation implications, and no defect exists to
justify it from inside an Attendance checkpoint.

### The Students/SIS side

`StudentEnrollmentService` now uses Section-before-Enrollment
everywhere. This is a **synchronization discipline only — no domain
outcome changed**, proven by the full pre-existing Students/SIS suite
passing unmodified.

- `enroll()` — lock the target Section, then create the row.
- `complete()`/`withdraw()`/`cancel()` — a preliminary **unlocked**
  read for DISCOVERY ONLY (which Section to lock), then lock that
  Section, then lock/re-read the Enrollment, then verify it is still
  `active` **and still belongs to the Section actually locked** (a
  concurrent transfer could have moved it in between), then transition.
- `transferPlacement()` — the same discovery read, then lock **both**
  Sections in **ascending id order**, then lock/re-read the source and
  re-verify, then transition the source (`ends_on = effective_date - 1
  day`) and insert the target (`starts_on = effective_date`).

The ascending-id ordering is the deadlock-avoidance mechanism: two
concurrent transfers moving Students in opposite directions (A→B and
B→A) both request {A, B} and both take A first, so one simply waits.
The old "lock the Enrollment, then read its Section" order is gone
everywhere — keeping it would have given Attendance and SIS opposite
acquisition orders and a genuine cycle.

`StudentEnrollmentService` remains the single runtime writer of
`student_enrollments` (Admission conversion and Enrollment rollover both
call it), so every runtime membership writer participates in Section
serialization.

## 13. Submission eligibility

A new register requires all of:

- the TimetableEntry exists in this School and is currently `active`;
- `attendance_date`'s ISO weekday matches the **locked** entry's
  `day_of_week`;
- `attendance_date` is not in the future (platform UTC application date;
  today itself is allowed);
- `attendance_date` falls inside the AcademicYear's inclusive
  `[starts_on, ends_on]`;
- the AcademicYear is `active` — **no new register after closure**;
- the as-of-date roster is non-empty;
- the submitted set matches that roster exactly;
- no existing entry/date Session, Section/Period/date Session, or
  overlapping Section/date wall-clock interval.

Correcting an **existing** record remains possible after the year
closes — see §14.

## 14. Correction (expected-status compare-and-swap)

`POST /attendance-records/{id}/correct` with `expected_status` and
`new_status`. Inside the row lock: if the actual status differs from
`expected_status`, the correction is **refused** (409
`ATTENDANCE_RECORD_STATUS_CHANGED`). A no-op (`new_status` equal to the
current status) is rejected as 422 rather than writing a misleading
`corrected_at` and audit row.

This is what stops a stale administrator silently overwriting a
colleague's correction: two admins who both read `absent` and submit
different corrections will see exactly one succeed.

The correction deliberately does **not** re-validate the current
TimetableEntry status, the AcademicYear status, the StudentEnrollment
status, or whether the Enrollment's current interval still contains the
date. A closed year, a deactivated schedule entry, a withdrawn Student
or a later backdated SIS change must never make a genuine clerical
correction impossible.

Only `status` and `corrected_at` ever change. No Session column, no
structural context column, no `student_enrollment_id`, and no delete.

## 15. Idempotency, API, UI, audit

**Idempotency.** `POST /attendance-sessions` requires an
`Idempotency-Key`. Order is authenticate → tenant/membership →
**authorize** → idempotency guard (CLAUDE.md rule 32): the
`capability:attendance.manage` middleware is declared before
`idempotent`, so an actor who has lost the capability is rejected before
any replay. `IdempotencyGuard::completeWithin()` is called **inside** the
same transaction as the register write, so the Session, its records, the
audit event and the idempotency completion all commit or all roll back
together (rule 33). The correction endpoint deliberately carries no
`Idempotency-Key`: compare-and-swap already makes a duplicate delivery
fail closed.

**Stored idempotency responses — CLAUDE.md rule 36 review.** The
submission endpoint returns Sensitive-tier content (the full register,
including each Student's roll number, display name and status), and the
`idempotent` middleware stores that response body in
`api_idempotency_keys.response_body` for the retention window so a retry
can be replayed byte-for-byte. Rule 36 requires this be explicitly
reviewed rather than assumed acceptable. **Reviewed and accepted**, for
these reasons: `api_idempotency_keys` is an ordinary tenant-owned,
RLS-protected table carrying exactly the same protections as
`attendance_records` itself, so no data crosses into a weaker
protection domain; the stored payload introduces no field of a HIGHER
tier than the source (no Health, financial, government-identifier or
biometric data can appear in an Attendance response by construction,
§4); retention is bounded by the configured idempotency TTL rather than
indefinite; and the alternative — storing a minimized body — would make
a replay return something different from the original response, which
defeats the purpose of replay and is worse for clients than the risk it
avoids. Revisit this decision if Attendance ever gains a
higher-tier field. Note also that the structured `idempotency.outcome`
log line carries ids only, never the payload (rule 37).

**Domain conflicts release the idempotency key rather than storing a
failure.** An Attendance domain exception is neither a
`ValidationException` nor an `AuthorizationException`, so
`EnsureIdempotent` treats it as unclassified and calls
`IdempotencyGuard::release()`. A retry with the same key therefore
re-runs the submission and deterministically fails the same way (the
uniqueness/overlap conditions that caused the conflict are all durable),
so the observable outcome is identical to a stored replay — just
slightly more work. This is correct-but-not-optimal, deliberately left
alone rather than changing shared idempotency middleware from inside an
Attendance checkpoint.

**API** (`/api/v1/schools/{schoolId}/…`) — six operations, all
documented in `packages/contracts/openapi/school-os-api.yaml`:

| Operation | Capability |
|---|---|
| `GET /attendance-sessions` | `attendance.view` |
| `POST /attendance-sessions` | `attendance.manage` + `Idempotency-Key` |
| `GET /attendance-sessions/scheduled-classes` | `attendance.manage` |
| `GET /attendance-sessions/roster-preview` | `attendance.manage` |
| `GET /attendance-sessions/{id}` | `attendance.view` |
| `POST /attendance-records/{id}/correct` | `attendance.manage` |

Both helpers authorize **before** running any query, and are gated by
Attendance's own capabilities — never Timetable's or Students' (the
Canteen capability-boundary lesson, carried forward).

**Selection vs history.** `scheduled-classes` reads the **current**
weekly Timetable — correct there and only there; you cannot pick today's
class from a historical snapshot. `roster-preview` is explicitly
non-authoritative (`meta.authoritative: false`) and takes no locks;
submission always re-derives under locks.

**Capabilities.** `attendance.view` / `attendance.manage`, granted to
`school_admin` and `principal`. Deliberately no `attendance.correct`
(correction is already CAS-protected and audited; splitting it would
imply an approver workflow this checkpoint does not build). Phase 0H.2 had
no `attendance.teacher` and was admin-only; TCH.4 adds it as a separate
owned path (section 18) and leaves these two capabilities unchanged. Never a
role-name check.

**UI** (`/app/attendance`). Minimal administrative workflow: pick a
date → pick a scheduled class → load the roster → mark every Student →
submit the complete register → view it → correct a status. No draft
save, no Guardian/Student view, no teacher self-service, no mobile
surface, no analytics.

**Audit** (`AuditRecorder`, ADR 0017):

- `attendance.session.submitted` — session id, provenance
  TimetableEntry id, Section id, date, record count, status counts.
  **Never the roster, never a Student id, never a name.**
- `attendance.record.corrected` — record id, session id, previous
  status, new status.

**Events.** Attendance emits **zero** domain events. No real consumer
exists, and Communications existing elsewhere is not a consumer
requirement (CLAUDE.md rule 2). No automated absence notification is
built.

**RLS.** Both tables carry `school_id`, use `BelongsToSchool`, UUIDv7,
`unique(id, school_id)`, and RLS **ENABLED and FORCED**, proven at the
raw-SQL layer including the missing-context fail-closed case.

## 16. Deliberately NOT in this checkpoint

Employee/staff attendance (Phase 0J/HR) · payroll attendance ·
leave · timesheets · biometric/RFID capture · teacher self-service
(since built by TCH.4, section 18) ·
Student portal · Guardian portal · mobile attendance · medical absence
reasons · free-text reasons · safeguarding/disciplinary notes ·
Documents integration · Communications integration · automated absence
notifications · Attendance analytics or dashboards · AI · timetable
versioning/publication · Academics · Examinations · partial/draft
register save · substitution modelling · a replace-register operation.

## 17. Test coverage map

| Concern | Test |
|---|---|
| Submission, eligibility, complete register, snapshot, conflicts | `Tests\Feature\Attendance\AttendanceSubmissionServiceTest` |
| CAS correction, no-op, post-closure/post-deactivation/post-withdrawal corrections | `Tests\Feature\Attendance\AttendanceCorrectionServiceTest` |
| TimetableEntry mutation, Period retiming, Period/Subject/teacher label semantics, SIS backdating | `Tests\Feature\Attendance\AttendanceHistoricalProvenanceTest` |
| API, idempotency replay, capability allow/deny, cross-School, minimization | `Tests\Feature\Attendance\AttendanceApiTest` |
| Dual composite FKs (both directions), RLS, CHECKs, uniqueness, delete integrity, FK shapes | `Tests\Feature\Postgres\AttendanceRecordsContextIntegrityTest` |
| Races 1, 2, 3, 5 (real OS processes) | `Tests\Feature\Attendance\AttendanceConcurrencyTest` |
| Race 4 — opposite-direction Section transfer | `Tests\Feature\Students\OppositeDirectionTransferConcurrencyTest` |
| AcademicYear close vs submission (pins the FOR SHARE lock mode) | `Tests\Feature\Attendance\AttendanceVersusAcademicYearCloseConcurrencyTest` |
| Rollover vs submission on one Section (proves the ordering exception acyclic) | `Tests\Feature\Attendance\AttendanceVersusRolloverConcurrencyTest` |
| As-of-date roster predicate, inclusive boundaries, ambiguity guard | `Tests\Feature\Students\StudentEnrollmentRosterReadServiceTest` |
| Single-writer, no reverse dependency, no reason/Health field | `Tests\Feature\Attendance\AttendanceArchitectureGuardTest` |
| SubjectOffering identity premise | `Tests\Feature\Attendance\SubjectOfferingIdentityGuardTest` |
| OpenAPI bidirectional coverage + contract minimization | `Tests\Feature\Attendance\AttendanceOpenApiCoverageTest` |
| Inertia workflow and page authorization | `Tests\Feature\App\AttendanceAdminUiTest` |
| TCH.4 owned teacher path: authorization matrix, cover, co-teaching, non-disclosure, provenance | `Tests\Feature\Attendance\TeacherAttendanceAccessTest` |
| TCH.4 teacher write vs assignment end / suspension / unlink / archive / employment end (real OS processes) | `Tests\Feature\Attendance\TeacherAttendanceConcurrencyTest` |
| TCH.4 dependency direction, no teacher_id authority, route gating, OpenAPI | `Tests\Feature\Attendance\TeacherAttendanceArchitectureGuardTest` |
| TCH.4 "My Attendance" pages and capability-driven navigation | `Tests\Feature\App\MyAttendanceUiTest` |

## 18. Owned teacher Attendance (TCH.4, ADR 0063 §32)

**Production gate.** Teacher Attendance functionality is implemented but
production enablement remains blocked by TCH-L1 until the required
legal/compliance determination is recorded. TCH-L1 (ADR 0063 §26) is
**OPEN**: not a development blocker, a production blocker. Nothing here
draws a statutory conclusion.

**Two tiers.**

```text
Tier 1 (unchanged):  attendance.view / attendance.manage  -- School-wide
Tier 2 (new):        attendance.teacher
                     + verified ActingEmployee today (HR)
                     + TeachingAssignment for the exact Section +
                       SubjectOffering on the register's attendance_date
```

The production `teacher` role carries exactly `curriculum.delivery.teacher`
and `attendance.teacher`. It has no `attendance.view`/`.manage` and no
`students.view`. The role alone reaches nothing.

**Date semantics.**
- The actor must be an eligible Employee **today**.
- Ownership is checked on the register's own `attendance_date`, for
  submission and for correction alike.
- A register is visible to a teacher when they own its class on its date.
  So a class's later teacher does not see or correct an earlier teacher's
  register, and a temporary cover teacher keeps the registers of their
  cover dates.
- Assignment bounds are inclusive.

**Provenance, not authority.** A register still names its class through a
TimetableEntry and still snapshots the entry's `teacher_id` (section 8).
Neither that column nor `timetable_entries.teacher_id` authorizes. So:
- a cover teacher with an assignment but no timetable slot can take the
  register (the session still names the timetabled teacher);
- a timetabled teacher without an assignment gets 404;
- co-teachers both qualify.

**Mechanism.**
- The same `AttendanceSubmissionService` and `AttendanceCorrectionService`
  run with an optional `AttendanceWriteGuard`; there is no second
  submission or correction path.
- `TeacherAttendanceGuard` runs inside their transaction before any
  Attendance lock: capability → `ActingEmployeeResolver::hold()` →
  visibility (404) → `TeachingOwnership::hold()` on the date
  (`ATTENDANCE_OUTSIDE_TEACHING_ASSIGNMENT`, 422).
- The lock order is identity → TeachingAssignment → the section 12 order.
- Reads use `TeacherAttendanceAccess::scope()`, which filters in the query.

**Surfaces.**
- API `/api/v1/schools/{school}/my/attendance-sessions` (GET, POST),
  `…/scheduled-classes`, `…/roster-preview`, `…/{id}` and
  `/my/attendance-records/{id}/correct`, all `capability:attendance.teacher`
  + `private-no-store`.
- The owned submit is **not** `idempotent` (CLAUDE.md rule 32): a replay
  would skip the identity/ownership re-check, and duplicates are refused by
  the unique indexes (409).
- Page `/app/my-attendance` ("My Attendance") reuses the Index, Take and
  Show pages with only the teacher's classes, rosters and registers. It is
  linked from the dashboard by the capability.

**Denials.**
- **403:** no capability, or not an eligible Employee
  (`HR_ACTING_EMPLOYEE_UNAVAILABLE`).
- **404:** an unowned class or register, another School's id, an unknown id
  and a malformed id — all the same response.

**Unchanged:** the schema, the Sensitive classification, the audit events
(the acting User is the actor, as for Tier 1), zero domain events, and every
section 5–14 rule.
