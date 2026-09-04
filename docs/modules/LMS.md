# LMS (Phase 0I)

**Status: Phase 0I.1 architecture contract frozen; Phase 0I.2 (Learning
Content Foundation) implemented.** Assignment, Submission, and every
external LMS integration remain unimplemented. This document is the
living operational reference for the LMS bounded context; the decision
record is ADR 0037 (`docs/architecture/adr/0037-lms-domain-contract.md`)
— read that first for the *why*, this document for the *what*,
mirroring how `ACADEMIC-STRUCTURE.md`/`HR.md` relate to their own ADRs.

## 1. Module scope

`docs/architecture/DOMAIN-MAP.md` defines **LMS** as *learning content,
assignments, submissions*, Layer 3 ("Core operations"), depending on
Academic Structure and Students/SIS (plus HR, for a future teacher
capability grant — decision 6). `docs/modules/ACADEMICS.md` §2 and
`docs/modules/EXAMINATIONS.md` §2 both already fix the cross-module
boundary line: *"Learning content, **assignments**, **submissions**,
coursework files | **LMS** (Phase 0I)."*

| Layer | Name |
|---|---|
| Roadmap umbrella | **LMS** (Phase 0I) |
| First planned checkpoint | Phase 0I.2 — Learning Content Foundation (not started) |
| Domain directory (planned) | `App\Domain\LMS` |
| Capability namespace (frozen, not seeded) | `lms.content.*` / `lms.assignments.*` / `lms.submissions.*` |

**In scope, eventually:** Learning Content, Assignment, Submission,
coursework/resource files (via Documents, decision 8).

**Explicitly out of scope for Phase 0I, permanently, absent a new
roadmap/ADR/product decision:**
- curriculum delivery, lesson planning, syllabus tracking (owned by
  **Academics**);
- examination marks, authoritative grades, grade scales, result
  publication, report cards, transcripts (owned by **Examinations**);
- any external LMS integration or standard — Canvas, Moodle, Google
  Classroom, Microsoft Teams, OneRoster, LTI, QTI, SCORM, xAPI, Common
  Cartridge, Caliper. No repository document assigns any of these to
  Phase 0I; they remain unscoped, not merely deferred.

**The governing invariant** (already stated in `ACADEMICS.md`/
`EXAMINATIONS.md`, restated here for a single authoritative LMS
reference):

> **An LMS assignment or submission must never implicitly become an
> Examinations grade/mark.** A grade may only ever originate from an
> explicit Examinations mark.

## 2. Terminology

| Term | Meaning | Avoid |
|---|---|---|
| **Learning Content** | A School-authored instructional resource (reading/link/note/attached file) belonging to one `SubjectOffering` — distributable material, not a topic outline (that's `SyllabusUnit`). | — |
| **Assignment** | A staff-authored unit of work (title, instructions, optional resource attachments, due date, lifecycle) that a `SubjectOffering`'s current roster is expected to complete. Never itself a grade-bearing record. | `Course` (see Academic Structure's own naming discipline — `Subject` already owns that concept) |
| **Submission** | One Student's response to one Assignment — coursework file(s)/text, a lifecycle, and optional non-authoritative teacher feedback. | — |
| **Coursework File** | Not a distinct domain entity — a Documents-module file whose owner arm is a LearningContent/Assignment/Submission row (§8). | — |

## 3. Entity relationships

```
SubjectOffering (Academic Structure)
  └─ LearningContent   (LMS; School-authored resource)
  └─ Assignment        (LMS; School-authored unit of work)
       └─ Submission   (LMS; one per Student per Assignment)
```

