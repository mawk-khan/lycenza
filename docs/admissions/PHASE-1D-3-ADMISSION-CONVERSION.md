# Phase 1D.3 — Accepted Admission → Student/SIS Conversion

This checkpoint implements the cross-domain conversion orchestration
decided in `docs/modules/ADMISSIONS.md` §7/§8 and hardened in
`docs/admissions/PHASE-1D-0-ADMISSIONS-ARCHITECTURE.md` §9: a single
`AdmissionConversionService::convert()` call that turns an `accepted`
AdmissionApplication into a canonical `Student`, an initial
`StudentEnrollment`, and (optionally) a `Guardian`/
`StudentGuardianRelationship`, entirely by composing existing,
unmodified Students/SIS/Guardians services inside one outer
transaction. **No authorization, no HTTP/API, no Vue/UI, no
re-admission/Student-matching, no Documents, no Communications** —
exactly as scoped.

## 1. Service

`App\Domain\Admissions\Application\AdmissionConversionService::convert()`
fulfills the `ConvertAcceptedAdmission` role named throughout the
architecture docs, implemented as a repository-conventional `XxxService`
class (this codebase has no `Command`/orchestrator-class precedent).

```php
convert(
    AdmissionApplication $application,
    string $studentNumber,
    Section $section,
    string $rollNumber,
    string $startsOn,
    ?GuardianConversionInstruction $guardian = null,
    ?User $actor = null,
): AdmissionConversionResult
```

Returns an immutable `AdmissionConversionResult` DTO (`application`,
`student`, `enrollment`, `guardian` and `relationship` — both nullable
when no Guardian instruction was given), matching this codebase's
existing `XxxResult` DTO convention
(`App\Domain\HR\Application\EmployeeImportRowResult`).

## 2. Accepted-only precondition, locked

Inside the outer transaction, the AdmissionApplication row is
`lockForUpdate()`-reloaded and re-evaluated from the authoritative
database state — never the caller's possibly-stale in-memory
`$application->status` (proven directly by
`AdmissionConversionServiceTest::a_stale_in_memory_accepted_application_is_rejected_once_the_real_row_has_moved_on`).

- `draft`/`submitted`/`rejected`/`withdrawn` → `InvalidAdmissionApplicationTransitionException($status, 'converted')`, reusing Phase 1D.2's exception (this IS the identical "illegal state transition" concept, `ADMISSIONS.md` §6's `accepted -> converted` being the only path in).
- Already `converted` → the distinct `AdmissionApplicationAlreadyConvertedException` — a retried conversion attempt (network retry, double click, a second worker losing the concurrency race) never re-runs canonical creation. No second Student, no second Enrollment, no second Guardian, no second success audit.

Because the DB CHECK (`admission_applications_conversion_provenance_check`,
Phase 1D.1) makes `status = 'accepted'` and "all three provenance
columns NULL" logically equivalent, a separate provenance-NULL check
is redundant and not performed.

## 3. Same-connection atomicity (verified, not assumed)

Every composed service and the audit models use Laravel's **default**
database connection — confirmed by reading each file directly, not
inferred:

| Component | Connection |
|---|---|
| `App\Domain\Students\Application\StudentService` | default (no `$connection` override, no explicit `DB::connection()` call) |
| `App\Domain\Guardians\Application\GuardianService` | default |
| `App\Domain\Guardians\Application\GuardianContactService` | default |
| `App\Domain\Guardians\Application\StudentGuardianRelationshipService` | default |
| `App\Domain\Students\Application\StudentEnrollmentService` | default |
| `App\Models\SchoolAuditEvent` / `App\Models\PlatformAuditEvent` (via `AuditRecorder`) | default |
| `App\Domain\Admissions\Infrastructure\AdmissionApplication` | default |

This is what makes the atomicity model sound: each composed service's
own internal `DB::transaction()` call nests as a PostgreSQL SAVEPOINT
under `AdmissionConversionService::convert()`'s outer
`DB::transaction()` rather than escaping to an independent connection
— Laravel's transaction nesting is genuinely atomic here because there
is only ever one underlying connection/session involved.

`ADMISSIONS.md` §11 explicitly deferred *proving* this (as opposed to
documenting it as a requirement) to this checkpoint. It is now proven
by a real forced-failure integration test (§5 below), not merely
asserted.

## 4. Composition order (unchanged from `ADMISSIONS.md` §8)

