# Phase 1D.5 — Admissions Administrative API

This checkpoint exposes the already-complete Admissions application
layer (Phase 1D.1-1D.4) through the repository's existing authenticated
`/api/v1` administrative surface. **No domain/schema semantics changed,
no Vue/Inertia UI, no Documents/Communications/Fees integration** —
exactly as scoped.

## 1. Controllers / routes

`App\Domain\Admissions\Http\Controllers\ApplicantController` and
`AdmissionApplicationController` — thin by construction, mirroring
`StudentController`/`StudentEnrollmentController`/`GuardianController`'s
established shape exactly. Every mutation delegates to the existing
Phase 1D.2/1D.3 Application-layer services; every read delegates to
the existing Phase 1D.4 read services. No lifecycle/compatibility/
conversion rule is duplicated in either controller.

All 12 routes live under the existing `Route::middleware(['auth:sanctum',
'school-membership'])->prefix('schools/{school}')` group in
`routes/api.php`, alongside every other administrative module —
Admissions does **not** get its own `/admissions` URL sub-prefix
(inspected first: no existing module uses one; Students/Guardians/
Enrollments/AcademicStructure/HR all sit flat under `/schools/{school}/`).

| Method | URI | Capability |
|---|---|---|
| GET | `/applicants` | `admissions.view` |
| POST | `/applicants` | `admissions.manage` |
| GET | `/applicants/{applicant}` | `admissions.view` |
| GET | `/applicants/{applicant}/applications` | `admissions.view` |
| GET | `/admission-applications` | `admissions.view` |
| POST | `/admission-applications` | `admissions.manage` |
| GET | `/admission-applications/{admissionApplication}` | `admissions.view` |
| POST | `/admission-applications/{id}/submit` | `admissions.manage` |
| POST | `/admission-applications/{id}/accept` | `admissions.manage` |
| POST | `/admission-applications/{id}/reject` | `admissions.manage` |
| POST | `/admission-applications/{id}/withdraw` | `admissions.manage` |
| POST | `/admission-applications/{id}/convert` | `admissions.manage` |

No delete endpoint, no generic `PATCH { status }` lifecycle endpoint —
the state machine owns its own explicit transition commands, exactly
as `ADMISSIONS.md`/root CLAUDE.md require. `admissions.manage` gates
every write including conversion — no separate `admissions.create`/
`.accept`/`.convert` capability (mirrors `enrollments.manage` covering
its whole Enrollment lifecycle as one capability).

Every mutation route carries `capability:admissions.manage`,
`throttle:school-api-mutations`, and `idempotent` (matching every
consequential create/state-transition route elsewhere in this file);
every controller action ALSO calls `$this->authorizeCapability(...)`
inline (defense in depth, the same double-check every existing
mutation controller performs). Validation is inline
`$request->validate([...])` throughout — no `FormRequest` classes
(unchanged repository convention).

## 2. Domain exception mapping

Every domain exception these services can raise
(`InvalidAdmissionApplicationTransitionException`,
`AdmissionApplicationAlreadyConvertedException`,
`IncompatibleConversionSectionException`,
`OpenAdmissionApplicationExistsException`,
`AdmissionGuardianSelectionRequiredException`,
`CrossSchoolAdmissionReferenceException`,
`CrossSchoolRelationshipException`, `DuplicateStudentNumberException`,
`DuplicateEnrollmentRollNumberException`) already extends a base class
implementing `getStatusCode()`/`errorCode()` — rendered **automatically**
by `bootstrap/app.php`'s generic `/api/*` exception envelope
(`{"error": {"message", "status", "code", "requestId", "errors"}}`).
None of these are caught in either controller. Only the two failure
modes with no such contract — `InvalidArgumentException` from
`EmailNormalizer`/`PhoneNormalizer` (malformed conversion-time contact
input) and a raw `UniqueConstraintViolationException` from a contact-
uniqueness race — are translated to `ValidationException`, mirroring
`GuardianContactController::store()`'s identical two-catch-block
pattern.

