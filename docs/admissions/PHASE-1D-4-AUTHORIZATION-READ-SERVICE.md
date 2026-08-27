# Phase 1D.4 — Admissions Authorization + Read Service

This checkpoint completes the non-HTTP application-layer foundation
required before the Admissions administrative API (Phase 1D.5): the
`admissions.view`/`admissions.manage` capability pair, evidence-backed
default role grants, and two tenant-safe, authorization-neutral read
services (`AdmissionApplicationReadService`, `ApplicantReadService`).
**No controllers, no routes, no Vue, no changes to lifecycle/
conversion semantics** — exactly as scoped.

## 1. Capabilities

```
admissions.view    View Admission Applicants and Applications
admissions.manage  Manage Admission Applicants and Applications
                    (create, update, decide, convert)
```

Registered in `Database\Seeders\CapabilityAndRoleSeeder`, matching the
established `<module>.view`/`<module>.manage` catalog convention
exactly (`students.*`, `guardians.*`, `enrollments.*`). A single pair
covers the whole lifecycle including conversion — `admissions.create`/
`.accept`/`.convert`/`.delete` were deliberately NOT added, mirroring
`enrollments.manage` covering create/complete/withdraw/cancel/transfer
as one capability rather than five (`docs/modules/ADMISSIONS.md` §13
already proposed exactly this pair; this checkpoint implements it
unchanged).

**`admissions.manage` does NOT imply `admissions.view`.** Confirmed by
reading `App\Support\Authorization\CapabilityResolver` directly: it
has no capability-inheritance mechanism at all — `schoolCapabilities()`
returns the flat, deduplicated union of every capability every one of
the actor's role assignments grants, with no expansion step. Every
role that needs both `admissions.view` and `admissions.manage` is
granted both explicitly below, exactly like every other view/manage
pair in this catalog (`enrollments.*`'s own docblock states this same
rule). Proven directly by
`AdmissionsCapabilityTest::admissions_view_does_not_imply_admissions_manage`/
`admissions_manage_does_not_imply_admissions_view`.

## 2. Default role grants

Both `school_admin` and `principal` receive **both**
`admissions.view` and `admissions.manage` — full parity, not the
asymmetric HR/Rollover pattern.

**Evidence, not invention:** the catalog already contains two directly
analogous precedents for this exact decision:

- `students.*`/`guardians.*` (Phase 1A.4): granted in full to BOTH
  roles, with the seeder's own comment reasoning "[Student/Guardian]
  identity is an operational, not purely administrative, concern" —
  unlike School profile/Campus administration, which stays
  Principal-view-only.
- `enrollments.*` (Phase 1B.4): granted in full to BOTH roles, with
  the identical "same kind of hands-on operational concern for a
  Principal" reasoning.

Admissions is the same kind of operational concern as these three —
reviewing applications, deciding, and converting an accepted Applicant
into a Student is routine day-to-day administrative work, not a rare
or unusually high-blast-radius action. This is explicitly **not** the
same shape as the two asymmetric precedents in the catalog:

- HR (`hr.employees.*`) withholds most sub-capabilities from both
  default roles because of Restricted/Highly-Sensitive HR data tiers
  that have no Admissions analogue (Admissions has one plain view/
  manage pair, no data-sensitivity tiering).
- `enrollments.rollovers.*` withholds `.manage` from Principal
  specifically because a single rollover **execution** can mutate
  hundreds/thousands of Enrollment rows in one action — a materially
  higher blast radius than any single `enrollments.manage` operation.
  Admissions conversion has no such bulk-execution shape: one
  `AdmissionConversionService::convert()` call creates exactly one
  Student and one StudentEnrollment, the same single-record scope as
  an ordinary `enrollments.manage` operation, never a bulk one.

Given no repository evidence supports withholding either capability
from either default role, and strong, directly analogous evidence
supports granting both in full (matching the majority pattern in this
exact "People operational data" area of the catalog), this checkpoint
did not need to invoke the "ROLE GRANT DECISION REQUIRED" stop
condition. No new role was introduced.

## 3. Authorization architecture

- No role-name string comparisons appear anywhere in production code
  (`Role::key` values like `school_admin`/`principal` exist only
  inside the seeder/fixture layer, exactly like every other module's
  precedent).
- `ApplicantService`, `AdmissionApplicationService`,
  `AdmissionConversionService` are **unmodified** — no `Gate` calls, no
  `User`/membership inspection added to any domain/Application-layer
  service.
- The two new read services are likewise authorization-neutral — see
  §4.

A future Phase 1D.5 controller is responsible for calling
`Gate::authorize('capability', ['admissions.view'|'admissions.manage', $school])`
(or the `AuthorizesCapability` trait) before ever reaching either read
service or any of the three Application-layer write services — exactly
the same boundary `StudentEnrollmentReadService`/
`EnrollmentRolloverReadService`'s own docblocks already establish for
their domains.

## 4. Read services

Two classes, one per aggregate (`docs/admissions/
PHASE-1D-0-ADMISSIONS-ARCHITECTURE.md` §7's explicit "1D.4 —
AdmissionApplicationReadService for list/filter" naming, extended with
a small `ApplicantReadService` for the Applicant-specific search/
detail/history need `docs/modules/ADMISSIONS.md` §14 also scopes).

Both mirror `App\Domain\Students\Application\StudentEnrollmentReadService`/
`EnrollmentRolloverReadService`'s established shape exactly — the
dominant pattern in this codebase's Students/Academic-adjacent read
services (3 of the 4 existing read services follow it; only HR's
`EmployeeDirectoryService` bakes authorization/DTOs into the service
itself, justified there by HR's Restricted/Highly-Sensitive
disclosure-boundary requirements that do not apply to Admissions):

