# LMS (Phase 0I)

**Status: Phase 0I.1 architecture contract frozen; Phase 0I.2 (Learning
Content Foundation) and Phase 0I.3 (Assignments) implemented. Phase 0I
is complete as an active LMS scope of Learning Content + Assignment
only.** Student Submission was reviewed (Phase 0I.4,
2026-09-05) and never legally cleared, and was then **cancelled as a
product-scope decision on 2026-09-05** — see §0 below and
`docs/architecture/adr/0039-lms-domain-contract.md`'s Submission
cancellation addendum. External LMS integration remains unscoped. This
document is the living operational reference for the LMS bounded
context; the decision record is ADR 0039
(`docs/architecture/adr/0039-lms-domain-contract.md`) — read that first
for the *why*, this document for the *what*, mirroring how
`ACADEMIC-STRUCTURE.md`/`HR.md` relate to their own ADRs.

## 0. Submission — CANCELLED / OUT OF SCOPE (2026-09-05)

**LMS student Submission functionality is cancelled and intentionally
out of scope for the School System ERP**, per a product-owner decision
dated 2026-09-05. This includes, without limitation: student
homework/coursework submission; student-authored Submission records;
Submission text responses; Submission file uploads; Submission
revisions/resubmissions; Teacher review of submitted coursework;
Guardian-on-behalf Submission; staff-on-behalf Submission; Submission
grading/scoring; Submission-related Documents ownership; and every
Submission-related event/API/UI named anywhere below in this document.

**This is a product-scope cancellation, not legal clearance.** No
repository document, at any point, ever recorded qualified legal
clearance for Submission — Phase 0I.4's escalation
(`docs/security/LMS-SUBMISSION-LEGAL-REVIEW.md`,
`docs/security/LMS-SUBMISSION-LEGAL-REVIEW-REQUEST.md`) received no
qualifying response before the product owner cancelled the underlying
capability. The historical legal/data-governance concerns those
documents raised are **not** resolved by this cancellation and must
not be assumed resolved if this scope is ever reopened.

