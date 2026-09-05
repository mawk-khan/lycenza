# ADR 0037: LMS Domain Contract (Phase 0I.1)

- Status: Accepted
- Date: 2026-09-04 (Phase 0I.1)

## Context

`docs/roadmap/MASTER-ROADMAP.md`'s "Phase 0I — LMS" entry has been an
empty stub since it was first listed. `docs/architecture/DOMAIN-MAP.md`
already carries a placeholder row (`LMS | Learning content, assignments,
submissions | Academic Structure, Students/SIS, HR |`), and both
`docs/modules/ACADEMICS.md` §2 and `docs/modules/EXAMINATIONS.md` §2
already state the cross-module boundary line — *"Learning content,
**assignments**, **submissions**, coursework files | **LMS** (Phase
0I)"* — and the explicit invariant *"An LMS assignment or a
`CurriculumDelivery` coverage record must never implicitly become a
grade — a grade may only ever originate from an explicit Examinations
mark."* No LMS schema, model, controller, service, route, or capability
exists anywhere in the codebase yet.

This ADR is Phase 0I.1: a documentation/architecture-only checkpoint
that freezes the LMS bounded-context contract before any schema is
written, exactly as ADR 0028 did for HR and ADR 0032 did for
Examinations' foundation fact. It makes no code change. Ten questions
had to be resolved: architectural scope; terminology/entity naming;
the Assignment/Examinations boundary; Submission data classification
and legal-review gating; the Student/Guardian actor model; the teacher
authorization model; the capability namespace; the Documents ownership
seam; the lifecycle contract; and the events/notifications contract.

## Decision

### 1. Phase 0I is an internal LMS bounded context — `App\Domain\LMS`

The approved Phase 0I implementation target is an **internal LMS
bounded context inside the modular monolith**, namespaced
`App\Domain\LMS`, matching every other Layer 3 module's
`app/Domain/<Module>` convention (ADR 0001).

- LMS depends outward on **Academic Structure** (SubjectOffering
  context) and **Students/SIS** (`SubjectOfferingRosterReadService`)
  only, exactly as `DOMAIN-MAP.md`'s existing row states — plus, as
  approved future seams (not code dependencies introduced by this
  ADR), **Documents** (Layer 4, coursework/resource files — decision 8)
  and **Communications** (Layer 4, notification audience resolution —
  decision 10), the same "Layer 3 depends on Layer 4 cross-cutting
  infrastructure" direction already established by Documents' and
  Communications' own `DOMAIN-MAP.md` rows ("Depended on by any Layer
  2-3 module that attaches files" / "Depended on by most Layer 3
  modules for notifications").
- LMS never reads `Subject`, `SubjectOffering`, `Section`, `Student`,
  `Employee`, `SyllabusUnit`, or `CurriculumDelivery` as raw Eloquent
  models or tables — only through those modules' Application-layer
  services or by composite-FK reference, per `DOMAIN-MAP.md` rule 3.
- LMS does not own examination grading, marks, grade scales, result
  publication, report cards, or transcripts — that boundary is already
  written down in `ACADEMICS.md` §2 / `EXAMINATIONS.md` §2 and this ADR
  changes nothing about it.
- LMS introduces no vendor-specific LMS abstraction. External LMS
  synchronization (Canvas, Moodle, Google Classroom, Microsoft Teams)
  and standards integration (OneRoster, LTI, QTI, SCORM, xAPI, Common
  Cartridge, Caliper) are **not authorized by this checkpoint** — no
  repository document assigns them to Phase 0I, and CLAUDE.md rule 2
  forbids speculative integration surface for a need that does not yet
  exist. They remain unscoped until a future, explicit roadmap/ADR/
  product decision assigns them.

No repository evidence contradicts any of the above — this decision is
a direct confirmation of what `DOMAIN-MAP.md`/`ACADEMICS.md`/
`EXAMINATIONS.md` already state, not a new design.

### 2. Terminology and entity scoping