| Exception | HTTP | code |
|---|---|---|
| `InvalidAdmissionApplicationTransitionException` | 422 | `INVALID_ADMISSION_APPLICATION_TRANSITION` |
| `AdmissionApplicationAlreadyConvertedException` | 422 | `ADMISSION_APPLICATION_ALREADY_CONVERTED` |
| `IncompatibleConversionSectionException` | 422 | `INCOMPATIBLE_CONVERSION_SECTION` |
| `OpenAdmissionApplicationExistsException` | 422 | `OPEN_ADMISSION_APPLICATION_EXISTS` |
| `AdmissionGuardianSelectionRequiredException` | 422 | `ADMISSION_GUARDIAN_SELECTION_REQUIRED` |
| `DuplicateStudentNumberException` | 422 | `DUPLICATE_STUDENT_NUMBER` |
| `DuplicateEnrollmentRollNumberException` | 422 | `DUPLICATE_ENROLLMENT_ROLL_NUMBER` |

No exception message contains SQLSTATE or a constraint/index name
(unchanged since Phase 1D.2/1D.3 — those exceptions' own messages were
already clean; this checkpoint only adds the HTTP transport).

## 3. Not-found / tenant privacy

Applicant/AdmissionApplication path-resolved ids use the exact
`AdmissionApplicationReadService::detail()`/`ApplicantReadService::detail()`
pattern (`abort_if($model === null, 404)`), relying entirely on
ambient SchoolScope/RLS — a foreign-School id and a random UUID
produce the **identical** 404, proven directly by test for every
entity-resolution endpoint (Applicant show/applications,
AdmissionApplication show/convert). Body-supplied foreign references
(`applicant_id`, `academic_year_id`, `campus_id`, `grade_level_id`,
`section_id`, `guardian.guardian_id`) are validated same-School via
`Rule::exists(...)->where('school_id', $school->id)` at the HTTP
boundary — a missing id and a foreign-School id both fail validation
identically (never distinguished), matching
`StudentEnrollmentController::store()`'s established `section_id`
pattern exactly.

A central User without an active membership in the target School gets
404 (not 403) — `EnsureSchoolMembershipContext` middleware, shared
unchanged infrastructure, "indistinguishable from a School that
doesn't exist."

No endpoint accepts `school_id` as request input anywhere — School
comes exclusively from the authenticated membership/`{school}` route
binding + `school-membership` middleware, proven directly by test
(a `school_id` field in an Applicant-create payload is silently
ignored; the created row's real `school_id` is the route's School).

## 4. Response projections

Neither controller ever returns `$model->toArray()`. Every response is
an explicit whitelist:

- **Applicant list/search row**: `id`, `firstName`, `middleName`,
  `lastName` — no `dateOfBirth` (matches
  `StudentController::presentSummary()`'s identical Highly-Sensitive-
  data list exclusion). **Detail**: adds `dateOfBirth`, `createdAt`,
  `updatedAt`.
- **AdmissionApplication list row**: `id`, `status`, `applicant`
  (Applicant summary shape), `academicYear`/`campus`/`gradeLevel`
  (compact `{id, name, code}` refs), `createdAt`, `convertedAt` — no
  `decisionNote` (Phase 1D.4 policy, re-proven by test at the HTTP
  layer). **Detail**: adds `decisionNote`, `convertedStudentId`,
  `convertedStudentEnrollmentId` (bare ids only — the full Student
  record is never re-projected here; that PII belongs to `/students/{id}`),
  `updatedAt`.
- **Conversion response** (`{application, student, enrollment,
  guardian, relationship}`): `student`/`guardian` use the same compact
  summary shape as their own respective controllers (`studentNumber`/
  `firstName`/`middleName`/`lastName`/`status` — no DOB, no Guardian
  contact values echoed back — staff already supplied whatever
  contact they submitted, echoing it back is unnecessary and proven
  absent by test). `guardian`/`relationship` are both `null` together
  when the request carried no `guardian` instruction.

## 5. Conversion request shape

`AdmissionGuardianInstructionInput` (nested under `guardian` in the
request body) is a small, closed, two-mode shape mirroring
`GuardianConversionInstruction` exactly. **The one canonical "no
Guardian" HTTP representation**: omit the `guardian` key entirely, or
send it as `null` — both map identically to a `null`
`GuardianConversionInstruction` (proven by test: the core success test
sends no `guardian` key at all and the response's `guardian`/
`relationship` are both `null`).

