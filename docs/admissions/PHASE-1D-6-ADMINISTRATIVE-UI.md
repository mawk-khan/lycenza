# Phase 1D.6 — Admissions Administrative UI

This checkpoint exposes the already-complete Admissions application
layer (Phase 1D.1-1D.4) and the already-complete `/api/v1` surface
(Phase 1D.5) through a session-authenticated Vue/Inertia administrative
UI, mirroring the Students/Guardians/Enrollment web surfaces exactly.
**No domain/schema/capability/OpenAPI change, no public admissions
portal, no Applicant login, no Guardian self-service, no Documents/
Communications/Fees integration** — exactly as scoped.

## 1. Pages / controllers

`App\Http\Controllers\App\ApplicantController` and
`AdmissionApplicationController` — thin session-authenticated Inertia
controllers under `App\Http\Controllers\App`, distinct from (but
delegating to the same services as) the Phase 1D.5 JSON API controllers
under `App\Domain\Admissions\Http\Controllers`. Every mutation calls the
existing Phase 1D.2/1D.3 Application-layer services
(`ApplicantService`, `AdmissionApplicationService`,
`AdmissionConversionService`); every non-trivial read calls the
existing Phase 1D.4 read services (`ApplicantReadService`,
`AdmissionApplicationReadService`). Neither service class was modified.

Routes live under `app/admissions` in `routes/web.php`, inside the
existing `Route::middleware('auth')` group:

| Method | URI | Page / action |
|---|---|---|
| GET | `/app/admissions` | AdmissionApplication directory |
| GET | `/app/admissions/applicants` | Applicant directory |
| GET | `/app/admissions/applicants/create` | Add Applicant form |
| POST | `/app/admissions/applicants` | Create Applicant |
| GET | `/app/admissions/applicants/{applicant}` | Applicant detail + full application history |
| GET | `/app/admissions/applicants/{applicant}/applications/create` | New Application form (nested under its Applicant) |
| POST | `/app/admissions/applicants/{applicant}/applications` | Create Application |
| GET | `/app/admissions/guardians/search` | Guardian-picker adapter (see §5) |
| GET | `/app/admissions/guardians/candidates` | Guardian-picker adapter (see §5) |
| GET | `/app/admissions/{admissionApplication}` | Application workspace (status, lifecycle actions, conversion) |
| POST | `/app/admissions/{admissionApplication}/submit` | draft → submitted |
| POST | `/app/admissions/{admissionApplication}/accept` | submitted → accepted |
| POST | `/app/admissions/{admissionApplication}/reject` | submitted → rejected |
| POST | `/app/admissions/{admissionApplication}/withdraw` | submitted/accepted → withdrawn |
| POST | `/app/admissions/{admissionApplication}/convert` | accepted → converted |

`applicants/*` and `{admissionApplication}/create`-equivalent literal
routes are registered before the `/{admissionApplication}` wildcard,
the same ordering discipline the Communications route group already
established for `announcements`/`templates`/`participants/search`.

Application creation is nested under its owning Applicant
(`/app/admissions/applicants/{applicant}/applications/create`), mirroring
`StudentEnrollmentController`'s "create nested under a Student"
convention — there is no free-standing Applicant picker anywhere in
this UI.

