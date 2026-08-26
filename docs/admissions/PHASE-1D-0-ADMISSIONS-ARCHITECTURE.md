# Phase 1D.0 — Admissions Architecture & Boundary Definition

## 1. What "Phase 1D" means (and doesn't)

**"Phase 1D" is an informal, module-local checkpoint label, not a
`docs/roadmap/MASTER-ROADMAP.md` phase.** The master roadmap sequences
work at the coarse Phase 0A-0O level; it places Students/SIS, Guardians,
and Admissions together under **Phase 0F — People**, and does not
itself enumerate finer checkpoints. `1A`/`1B`/`1C` are an informal
Students/SIS numbering convention that lives entirely in
`docs/students/` filenames and commit messages — `PHASE-1C-STUDENT-
SUBJECT-ENROLLMENT-FOUNDATION.md` §1 says this explicitly, and this
checkpoint follows the identical precedent. `MASTER-ROADMAP.md` is not
edited by this checkpoint to manufacture a formal phase that doesn't
exist.

Admissions is filed under its own `docs/admissions/` directory (this
file) plus `docs/modules/ADMISSIONS.md`, not `docs/students/`, because
`docs/architecture/DOMAIN-MAP.md` already models Admissions as its
**own** module (a distinct ownership row, not a Students/SIS
sub-part) — the same reasoning `PHASE-1C-...md` §1 used to justify
filing itself under `docs/students/` rather than `docs/academic-
structure/` (file location follows aggregate ownership, not the
numbering convention).

## 2. Roadmap evidence for Admissions (why this checkpoint exists)

- `MASTER-ROADMAP.md`, Phase 0F — People: "Students/SIS, Guardians,
  Admissions. First real domain events (`StudentAdmitted`,
  `GuardianLinked`, `AdmissionLeadCreated` —
  `docs/architecture/EVENTS.md`) get real producers here..."