- **Assignment is scoped by SubjectOffering, not SubjectOffering +
  Section.** `App\Domain\Students\Application\
  SubjectOfferingRosterReadService::currentRosterStudentIds()` /
  `currentRosterCount()` — the sole roster-resolution seam Students/SIS
  exposes — is explicitly Section-agnostic ("a Student in any
  compatible Section of the matching Grade/Campus/Year is eligible")
  and branches internally on required-vs-elective so LMS never needs
  to know which kind of Offering it targets. This mirrors
  `SyllabusUnit`/`ExaminationPaper` (both Offering-wide, "NOT
  Section-specific"), not `CurriculumDelivery`/`AttendanceRecord`
  (genuinely Section-scoped cohort-activity entities). See ADR 0037 §2
  for the full justification.
- **AcademicYear/Campus/GradeLevel** are inherited as integrity pins
  through the `SubjectOffering` composite FK — never denormalized as
  independently client-supplied fields, matching `ExaminationPaper`'s
  own pin pattern.
- **Section** is never a direct parent of Assignment or Learning
  Content. A specific Student's Section, if ever needed for display,
  is resolved through `StudentEnrollment`, never stored redundantly.
- **SyllabusUnit / CurriculumDelivery** — `ACADEMICS.md` §19 already
  reserves a purely additive, nullable future reference from LMS to
  either. Not built by this contract; Academics itself must never
  reference LMS back.
- **Student** — referenced only via Submission (one row per Student per
  Assignment) and via `SubjectOfferingRosterReadService` for roster
  resolution; never denormalized into Assignment/LearningContent.
- **Employee (teacher)** — referenced only for authorship/capability
  purposes (decision 6); no ownership record exists yet (§6).

## 4. Assignment/Submission versus Examinations boundary

| Concept | Owner |
|---|---|
| Curriculum delivery, lesson planning, syllabus tracking | **Academics** |
| Exam scheduling, grading, marks, grade scales, result publication, report cards, transcripts | **Examinations** |
| Learning content, assignments, submissions, coursework files | **LMS** |

**LMS may own:** Assignment title, instructions, resource attachments,
Assignment lifecycle, due date, Submission lifecycle, teacher feedback,
a returned/revision-requested state (if later approved, §9), and
non-authoritative feedback metadata.

**LMS must never own as an authoritative academic record:**
Examination marks, official grades, report-card values, transcript
values, result-publication state.

**No numeric score or rubric field is part of this contract.**
Deferred entirely — see ADR 0037 §3 for the full reasoning
(Examinations' own `StudentMark` is itself still "PROVISIONAL, GATED";
this codebase has no structural mechanism yet to guarantee an
LMS-side score can never be mistaken for an authoritative mark). Only
qualitative (free-text) teacher feedback is in scope, and that text is
itself subject to §5's classification/legal gate.

## 5. Data classification — Sensitive, `[LEGAL REVIEW REQUIRED]`

See `docs/security/DATA-CLASSIFICATION.md` for the authoritative rows.
Summary:

| Entity | Tier | Gate |
|---|---|---|
| Learning Content | Confidential | none — staff-authored, no Student identity |
| Assignment (definition/lifecycle) | Confidential | none — staff-authored, no Student identity |
| Submission (content, feedback, history) | **Sensitive** | **`[LEGAL REVIEW REQUIRED]`** |

**Verdict: SUBMISSIONS REQUIRE LEGAL REVIEW.** Submission is the first
checkpoint in this roadmap to durably store unbounded, Student-authored
free-text and/or file content — every prior Sensitive-tier Student
module (`AttendanceRecord`, `SyllabusUnit`, `CurriculumDelivery`,
`Examination`, `ExaminationPaper`) deliberately *excludes* free text for
exactly this reason. This is **not** the same gate as Examinations'
`StudentMark` flag (grade-authority concern) — it is Submission's own,
independently-triggered instance of the children's-data DPDP gate, and
is **not** waived merely because Documents storage already exists
(ADR 0012 governs storage/access-control mechanics, not the antecedent
lawfulness-of-collection question). Full reasoning: ADR 0037 §4.

**Blocked:** any implementation collecting, storing, or exposing actual
Submission content (text, files, or per-Student teacher feedback).

**Not blocked, may proceed:** Learning Content and Assignment
(definition/lifecycle/resource attachments) — Confidential, staff-
authored, gated by capability only, matching `SyllabusUnit`/
`Examination`/`ExaminationPaper`'s precedent exactly.

## 6. Student/Guardian actor model — unresolved dependency, not an LMS decision

Verified directly against the current `origin/main` source (file
presence, not checkpoint-report prose alone):

- **No authenticated Student account exists.** `students` has no
  `user_id`; no `student` role exists in `CapabilityAndRoleSeeder`; per
  `docs/communication-hub/PHASE-5D-3-FINAL-INTEGRATION.md` §10, "no
  synthetic Student account role or provisioning path exists... only
  linking an account that already exists is supported."
- **A real Guardian self-service login DOES exist on `origin/main`
  today.** `App\Domain\Identity\Application\{AccountInvitationService,
  GuardianAccountActivationService}` and the `identity_account_invitations`
  migration are present in this checkpoint's own worktree. An admin
  issues a one-time hashed-token email invitation to a Guardian's own
  verified `GuardianContact` email; accepting it resolves-or-creates a
  `User` (proving mailbox control) and a `SchoolMembership`, then
  links-or-reuses the `AccountLink` — one atomic transaction, no
  password ever set by an admin. The resulting membership still holds
  no `school_admin`/`principal`-shaped role.
- **No delegated "on behalf of" model exists anywhere** — a linked
  Guardian's `AccountLink` "never, by itself, authorizes" acting for
  the linked Student; every authenticated actor acts only as itself.
- **No Student/Guardian self-service *portal* exists** — the Guardian
  login above reaches Communications' existing IN_APP surfaces only,
  not a dedicated parent-portal product surface.
- **No action anywhere is authorized through the ordinary staff
  capability system for a Guardian or Student actor** — no `student`/
  `guardian` role exists, and a linked Guardian's membership is never
  assigned one. Guardian eligibility for an existing feature
  (Communications conversation participation) instead flows through
  `ConversationParticipantAuthorizationService`'s capability + policy +
  target-exists + active-`AccountLink` chain — never a bare capability
  check against the Guardian's own membership.

**Decision: unresolved for Student; a real dependency exists for
Guardian, but the product-policy question is not decided here** (ADR
0037 §5). For Student, the blocker is structural (no actor exists at
all). For Guardian, an authenticated actor now genuinely exists and
could technically reach a future submit action through the same
AccountLink chain Communications already uses — but whether a School
should let a Guardian submit schoolwork on a Student's behalf is a
product-policy question with no existing precedent either way. Who may
submit a Submission remains **unresolved**, and is independently moot
for now because §5 above (data classification) blocks any real
Submission implementation regardless of which actor is eventually
authorized. This is a dependency for a future checkpoint to resolve —
not a Phase 0I.1 blocker, and not something Learning Content/Assignment
work needs resolved first.

## 7. Teacher authorization model — Option A, capability-only v1

No canonical teacher-to-Section/SubjectOffering ownership record exists
in this codebase. `TimetableEntry.teacher_id` is a real, mutable
weekly-scheduling fact, never described anywhere as authoritative for
ownership/authorization. The gap is named explicitly and repeatedly in
`ACADEMICS.md` ("the platform's first ownership-based authorization
model... does not exist") and `ACADEMIC-STRUCTURE.md` ("an explicit
gap... nothing here fakes or stubs that relationship").

**Chosen: Option A** — capability-only LMS teacher/admin management for
v1, following `SyllabusUnit`/`CurriculumDelivery`/`Examination`/
`ExaminationPaper`'s identical precedent: `lms.content.manage`/
`lms.assignments.manage` granted to School Admin/Principal (and
optionally Academic Coordinator) only, **not** to Teacher, since
granting Teacher a School-wide `.manage` capability with no ownership
scope would let any teacher manage any other teacher's Assignments.

**This is an intentional Phase 0I foundation constraint, not a
permanent substitute for teacher ownership.** A future teacher-scoped
LMS checkpoint is blocked on the same ownership-based authorization
model every other academic module already defers to, not on anything
LMS-specific.

## 8. Capability namespace (frozen, not registered)

| Capability | Scope |
|---|---|
| `lms.content.view` | View Learning Content |
| `lms.content.manage` | Author/edit Learning Content |
| `lms.assignments.view` | View Assignments |
| `lms.assignments.manage` | Author/edit/publish/close Assignments |
| `lms.submissions.view` | Staff view of Submissions |
| `lms.submissions.manage` | Staff management of Submissions (e.g. return/feedback) |
| `lms.submissions.submit` | The Student/Guardian-side act of submitting |

Depth-2 dotted convention, matching `examinations.definitions.*`/
`curriculum.delivery.*`/`syllabus.*`. **Not seeded in
`CapabilityAndRoleSeeder`** — this checkpoint freezes the namespace
shape only; registration happens at implementation time, per every
prior module's precedent.

`lms.submissions.submit` is **not** a normal staff capability — §6
already established that no Student/Guardian capability model exists
at all today. This capability's authorization mechanism is blocked on
§6's dependency, exactly like Submission generally; it is named here
only to freeze its spelling.

## 9. Documents ownership seam (approved future extension, not implemented)

Per ADR 0012 and the current `documents_exactly_one_owner_check`
exclusive-arc CHECK (`employee_id`/`student_id`/`guardian_id`, exactly
one non-null, database-enforced), LMS coursework/resource files extend
the **same shared arc** — not a dedicated table (ADR 0029's
separate-table criteria — non-generic structured fields, narrower
classification vocabulary — do not apply to LMS files).

**Approved future shape:**
- Three new nullable owner columns on `documents` —
  `learning_content_id`, `assignment_id`, `submission_id` — each with a
  composite FK to `(id, school_id)` on its LMS table, added additively.
- `documents_exactly_one_owner_check`'s sum widens to include all
  three, preserving exactly-one-owner-always.
- **`submission_id` ships deactivated at the application layer**
  (`DocumentOwnerTypeNotSupportedException`, matching the existing
  `student_id`/`guardian_id` pattern) until §5's legal-review gate
  clears. `learning_content_id`/`assignment_id` may activate
  independently once their own checkpoints ship, since neither is
  gated.
- Delete/archive: unchanged — Documents are archived, never hard
  deleted; cascade-on-delete fires only if the owning LMS row itself is
  deleted, which never happens once referenced (§10 — status-based
  retirement only).
- Tenant isolation: unchanged — composite-FK + `TenantRls`, no new
  mechanism.

## 10. Lifecycle contract

**Assignment** — `draft → published → closed` (three states, no
separate `archived`):
- Published Assignments MAY be edited via ordinary PATCH, including the
  due date — matching `Examination`/`ExaminationPaper`/`SyllabusUnit`'s
  own "no frozen-after-publish rule" precedent.
- Closed Assignments do not accept new Submissions; `closed →
  published` reopening is an ordinary status transition, not a one-way
  door.
- **Due date is informational/display-only in v1** — whether a
  Submission is still accepted is governed by the Assignment's
  `published`/`closed` status, not a due-date-vs-now clock comparison.
  This intentionally avoids building timezone/clock-comparison logic
  with no confirmed product requirement yet.
- No DELETE route — status-based retirement only (rule 73).

**Submission** — `submitted` is the only state this contract commits
to. A `returned`/`resubmitted` revision loop is product-likely but
**PROVISIONAL**, left for the checkpoint that actually builds
Submission (necessarily after §5's legal-review gate clears and §6's
actor-model dependency resolves) to confirm against real product
requirement. A Student-side `draft` (unsubmitted) state is **not**
committed to either, for the same reason — it presumes an interactive
Student session §6 shows does not exist. Multiple independent grading
attempts are **not** supported; if resubmission is later approved, it
replaces which Submission is "current" via an append-only revision
history, never an in-place overwrite. No DELETE route once referenced.

## 11. Events and notifications contract (illustrative, not implemented)

`assignment.published.v1` · `assignment.closed.v1` ·
`submission.created.v1` · `submission.resubmitted.v1`

- Each event, once its owning checkpoint ships, implements
  `App\Support\Events\ShouldBeOutboxed` via `OutboxedEventDefaults`
  (ADR 0025) like every other domain event in this codebase.
- **None are registered in `App\Support\Webhooks\WebhookEventRegistry`
  by this contract** — external webhook eligibility is a separate,
  explicitly reviewed future decision (CLAUDE.md rules 45/77), matching
  every domain event shipped so far.
- **Recipient resolution for assignment notifications should reuse
  `SubjectOfferingRosterReadService`** — the same seam Communications'
  own `SubjectOfferingAudienceResolver` already established
  ("Communications never re-derives roster membership") — rather than
  LMS re-deriving Guardian/Student reachability itself. No current
  precedent in this codebase has a Layer 3 domain event automatically
  trigger a Communications notification; if a future checkpoint wants
  that, it requires its own explicit consumer design at that time.

## 12. Not in this checkpoint (Phase 0I.1)

Any LMS migration, model, controller, service, route, or capability
registration · external LMS integration/standards of any kind
(Canvas/Moodle/Google Classroom/Teams/OneRoster/LTI/QTI/SCORM/xAPI/
Common Cartridge/Caliper) · numeric scoring/rubrics · Submission
content collection of any kind (legal-review gated) · Student/Guardian
authentication or delegation (cross-cutting dependency, not LMS's to
build) · teacher-scoped/ownership-based authorization · webhook
registration for any LMS event · a `Curriculum`/`Course` entity ·
`Section`-scoped Assignment · any UI.

## 13. Phase 0I.2 — Learning Content Foundation (as-built)

`App\Domain\LMS` — the first concrete LMS fact, implementing exactly
the §5-cleared scope: Learning Content only, no Assignment, no
Submission, no external integration.

**Model**: `LearningContent` / `learning_content` — `id`, `school_id`,
`subject_offering_id` (single parent, composite FK RESTRICT, mirroring
`SyllabusUnit`'s exact shape — no `code` column, no author/owner
column), `title`, `description` (nullable text), `sequence` (display
order, not unique), `status`. `unique(id, school_id)` kept for the
Documents owner-arm FK below.

**Lifecycle**: `draft | published | archived`, exactly three legal
transitions — `draft→published`, `published→archived`,
`archived→published` — the identical closed-transition-map shape ADR
0035 (GradeScale) established, under a parent-row `lockForUpdate()`
(never `TenantLock`). Ordinary field edits (title/description/sequence)
are permitted at **any** status via `update()`, which never accepts
`status`; a lifecycle change always goes through the dedicated
`publish()`/`archive()` service methods and API/web action routes,
matching `CurriculumDeliveryService`'s update/transition split. No
delete route (rule 73).

**Application service**: `App\Domain\LMS\Application\LearningContentService`
is the sole write path (proven by an architecture guard test) — required
here (unlike `SyllabusUnit`'s thin controller) because the lifecycle
state machine plus its aggregate-local lock are rule 76's literal
trigger, the same shape GradeScaleService already established.

**Authorization**: `lms.content.view` / `lms.content.manage`
(§8/decision 7's frozen namespace, now seeded), granted to
`school_admin`/`principal` only — capability-only v1, no teacher
capability, matching decision 6 exactly.

**API** (`/api/v1/schools/{school}/...`): exactly six operations — list/
create nested under `subject-offerings/{subjectOffering}/learning-content`,
show/update flat at `learning-content/{learningContent}`, plus dedicated
`POST .../publish` and `POST .../archive` action routes. No delete, no
Assignment or Submission route. Web (`/app/learning-content`) mirrors
the same five operations (list/create/update/publish/archive) through
`App\Http\Controllers\App\LMS\LearningContentController`, converting a
domain `LmsException` into an ordinary Inertia form error exactly like
`CurriculumDeliveryController` already does.

**Documents integration** (decision 8, realized): a fourth
exclusive-arc owner column, `learning_content_id`, added additively to
`documents` alongside its own composite FK and a widened
`documents_exactly_one_owner_check` (now four terms). Activated in
`DocumentOwner`/`DocumentService`/`DocumentReadService`/
`DocumentListingService` behind `lms.content.manage`/`.view` — the
first non-personal-data owner type, so (unlike Employee's two-tier
split) it carries exactly ONE valid `classification_tier`, `internal`,
fixed server-side and never caller-supplied. New transport:
`POST`/`GET /api/v1/schools/{school}/learning-content/{learningContent}/documents`
on the existing shared `DocumentController` — the generic by-id
`show`/`content`/`archive` Document routes needed no change at all,
since they resolve owner type from the persisted row, never the URL.
`assignment_id`/`submission_id` remain unadded (`submission_id` stays
gated on §5's legal-review clearance when that checkpoint arrives).

**Events**: none. `assignment.published.v1`/etc. remain illustrative
future work per §11 — this checkpoint emits nothing (no consumer
exists, rule 2).

**Classification**: unchanged from §5 — Learning Content is
Confidential, not gated. No Student/Guardian/Employee/teacher identity
exists anywhere in `learning_content` (architecture-guard-tested).

## 14. Future

- **A future Assignment definition checkpoint**: same posture as
  Learning Content — recommended next (Phase 0I.3).
- **Submission**: blocked until §5's legal review clears AND §6's
  Student/Guardian actor-model dependency resolves elsewhere in the
  platform.
- **Teacher ownership**: blocked on the platform's first
  ownership-based authorization model, whenever a future checkpoint
  (Lesson Planning, a teacher-scoped LMS view, or a dedicated
  ownership-model checkpoint) builds it.
- **SyllabusUnit/CurriculumDelivery references from LMS**: purely
  additive, nullable, not yet needed.
- **External LMS integration/standards**: unscoped; requires its own
  future roadmap/ADR/product decision before any work begins.