`admissions.view` gates every read page; `admissions.manage` gates
every mutation and every lifecycle/conversion action — no separate
`admissions.create`/`.accept`/`.convert` capability (matches the Phase
1D.5 API's identical single-capability design). Authorization is
checked with `AuthorizesCapability::authorizeCapability()` inside each
controller action, never a route `capability:` middleware — matching
every other `App\Http\Controllers\App\*` controller in this
repository (`StudentController`, `StudentEnrollmentController`,
`GuardianController`).

## 2. Domain exception mapping

Unlike the `/api/v1` surface — which relies on `bootstrap/app.php`'s
generic `/api/*` exception envelope to render every `AdmissionsException`
subclass automatically — the `/app` web/Inertia surface has no such
automatic envelope. Every domain exception a user can plausibly trigger
through this UI is therefore caught explicitly and translated to
`ValidationException::withMessages()`, exactly like
`StudentEnrollmentController`'s pattern, so Inertia's `form.errors`
renders it inline instead of a broken 500 page:

| Action | Exception | Field key |
|---|---|---|
| Create Application | `OpenAdmissionApplicationExistsException`, `CrossSchoolAdmissionReferenceException`\* | `grade_level_id` |
| submit/accept/reject/withdraw | `InvalidAdmissionApplicationTransitionException` | `status` (synthetic — no real form field backs these one-click actions) |
| Convert | `IncompatibleConversionSectionException`, `ActiveEnrollmentConflictException`\*, `CrossSchoolEnrollmentException`\* | `section_id` |
| Convert | `AdmissionApplicationAlreadyConvertedException`, `InvalidAdmissionApplicationTransitionException` | `status` |
| Convert | `DuplicateStudentNumberException` | `student_number` |
| Convert | `DuplicateEnrollmentRollNumberException`, `InvalidEnrollmentRollNumberException` | `roll_number` |
| Convert | `InvalidEnrollmentDateRangeException` | `starts_on` |
| Convert | `AdmissionGuardianSelectionRequiredException` | `guardian.contact_value` |
| Convert | `CrossSchoolRelationshipException`\*, `DuplicateRelationshipException`\* | `guardian.guardian_id` |
| Convert | `InvalidArgumentException` (malformed contact), `UniqueConstraintViolationException` (contact race) | `guardian.contact_value` |

\* Defense-in-depth only — same-School `Rule::exists()` validation at
the HTTP boundary already makes these unreachable through this UI's
own forms, matching `StudentEnrollmentController::store()`'s identical
reasoning for `section_id`.

The already-converted case (`AdmissionApplicationAlreadyConvertedException`)
is the direct "stale second tab" scenario: submitting the conversion
form again after another tab/request already converted the same
Application shows a clean inline error under `status` and creates no
second Student/Enrollment (re-proven at this layer in
`tests/Feature/App/AdmissionsUiTest.php`, on top of the domain-level
guarantee Phase 1D.3 already proved).

## 3. Section picker

The conversion form's Section `<select>` is populated by a new private
`AdmissionApplicationController::compatibleSectionOptions()`, gated by
no capability of its own — only whatever the calling `show()` action
already checked (`admissions.view` to render the page,
`admissions.manage` to see the conversion section at all). This
directly follows `StudentEnrollmentController::sectionOptions()`'s
established pattern of an inline, capability-neutral reference-data
query with no dedicated endpoint — **no new `academics.*` capability
was needed**, resolving this checkpoint's Section-picker dependency
without requiring a stop.

Unlike Enrollment's `onlyEligible` (active Section, non-closed Academic
Year), the Admissions picker filters to Sections whose
`academic_year_id`/`campus_id`/`grade_level_id` **exactly match** the
Application's own committed academic context — a UI convenience that
steers staff toward a valid choice; `AdmissionConversionService::convert()`
remains the sole authority for this compatibility check
(`IncompatibleConversionSectionException`), never duplicated here. The
picker is only populated (and only rendered) when the Application is
`accepted` and the actor holds `admissions.manage`.

## 4. Guardian picker

The conversion form's "link existing Guardian" search and the
create-mode "check for existing" contact lookup are served by two new,
narrow adapter endpoints:

- `GET /app/admissions/guardians/search` (name search)
- `GET /app/admissions/guardians/candidates` (exact contact match)

**Why new endpoints, not the existing ones.** The existing equivalents
(`StudentGuardianRelationshipController::searchGuardians()`/
`candidateGuardians()`) live under
`/app/students/{student}/guardians/...` and require **both**
`students.manage` and `guardians.manage` — a design that made sense for
their original purpose (linking a Guardian to an already-existing
Student) but is structurally wrong for Admissions conversion: there is
no Student yet at the point a staff member is searching for a Guardian
to attach, and every other Admissions action in this checkpoint (and in
Phase 1D.5) gates on `admissions.manage` alone, never transitively
requiring the capabilities of the services it composes.

