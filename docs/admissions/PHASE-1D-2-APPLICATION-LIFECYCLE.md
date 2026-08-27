# Phase 1D.2 — Application Lifecycle Service

This checkpoint implements the Application-layer write path for
Admissions on top of the schema shipped in Phase 1D.1
(`docs/admissions/PHASE-1D-1-ADMISSIONS-DOMAIN-SCHEMA.md`): Applicant
creation, AdmissionApplication creation, and the four lifecycle
transitions reachable before conversion
(`submit`/`accept`/`reject`/`withdraw`). **No conversion, no
authorization, no HTTP/API, no Vue/UI exists yet** — exactly as scoped
by `docs/modules/ADMISSIONS.md` §17's "1D.2" slice.

## 1. Service boundaries

Two services, matching the conceptual split `PHASE-1D-0-ADMISSIONS-
ARCHITECTURE.md` §7 already named:

- `App\Domain\Admissions\Application\ApplicantService` — Applicant
  identity creation only. Mirrors
  `App\Domain\Students\Application\StudentService::create()`'s exact
  shape: `$attributes` are trusted, prevalidated primitives (no domain
  validation beyond Eloquent's own casts — the same precedent
  `StudentService::create()` already establishes).
- `App\Domain\Admissions\Application\AdmissionApplicationService` —
  AdmissionApplication creation plus every lifecycle transition this
  checkpoint may reach. Mirrors
  `App\Domain\Students\Application\StudentEnrollmentService`'s exact
  shape: dedicated methods per transition, never a generic
  `update(status:)`.

Both are deliberately authorization-neutral, matching every other
Application-layer service in this codebase — Phase 1D.4 owns the
`admissions.view`/`admissions.manage` capability seeding and the
`Gate::authorize`/`AuthorizesCapability` check a future controller
applies before ever reaching these services (root CLAUDE.md rule 24).

## 2. Applicant creation

`ApplicantService::create(School $school, array $attributes, ?User
$actor = null): Applicant`

- School ownership always comes from the caller-supplied, already-
  trusted `$school` argument — never from request payload (root
  CLAUDE.md rule 19).
- Accepted fields: `first_name` (required), `middle_name` (nullable),
  `last_name` (nullable), `date_of_birth` (required) — exactly
  `ADMISSIONS.md` §4's schema, no more.
- Audit: `admission_applicant.created`, subject the new `Applicant`,
  no metadata beyond what `AuditRecorder` captures automatically (the
  subject id) — name/date_of_birth are never included (Sensitive
  personal data of a minor, `docs/security/DATA-CLASSIFICATION.md`).

## 3. Application creation

`AdmissionApplicationService::create(Applicant $applicant,
AcademicYear $academicYear, Campus $campus, GradeLevel $gradeLevel,
?User $actor = null): AdmissionApplication`

- Always creates in `draft` status. No `section_id`, roll number, or
  Guardian/contact input — those are conversion-time-only decisions
  (`ADMISSIONS.md` §5/§9), structurally unreachable from this method's
  signature.
- **Cross-School check order (root CLAUDE.md rule 10):** `AcademicYear`/
  `Campus`/`GradeLevel` are compared against `$applicant->school_id`
  and rejected with `CrossSchoolAdmissionReferenceException` BEFORE
  anything else runs — in particular, before the open-application
  check below, so a cross-School reference attempt never leaks whether
  an unrelated open application already exists in the other School.