1. `StudentService::create()` — identity fields copied verbatim from
   `Applicant` (`first_name`/`middle_name`/`last_name`/`date_of_birth`).
   `student_number` is conversion-command input, passed straight
   through (§6 below) — `decision_note` is never read.
2. `GuardianService::create()` — only in `create` mode.
3. `GuardianContactService::create()` — only in `create` mode with a
   contact value supplied.
4. `StudentGuardianRelationshipService::link()` — whenever a Guardian
   instruction is given (either mode).
5. `StudentEnrollmentService::enroll()` — Section/roll_number/starts_on
   are conversion-time-only inputs, never pre-stored on the
   application (`ADMISSIONS.md` §5).

Then, still inside the same transaction: a conditional
`WHERE status = 'accepted'` UPDATE sets
`status = 'converted'`/`converted_student_id`/
`converted_student_enrollment_id`/`converted_at`, followed by the
`admission_application.converted` audit event.

## 5. Forced-failure atomicity proof (mandatory gate)

`AdmissionConversionServiceTest::a_forced_failure_at_the_final_enrollment_step_rolls_back_every_earlier_canonical_write`
uses a **real** database constraint, not an artificial hook: a target
Section's roll number `'09'` is pre-occupied (via the real
`StudentEnrollmentService::enroll()`, so a genuine baseline
`student.created`/`student_enrollment.created` audit trail exists),
then the conversion under test is invoked with a Guardian instruction
(`create` mode, with a contact) requesting the SAME roll number `'09'`
in the SAME Section — so `StudentEnrollmentService::enroll()`'s own
`student_enrollments_school_id_academic_year_id_section_id_roll_`
unique constraint throws `DuplicateEnrollmentRollNumberException`
AFTER Student, Guardian, GuardianContact, and
StudentGuardianRelationship have all already been written earlier in
the same transaction.

After the exception, the test proves:

- AdmissionApplication is still `accepted`, all provenance columns NULL.
- Exactly the ONE pre-existing (unrelated) Student/Enrollment remain — the conversion's own Student/Enrollment never persisted.
- Zero Guardian/GuardianContact/StudentGuardianRelationship rows exist.
- Zero `admission_application.converted`/`guardian.created`/`guardian_contact.added`/`student_guardian.linked` audit events exist.
- Exactly ONE `student.created`/`student_enrollment.created` audit event exists (the pre-existing baseline's, not a second one from the rolled-back conversion attempt) — proving nested canonical success audits roll back together with their data, never surviving as false-success records.

## 6. Concurrent double-conversion (mandatory gate)

`AdmissionConversionConcurrencyTest` spawns two GENUINE, separate OS
processes (`Symfony\Component\Process\Process`, mirroring
`AcademicYearActivationConcurrencyTest`'s established real-concurrency
pattern) racing `AdmissionConversionService::convert()` against the
SAME `accepted` AdmissionApplication over real PostgreSQL. The row
lock (`lockForUpdate()`) is what makes this safe: exactly one
process's transaction proceeds and commits; the other blocks on the
row lock until the first commits, then observes `status = 'converted'`
and throws `AdmissionApplicationAlreadyConvertedException` — without
ever creating a Student. Verified: exactly one `converted` output,
exactly one `AdmissionApplicationAlreadyConvertedException` output,
exactly one Student and one StudentEnrollment exist afterward. Run
repeatedly during development with no flakiness observed.

## 7. Section compatibility

`StudentEnrollmentService::enroll()` has no concept of "the
AdmissionApplication's intended academic context" — it derives
`academic_year_id`/`campus_id`/`grade_level_id` purely from whatever
`Section` it is given. This checkpoint is therefore the only place
that can catch a Section whose context silently disagrees with the
application's own committed intent (`ADMISSIONS.md` §5: an application
commits to AcademicYear + Campus + GradeLevel; Section is the
conversion-time decision). `AdmissionConversionService::convert()`
checks all four dimensions (School, AcademicYear, Campus, GradeLevel)
BEFORE entering the transaction or creating any canonical record,
throwing `IncompatibleConversionSectionException` on any mismatch —
covered by four dedicated tests (cross-School, wrong year, wrong
campus, wrong grade).

Guardian cross-School safety is deliberately **not** duplicated —
`StudentGuardianRelationshipService::link()`'s own
`CrossSchoolRelationshipException` already provides it, and the
forced-failure/atomicity guarantee means letting Student creation
happen before that check fires is safe (it rolls back completely,
proven by `linking_a_foreign_school_guardian_is_rejected_and_leaves_no_student_behind`).