**Why this is safe.** The two new actions reuse the **identical** query
logic — the same `Guardian::query()->where(...->ilike...))` name search
and the same `GuardianContactService::findCandidatesBySchool()` exact-
contact lookup already proven safe (same-School only, no fuzzy
matching, no cross-School leakage) by the Students/Guardians test
suites — copied verbatim into
`AdmissionApplicationController::searchGuardians()`/`candidateGuardians()`,
gated only by `admissions.manage`. This is the narrow,
repository-consistent adapter this checkpoint's brief explicitly
permitted when "genuinely required and security-equivalent to existing
Guardian reads" — no new capability, no new query behavior, only a
narrower authorization gate appropriate to Admissions' own design.

## 5. Guardian modes in the conversion form

The conversion form's Guardian section offers three modes, matching
`GuardianConversionInstruction`'s own closed shape exactly (never
inferred from field presence):

- **None** (default) — omits the `guardian` key from the request
  entirely, the one canonical "no Guardian" representation (Phase
  1D.5's identical convention).
- **Create** — first/middle/last name, optional contact type/value.
  Contact information is transient form input only — never persisted
  by this controller (`AdmissionConversionService` owns that, via the
  existing `GuardianContactService`). Submitting a contact value that
  matches an existing Guardian shows a clean inline error under
  `guardian.contact_value` (`AdmissionGuardianSelectionRequiredException`);
  the form additionally offers a "Check for existing" button (using the
  candidates adapter above) that lets staff discover this conflict
  *before* submitting and switch straight to Link-existing mode with
  the matched Guardian pre-selected.
- **Link existing** — a live name-search picker (using the search
  adapter above); the selected Guardian's id is submitted as
  `guardian.guardian_id`, validated same-School at the HTTP boundary.

## 6. Privacy

- Applicant **list/search** rows exclude `dateOfBirth` (matches
  `ApplicantReadService::search()`'s own list-level exclusion); the
  Applicant **detail** page includes it.
- AdmissionApplication **list/directory** rows exclude `decisionNote`;
  the Application **workspace** page includes it.
- The Application workspace's `convertedStudentId`/
  `convertedStudentEnrollmentId` are **bare ids only** — never an
  embedded Student name/number/DOB projection. This matters because an
  `admissions.view` actor does not necessarily hold `students.view`;
  `show()` separately computes a `canViewStudents` prop
  (`students.view`), and the Vue page uses it only to decide whether to
  *render a link* to `/app/students/{id}` — the destination itself
  re-checks authorization server-side regardless, the same
  "hidden button is not authorization" principle
  `nav.canViewStudents` already establishes for the Dashboard.
- Guardian contact values submitted during conversion are never echoed
  back in any response or prop (matches the Phase 1D.5 API's identical
  "staff already supplied it, don't repeat it" design).

## 7. Navigation

`DashboardController` gains a `nav.canViewAdmissions` boolean
(`admissions.view`), and `Dashboard.vue` gains a corresponding
`<li v-if="nav.canViewAdmissions">` link to `/app/admissions` —
identical shape to every other capability-gated nav entry already
there. As with the rest of this navigation map, every linked
destination re-checks authorization server-side regardless of what
this boolean says.

## 8. Shared components

`StatusBadge.vue` is extended (not replaced, matching Phase 1B.6/1B.7F's
established precedent for this same file) with the four new
AdmissionApplication statuses that didn't already exist in its status
vocabulary: `submitted`, `accepted`, `rejected`, `converted` (`draft`
and `withdrawn` were already present and are reused as-is —
semantically identical plain labels). `Pagination.vue` and
`EmptyState.vue` are reused directly, unmodified. No new shared/global
component was introduced; there is still no `Layouts` directory in this
codebase, and this checkpoint does not add one.

## 9. Destructive/important action confirmation

Withdraw, Reject, and Convert each show a native `window.confirm()`
dialog before submitting, matching
`StudentGuardianRelationshipController`'s `unlink()`/`Transfer.vue`'s
established pattern for this codebase — Submit and Accept do not (lower
consequence, and Accept explicitly does not create any canonical
record, only a status change).

## 10. Deferred (unchanged)

Public admissions portal, Applicant login, Guardian self-service,
Documents workflow, application fees, Communications notifications,
CRM/inquiry pipeline, re-admission/existing-Student matching — no
evidence any belong to this checkpoint.