- **Academic context compatibility:** confirmed by reading the actual
  models (`App\Models\Campus`, `App\Domain\AcademicStructure\
  Infrastructure\GradeLevel`) — both are independently School-owned
  reference data with no Campus/AcademicYear/GradeLevel cross-linkage
  in this codebase (`GradeLevel`'s own docblock: "School-wide... not
  Campus- or AcademicYear-scoped"). Same-School ownership is therefore
  the ONLY compatibility rule this checkpoint enforces — there is no
  additional relationship to validate, matching `ADMISSIONS.md`'s own
  expectation.
- **Open-application conflict:** the database's partial unique index
  (`admission_applications_one_open_per_context`, Phase 1D.1) remains
  the authoritative concurrency guarantee. The service does not
  pre-check for an existing open application — it lets the write
  attempt and translates a `UniqueConstraintViolationException` whose
  `$e->index` matches that index into
  `OpenAdmissionApplicationExistsException`, exactly the pattern
  `StudentEnrollmentService::enroll()`/`StudentSubjectEnrollmentService::
  enroll()` already establish for `student_enrollments_one_active_per_
  student_year`/`student_subject_enrollments_one_active_per_offering`.
- Audit: `admission_application.created`, metadata limited to
  `applicantId`/`academicYearId`/`campusId`/`gradeLevelId` (IDs only,
  per `ADMISSIONS.md` §15).

## 4. Lifecycle

Reachable transitions in this checkpoint (`ADMISSIONS.md` §6, minus
`accepted -> converted`, which Phase 1D.3 owns exclusively):

```
draft     -> submitted   (submit())
submitted -> accepted    (accept())
submitted -> rejected    (reject())
submitted -> withdrawn   (withdraw())
accepted  -> withdrawn   (withdraw())
```

- **Submit:** `draft -> submitted` only. No conversion provenance, no
  `decision_note` mutation — the accepted architecture does not
  describe a note at submission time.
- **Accept:** `submitted -> accepted` only. `decision_note` is
  optional — the column is general-purpose in the accepted schema
  (`ADMISSIONS.md` §5), not restricted to rejection, so acceptance may
  carry an internal staff note (e.g. "approved by committee") exactly
  like rejection can.
- **Reject:** `submitted -> rejected` only. `decision_note` is
  optional — a rejection is never required to carry a sensitive
  free-text explanation merely to be recorded.
- **Withdraw:** `submitted -> withdrawn` OR `accepted -> withdrawn`.
  Never touches `decision_note` — an accepted application's decision
  note must survive a later withdrawal unchanged (`ADMISSIONS.md` §6:
  "the decision itself is immutable"), so `withdraw()` passes no
  `extraUpdate` to the shared transition guard.
- **Blank `decision_note` normalization:** a note that is `null` or
  all-whitespace after `trim()` is stored as `null` — matching this
  codebase's existing blank-to-null precedent
  (`App\Domain\HR\Application\EmployeeImportRow`).

### Illegal transitions

Every other combination — in particular any transition FROM
`rejected`, `withdrawn`, or `converted` — is rejected by
`InvalidAdmissionApplicationTransitionException` (422,
`INVALID_ADMISSION_APPLICATION_TRANSITION`). `converted` is reachable
only via direct factory/DB fixture in this checkpoint (no service sets
it); every lifecycle method rejects it identically to any other
terminal status, and no lifecycle method ever mutates
`converted_student_id`/`converted_student_enrollment_id`/
`converted_at`.

### Locking / concurrency

Every lifecycle method funnels through a single private
`transitionTo()` helper that mirrors
`StudentEnrollmentService::transitionToTerminalStatus()`/
`AcademicYearService::activate()`'s established double-guard shape:

1. `lockForUpdate()`-reload the authoritative row inside
   `DB::transaction()` — never trusts the caller's possibly-stale
   in-memory `$application->status`.
2. Reject immediately if the locked row's CURRENT status is not in the
   transition's allowed `$fromStatuses`.
3. Perform a conditional `WHERE status IN ($fromStatuses)` UPDATE and
   check the affected-row count — a lost race (someone else already
   transitioned this exact row between the lock and the update) is
   caught here, not assumed impossible.
4. Audit only after the conditional UPDATE actually affected a row —
   no false-success audit is possible.

`tests/Feature/Admissions/AdmissionApplicationServiceTest.php`'s
`the_service_reloads_the_authoritative_row_rather_than_trusting_a_
stale_in_memory_status` test proves this directly: a stale in-memory
reference (still reading `submitted` after a separate `reject()` call
already moved the real row to `rejected`) is correctly rejected by
`accept()`, which would otherwise have incorrectly succeeded had the
service trusted the stale object instead of the locked reload.

## 5. Tenancy

Every service method calls `TenantContext::withSchool($model->school,
...)`, deriving School context from the model's own `school`
relation — the same established pattern
`StudentEnrollmentService`/`AcademicYearService`/`GuardianService`
already use throughout this codebase. This works because a real
caller can never obtain a foreign-School model to pass into the
service in the first place: `SchoolScope` (Layer 1, `BelongsToSchool`)
filters every ordinary tenant-scoped query to the ambient
`TenantContext`, so School A's actor cannot load School B's
`AdmissionApplication` through the sanctioned query path at all.
`tests/Feature/Admissions/AdmissionApplicationServiceTest.php`'s
`a_school_cannot_read_another_schools_admission_application_via_the_
ordinary_tenant_scoped_query` test proves this boundary directly (a
`find()` under School A's context returns `null` for a School B row —
the same leakage-prevention mechanism proven exhaustively at the raw
RLS layer by `tests/Feature/Postgres/AdmissionApplicationIntegrityTest.php`).

## 6. Audit

Every successful mutation is audited via the existing
`AuditRecorder::school()` (ADR 0017), append-only:

- `admission_applicant.created`
- `admission_application.created`
- `admission_application.submitted`
- `admission_application.accepted`
- `admission_application.rejected`
- `admission_application.withdrawn`

Metadata is limited to IDs and non-PII status values
(`applicantId`/`academicYearId`/`campusId`/`gradeLevelId`/
`previousStatus`/`newStatus` where applicable) — never Applicant
name/date_of_birth, never `decision_note`. Illegal transitions,
cross-School reference rejections, open-application conflicts, and any
transaction failure never produce a success audit event — the audit
call sits after the conditional UPDATE/insert inside the same
`DB::transaction()`, so a rollback removes both together.

## 7. Deferred (unchanged from `ADMISSIONS.md`)

- **Conversion** (`accepted -> converted`, `ConvertAcceptedAdmission`)
  — Phase 1D.3.
- **Authorization** (`admissions.view`/`admissions.manage` capability
  seeding, `Gate`/`AuthorizesCapability` checks) — Phase 1D.4.
- **HTTP/API** (controllers, routes, request validation, OpenAPI) —
  Phase 1D.5.
- **Vue/Inertia UI** — Phase 1D.6.
- **Domain events/outbox** — no mandatory event-publication pattern
  exists for a comparable lifecycle service in this codebase (Phase
  1C's `StudentSubjectEnrollmentService` ships with none either);
  deferred, not built speculatively.
- **`decision_note` post-hoc correction** — `ADMISSIONS.md` §6
  anticipates staff being able to correct a note administratively
  after a terminal decision, but no such method exists in this
  checkpoint (not named in the 1D.2 brief); a future checkpoint adds
  it if real usage requires it.

## 8. Recommended next checkpoint

**Phase 1D.3 — Accepted Admission → Student/SIS Conversion**
(`ConvertAcceptedAdmission`, per `ADMISSIONS.md` §7/§8/§11, including
the required forced-failure atomicity test). Not implemented here.