- Plain array `$filters`, `LengthAwarePaginator`/`Collection` returns
  — no DTOs/Resources, no new "broad repository layer."
- No `User`/`Gate` injected, no membership inspection.
- No explicit `TenantContext::withSchool()` call — relies entirely on
  the ambient `SchoolScope`/RLS already active on `applicants`/
  `admission_applications` (the same "caller is always an
  already-tenant-resolved request/job" assumption both mirrored
  precedents make). Every test drives this through
  `TenantContext::withSchool()` itself, matching how a real request's
  middleware would already have established context.
- Never mutates state, never audited (reads are reads — proven by a
  dedicated no-side-effects test in each suite).

### `AdmissionApplicationReadService`

- `directory(array $filters = [], int $perPage = 25): LengthAwarePaginator`
  — filters: `status` (allow-listed against the six real lifecycle
  values — an unrecognized value is silently ignored, never passed to
  the query), `academic_year_id`, `campus_id`, `grade_level_id`,
  `applicant_name` (first/last name `ilike '%term%'`, matching
  `StudentController::index()`'s exact search shape). Default sort:
  newest first (`created_at desc`, `id desc` tiebreaker). Selects a
  column list excluding `decision_note` and eager-loads `applicant`
  with a partial column selection excluding `date_of_birth`, plus
  `academicYear`/`campus`/`gradeLevel` — never Guardian/contact/
  StudentSubjectEnrollment/Communications/Documents.
- `detail(string $applicationId): ?AdmissionApplication` — full model
  (`decision_note` included), eager-loads `applicant` (full, DOB
  included), `academicYear`, `campus`, `gradeLevel`,
  `convertedStudent`, `convertedStudentEnrollment`. Relies on `find()`
  under ambient RLS: a foreign-School id and a random UUID both
  resolve to `null`, structurally indistinguishable (proven by test).

### `ApplicantReadService`

- `search(?string $name = null, int $perPage = 25): LengthAwarePaginator`
  — first/last name substring match, School-scoped, no fuzzy/
  phonetic tolerance (proven: a misspelling does not match). Selects
  only `id`/`school_id`/`first_name`/`middle_name`/`last_name` — never
  `date_of_birth`, matching `StudentController::presentSummary()`'s
  identical list-level DOB exclusion.
- `detail(string $applicantId): ?Applicant` — full model, DOB
  included (detail-level, matching `presentDetail()`'s precedent).
- `applications(Applicant $applicant): Collection<AdmissionApplication>`
  — every AdmissionApplication this Applicant has ever had, newest
  first, never collapsed (a rejected application followed by a new
  draft reapplication both remain visible and distinct rows — proven
  by test, matching `ADMISSIONS.md` §3's "reapplication is a new row,
  never a mutation" rule).

Both classes cap `perPage` at `MAX_PER_PAGE = 100` (matching
`EmployeeDirectoryService::MAX_PER_PAGE`'s established numeric
convention — the only other place in this codebase that enforces a
paginator ceiling explicitly), default `25`.

## 5. Tenant isolation / non-enumeration

Every filter is applied to the already-tenant-scoped
`AdmissionApplication`/`Applicant` query state itself (SchoolScope/
RLS) — a foreign-School id passed as any filter (academic year,
campus, grade level, or a directly-requested detail id) simply matches
zero rows or resolves to `null`; it is never distinguished from "does
not exist," and it can never widen or redirect the query outside the
current School. No `withoutGlobalScope`/`withoutGlobalScopes` appears
anywhere in this checkpoint's diff. Proven directly by dedicated tests
for every filter dimension and both `detail()` methods (foreign-School
row vs. random UUID produce the identical outcome).

## 6. Performance

`directory()` on both services issues a small, fixed number of queries
per page regardless of row count (one page query + one count query +
N eager-load queries, never one query per row) — proven by a
`DB::listen()`-based query-count test on
`AdmissionApplicationReadService::directory()`, mirroring
`StudentEnrollmentReadServiceTest`'s identical N+1 regression pattern.

## 7. Privacy

- `decision_note`: excluded from `directory()`'s hydrated columns
  (list view), included in `detail()` (internal detail, appropriate
  for a future `admissions.view`/`.manage`-gated endpoint). Never
  searched, never in an audit event (unchanged from Phase 1D.2/1D.3),
  never in a log.
- `Applicant.date_of_birth`: excluded from both
  `ApplicantReadService::search()` results and
  `AdmissionApplicationReadService::directory()`'s eager-loaded
  `applicant` relation (list-level), included at both services'
  `detail()` level — matching `StudentController`'s existing
  administrative Student read precedent exactly.
- No Guardian/contact data is joined, selected, or eager-loaded
  anywhere in either read service — `applicants`/
  `admission_applications` still have no contact columns at all
  (unchanged since Phase 1D.1), and neither service reaches into
  `guardians`/`guardian_contacts`/`student_guardian_relationships`.
- No email/phone search exists (Admissions stores none — unchanged).

## 8. Deferred (unchanged from `ADMISSIONS.md`)

- HTTP/API (controllers, routes, request validation, OpenAPI) — Phase
  1D.5, which combines `authorizeCapability('admissions.view'|
  '.manage', $school)` with these two read services.
- Vue/Inertia UI — Phase 1D.6.
- Re-admission/existing-Student matching, Documents, Communications —
  unchanged, no evidence any belong to this checkpoint.

## 9. Recommended next checkpoint

**Phase 1D.5 — Admissions Administrative API.** Not implemented here.
