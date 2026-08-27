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
- **Superseded by §9 below (1D.0A hardening) and `ADMISSIONS.md` §9:**
  Admissions stores no guardian/applicant contact data at all in v1, in
  any form — guardian name/email/phone are supplied as direct input to
  the conversion command itself and flow straight into the canonical
  `GuardianService`/`GuardianContactService`, never staged in an
  Admissions-owned column first. Conversion still reuses the existing
  `GuardianContactService::findCandidatesBySchool()` lookup against
  that conversion-time input for an explicit staff create-vs-link
  decision, never fuzzy/auto-linked.
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
   module skeleton; `applicants` (identity fields only — no contact
   columns, per §9 hardening) and `admission_applications` migrations
   (composite FKs, RLS, the partial-unique "no duplicate simultaneous
   open application per Applicant/AcademicYear/Campus/GradeLevel"
   index, the `status ⇔ conversion-provenance` CHECK from `ADMISSIONS.md`
   §5); `Applicant`/`AdmissionApplication` Eloquent models; RLS
   isolation tests (real PostgreSQL, cross-School). No services, no
   HTTP surface yet. This slice's schema is intentionally smaller after
   1D.0A than originally scoped in 1D.0 — no guardian/contact table or
   columns at all.
2. **1D.2 — Application Lifecycle Service.** `AdmissionApplicationService`
   (create/update-while-draft/submit/accept/reject/withdraw), the
   status-transition guard, audit events (§15 of `ADMISSIONS.md`), and
   domain/unit tests for every legal and illegal transition.
3. **1D.3 — Accepted → Student/SIS Conversion.** `ConvertAcceptedAdmission`,
   accepting guardian name/email/phone as direct command input (never
   pre-stored, §9 hardening), composing the five existing services
   inside one outer transaction, the Guardian create-vs-link lookup,
   conversion-provenance fields, the idempotency guard, and the
   mandatory forced-failure atomicity test (§11 of `ADMISSIONS.md` —
   this checkpoint's own required proof, not assumed in advance).
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

## 9. Hardening pass (1D.0A) — privacy and conversion boundary corrections

A follow-up review of this checkpoint identified two problems in the
original architecture, both corrected in place (this doc and
`ADMISSIONS.md`) rather than in a separate document, since neither
changes the module boundary or roadmap placement established above —
only the internal design of two already-decided points.

**1. Pre-conversion contact PII.** The original design proposed plain
`applicant_guardian_email`/`_phone` columns on `AdmissionApplication`,
reasoned to be safe because the data wasn't yet a canonical Guardian.
That reasoning was wrong — pre-canonical PII is still PII. **Corrected
decision: Admissions stores no guardian/applicant contact data at all,
in any form, in v1.** Guardian name/email/phone are supplied as direct
input to the conversion command at the moment of conversion and flow
straight into the already-correct canonical `GuardianService`/
`GuardianContactService` handling — Admissions never has a copy of
this data to protect. This also further **simplifies** 1D.1's schema
(one fewer set of columns, no encryption/hashing design needed in this
domain at all) rather than complicating it. See `ADMISSIONS.md` §9 for
the full reasoning, including why an Admissions-owned encrypted
contact store was considered and explicitly deferred (`ContactLookupHasher`'s
fixed, non-parameterized domain-prefix design means doing this
properly requires a Support-layer decision this documentation-only
checkpoint should not make as a side effect).

**2. Conversion boundary precision.** Three points were tightened with
direct code citations rather than general pattern-matching: (a) the
status-value-vs-CHECK-constraint choice is now grounded in the actual
`students`/`student_enrollments`/`student_subject_enrollments`
migrations (application-level guard, matching precedent exactly — see
`ADMISSIONS.md` §6A), with a genuine new CHECK proposed only for the
cross-column `status ⇔ conversion-provenance` invariant (`ADMISSIONS.md`
§5); (b) the transaction-atomicity claim was corrected from an
implied-proven statement to an explicitly *required, not yet proven*
invariant, with all five composed services' actual bodies now directly
cited (`ADMISSIONS.md` §11); (c) Guardian optionality at conversion and
the honest absence of any deterministic Student-level duplicate-
detection mechanism are now both named explicitly rather than left
implicit (`ADMISSIONS.md` §10-11).

No change to domain ownership, aggregate model, lifecycle, tenancy/RLS
pattern, or the recommended next checkpoint (§8 above) — 1D.1 remains
the correct next step, now with a smaller, more precisely-specified
schema.
