# LMS Submission — Legal/Data-Governance Gate Review (Phase 0I.4)

> ## STATUS: CLOSED — FEATURE CANCELLED / OUT OF SCOPE (2026-09-05)
>
> This review was **never legally cleared** — the formal request it
> produced (`docs/security/LMS-SUBMISSION-LEGAL-REVIEW-REQUEST.md`)
> received no qualifying response from qualified legal counsel or
> product governance. On 2026-09-05, the product owner independently
> **cancelled the Submission capability entirely** as a product-scope
> decision, unrelated to whether that legal question was ever answered.
> Further legal/product review of Submission is no longer required
> **while that capability remains out of scope** — but this is not, and
> must never be represented as, legal clearance of the processing this
> document describes. This document, and everything below, is retained
> as a historical architecture/governance record — the 18-question
> escalation and the analysis behind it remain intact as provenance for
> whatever future checkpoint might reopen this scope from first
> principles. See `docs/architecture/adr/0039-lms-domain-contract.md`'s
> Submission cancellation addendum for the authoritative decision text.

- Checkpoint: Phase 0I.4 (2026-09-05)
- Review baseline: `feature/phase-0i3-assignments @ 229f963` — the
  newest published Phase 0I prerequisite tip (0I.1 `d6d1994` → 0I.2
  `9f4b890` → 0I.3 `229f963`); none of the three is merged to
  `origin/main` (`origin/main @ 73665b7`, unrelated Staff MFA work).
- Nature of this document: **an engineering data-governance review, not
  legal advice.** It maps the proposed Submission feature against this
  repository's own classification framework and existing precedent, and
  produces a question set for qualified counsel/product governance. It
  does not, and cannot, itself clear ADR 0039 §4's legal-review gate.
- **No implementation accompanies this checkpoint.** No migration,
  model, service, controller, route, capability, UI, or Documents owner
  arm is introduced. See §21–22 below.

## 1. Purpose

ADR 0039 §4 (`docs/architecture/adr/0039-lms-domain-contract.md`)
already classified Submission **Sensitive** and raised a
`[LEGAL REVIEW REQUIRED]` gate at the *architecture-contract* stage
(Phase 0I.1, 2026-09-04), before Learning Content or Assignment schema
existed. This checkpoint revisits that gate now that Learning Content
(0I.2) and Assignment (0I.3) are real, implemented, tested code —
re-verifying the gate is still correctly reasoned, has not been
silently weakened, and remains blocked absent an actual recorded legal
decision. It also performs the detailed data-inventory exercise ADR
0039 did at a summary level, and produces the counsel-facing question
set ADR 0039 itself does not contain.

## 2. Existing legal/privacy evidence — what the repository actually establishes

Every citation below was verified directly against the file at the
stated path/line in this checkpoint's own worktree, not recalled from
memory or another checkpoint's self-description.