- `mode: "create"` — `first_name` (required), `middle_name`/
  `last_name` (nullable), optional `contact_type`/`contact_value`
  (both required together). Guardian contact input is transient HTTP
  request input only — passed straight through to
  `AdmissionConversionService::convert()`, never persisted by this
  controller or by Admissions, never echoed in the response (proven by
  test: the submitted email string never appears anywhere in the
  conversion response body).
- `mode: "link_existing"` — `guardian_id` (required, validated
  same-School at the HTTP boundary).

`AdmissionGuardianSelectionRequiredException`'s 422 response never
contains candidate Guardian ids, names, or contact values — the
generic error envelope only carries the exception's own generic
message and `errorCode()`; `candidateCount` (the one piece of
diagnostic data the exception object carries) is not part of the
rendered envelope at all. The existing, already-authorized
`POST /schools/{school}/guardian-candidates` endpoint
(`GuardianController::candidates()`, `guardians.view`) remains the
sanctioned way a future UI looks up contact-matching candidates before
resubmitting as `link_existing` — this checkpoint does **not** add a
duplicate Admissions-specific candidate-lookup endpoint (no repository
evidence established a need for one; reusing the existing Guardian
API is the smaller, evidence-backed choice).

## 6. Idempotency

Every consequential mutation route carries the standard `idempotent`
route middleware, on top of each service's own domain-level
idempotency (Phase 1D.2's conditional-UPDATE lifecycle guard, Phase
1D.3's locked-row + `AdmissionApplicationAlreadyConvertedException`) —
the same "both layers" pattern `AcademicYearController::activate()`
already establishes. No new/generalized HTTP idempotency middleware
was built. A second `convert` HTTP call against an already-converted
application (different `Idempotency-Key`, simulating a genuinely new
request rather than a literal retry) is proven to hit the domain-level
guard and return `ADMISSION_APPLICATION_ALREADY_CONVERTED` with no
duplicate Student/Enrollment.

## 7. Pagination / performance

Both list endpoints (`GET /applicants`, `GET /admission-applications`)
accept `per_page` (default 25, max 100 — clamped inside the read
service itself, `ApplicantReadService::MAX_PER_PAGE`/
`AdmissionApplicationReadService::MAX_PER_PAGE`, matching
`EmployeeDirectoryService::MAX_PER_PAGE`'s established numeric
convention) and `page`. Both controllers call the read service's
`directory()`/`search()` method directly — no query logic duplicated
in either controller — inheriting the read service's own proven fixed-
query-count eager loading (Phase 1D.4's N+1 regression test remains
green, re-run unchanged in this checkpoint).

## 8. OpenAPI

Comparable administrative `/api/v1` domains (Students, Guardians,
Enrollments, Academic Structure, HR) are already fully represented in
`packages/contracts/openapi/school-os-api.yaml` — Admissions is
therefore added there too, following the identical schema/parameter/
response style exactly (12 new paths, `ApplicantSummary`/`Applicant`/
`ApplicantInput`, `AdmissionApplicationSummary`/`AdmissionApplication`/
`AdmissionApplicationInput`, `AdmissionDecisionInput`,
`AdmissionGuardianInstructionInput`, `AdmissionConversionInput`,
`AdmissionConversionResult`/`AdmissionConversionEnrollment`/
`AdmissionConversionRelationship`, reusing `EnrollmentRef`/
`StudentSummary`/`GuardianSummary`/`ContactType`/`RelationshipType`
where the response shape genuinely matches). `npm run generate`
(`packages/shared-types`) regenerated `src/generated/school-os-api.ts`
from this source — never hand-edited; `npm run type-check` passes.

## 9. Deferred (unchanged)

Vue/Inertia UI (Phase 1D.6), Documents/Communications/Fees
integration, public/self-service admissions, re-admission/existing-
Student matching — no evidence any belong to this checkpoint.

## 10. Recommended next checkpoint

**Phase 1D.6 — Admissions Administrative UI.** Not implemented here.