**Every section below that still describes Submission (§§2–6, 8–11, 14)
is retained as historical architecture reasoning, not as a live,
buildable plan.** Where a section's original wording reads as
forward-looking ("blocked until...", "future checkpoint...", "approved
future extension"), treat it as superseded by this §0 — it is preserved
for provenance and for whatever future checkpoint might reopen this
scope from first principles, not as an active roadmap item. The active
LMS scope is **Learning Content and Assignment only**.

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
| Active scope | Learning Content (0I.2, implemented) + Assignment (0I.3, implemented) |
| Domain directory | `App\Domain\LMS` |
| Capability namespace (seeded) | `lms.content.*` / `lms.assignments.*` |

**In scope, and complete:** Learning Content, Assignment,
coursework/resource files (via Documents, decision 8).

**Cancelled, 2026-09-05 (see §0) — not in scope:** Submission (student
homework/coursework submission in any form), and the `lms.submissions.*`
capability family.

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
| **Submission** *(CANCELLED, §0)* | One Student's response to one Assignment — coursework file(s)/text, a lifecycle, and optional non-authoritative teacher feedback. Historical term only; not a buildable entity. | — |
| **Coursework File** | Not a distinct domain entity — a Documents-module file whose owner arm is a LearningContent/Assignment/Submission row (§8). | — |

## 3. Entity relationships

```
SubjectOffering (Academic Structure)
  └─ LearningContent   (LMS; School-authored resource) -- active
  └─ Assignment        (LMS; School-authored unit of work) -- active
       └─ Submission   (LMS; one per Student per Assignment) -- CANCELLED, §0
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
  (genuinely Section-scoped cohort-activity entities). See ADR 0039 §2
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
- **Employee (teacher)** — since TCH.5B, an optional immutable
  `owner_employee_id` (composite FK to `employees`) on each resource. It is
  set only on a teacher-owned row and is never an author/audit field: the
  audit actor is still the User. Authorization is still capability-only
  (§7); see §15 for the two persistence states.

## 4. Assignment/Submission versus Examinations boundary

| Concept | Owner |
|---|---|
| Curriculum delivery, lesson planning, syllabus tracking | **Academics** |
| Exam scheduling, grading, marks, grade scales, result publication, report cards, transcripts | **Examinations** |
| Learning content, assignments, submissions, coursework files | **LMS** |

**LMS owns:** Learning Content, Assignment title, instructions, resource
attachments, Assignment lifecycle, due date. Submission lifecycle,
teacher feedback, and a returned/revision-requested state were
originally contemplated here but are **CANCELLED (§0)**.

**LMS must never own as an authoritative academic record:**
Examination marks, official grades, report-card values, transcript
values, result-publication state.

**No numeric score or rubric field is part of this contract.**
Deferred entirely — see ADR 0039 §3 for the full reasoning
(Examinations' own `StudentMark` is itself still "PROVISIONAL, GATED";
this codebase has no structural mechanism yet to guarantee an
LMS-side score can never be mistaken for an authoritative mark). Only
qualitative (free-text) teacher feedback is in scope, and that text is
itself subject to §5's classification/legal gate.

## 5. Data classification — historical (Submission cancelled, see §0)

See `docs/security/DATA-CLASSIFICATION.md` for the authoritative,
current rows.

| Entity | Tier | Status |
|---|---|---|
| Learning Content | Confidential | active, implemented |
| Assignment (definition/lifecycle) | Confidential | active, implemented |
| Submission (content, feedback, history) | Sensitive (was) | **CANCELLED / OUT OF SCOPE (2026-09-05) — never legally cleared** |

**Historical record, retained for provenance:** Submission was
originally classified Sensitive with a `[LEGAL REVIEW REQUIRED]` gate,
on the grounds that it would have been the first checkpoint in this
roadmap to durably store unbounded, Student-authored free-text and/or
file content — every prior Sensitive-tier Student module
(`AttendanceRecord`, `SyllabusUnit`, `CurriculumDelivery`, `Examination`,
`ExaminationPaper`) deliberately *excludes* free text for exactly this
reason. That gate was distinct from Examinations' `StudentMark` flag
(grade-authority concern) and was never waived merely because Documents
storage already exists. Full historical reasoning: ADR 0039 §4.

**Phase 0I.4 (2026-09-05)** independently re-verified this gate after
Learning Content and Assignment shipped, performed a detailed
per-category data-inventory/classification exercise, and formally
escalated a counsel-facing legal-review question set — see
`docs/security/LMS-SUBMISSION-LEGAL-REVIEW.md` and
`docs/security/LMS-SUBMISSION-LEGAL-REVIEW-REQUEST.md`. **No qualifying
response was ever received.** On 2026-09-05 the product owner cancelled
the Submission capability entirely, as a product-scope decision
independent of that unresolved legal question — the gate is retired
because the feature no longer exists, **not because legal clearance was
obtained.** Learning Content and Assignment remain Confidential,
unaffected, and were never gated.

## 6. Student/Guardian actor model — historical (moot, Submission cancelled)

**This section is retained as historical record only.** It was
material to whether/how Submission could be built; since Submission is
cancelled (§0), the question it describes no longer blocks anything in
the active LMS scope (Learning Content, Assignment). Verified directly
against the current `origin/main` source at the time this section was
written (file presence, not checkpoint-report prose alone):

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
0039 §5). For Student, the blocker is structural (no actor exists at
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

## 8. Capability namespace

| Capability | Scope | Status |
|---|---|---|
| `lms.content.view` | View Learning Content | active, seeded |
| `lms.content.manage` | Author/edit Learning Content | active, seeded |
| `lms.assignments.view` | View Assignments | active, seeded |
| `lms.assignments.manage` | Author/edit/publish/close Assignments | active, seeded |
| `lms.submissions.view` | Staff view of Submissions | **CANCELLED (2026-09-05) — must never be seeded** |
| `lms.submissions.manage` | Staff management of Submissions | **CANCELLED (2026-09-05) — must never be seeded** |
| `lms.submissions.submit` | The Student/Guardian-side act of submitting | **CANCELLED (2026-09-05) — must never be seeded** |

Depth-2 dotted convention, matching `examinations.definitions.*`/
`curriculum.delivery.*`/`syllabus.*`. The `lms.submissions.*` family was
frozen (never seeded) pending Submission implementation; it is now
retired along with the feature (§0) and must not be added to
`CapabilityAndRoleSeeder` unless a future, explicit product decision
formally reopens Submission scope.

## 9. Documents ownership seam (as-built: Learning Content + Assignment only)

Per ADR 0012 and the current `documents_exactly_one_owner_check`
exclusive-arc CHECK, LMS coursework/resource files extend the **same
shared arc** — not a dedicated table (ADR 0029's separate-table
criteria do not apply to LMS files).

**As built (0I.2, 0I.3):** `learning_content_id` and `assignment_id`
were added additively to `documents`, each with a composite FK to
`(id, school_id)` on its LMS table; `documents_exactly_one_owner_check`
widened to include both, preserving exactly-one-owner-always. Both are
active, `internal`-tier only, gated by `lms.content.*`/`lms.assignments.*`.
Since TCH.5B, Documents asks LMS for that decision through
`App\Domain\LMS\Application\LmsParentResourceAuthorization`
(`authorizeRead`/`authorizeWrite`), which applies exactly those Tier 1
capabilities first; TCH.5C (Learning Content) and TCH.5D (Assignments) added
the owned (teacher) branch there, per parent kind. Documents names no LMS
capability, owner or audience.

**`submission_id` — CANCELLED, not added, must not be added.** It was
previously named as an approved-but-not-yet-implemented future owner
column, deactivated at the application layer
(`DocumentOwnerTypeNotSupportedException`) pending §5's legal-review
gate. Since Submission itself is cancelled (§0), this column must not
be added to `documents` under any future Phase 0I checkpoint unless a
fresh, explicit product decision reopens Submission scope — at which
point the legal-review question, not merely a schema seam, would need
to be reopened from first principles.

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

**Submission — CANCELLED (§0).** The lifecycle sketch below is retained
as historical record only; it was never implemented and is not an
active plan. `submitted` was the only state this contract committed to;
a `returned`/`resubmitted` revision loop was left provisional for
whichever checkpoint might have built Submission; a Student-side
`draft` state was deliberately not committed to. None of this is
buildable now — Submission is out of scope (§0) and this subsection
must not be read as a queued design.

## 11. Events and notifications contract (illustrative, not implemented)

`assignment.published.v1` · `assignment.closed.v1` — the only two
events remaining in scope. `submission.created.v1` /
`submission.resubmitted.v1` are **CANCELLED (§0)** along with
Submission itself and must not be implemented or registered.

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
`assignment_id` was added in Phase 0I.3 (§9, as-built); `submission_id`
remains unadded and, per §0, is now cancelled rather than merely gated.

**Events**: none. `assignment.published.v1`/etc. remain illustrative
future work per §11 — this checkpoint emits nothing (no consumer
exists, rule 2).

**Classification**: unchanged from §5 — Learning Content is
Confidential, not gated. No Student/Guardian/Employee/teacher identity
exists anywhere in `learning_content` (architecture-guard-tested).

## 14. Future

- **Phase 0I is complete** as Learning Content (0I.2) + Assignment
  (0I.3). No further Phase 0I checkpoint is planned or required.
- **Submission: CANCELLED / OUT OF SCOPE (2026-09-05, §0).** Not
  "blocked" or "awaiting legal review" — a terminal product-scope
  decision. Reopening it would require a fresh architecture and
  governance review from first principles, including re-raising the
  legal-review question this ADR/module never received a qualifying
  answer to; nothing in this cancellation should be read as having
  resolved that question.
- **Teacher ownership** (TCH.5A contract, ADR 0063 §34; Learning Content
  implemented by TCH.5C, §36 and §16 below; Assignment not implemented). This amends ADR 0039 §2 and §6 for teacher-authored rows only:
  - **Owner and audience.** A teacher-authored Learning Content or
    Assignment carries an immutable owner Employee and an immutable
    one-or-more Section audience (an FK-backed bridge pinned to the
    Offering's year/campus/grade context).
  - **Creation and writes.** Owner-only, with ActingEmployee held in the
    transaction. A current TeachingAssignment must cover every audience
    Section on the School-local date.
  - **Published reads.** Open to any eligible teacher who currently owns an
    audience Section. Co-teachers and successors read, never edit, and
    ownership never transfers.
  - **Existing and admin-created rows.** They keep a NULL owner, no audience
    and today's Offering-wide meaning (no backfill). Admins keep Tier 1
    School-wide authority unchanged.
  - **Attachments.** Authorized for teachers only through the parent LMS
    row, never by capability alone.
  - **Classification.** Learning Content and Assignment re-tier to
    Sensitive when the persistence lands.
  - **Sequence.** TCH.5B persistence foundation (closed) → TCH.5C Learning
    Content adoption (`lms.content.teacher`, implemented) → TCH.5D
    Assignment adoption (`lms.assignments.teacher`, implemented, §17).

  Independent of Submission's cancellation, which TCH does not reopen.
- **SyllabusUnit/CurriculumDelivery references from LMS**: purely
  additive, nullable, not yet needed.
- **External LMS integration/standards**: unscoped; requires its own
  future roadmap/ADR/product decision before any work begins.

## 15. Ownership persistence states (TCH.5B, ADR 0063 §35)

Every Learning Content and Assignment row is in exactly one state:

| State | `owner_employee_id` | Section audience | Meaning |
|---|---|---|---|
| **Offering-wide** (legacy/administrative) | NULL | none | the whole SubjectOffering, as since Phase 0I |
| **Teacher-owned** | an Employee of the School | ≥ 1 Section of the Offering's context | Section-targeted material of one teacher |

- **Database-enforced:**
  - an owner needs an audience (checked at commit);
  - an audience needs an owner;
  - both are written by one transaction and immutable afterwards;
  - audience Sections are pinned to the Offering's year, campus and grade
    by composite FKs;
  - `learning_content_section_audiences`/`assignment_section_audiences`
    have forced RLS.
- **Existing rows.** Every existing row, and every row the administrative
  surfaces create, is Offering-wide. Nothing was backfilled.
- **Reachability.** Teachers create teacher-owned Learning Content since
  TCH.5C (§16) and teacher-owned Assignments since TCH.5D (§17). No
  transport ever accepts an owner: it is always the ActingEmployee.
- **Classification.** Both resources are Sensitive since TCH.5B.

## 16. Owned teacher Learning Content (TCH.5C, ADR 0063 §36)

**Learning Content teacher adoption is implemented** (Assignments: §17).
**Submission remains cancelled.**

| | Tier 1 (unchanged) | Tier 2 (teacher) |
|---|---|---|
| Capability | `lms.content.view` / `.manage` | `lms.content.teacher` (Teacher role; `school_admin` for grantability only) |
| Identity | none needed | ActingEmployee, today |
| Create | Offering-wide rows | Section-targeted rows owned by the ActingEmployee, for Sections they teach today (all of them) |
| Write | every row | own rows, while teaching every audience Section |
| Read | every row | own rows (while teaching every Section); published rows for any taught Section; published Offering-wide rows of a taught Offering |

- **Date.** The School-local current date anchors every teacher decision;
  never `created_at` or the Timetable.
- **Hand-over and co-teaching.** Ownership never transfers.
  - Successors and co-teachers read published rows for their Sections and
    never write them.
  - A multi-Section row becomes read-only for its owner once one audience
    Section is no longer taught.
  - Administrators keep full Tier 1 control.
- **API** `/api/v1/schools/{school}/my/learning-content-contexts`,
  `/my/learning-content` (GET, POST), `/my/learning-content/{id}` (GET,
  PATCH) and `…/publish`, `…/archive`.
  - All carry `capability:lms.content.teacher` + `private-no-store`.
  - Non-disclosing 404s.
  - The owner id is never serialized.
- **Page** `/app/my-learning-content`, linked by the capability.
- **Attachments.** Teachers use the ordinary Documents routes, decided per
  parent row by `LmsParentResourceAuthorization`.
  - Reading a row lets them read its files.
  - Only an owner who teaches every audience Section may upload or archive.
  - Assignment attachments stay Tier 1.
- **Classification.** Sensitive, unchanged since TCH.5B. No new legal gate.

## 17. Owned teacher Assignments (TCH.5D, ADR 0063 §37)

**Learning Content teacher adoption is implemented. Assignment teacher
adoption is implemented. LMS Submission remains cancelled and outside
TCH.**

Assignments follow §16's rule exactly, under `lms.assignments.teacher`;
Tier 1 `lms.assignments.view/.manage` is unchanged.

- **Lifecycle unchanged.** `draft → published → closed → published`.
  Re-publication is the same publish action, and it needs a due date.
- **Shared status: `published` only.** `closed` is the retiring state
  (ADR 0039 §9), so a teacher's closed or draft Assignment is visible only
  to its owner, while they teach every audience Section. Admin draft and
  closed Assignments are never teacher-visible.
- **`due_on` is informational.** It never decides authority: the
  School-local current date does.
- **Electives.** TeachingAssignments cover required Offerings only, so
  elective Assignments have no teacher path and stay Tier 1.
- **API** `/api/v1/schools/{school}/my/assignment-contexts`,
  `/my/assignments` (GET, POST), `/my/assignments/{id}` (GET, PATCH) and
  `…/publish`, `…/close`.
  - All carry `capability:lms.assignments.teacher` + `private-no-store`.
  - Non-disclosing 404s, decided before any field validation.
- **Page** `/app/my-assignments` ("My Assignments"), linked by the
  capability.
- **Attachments** follow the parent Assignment through
  `LmsParentResourceAuthorization`. Each parent kind needs its own owned
  capability.
- **Excluded.** No Student, Submission, mark, grade or feedback anywhere.