| Source | What it establishes |
|---|---|
| `docs/architecture/adr/0039-lms-domain-contract.md` §4 (lines 160–227) | Submission = Sensitive; independently triggers the children's-data `[LEGAL REVIEW REQUIRED]` gate (distinct from Examinations' `StudentMark` gate); the *entire* Submission entity is blocked, not just its free-text columns; release condition is "qualified legal review... or an explicit, user-approved scope-narrowing decision." |
| `docs/architecture/adr/0039-lms-domain-contract.md` §5 (lines 229–325) | No Student authentication exists anywhere (`students.user_id` does not exist; no `student` role). A real Guardian self-service login exists (`AccountInvitationService`/`GuardianAccountActivationService`). No "act on behalf of" delegation model exists anywhere in the codebase. Who may submit is explicitly **unresolved**. |
| `docs/security/DATA-CLASSIFICATION.md` (lines 3–12) | Self-disclaimer: "written by engineering, not legal counsel, and makes no claim about compliance with any specific law or regulation" — the product "must not represent itself as compliant with... the DPDP Act... until a qualified legal review has actually happened." |
| `docs/security/DATA-CLASSIFICATION.md` line 28 | "Children's data specifically" = Highly Sensitive, `[LEGAL REVIEW REQUIRED]` — DPDP Act obligations "must be reviewed by qualified counsel before any module processing student data ships to real schools." |
| `docs/security/DATA-CLASSIFICATION.md` line 46 | "LMS Submission / Student Work Product" row: Sensitive baseline, `[LEGAL REVIEW REQUIRED]`, identical language to ADR 0039 §4. |
| `docs/security/DATA-CLASSIFICATION.md` lines 69–73 | Retention periods per category are **repo-wide** `[LEGAL REVIEW REQUIRED]`, "not yet made" for any module — not a Submission-specific gap. |
| `docs/modules/EXAMINATIONS.md` §20 (lines 663–672) | `StudentMark` is itself still "PROVISIONAL, GATED" on the same children's-data gate, on separate grounds (grade authority + free-text remark risk) — confirms two independent, still-open gates exist in this codebase today, not one. |
| `docs/architecture/adr/0036-phase-9-6-statutory-payroll-architecture.md` + `docs/modules/PAYROLL.md` (lines 10–52) | The one precedent in this repository where a `[LEGAL REVIEW REQUIRED]` gate **was actually cleared**: a named, dated, jurisdiction-specific legal artifact (`SCH/PAY/REG/2026-9.6`, Telangana, effective 1 April 2026) is quoted verbatim in the ADR, with an explicit engineering "binding correction addendum" reconciling ambiguity, implemented on a separately-numbered branch. This is the evidentiary bar a Submission clearance would need to meet — see §19. |
| `docs/communication-hub/PHASE-5D-2-GUARDIAN-STUDENT-PREFERENCES-CONSENT.md` | The only consent model implemented anywhere in this repository (Guardian email-channel preference + append-only consent-event history). Its own §29 states explicitly: recording a consent event "is **not** a claim that School OS thereby satisfies GDPR, COPPA, FERPA, or any other legal/regulatory regime." No student-coursework consent model exists. |
| `docs/architecture/adr/0039-lms-domain-contract.md` §8 (lines 386–430); `apps/platform/database/migrations/2026_10_13_090300_add_assignment_owner_to_documents_table.php` (lines 19–25) | The `submission_id` Documents owner arm is explicitly *not* added yet, "exactly as `docs/modules/LMS.md` §9 anticipated" — confirming Phase 0I.3 correctly deferred to this checkpoint rather than pre-empting it. |
| Repo-wide search | No hits anywhere for "erasure," "right to be forgotten," or "anonymiz(e/ation)." No hits for "data principal"/"data fiduciary" (DPDP's own statutory terms). No authoritative legal sign-off document, addendum, or counsel-reviewed policy exists for LMS/Submission specifically. |

**Conclusion of this section:** the gate is real, current, correctly
reasoned, and has not been quietly narrowed by any of the three
checkpoints built since it was raised. No new evidence found by this
review changes that.

## 3. Submission data inventory

### Submission identity
School, Assignment, Student, submitting actor (identity of whoever
performed the HTTP action — not necessarily the Student, see §6),
submission/revision timestamps, status.

### Student-authored content
Plain text responses, rich text, essays, comments, structured answers.
Content and length are open-ended by nature of an Assignment (ADR 0039
§4: "a Submission's entire purpose is open-ended Student-authored
content... whose actual content is unknowable in advance").

### Coursework files
Any file category the Documents module's MIME allowlist would
technically accept (`application/pdf`, `image/jpeg`, `image/png`,
`image/webp`, `.doc`/`.docx`, `.xls`/`.xlsx` — `apps/platform/config/documents.php`)
is *technically storable* today. **This inventory does not authorize
any of them for Submission** — the allowlist governs what Documents can
safely store as bytes, not what a School may lawfully collect from a
minor as coursework. Audio/video/archive formats are not in the
current allowlist at all and would need their own separate technical
+ classification review if ever proposed.

### Metadata
Filename (sanitized display copy only — storage key is a
server-generated UUID, `DocumentService.php`), MIME type (server-sniffed,
never trusted from the client), size, upload timestamp, checksum (not
currently computed by Documents — a gap, see §13), attempt/revision
number (not yet modeled — ADR 0039 §9 defers this to "if resubmission
is later approved").

### Teacher-created data associated with a Submission
Feedback (free text), a returned/revision-requested status (provisional
per ADR 0039 §9), review timestamps.

### Confirmed exclusions
Submission does not, and under ADR 0039 §3 must never, own: official
marks, official grades, Examination results, report-card values, or
transcript values. Numeric scoring of any kind remains deferred
entirely (ADR 0039 §3) — this review changes nothing about that
boundary and does not revisit it.

## 4. Data classification, per category

`docs/security/DATA-CLASSIFICATION.md` already fixes Submission's
*baseline* tier as Sensitive with a legal-review gate (line 46). This
section checks whether uniform treatment is actually correct once the
inventory above is considered, per the classification framework's own
five-tier definitions (`DATA-CLASSIFICATION.md` lines 14–22).

| Category | Recommended tier (pending legal clearance) | Reasoning |
|---|---|---|
| Submission row (identity/timestamps/status) | Sensitive | Structured personal data of an identifiable minor tied to a specific academic activity — same shape as `AttendanceRecord` (line 39), the baseline ADR 0039 §4 already anchors to. No free text in this shell alone. |
| Student-authored text | Sensitive, with a **structural risk of Highly-Sensitive content** | Unbounded free text from a minor "could carry exactly the categories (health disclosure, safeguarding concern, family/home detail)" that would otherwise require the Health tier's own gate (ADR 0039 §4, quoting `ACADEMICS.md` §5's "audit-leakage and classification hazard" reasoning). This cannot be downgraded to a flat Sensitive tier without a content-inspection mechanism this checkpoint does not propose building (§14). |
| Coursework files | Sensitive floor; **classification inherited from actual content**, matching `DATA-CLASSIFICATION.md` line 50's "Documents/files" row verbatim ("a birth certificate scan is Highly Sensitive; a public event photo may be Public") | A restricted MIME allowlist (§3) narrows *format* risk but says nothing about *content* risk — a PDF or JPEG from a minor can contain anything a document or photo can contain. Treating every Submission file as uniformly `internal` (LearningContent/Assignment's existing tier) would be a materially incorrect default; `internal` is not proposed for Submission by this review. |
| Teacher feedback | Sensitive | Personal data about an identifiable Student, authored by staff about that Student specifically — the same bar as the Submission text itself, not a lower one merely because staff wrote it. |
| Assignment linkage / timestamps / history | Sensitive | Metadata revealing an identifiable Student's academic activity pattern; lower content-risk than free text but still personal data under the same tier as Student data generally (line 27). |
| Audit metadata (bounded — see §15) | Sensitive (per ADR 0017's general "audit data itself is sensitive" principle), but explicitly *not* carrying the free-text/file content itself | Distinguishing the audit *record* (who did what, when) from the audited *content* (what the essay said) is exactly the discipline `AssignmentService.php` already established for Assignment and must carry forward unchanged. |

**Documents `classification_tier` for a future Submission owner arm:**
today's four-tier vocabulary (`public`/`internal`/`sensitive`/
`highly_sensitive`, `apps/platform/database/migrations/2026_08_29_090000_create_documents_table.php`)
already has a `sensitive` tier that fits this row's baseline; `internal`
(what LearningContent/Assignment use) would be incorrect. This review
recommends a **`sensitive` floor**, not `internal`, if/when the owner
arm is ever activated — but does **not** decide whether an
escalation path to `highly_sensitive` is required for specific files
(e.g. one flagged as containing sensitive content), since that is a
product/moderation-policy decision (§14) this checkpoint does not
resolve. No owner arm, migration, or capability is added by this
document.

## 5. Children's-data / minor-data analysis

**Repository-confirmed facts:**
- `students.date_of_birth` is a required, non-nullable column
  (`apps/platform/database/migrations/2026_08_23_100000_create_students_table.php:35`)
  — the platform already knows every Student's exact age.
- `DATA-CLASSIFICATION.md` line 28 already states, as an engineering
  fact, that "Students are, for most of this product's user base,
  minors," and independently flags DPDP Act child-data obligations as
  `[LEGAL REVIEW REQUIRED]` — this predates and is broader than LMS.
- No age-threshold-differentiated rule of any kind exists anywhere in
  the codebase (no "if age >= N" branch, no distinct minor/adult
  consent flow). The system currently treats all Students identically
  regardless of age.
- Guardian relationships exist (`AccountLink`, `GuardianContact`) and a
  real Guardian authentication path exists (§2), but no
  guardian-consent-for-a-specific-processing-activity record exists
  for anything resembling coursework.
- No "in loco parentis" or institutional-authority concept is named
  anywhere in the documentation.

**Legal questions requiring qualified review** (repository is silent;
do not treat silence as either permission or prohibition):
- Whether DPDP Act consent/processing obligations for children differ
  by age band within the student population, and if so where the line
  falls.
- Whether School enrollment itself constitutes a sufficient legal basis
  to process coursework specifically (as opposed to ordinary
  administrative student records already collected at enrollment).
- Whether a distinct, explicit consent record (beyond enrollment) is
  required before a School collects Student-authored free-text/file
  content through this product.

This distinction — confirmed fact above the line, open legal question
below it — is preserved throughout this document; nothing in §§6–18
resolves a legal question by engineering judgment.

## 6. Submitting actor

Re-verified directly against `feature/phase-0i3-assignments`'s own
source (not carried over from ADR 0039's prose):

- **Student direct login: does not exist.** `config/auth.php` defines
  exactly one guard (`web`, backed by `User`); `students` has no
  `user_id`; no `student` role exists in `CapabilityAndRoleSeeder`.
  This is an identity/product dependency outside LMS's scope, not
  something this checkpoint or a future Submission checkpoint can
  build internally (ADR 0002 forbids a parallel auth system).
- **Guardian login: exists and works**, via
  `App\Domain\Identity\Application\{AccountInvitationService,
  GuardianAccountActivationService}` — invitation-based, proves mailbox
  control, grants no staff-shaped role.
- **Guardian-on-behalf-of-Student: not permitted by any existing
  mechanism.** `AccountLink` is explicit that linkage "never, by
  itself, authorizes" acting for the linked Student. This review does
  **not** infer permission from the login path's mere existence — ADR
  0039 §5 already rejected that inference, and this review agrees:
  authentication capability is not the same question as product/legal
  authorization to act for a minor.
- **Staff-on-behalf-of-Student: not evaluated by any existing document.**
  No repository precedent (accessibility, offline collection, or
  administrative re-entry of paper coursework) currently addresses
  this. Flagged here as an open product-policy question, not decided.
- **Multiple actor types / permanent auditability of the original
  submitter:** not yet designed. If more than one actor type is ever
  authorized to submit, the audit record (§15) must capture *which*
  actor type and *which* specific identity performed the action,
  distinct from whose Submission it is — this is a design requirement
  for whenever Submission is built, not a decision this review makes.

**This review does not select a submitting-actor policy.** It remains
exactly as unresolved as ADR 0039 §5 left it, and — per that ADR's own
observation — is moot until §18's legal gate clears in any case.

## 7. Consent and lawful-processing questions

Every item below is recorded as a **question requiring qualified
legal/product determination**, not answered here, because no
authoritative repository document already answers it:

1. Whether enrollment itself is a sufficient legal basis for processing
   coursework, or whether a separate basis (e.g. consent) is required.
2. Whether Guardian consent is required specifically for coursework
   collection (distinct from the existing Communications
   email-preference consent, which covers a different processing
   activity entirely).
3. Whether Student consent is legally relevant at any age, given no
   Student authentication path exists to collect it from the Student
   directly today.
4. Whether an explicit, storable consent record is required before
   Submission collection begins (the Phase 5D.2 consent-event pattern —
   append-only, `granted`/`withdrawn` history — is the nearest
   reusable mechanism if counsel determines one is needed, but its use
   for coursework specifically has not been evaluated by anyone).
5. Whether consent withdrawal (if a consent model is required) affects
   already-submitted coursework retroactively.
6. Whether the age-band question in §5 changes any of the above.
7. Whether optional media uploads (photo/audio/video, none of which
   are in today's MIME allowlist) would change the legal posture if
   ever proposed.
8. Whether a teacher may lawfully request sensitive personal content
   as part of an Assignment's instructions (e.g., an assignment that
   incidentally invites health/family disclosure).
9. Whether third-party personal information a Student incidentally
   includes in submitted work (e.g., a photo containing another
   person) creates a separate obligation the School or platform must
   address.

## 8. Purpose limitation

**Intended, in-scope educational purposes** (consistent with ADR 0039's
own framing of what LMS is for): completing assigned coursework,
teacher review, feedback, evidence of completion, academic-support
processes.

**Secondary uses — default-forbidden, not evaluated, out of scope for
this checkpoint or any Submission implementation checkpoint until
separately authorized:**
- AI model training or provider-side retention of Submission content
  (`docs/ai/AI-SECURITY.md`'s own language: provider data-handling "is
  a per-provider legal/contractual concern to evaluate... flagged here
  as requiring legal/compliance review before a real provider handling
  real school/student data is selected" — this predates and applies
  squarely to Submission).
- Automated profiling or behavioral analytics.
- Third-party plagiarism-detection services (this would mean
  transmitting Student-authored content off-platform to an external
  vendor — its own, separate privacy/security/legal review, per §17).
- External content scanning of any kind.
- Marketing, or institutional analytics unrelated to the educational
  purposes above.

No repository document authorizes any secondary use for any Sensitive-
or Highly-Sensitive-tier data today; Submission is not an exception.

## 9. Retention and deletion

**Repository-confirmed fact:** retention is an unresolved, repo-wide
`[LEGAL REVIEW REQUIRED]` gate (`DATA-CLASSIFICATION.md` lines 69–73)
— not a Submission-specific gap, and not something this checkpoint can
resolve in isolation, since inventing a Submission-only retention
period while every other Sensitive-tier module has none would itself
be an ungrounded, undocumented policy decision (the exact failure mode
`docs/modules/DOCUMENTS.md`'s own P1 finding warns against: "Fabricated
compliance/retention policy invented to 'solve' [a gap] without legal
review... Explicitly not done this checkpoint").

**What must eventually be decided, evaluated separately per category**
(Submission row; Student-authored text; uploaded files; teacher
feedback; audit trail; revision history):
- Retention duration, and whether it is tied to `AcademicYear`,
  enrollment status, or a fixed calendar period.
- Whether deletion means hard delete, soft delete/status-based
  retirement (this codebase's default pattern — CLAUDE.md rule 73),
  anonymization, or restricted-visibility archival.
- Whether a legal hold concept is needed, and whether retention should
  be School-configurable.

**This review does not invent a retention period.** It is recorded
here as an **unresolved legal/data-governance blocker**, identical in
form and severity to the same open gate every other Sensitive-tier
module in this codebase currently carries.

## 10. Correction and version history

ADR 0039 §9 already froze what can safely be decided at this stage,
and this review changes none of it:
- `submitted` is the only committed Submission state.
- A `returned`/`resubmitted` revision loop is explicitly **provisional**
  — left for the checkpoint that actually builds Submission to confirm
  against real product requirement.
- A Student-side `draft` (unsubmitted) state is **not** committed to,
  since it presumes an interactive Student session that does not exist
  (§6).
- If resubmission is ever approved, "current" status must be tracked
  via an **append-only revision history**, never an in-place overwrite
  — matching CLAUDE.md rule 11's audit convention.
- Whether teacher feedback may itself be edited/corrected after being
  given is not yet decided; if it is ever editable, the same
  append-only-correction discipline applies, not a silent overwrite.

No new decision is made here; this section exists only to confirm the
frozen ADR 0039 §9 shape remains correct on re-review and to flag its
retention-adjacent implications (§9 above) explicitly.

## 11. Data-access boundaries

**Confirmed principal categories:** submitting Student (no login path
exists — moot for now), linked Guardian (login exists; delegation does
not — see §6), the Assignment's School's teaching staff, School
administrators (Principal/Admin), platform administrators (governed by
CLAUDE.md rule 26 — no RLS bypass regardless of platform role).

**Assessment of whether Phase 0I.3's capability-only posture is
sufficient for Submission access:** it is not automatically sufficient,
and should not be silently inherited.

Phase 0I.3 (`lms.assignments.manage`, granted School-wide to
`school_admin`/`principal` only, never Teacher) was justified precisely
*because* Assignment content is staff-authored administrative data with
no Student identity in it (ADR 0039 §4's own Confidential
classification) — the same reasoning `SyllabusUnit`/`Examination`/
`ExaminationPaper` already established, and ADR 0039 §6 is explicit
that granting Teacher a School-wide `.manage` capability *would* be
unsafe once a real ownership boundary matters ("would let any teacher
manage any other teacher's Assignments").

Submission is materially different: it is **individual, identifiable
Student work**, not staff-authored administrative content. Two distinct
access questions follow, and this review does not resolve either
silently:

1. **Admin/Principal broad access** — a School-wide `lms.submissions.*`
   grant to Admin/Principal (mirroring Assignment's pattern) is
   plausibly defensible as ordinary administrative oversight, the same
   posture already accepted for `AttendanceRecord` and other
   Sensitive-tier Student data. This review does not object to that
   specific narrow claim, but notes it has not been separately
   confirmed by product/legal review either.
2. **Teacher access** — a capability-only grant broad enough to let
   *any* teacher at a School see *any* Student's Submission (the only
   shape available today, absent an ownership model) is a materially
   larger exposure than Assignment ever created, because the content
   being exposed is now personally identifiable Student work rather
   than staff-authored material. ADR 0039 §6 already names the missing
   prerequisite: "the platform's first ownership-based authorization
   model... does not exist." **This review classifies a
   teacher-to-Offering (or teacher-to-Section) ownership/scoping
   mechanism as a prerequisite that must exist before Teacher access to
   Submissions is granted** — Admin/Principal-only access, if that is
   the product's chosen v1 policy, does not require it, but that
   choice itself needs an explicit product decision, not an assumed
   default.

Guardian/Student self-access to their own Submission is unresolved for
the same reason §6 is unresolved — no delegation/self-service access
model exists to grant it through yet.

## 12. Tenant isolation

Any future Submission implementation must, without exception, follow
`docs/architecture/TENANCY.md`'s three-layer standard already mandatory
for every tenant-owned table: application-layer `SchoolScope`, Postgres
RLS (enabled *and forced*), and tenant-scoped storage paths
(`TenantStoragePath::for()`), plus composite `(id, school_id)` foreign
keys against every School-scoped parent (`Assignment`, `Student`),
exactly as `assignments`/`learning_content` already do.

**No additional segregation mechanism exists in this repository's
architecture for Highly Sensitive data beyond ordinary School-RLS** —
confirmed by direct review of `TENANCY.md` (no separate-database,
separate-encryption-domain, or extra-RLS-tier concept for Highly
Sensitive data; the only extra control described anywhere is
`DATA-CLASSIFICATION.md`'s "minimized default visibility," which is a
capability/UI-scoping rule, not a tenancy mechanism). If qualified legal
review determines Submission content requires isolation beyond
ordinary School tenancy (e.g. a data-localization/cross-border-transfer
requirement — §17), that would be new architecture this repository does
not yet have, not an extension of an existing but under-used mechanism.

## 13. Coursework-file security

**Controls that already exist today** (apply unchanged to any future
Submission file, since Submission would reuse the same Documents
module): a fixed 8-type MIME allowlist verified server-side by content
sniffing (never trusted client `Content-Type`), a 10 MB default size
cap, tenant-scoped server-generated storage keys
(`TenantStoragePath::for()`), streaming download (never whole-file
buffering — architecture-guard-tested), and no signed/public/temporary
URL surface at all (every access goes through an authenticated,
capability-checked controller action).

**Missing prerequisites, distinguished by kind:**
- **Security prerequisite (independent of legal clearance):**
  malware/virus scanning does not exist anywhere in Documents today
  (`docs/modules/DOCUMENTS.md` names this as an explicit non-goal in
  every checkpoint so far). Expanding the uploader pool from vetted
  staff (Employee/LearningContent/Assignment owner arms) to a much
  larger, less-trusted population (Students, or Guardians on their
  behalf) raises this from an accepted gap to a genuine blocker —
  **this review lists it as a technical/security blocker that must be
  closed before a Submission file-upload surface ships, regardless of
  the legal gate's outcome.**
- **Security prerequisite:** no checksum/integrity hash is computed on
  upload today (`DocumentService.php`) — worth adding for coursework
  given later dispute-resolution/integrity value, though not a hard
  blocker the way malware scanning is.
- **Product/security prerequisite:** image uploads may carry EXIF
  metadata (including geolocation) the platform does not currently
  strip. For a minor's uploaded photo, this is a distinct exposure the
  existing MIME-allowlist/size-cap controls do nothing to address —
  flagged as a prerequisite to evaluate, not decided here.
- **Legal prerequisite:** none of the above resolves the antecedent
  §18 question of whether the platform may collect the content at all
  — file-security controls answer "is it stored safely," never "may we
  store it."

## 14. Sensitive-content risk

ADR 0039 §4 already names the core risk precisely: an open-ended
Student submission "could carry exactly the categories (health
disclosure, safeguarding concern, family/home detail)" the Health
tier's own separate gate exists to protect. This review adds no new
technical finding here, only confirms the risk is real and unaddressed:
no automated content inspection exists in this codebase, and **this
review does not propose building one** (consistent with the checkpoint
brief's explicit instruction). What remains is a **decision** —
prohibit certain content categories outright, rely on teacher Assignment
design to avoid inviting sensitive disclosures, publish an
acceptable-use policy, or implement moderation at a future date — and
that decision belongs to product/legal governance, not engineering.

## 15. Audit logging

The bounded-metadata discipline `AssignmentService.php` already
establishes (`apps/platform/app/Domain/LMS/Application/AssignmentService.php`,
e.g. lines 106–113/154–161) is the correct pattern to carry forward
unchanged for Submission:

**Should appear:** actor (and actor type, per §6), Student id,
Assignment id, action, timestamp, status transition, Document/file ids
(reference only).

**Must never appear:** the submitted essay/text body, teacher feedback
body, raw uploaded file content, filenames beyond what is operationally
necessary, or any excerpt of sensitive content — matching ADR 0017's
general principle that "audit data itself is sensitive... not treated
as low-sensitivity operational log data" and the identical discipline
already proven for Documents uploads generally (`docs/modules/DOCUMENTS.md`:
audit metadata carries `documentId`/`ownerType`/`classificationTier`/
`sizeBytes`/`mimeType`, never `storagePath`).

## 16. Events

ADR 0039 §10's illustrative names (`submission.created.v1`,
`submission.resubmitted.v1`) remain **not implemented** by this
checkpoint, exactly as before. If and when a future, legally-cleared
checkpoint implements them, this review's finding is: a payload may
safely carry submission/assignment/student/school ids and a
timestamp/status, matching every domain event's existing minimization
discipline (`docs/architecture/EVENTS.md`: "kept minimal... not full
denormalized snapshots"; `docs/architecture/INTEGRATIONS.md`: "webhook
payloads are never raw Eloquent model serializations"). **No event
payload may ever contain the Submission's text body or file
content.** Per `WebhookEventRegistry`'s closed-catalog rule
(`docs/architecture/INTEGRATIONS.md`), neither event becomes externally
subscribable merely by being outboxed — that remains its own,
separately reviewed future decision, unaffected by this checkpoint.

## 17. AI and downstream processing / external systems

Per `docs/ai/AI-SECURITY.md`'s existing default-deny posture ("no
broader read access than [a tool's] capabilities define"; provider
training/retention "is a per-provider legal/contractual concern... to
evaluate before integrating any real provider"), this review states
explicitly, as a boundary to be preserved rather than a new invention:
Submission data is **not** automatically available to any AI Gateway
tool, training pipeline, embeddings/RAG index, or automated profiling
process merely because the Submission entity exists. Any future
AI-adjacent use of Submission content requires its own separate,
explicit tool-contract design and approval — this checkpoint neither
grants nor forecloses that, it only confirms no such access is implied
by anything built so far (no AI/RAG layer exists in this codebase at
all today, per `docs/ai/AI-PLATFORM.md`'s own component table).

ADR 0039 decision 1 already excludes external LMS integration/standards
(Canvas, Moodle, Google Classroom, Microsoft Teams, OneRoster, LTI,
QTI, SCORM, xAPI, Common Cartridge, Caliper) from Phase 0I entirely —
this review changes nothing about that boundary. Any future integration
that would transmit Submission content off-platform (including a
third-party plagiarism-detection service, named explicitly in §7) would
require its own dedicated privacy/security/legal review before design
work begins — it is not pre-cleared by this document or by ADR 0039.

## 18. Qualified legal-review requirement — determination

**No authoritative recorded legal decision exists in this repository
that satisfies ADR 0039 §4's requirement**, evaluated against the one
concrete precedent this repository already has for what that evidence
looks like when it does exist (Payroll 9.6, §2 above: a named, dated,
jurisdiction-specific legal document, quoted in an ADR, with an
engineering correction addendum, implemented on its own branch).
Specifically:

- No approved legal addendum exists for LMS/Submission.
- No signed-off ADR/legal decision exists for LMS/Submission (ADR 0039
  itself *raises* the gate; it does not, and does not claim to, clear
  it).
- No counsel-reviewed policy exists for LMS/Submission.
- No repository-recorded approval of any kind explicitly covers
  student-coursework Submissions.

`docs/security/DATA-CLASSIFICATION.md`'s own governing text is explicit
that its classification work is "written by engineering, not legal
counsel" and carries no compliance claim — engineering opinion (this
document included) is therefore, by the framework's own terms, not
sufficient evidence to clear the gate. The absence of any repository
statement prohibiting Submission is likewise **not** read as approval,
per this checkpoint's explicit instruction.

**Determination: the gate remains blocked.**

The question set in §19 was formally packaged and issued to qualified
legal counsel and product governance outside this repository on
2026-09-05 — see `docs/security/LMS-SUBMISSION-LEGAL-REVIEW-REQUEST.md`
for the request as issued. That document records the request only; it
is not a response and does not change this determination.

## 19. Required legal-review questions

The following question set is produced for qualified counsel / product
governance outside this engineering repository. Where this review found
directly relevant repository context, it is noted in brackets —
engineering has not attempted to answer the legal substance of any
question.

1. Is processing student coursework for normal educational use
   permitted under the applicable legal regime (India's DPDP Act, 2023,
   per `DATA-CLASSIFICATION.md`), and on what basis?
2. Does the treatment differ for minors? [Confirmed: students are
   predominantly minors; DOB is already collected; no age-band logic
   exists in the platform today.]
3. Is Guardian consent required, and if so, for what specifically
   (distinct from the existing Communications email-preference consent,
   which governs a different processing activity)?
4. May a Guardian submit work on behalf of a Student? [Confirmed: a
   real Guardian authentication path exists today; no delegation
   mechanism of any kind exists anywhere in this codebase; this is a
   live technical option, not a hypothetical one, if legally and
   product-approved.]
5. What is the required retention period for Submission content,
   separately for the Submission row, Student-authored text, uploaded
   files, teacher feedback, audit trail, and revision history?
   [Confirmed: no retention period exists for any module in this
   platform today — this is not a Submission-specific gap.]
6. What deletion/erasure rights apply, and what does "deletion" mean
   operationally (hard delete vs. anonymization vs. status-based
   retirement)?
7. Must prior versions/resubmissions be retained, and for how long?
8. Are teacher comments/feedback part of the same protected educational
   record as the Submission itself, or a separate category?
9. Are media uploads (photos/audio/video) permitted at all for student
   coursework? [Confirmed: none of these formats are in today's
   Documents MIME allowlist; adding them would be a separate technical
   decision even if legally permitted.]
10. What restrictions or handling apply to particularly sensitive
    content a Student uploads or writes, whether accidentally or
    intentionally (health, safeguarding, family/home detail, third-party
    personal information)?
11. May School administrators with broad capabilities view all
    Submissions School-wide, or must access be narrower?
12. What rules apply to staff access outside a Student's own assigned
    teacher/class? [Confirmed: no teacher-to-Offering ownership model
    exists in this codebase today — this is an engineering prerequisite
    regardless of the legal answer, see §20.]
13. May Submission data be used for AI processing, analytics,
    plagiarism detection, or any third-party service?
14. Are there data-localization or cross-border-transfer restrictions
    that affect where Submission content (or any AI/third-party
    processing of it) may be hosted or transmitted?
15. What breach/incident-notification obligations apply to stored
    coursework specifically?
16. Does the current Documents module's storage/access-control model
    (per §13 above) satisfy whatever protections are legally required,
    or are additional controls (e.g. malware scanning, per this
    review's own security-prerequisite finding) also legally mandated
    rather than merely good practice?
17. Are there minimum age-specific UX/consent-notice requirements for
    the product surface a Student or Guardian would use?
18. What records of processing/consent must be retained as evidence of
    compliance, and in what form?

## 20. Engineering prerequisites

Independent of legal clearance, classified as requested:

| Prerequisite | Classification |
|---|---|
| Authenticated Student submitting actor | **Technical blocker** — no Student authentication exists anywhere in the platform (a cross-cutting identity dependency, not LMS's to build). |
| Authenticated Guardian submitting actor | **Ready**, technically — a real login path exists. Gated only by the product-policy question (§6/§18) and by §18's legal gate, not by any missing technology. |
| Staff-on-behalf-of-Student submission | **Product-policy blocker** — no existing precedent addresses this; not evaluated by any repository document. |
| Authorization narrower than broad staff capability | **Technical + product-policy blocker** for Teacher access specifically (§11); **not required** for an Admin/Principal-only v1, but that scope choice itself needs an explicit product decision. |
| Malware/virus scanning for untrusted uploads | **Technical blocker** — must exist before opening a Submission file-upload surface, independent of the legal gate's outcome. |
| Documents `classification_tier` for Submission | **Decision required** — `sensitive` floor recommended (§4); escalation-to-`highly_sensitive` policy not resolved. |
| Retention policy | **Legal blocker** — repo-wide open gate, not Submission-specific, but Submission cannot ship without one existing. |
| Submission-attempt/version (revision-history) model | **Ready as a frozen design decision** (ADR 0039 §9 — append-only, never overwrite) — not yet implemented, but not blocked on anything further. |
| Audit design | **Ready** — the bounded-metadata pattern is proven and directly reusable (§15). |
| RLS / composite FKs | **Ready** — standard, already-proven pattern (§12), no new mechanism needed. |
| Assignment eligibility rules (only `published`, not `closed`, accepts new Submissions) | **Ready** — already defined by ADR 0039 §9's Assignment lifecycle, unchanged by Phase 0I.3. |
| Due-date/closed-state semantics | **Ready** — inherited unchanged from Phase 0I.3, informational/display-only per ADR 0039 §9. |
| Content-sensitivity policy (prohibit/warn/rely-on-design/moderate) | **Product-policy blocker** (§14) — not an engineering decision. |
| Data-localization/cross-border architecture, if legally required | **Not yet built** — would be new architecture, not an extension of an existing mechanism (§12/§19 Q14). |

## 21. Documentation changes made by this checkpoint

- **New**: this document, `docs/security/LMS-SUBMISSION-LEGAL-REVIEW.md`.
- **`docs/architecture/adr/0039-lms-domain-contract.md`**: appended a
  short, clearly-marked Phase 0I.4 review addendum recording that the
  gate was independently re-verified and remains unchanged (no decision
  in the ADR's frozen body is altered or weakened).
- **`docs/modules/LMS.md`**: corrected a now-stale status line (it
  still said Assignment was unimplemented, though Phase 0I.3 shipped
  it) and added a short pointer to this review document from §5.
- **`docs/roadmap/MASTER-ROADMAP.md`**: added a Phase 0I.4 entry
  recording the review occurred and the gate's BLOCKED status, matching
  the existing Phase 0I.1/0I.2 entry style.
- **`docs/security/DATA-CLASSIFICATION.md`**: **no change** — its
  existing Submission row (line 46) already states the correct
  classification and gate; this review found nothing requiring it to
  be added to or reworded, and the `[LEGAL REVIEW REQUIRED]` marker is
  preserved exactly as written.

No migration, model, service, controller, route, capability, UI, event,
or Documents owner-arm change accompanies this checkpoint.

## 22. Gate verdict

**BLOCKED — SUBMISSIONS REQUIRE QUALIFIED LEGAL REVIEW**

No authoritative, qualified legal clearance for LMS Submission exists
anywhere in this repository (§18). The precise next action required to
clear this gate is: escalate §19's question set to qualified legal
counsel and product governance **outside this engineering repository**,
obtain an actual recorded decision (in the form §2's Payroll precedent
already demonstrates — a named, dated, referenceable legal
determination), and record that decision as a new ADR addendum or
dedicated decision document citing it explicitly. Engineering review
alone — including this document — cannot clear this gate. No Submission
schema, model, service, controller, route, capability, UI, or Documents
owner arm should be implemented until that clearance exists.