| Term | Meaning |
|---|---|
| **Learning Content** | A School-authored instructional resource (a reading, a link, a note, an attached file) belonging to one `SubjectOffering` — the LMS counterpart to `SyllabusUnit`'s catalogue-of-expected-content, but for actual distributable material rather than a topic outline. |
| **Assignment** | A staff-authored unit of work — title, instructions, optional resource attachments, a due date, and a lifecycle — that a `SubjectOffering`'s roster is expected to complete. Never a grade-bearing record itself. |
| **Submission** | One Student's response to one Assignment — coursework file(s) and/or text, a lifecycle, and optional non-authoritative teacher feedback. |
| **Coursework File** | A file attached to a Submission (student-authored work product) or to an Assignment/Learning Content (staff-authored resource) — not a distinct domain entity; it is a Documents-module file whose owner arm is the Assignment/Submission/LearningContent row (decision 8). |

**No generic `Course` entity is introduced.** `docs/modules/
ACADEMIC-STRUCTURE.md`'s naming discipline already rejected `Course` as
a synonym for `Subject`; introducing one in LMS would create exactly
the ambiguity that discipline exists to prevent. LMS entities reference
`SubjectOffering` directly.

**Assignment is scoped by SubjectOffering (option 1), not
SubjectOffering + Section.** This mirrors the established pattern for
every other *definitional/instructional* Layer 3 entity in this
codebase — `SyllabusUnit` (Offering-wide) and `ExaminationPaper`
(Offering-wide, explicitly "NOT Section-specific") — as distinct from
*cohort-activity* entities that are genuinely Section-scoped
(`CurriculumDelivery`, `AttendanceRecord`). It is also the only choice
`App\Domain\Students\Application\SubjectOfferingRosterReadService`
actually supports: its `currentRosterStudentIds(SubjectOffering
$offering)`/`currentRosterCount()` pair is explicitly **Section-agnostic
by design** ("a Student in any compatible Section of the matching
Grade/Campus/Year is eligible" — `docs/students/
PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md`), branches
internally on required-vs-elective so LMS never needs to know which
kind of Offering it targets, and is explicitly named as the seam a
"future Assessment/Timetable module" (and, by the same reasoning, LMS)
should consume rather than re-deriving roster membership — exactly the
precedent `App\Domain\Communications\Application\Audience\
SubjectOfferingAudienceResolver` already set (`docs/communication-hub/
PHASE-5C-1-SUBJECT-OFFERING-AUDIENCES.md` §4: "Communications never
re-derives roster membership").

**Relation to other entities:**
- **AcademicYear/Campus/GradeLevel** — inherited as integrity pins
  through Assignment's `SubjectOffering` composite FK, exactly like
  `ExaminationPaper`'s `academic_year_id`/`campus_id`/`grade_level_id`
  pins. Never denormalized as independent client-supplied fields.
- **SubjectOffering** — Assignment's and Learning Content's sole
  required parent.
- **Section** — never a direct parent of Assignment or Learning
  Content (matching SyllabusUnit/Examination precedent); a specific
  Student's Section membership, if ever needed for display, is
  resolved by consulting `StudentEnrollment`/`SubjectOfferingRosterReadService`,
  never stored redundantly on the Assignment row.
- **SyllabusUnit** / **CurriculumDelivery** — `ACADEMICS.md` §19
  already reserves this exact seam ("Examinations and LMS may later
  reference `syllabus_unit_id` or `curriculum_delivery_id`. Nothing
  here prevents that... Academics itself must never reference them.").
  This ADR confirms LMS MAY add a nullable, purely additive reference
  to either in a future checkpoint; it is not required by 0I.1 and no
  such column is introduced here.

### 3. Assignment/Submission versus Examinations boundary

**LMS may own:** Assignment title, instructions, resource attachments,
Assignment lifecycle, due date, Submission lifecycle, teacher feedback,
a returned/revision-requested state (decision 9), and non-authoritative
feedback metadata.

**LMS must never own as an authoritative academic record:** Examination
marks, official grades, report-card values, transcript values, or
result-publication state. The existing invariant stands unchanged: *"An
LMS assignment or submission must never implicitly become an
Examinations grade/mark."*

**Numeric scoring is deferred entirely from the initial LMS
implementation.** The brief permits an informal score IF it can be
guaranteed non-authoritative. This codebase's own track record shows
that guarantee is only made structurally credible with dedicated
architecture-guard tests pinning a closed column set (the pattern
`CurriculumDeliveryArchitectureGuardTest`/`ExaminationArchitectureGuardTest`
already establish) and a companion consuming entity on the Examinations
side to guarantee non-propagation — neither exists yet, and
Examinations' own `StudentMark` remains itself
"PROVISIONAL, GATED" (`EXAMINATIONS.md` §20) specifically because *"a
mark must carry no free-text remark column, or it risks drifting
toward the Health tier's own legal gate."* Building a parallel
LMS-side numeric field now, before Examinations' own marks checkpoint
exists to define what "non-authoritative" concretely excludes, cannot
be cleanly guaranteed. **No numeric score or rubric field is part of
this contract.** Only qualitative teacher feedback (free text) is
in scope, and only subject to decision 4's classification/legal gate.

### 4. Submission classification: Sensitive, and a dedicated
   `[LEGAL REVIEW REQUIRED]` gate — **SUBMISSIONS REQUIRE LEGAL REVIEW**

A Submission (Student-authored text and/or files, submission/revision
timestamps, the Assignment/Student relationship, and non-authoritative
teacher feedback) is **Sensitive** personal data of an identifiable
Student under `docs/security/DATA-CLASSIFICATION.md`'s tier
definitions — the same baseline tier as `AttendanceRecord` (ordinary
personal data of an identifiable minor, structurally excluding health/
government-ID/biometric/financial content).

It does **not** automatically inherit Examinations' own `StudentMark`
`[LEGAL REVIEW REQUIRED]` flag — that gate concerns grade/assessment
authority (a different concern from Submission content) — but it
**independently triggers its own instance of the children's-data
`[LEGAL REVIEW REQUIRED]` gate** (`DATA-CLASSIFICATION.md`'s "Children's
data specifically" row) on distinct grounds: **Submission is the first
checkpoint in this roadmap to durably store unbounded, Student-authored
free-text and/or file content**, rather than structured fields about a
Student. Every other Sensitive-tier Student/academic module built so
far — `AttendanceRecord`, `SyllabusUnit`, `CurriculumDelivery`,
`Examination`, `ExaminationPaper` — deliberately **excludes** free text
for exactly this reason (`ACADEMICS.md` §5: *"an unbounded prose column
is an audit-leakage and classification hazard — it could carry
Student-specific commentary"*; `EXAMINATIONS.md` §20 makes the same
point about a future mark's remark column). A Submission's entire
purpose is open-ended Student-authored content and an attached file
whose actual content is unknowable in advance — the exact case
`DATA-CLASSIFICATION.md`'s own "Documents/files" row already flags
("classification inherited from what the document contains... a birth
certificate scan is Highly Sensitive"). Nothing structurally prevents a
Student's coursework submission or a teacher's free-text feedback from
containing exactly the categories (health disclosure, safeguarding
concern, family/home detail) that would otherwise require the Health
tier's own separate gate — a risk this codebase has, until now,
engineered around at the schema level rather than accepted.

Storing such content is also **not unrestricted merely because
Documents storage already exists**: ADR 0012 fixes how a file is
stored and access-controlled once a module decides to collect it — it
does not resolve the antecedent question of whether the product may
lawfully collect free-form personal content from minors at all, under
what consent/retention/visibility terms, which is exactly what
`DATA-CLASSIFICATION.md`'s DPDP Act flag requires qualified legal
review to answer.

**What is blocked:** any implementation of Submission that collects,
stores, or exposes actual Student-authored text/files, or per-Student
teacher feedback, is blocked pending legal review. Because a
Submission's structural shell has no meaning independent of that
content, the entire Submission entity — not merely its free-text
columns — is gated.

**What can safely proceed:** Learning Content and Assignment
(definition, lifecycle, resource attachments) are staff-authored,
carry no Student identity, and classify **Confidential** — the same
tier and same reasoning as `SyllabusUnit`, `CurriculumDelivery`,
`Examination`, and `ExaminationPaper` (admin/academic operational data,
gated by a specific capability, not personal data). These are not
blocked by this gate and may proceed to schema design in a future
checkpoint (recommended: Phase 0I.2 — Learning Content Foundation, then
an Assignment definition checkpoint) without legal review.

This gate is not weakened to keep implementation moving: Submission
work does not proceed until either qualified legal review clears it or
an explicit, user-approved scope-narrowing decision is made — the same
discipline already applied to Payroll's statutory checkpoint (9.6) and
Health.

### 5. Student/Guardian actor model: unresolved — a genuine dependency,
   not an LMS-internal decision

Direct verification against the current `origin/main` source (not
inherited Phase 5 assumptions, and not the self-reported status inside
any individual checkpoint report — those can predate a later
publication) found:

- **No authenticated Student account exists.** `students` has no
  `user_id` column; no `student` role exists in
  `CapabilityAndRoleSeeder`; `docs/security/AUTHORIZATION.md`'s actor
  table is explicitly self-labeled a **design reference** ("None of
  these are implemented yet... this is the design reference for
  whichever module builds it"). `docs/communication-hub/
  PHASE-5D-3-FINAL-INTEGRATION.md` §10 confirms this remains true at
  the most recent checkpoint to touch it: *"No synthetic Student
  account role or provisioning path exists... Inviting a brand-new
  account (as is available for Guardians) is not available for Students
  yet — only linking an account that already exists is supported. No
  fake 'Invite Student' control."*
- **A real, working Guardian self-service login DOES exist on
  `origin/main` today** — this is the one point where the codebase has
  moved past the "design reference only" state. Confirmed directly by
  file presence in this checkpoint's own worktree, not by document
  prose alone: `App\Domain\Identity\Application\{AccountInvitationService,
  GuardianAccountActivationService}`, `App\Http\Controllers\App\
  GuardianAccountInvitationController`, and the
  `identity_account_invitations` migration are all present on
  `origin/main` at this checkpoint's baseline SHA. (`docs/
  communication-hub/PHASE-5D-3-FINAL-INTEGRATION.md`'s own text says
  "local integration only, `origin/main` was not touched" — that was
  true when that report was written; the commit `8cf2ed0` on `main`'s
  history shows it was published after, and this checkpoint verified
  the code exists in the actual checkout, not the report's own
  self-description, which is why this ADR does not simply cite that
  report's conclusion.) The flow: an admin issues a one-time,
  hashed-token email invitation to a Guardian's own verified
  `GuardianContact` email (`AccountInvitationService`); accepting it
  (`GuardianAccountActivationService::accept()`) resolves-or-creates a
  `User` (proving mailbox control, never a bare admin-set password) and
  a `SchoolMembership`, then links-or-reuses the `AccountLink` — one
  atomic transaction, no capability/role grant, and (per Phase 5B.2's
  unmodified `AccountLinkService`) a Guardian-only membership still
  gets zero `school_admin`/`principal`-shaped role. This is a real
  login path, not future work — LMS's own decision below must be based
  on this fact, not on Guardian being unreachable.
- **No delegated "on behalf of" action model exists anywhere**, for
  either actor. A linked Guardian's `AccountLink` is explicit that it
  "never, by itself, authorizes" acting for the linked Student — every
  authenticated actor (staff or linked Guardian) acts only as itself.
  Nothing in the codebase today lets one authenticated principal
  perform an action explicitly attributed to a different domain
  identity.
- **No Student/Guardian self-service *portal* exists** — the
  Guardian login above reaches Communications' existing IN_APP
  conversation/announcement surfaces only (via the ordinary
  `SchoolMembership`-authenticated `/app/*` routes), not a dedicated
  parent-portal product surface. Repeatedly and explicitly deferred as
  its own concern across every Phase 5D checkpoint's "Deferred"
  section.
- **No action anywhere in this codebase authorizes a Guardian/Student
  through the ordinary staff capability system.** No `student`/
  `guardian` role exists in `CapabilityAndRoleSeeder`, and a linked
  Guardian's `SchoolMembership` is never assigned one. Guardian
  eligibility for an existing feature (Communications conversation
  participation, IN_APP reachability) is resolved through a dedicated
  chain instead — `App\Domain\Communications\Application\
  ConversationParticipantAuthorizationService`'s own documented order:
  *staff-side capability (for the staff actor initiating contact) →
  School safeguarding policy → target existence → an active
  `AccountLink` whose linked `SchoolMembership` is itself active* — the
  Guardian's own participation is never itself gated by
  `Gate::authorize('capability', ...)`. This is the pattern §7
  preserves for LMS rather than inventing a new one.

**Decision: unresolved/deferred for Student; a real dependency exists
for Guardian, but the product-policy question is still not decided
here.** Per this checkpoint's own instruction, LMS does not invent a
parallel authentication or delegation model regardless. For Student,
the blocker is structural — no authenticated actor exists to reach a
submit action at all. For Guardian, an authenticated actor now
genuinely exists and could technically reach a future submit action
through the same AccountLink chain Communications already uses — but
whether a School should let a Guardian submit schoolwork on a Student's
behalf at all is a product-policy question this ADR has no existing
precedent to resolve one way or the other (no other module lets one
domain identity act "for" another today). Who may submit a Submission —
student only, guardian on behalf of student, both, or some other
shape — remains **unresolved**, and is independently moot for now
because §4's legal-review gate blocks any real Submission
implementation regardless of which actor ends up authorized. **This is
flagged as an explicit dependency** for whichever future checkpoint
builds real Submission handling to resolve (a product decision plus,
for Student specifically, a portal-identity checkpoint outside LMS's
own scope) — not a Phase 0I.1 blocker in itself, and not something
Phase 0I.2's staff-authored Learning Content/Assignment work needs
resolved first.

### 6. Teacher authorization: Option A — capability-only for v1

No canonical teacher-to-Section/SubjectOffering ownership record exists
in this codebase today. `TimetableEntry.teacher_id` is a real FK to
`employees`, but it is a mutable weekly-scheduling fact, never
described anywhere as authoritative for ownership/authorization — the
gap is instead named explicitly and repeatedly (`ACADEMICS.md`: *"the
platform's first ownership-based authorization model... does not
exist: there is no teacher role, no teacher self-service, and
`employees.user_id` is nullable"*; `ACADEMIC-STRUCTURE.md`: a future
teacher-to-Section assignment is "an explicit gap... nothing here fakes
or stubs that relationship").

**Chosen: Option A — capability-only LMS teacher/admin management for
v1**, following the exact precedent every prior Layer 3 academic module
already set (`SyllabusUnit`, `CurriculumDelivery`, `Examination`,
`ExaminationPaper`: "admin-only v1 — no teacher ownership, no teacher
capability... no role-name checks"). `lms.content.manage`/
`lms.assignments.manage` are granted to School Admin/Principal (and,
if a School chooses, Academic Coordinator) roles only — **not** to the
Teacher role — because granting Teacher a School-wide `.manage`
capability today, with no ownership model to scope it, would let any
teacher manage any other teacher's Assignments, the exact failure mode
capability-based authorization (CLAUDE.md rule 24) exists to prevent.

This is documented as an **intentional Phase 0I foundation
constraint**, not a permanent substitute for teacher ownership — the
same forward note `CurriculumDelivery`/`Lesson Planning` already carry.
A future teacher-scoped LMS checkpoint is blocked on the same
ownership-based authorization model every other module already defers
to, not on anything specific to LMS.

### 7. Capability namespace (frozen, not registered)

| Capability | Scope |
|---|---|
| `lms.content.view` / `lms.content.manage` | Learning Content |
| `lms.assignments.view` / `lms.assignments.manage` | Assignment |
| `lms.submissions.view` / `lms.submissions.manage` | Staff oversight of Submissions |
| `lms.submissions.submit` | The Student/Guardian-side act of submitting |

This follows the established depth-2 dotted convention
(`examinations.definitions.*`, `curriculum.delivery.*`, `syllabus.*`)
so a later `lms.marks.*`-shaped family (should Examinations integration
ever need one) cannot collide with these. **Not seeded in
`CapabilityAndRoleSeeder` by this checkpoint** — capability catalog
registration happens at implementation time, per every prior module's
own precedent; freezing the namespace shape now is what this
architecture checkpoint is for.

`lms.submissions.submit` is deliberately **not** a normal staff
capability grant — decision 5 already established that no Student/
Guardian capability model exists at all today (the Actor Categories
table itself is unimplemented design reference). This capability's
authorization mechanism is therefore itself blocked on decision 5's
dependency, exactly like Submission's data/actions generally; it is
named here only to freeze its spelling, not to claim it is
implementable now.

### 8. Documents ownership seam

Per ADR 0012 and the current exclusive-owner-arc implementation
(`documents_exactly_one_owner_check`, a `CASE`-sum CHECK constraint
requiring exactly one of `employee_id`/`student_id`/`guardian_id`
non-null; `student_id`/`guardian_id` exist structurally but are
deliberately deactivated today via `DocumentOwnerTypeNotSupportedException`),
LMS coursework files extend the **same shared exclusive arc** — they do
not get a dedicated separate table.

**Rationale against a separate table (ADR 0029's own criterion):** HR's
`employee_documents` stays permanently separate because it never held
real file bytes, carries real load-bearing HR-specific structured
fields with no generic equivalent (`category`/`issued_on`/`expires_on`),
and uses a narrower classification vocabulary. None of that applies to
LMS coursework files/resource attachments — they are ordinary stored
files whose metadata (uploader, timestamp, classification tag) is
exactly what the generic `documents` schema already models, with no
domain-specific structured field the shared table lacks.

**Approved future extension (not implemented by this ADR):**
- Three new nullable owner columns — `learning_content_id`,
  `assignment_id`, `submission_id` — each with a composite FK to
  `(id, school_id)` on its respective LMS table, added by an additive
  migration exactly like every prior owner-arm addition.
- `documents_exactly_one_owner_check`'s arithmetic sum widens to
  include all three new terms, preserving "exactly one owner, always,"
  never multiple simultaneous owners.
- **The `submission_id` arm ships deactivated at the application layer
  regardless of migration timing** — the same `DocumentOwnerTypeNotSupportedException`
  pattern the `student_id`/`guardian_id` arms already use — until
  decision 4's legal-review gate clears. `learning_content_id`/
  `assignment_id` may activate independently once their own
  checkpoints are implemented, since neither is gated.
- Delete/archive semantics inherit unchanged: Documents are never hard
  deleted, only archived (`status` transition), with cascade-on-delete
  firing only if the owning LMS row itself is deleted — which, per
  decision 9, never happens once referenced (status-based retirement
  only, matching CLAUDE.md rule 73).
- Tenant isolation inherits unchanged: the same composite-FK +
  `TenantRls` pattern, no new mechanism.

No polymorphic redesign of Documents is introduced — this is the
identical three-part "new column + composite FK + widen the CHECK"
pattern every owner arm to date has used.

### 9. Lifecycle contract

**Assignment**: `draft → published → closed`, three states, no
separate `archived` state (matching rule 2 — a fourth state has no
demonstrated need yet; `closed` already serves the retiring purpose).
Published Assignments MAY be edited via ordinary PATCH, including the
due date, matching every reference-entity precedent in this codebase
(`Examination`/`ExaminationPaper`/`SyllabusUnit` all permit correction
post-publication with no "frozen after publish" rule) — no product
requirement yet justifies a stricter machine. Closed Assignments do not
accept new Submissions; reopening (`closed → published`) is an ordinary
status transition, not a one-way door, since no invariant yet demands
otherwise. **Due date is informational/display-only in v1, not a
structural enforcement boundary** — whether a late Submission is
accepted is governed by whether the Assignment is still `published`
(open) or already `closed`, not by a due-date-vs-now comparison; this
avoids building unneeded timezone/clock-comparison logic speculatively
(rule 2) while keeping the field meaningful for notification/display
purposes. No DELETE route — status-based retirement only (rule 73).

**Submission**: `submitted` is the only state this contract commits
to. A `returned`/`resubmitted` revision loop is **product-likely but
PROVISIONAL** — the brief itself frames teacher feedback and a
returned state as "if approved" — and is left for whichever future
checkpoint actually builds Submission (necessarily after decision 4's
legal-review gate clears and decision 5's actor-model dependency is
resolved) to confirm against real product requirement, rather than
over-specifying a state machine with no confirmed need now (rule 2). A
`draft` (Student-side, unsubmitted) state is deliberately **not**
committed to at this checkpoint either, for the same reason — it
presumes an interactive Student session that decision 5 shows does not
exist yet. Multiple independent grading attempts are **not** supported;
if resubmission is later approved, it replaces which Submission is
"current" via an append-only revision history (matching rule 11's
append-only-correction convention), never an in-place overwrite. No
DELETE route once referenced — status-based retirement only.

### 10. Events and notifications contract

Illustrative future event names, to be implemented (each via
`ShouldBeOutboxed`/`OutboxedEventDefaults`, ADR 0025) only once their
owning checkpoint actually ships:

`assignment.published.v1` · `assignment.closed.v1` ·
`submission.created.v1` · `submission.resubmitted.v1`

None are registered in `App\Support\Webhooks\WebhookEventRegistry` by
this ADR — every domain event shipped so far in this codebase
(`SchoolProfileUpdated`, `AcademicYearCreated`, `SubjectOfferingCreated`,
...) stays internal-only until an explicit, separately reviewed
decision registers it (CLAUDE.md rules 45/77); external webhook
eligibility for LMS events is not decided here.

**For assignment-notification recipient resolution, LMS should consume
the same seam Communications' own `SubjectOfferingAudienceResolver`
already established** — resolve the target roster via
`SubjectOfferingRosterReadService::currentRosterStudentIds()` (the same
call LMS already makes for roster/eligibility purposes per decision 2)
rather than LMS re-deriving Guardian/Student reachability itself.
No current precedent in this codebase has a Layer 3 module's domain
event trigger an automatic Communications notification (every Layer 3
event shipped so far — `AcademicYearCreated`, `ExaminationPaper`'s zero
events, etc. — has no consumer yet); if a future checkpoint wants
`assignment.published.v1` to trigger a notification automatically, that
requires its own explicit consumer design at that time, not an
assumption baked in by this contract.

## Rationale

- Freezing scope, terminology, classification, and lifecycle before any
  migration is written is exactly the discipline ADR 0028/0032 already
  established for HR/Examinations — a checkpoint that gets these wrong
  is expensive to unwind once schema and API surface exist, per the
  root `CLAUDE.md`'s closing guidance.
- Gating Submission on legal review, rather than assuming the existing
  StudentMark gate does or doesn't apply, follows the brief's explicit
  instruction to reason independently from first principles against
  this codebase's own classification model, and follows the same
  conservative posture Health and Payroll's statutory checkpoint
  already established — this codebase does not ship free-form
  identifiable-minor content without an explicit, reasoned classification
  decision.
- Deferring the Student/Guardian actor-model question rather than
  inventing a parallel LMS-internal identity system avoids exactly the
  duplicated, inconsistent authentication logic ADR 0002/`DOMAIN-MAP.md`
  already warn against, and keeps the real architectural blocker
  (no Student/Guardian portal identity exists) visible rather than
  hidden behind an LMS-specific workaround.

## Alternatives considered

1. **Scope Assignment to SubjectOffering + Section.** Rejected:
   `SubjectOfferingRosterReadService` — the only roster seam that
   exists — is deliberately Section-agnostic; adding a Section
   dimension to Assignment would require either duplicating roster
   logic LMS has no business owning, or leaving the Section field
   structurally meaningless for elective Offerings that span sections.
2. **Treat Submission as Confidential (not Sensitive/gated), since no
   other LMS entity is gated.** Rejected: Submission is fundamentally
   different in kind from `SyllabusUnit`/`Examination`/`ExaminationPaper`
   — those are staff-authored operational data; Submission is
   Student-authored personal content, which the classification model's
   own worked examples (Attendance, Documents/files) already treat as
   requiring a materially higher bar.
3. **Assume the StudentMark `[LEGAL REVIEW REQUIRED]` gate already
   covers Submission, so no new gate needs stating.** Rejected — the
   brief explicitly forbids this assumption, and the two gates rest on
   different grounds (grade authority vs. open-ended personal content);
   conflating them would understate what actually needs review if
   Submission's specific risk (unbounded free text/files) were ever
   evaluated separately.
4. **Build a minimal Student "read-only" authentication shim inside
   LMS to unblock Submission architecture now.** Rejected outright —
   this is precisely the "invent a new portal identity model in Phase
   0I" the brief forbids, and would create the parallel,
   inconsistent-with-the-rest-of-the-platform authentication system
   ADR 0002 already rules out.
5. **A dedicated `lms_documents` table instead of extending the shared
   Documents exclusive arc.** Rejected against ADR 0029's own stated
   criteria — LMS coursework files have no domain-specific structured
   field and no narrower classification need that would justify
   HR's exception.

## Consequences

- `docs/modules/LMS.md` becomes the living operational reference for
  every future Phase 0I checkpoint's schema/API/authorization/privacy
  decisions, mirroring how `ACADEMIC-STRUCTURE.md`/`HR.md` are treated
  — this ADR is the decision record, that document is the detail.
- `docs/architecture/DOMAIN-MAP.md`'s LMS row gains a Notes entry
  pointing here; its dependency column is unchanged (already correct).
- `docs/security/DATA-CLASSIFICATION.md` gains explicit LMS rows
  (Learning Content/Assignment: Confidential; Submission: Sensitive,
  `[LEGAL REVIEW REQUIRED]`).
- `docs/roadmap/MASTER-ROADMAP.md`'s Phase 0I stub is replaced with a
  scope/status pointer to this ADR and to `docs/modules/LMS.md`,
  matching the annotation style already used for Phase 0J/HR (ADR
  0028) and Phase 0J/Payroll (ADR 0034).
- Phase 0I.2 (Learning Content Foundation) may proceed without further
  legal review, following the same Confidential/admin-authored pattern
  already proven by Syllabus Foundation. Any future checkpoint touching
  Submission content is blocked until this ADR's legal-review gate
  clears; a checkpoint implementing Submission *creation* is additionally
  blocked on §5's unresolved product-policy question (who may submit),
  and, if that question is ever resolved in Student's favor, on a real
  Student authenticated actor existing outside LMS's own scope — Guardian
  is not similarly blocked on authentication, since a real Guardian login
  path already exists, only on the product-policy question and on §4's
  gate.
- No code, migration, route, or capability-catalog entry is introduced
  by this ADR.

## Phase 0I.4 review addendum (2026-09-05)

Phase 0I.4 (`docs/security/LMS-SUBMISSION-LEGAL-REVIEW.md`) independently
re-verified §4's gate and §5's actor-model dependency against the
codebase as it stood after Phase 0I.2 (Learning Content) and Phase 0I.3
(Assignment) shipped, performed the detailed data-inventory/
classification exercise §4 above summarizes, and produced a
counsel-facing legal-review question set. **No decision in this ADR's
frozen body is altered, narrowed, or weakened by that review.** Its
findings:

- No authoritative legal clearance for Submission exists anywhere in
  this repository — §4's gate remains **BLOCKED**, evaluated against
  this repository's own evidentiary bar for what a cleared gate looks
  like (ADR 0036, Payroll 9.6's statutory clearance).
- §5's actor-model dependency remains unresolved exactly as stated
  here; nothing built since (Learning Content, Assignment) changed it.
- The review additionally identifies engineering prerequisites that are
  independent of legal clearance (e.g. malware/virus scanning for
  untrusted uploads, a teacher-ownership/scoping model before Teacher
  access to Submissions specifically, given Submission's materially
  higher sensitivity than Assignment) — see that document's §11/§20 for
  the full list.

This addendum does not clear the gate, does not narrow its scope, and
does not authorize any Submission implementation. It exists only to
record that the gate was re-examined and confirmed still correct and
still in force.