## 8. Guardian policy

**Guardian is optional at conversion** — confirmed by reading
`StudentService::create()` (never touches Guardian/relationship
tables) and the `students` migration (no `guardian_id` column, no
constraint requiring a relationship). Passing `$guardian = null` is
the "no Guardian" mode.

`GuardianConversionInstruction` (a small, closed, two-mode value
object — never a generalized command framework) selects:

- **`create`** — `GuardianService::create()` + optional
  `GuardianContactService::create()`. Before creating, if a contact
  value is supplied, the existing
  `GuardianContactService::findCandidatesBySchool()` exact-match
  lookup runs; if it finds ANY candidate, `AdmissionConversionService`
  refuses to blindly create a duplicate Guardian —
  `AdmissionGuardianSelectionRequiredException` is thrown (no
  fuzzy/name matching, no silent auto-link, no partial canonical
  creation — the whole transaction, including the already-created
  Student, rolls back).
- **`link_existing`** — an explicit, staff-selected `Guardian` model.
  No new Guardian is created. Cross-School safety comes from
  `StudentGuardianRelationshipService::link()` itself (§7 above).

Guardian name/contact are transient, in-memory command input only —
Admissions never persists them (verified structurally:
`applicants`/`admission_applications` have no `email`/`phone` columns,
and by test — `conversion_with_a_new_guardian_creates_guardian_contact_and_relationship_with_no_admissions_contact_copy`).

## 9. Student Number

Still owned entirely by Students/SIS (`ADMISSIONS.md` §8's "no
allocator exists" finding, unchanged). `$studentNumber` is
staff-supplied conversion-time input, passed straight through to
`StudentService::create()`, which remains the sole authority for
per-School uniqueness (`DuplicateStudentNumberException`, tested
end-to-end here with no SQLSTATE exposure).

## 10. Residual duplicate-Student limitation (documented, not solved)

Unchanged from `ADMISSIONS.md` §10: there is no deterministic
cross-person identity key for `Student` in this codebase. Conversion
always creates a brand-new `Student` — already-converted provenance
prevents the SAME AdmissionApplication from converting twice, and
`DuplicateStudentNumberException` prevents two Students sharing a
literal number, but neither catches a genuinely returning child
receiving a second `Student` identity under a different number.
Re-admission/existing-Student linking remains explicitly deferred to
a future checkpoint (`ADMISSIONS.md` §10's own "OPEN QUESTION").

## 11. Audit

`admission_application.converted` — metadata: `applicantId`,
`studentId`, `studentEnrollmentId`, `academicYearId`, `campusId`,
`gradeLevelId`. Never: Student Number, Applicant name/DOB, Guardian
name/contact, `decision_note`, or `roll_number` (verified by test:
`the_conversion_success_audit_carries_only_id_metadata`). Composed
services' own success audits (`student.created`, `guardian.created`,
`guardian_contact.added`, `student_guardian.linked`,
`student_enrollment.created`) are allowed to fire — they are part of
the same outer transaction and roll back together with everything
else on failure (§5).

## 12. Exceptions added

- `AdmissionApplicationAlreadyConvertedException` — idempotent-retry
  outcome (distinct from illegal-transition).
- `IncompatibleConversionSectionException` — Section's academic
  context does not match the application's.
- `AdmissionGuardianSelectionRequiredException` — `create` mode found
  an existing contact-matching Guardian candidate; carries
  `candidateCount` only (no ids/names).

`InvalidAdmissionApplicationTransitionException` (Phase 1D.2) is
reused for the non-accepted-source-status case; `DuplicateStudentNumberException`/
`DuplicateEnrollmentRollNumberException`/`CrossSchoolRelationshipException`
(all pre-existing) propagate unmodified from the composed services.

## 13. Deferred (unchanged)

- Re-admission / existing-Student matching (`ADMISSIONS.md` §10).
- Authorization (`admissions.view`/`.manage`) — Phase 1D.4.
- HTTP/API, Vue/Inertia UI — Phase 1D.5/1D.6.
- Public/self-service admissions, Documents, Communications — no
  evidence any belong to this checkpoint; not built speculatively.

## 14. Recommended next checkpoint

**Phase 1D.4 — Admissions Authorization + Read Service.** Not
implemented here.