- `docs/architecture/DOMAIN-MAP.md` line 75 (Layer 2 table): Admissions
  is already modeled with a defined ownership scope ("Admission leads,
  applications, admission workflow → produces a Student record via
  Students/SIS's Application contract") and a fixed dependency
  direction ("Admissions calls into SIS to create a student; SIS never
  calls into Admissions").
- `docs/modules/STUDENT-ENROLLMENT.md` line 2649: "**Admissions
  (enquiry/lead/application/interview/offer workflow)** — explicitly a
  separate, later Admissions checkpoint. Phase 1B provides the landing
  point (`accepted applicant → Student identity → StudentEnrollment`),
  not the applicant pipeline itself."
- `docs/modules/STUDENT-GUARDIAN-IDENTITY.md`'s "deferred" list
  reiterates the same boundary from the Identity side.
- No `docs/modules/ADMISSIONS.md` existed before this checkpoint —
  every other built module (Students, Guardians, Communications, HR,
  Academic Structure) has one; Admissions was the one named-but-
  undocumented gap.

## 3. Domain ownership (summary — full detail in `docs/modules/ADMISSIONS.md` §2)

| Owns | Module |
|---|---|
| Pre-Student application workflow (`Applicant`, `AdmissionApplication`) | **Admissions** (new) |
| Student identity, Guardian identity, relationships, enrollment, subject enrollment | Students/SIS (unchanged) |
| AcademicYear, Campus, GradeLevel, Section, Subject, SubjectOffering | Academic Structure (unchanged) |

Dependency direction: Admissions → Academic Structure, Schools/Tenancy,
Students/SIS service contracts. Students/SIS never depends on
Admissions.

## 4. Why this checkpoint is next (from the accepted Phase 1 inventory)

Of every credible remaining Students/SIS-adjacent option, Admissions
was the only one that is (a) completely unbuilt — zero code anywhere,
(b) repeatedly and explicitly named as future work across every
relevant existing doc, (c) has every dependency already available
(Academic Structure complete since Phase 0D; Students/SIS identity +
enrollment landing point complete since Phase 1A/1B), and (d) is a
genuine new foundational domain rather than an enhancement to already-
complete Phase 1C work (elective mutual-exclusivity, rollover
integration, roster UI — all smaller, lower-priority follow-ups per
the accepted inventory).

## 5. Decisions made in this checkpoint

See `docs/modules/ADMISSIONS.md` for the full architecture. Summary of
every DECIDED point:

- Two aggregates: `Applicant` + `AdmissionApplication`, not one.
- No Inquiry/Lead aggregate, no AdmissionDecision entity in v1.
- Lifecycle: `draft → submitted → (accepted|rejected|withdrawn)`,
  `accepted → converted` (only path) or `accepted → withdrawn`.
- Accepted ≠ converted — converted is a separate, staff-triggered,
  idempotent conversion command.
- Conversion composes five **existing, unmodified** Students/SIS/
  Guardians services inside one new outer transaction (`StudentService::
  create`, `GuardianService::create`, `GuardianContactService::create`,
  `StudentGuardianRelationshipService::link`,
  `StudentEnrollmentService::enroll`) — Section/roll_number chosen at
  conversion time, never pre-stored on the application.
- Guardian contact captured pre-conversion is a draft, non-canonical,
  application-scoped snapshot — never a second encrypted/searchable
  identity system; conversion reuses the existing
  `GuardianContactService::findCandidatesBySchool()` lookup for an
  explicit staff create-vs-link decision, never fuzzy/auto-linked.
- v1 always creates a new Student at conversion; re-admission/existing-
  Student linking is explicitly deferred (named as an open risk, not
  silently ignored).
- Full tenancy/RLS/composite-FK pattern, no exceptions.
- Two new capabilities proposed (`admissions.view`/`.manage`), not
  seeded yet.
- PII, audit, and deferred-scope boundaries fully enumerated.

## 6. Open questions (named, not resolved here)

- Student Number allocation at conversion scale — Phase 1A has no
  allocator; staff manually enters/confirms one during conversion.
  Whether Students/SIS eventually needs a `StudentNumberAllocator`
  (mirroring HR's `EmployeeNumberAllocator`) is out of Admissions'
  own boundary to decide.
- Returning-Student recognition before conversion — procedural
  mitigation only in v1 (staff should check for an existing Student
  first); no architectural duplicate-detection exists for Students the
  way it does for Guardians.

## 7. Implementation slices

1. **1D.1 — Admissions Domain & Schema Foundation.** `App\Domain\Admissions`
   module skeleton; `applicants` and `admission_applications` migrations
   (composite FKs, RLS, the partial-unique "no duplicate simultaneous
   open application per Applicant/AcademicYear/Campus/GradeLevel"
   index); `Applicant`/`AdmissionApplication` Eloquent models; RLS
   isolation tests (real PostgreSQL, cross-School). No services, no
   HTTP surface yet.
2. **1D.2 — Application Lifecycle Service.** `AdmissionApplicationService`
   (create/update-while-draft/submit/accept/reject/withdraw), the
   status-transition guard, audit events (§15 of `ADMISSIONS.md`), and
   domain/unit tests for every legal and illegal transition.
3. **1D.3 — Accepted → Student/SIS Conversion.** `ConvertAcceptedAdmission`,
   composing the five existing services inside one transaction, the
   Guardian create-vs-link lookup, conversion-provenance fields, the
   idempotency guard, and the mandatory forced-failure atomicity test
   (§11 of `ADMISSIONS.md`).
4. **1D.4 — Authorization + Read Service.** Seed `admissions.view`/
   `.manage` into `CapabilityAndRoleSeeder.php`, an `AdmissionApplicationReadService`
   for list/filter (§14 of `ADMISSIONS.md`), authorization tests (allow
   + deny, per root CLAUDE.md rule 13).
5. **1D.5 — Administrative API.** `/api/v1` routes mirroring the
   Students/Enrollments admin-surface shape; request validation;
   cross-School/IDOR tests; API error contract.
6. **1D.6 — Administrative UI.** Vue/Inertia pages for the application
   list, create/edit-while-draft, decision actions, and the conversion
   action — reusing existing form/picker patterns established by
   `Students/Create.vue`/`Enrollments/Create.vue`, not a new UI
   framework.

## 8. Recommended next checkpoint

**Phase 1D.1 — Admissions Domain & Schema Foundation.**

Branch: no existing branch/worktree corresponds to this work (verified
— `git branch --all | grep -Ei 'admission|applicant|phase-1d'` returns
nothing but this checkpoint's own new branch). The next gate should
continue on `feature/phase-1d-admissions-foundation` (this checkpoint's
branch), or a fresh branch from current `origin/main` if this one has
since been merged.
