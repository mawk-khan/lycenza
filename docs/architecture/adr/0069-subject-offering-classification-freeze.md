# ADR 0069: SubjectOffering Classification Freeze (required ↔ elective)

- Status: **Accepted and implemented (2026-10-07).** Closes RES.5 follow-up
  **S1** (ADR 0068 §27.11) and the follow-ups ADR 0068 §20.1 / §21.8
  recommended.
- Date: 2026-10-07
- Module: Academic Structure (owner of `subject_offerings`), with database
  rules on the evidence tables of the modules that depend on it.
- Builds on: ADR 0032 / 0033 (Examinations, ExaminationPaper), ADR 0063
  (TeachingAssignments, TCH-E), ADR 0068 (P3, StudentMark), the Phase 1C/1F
  StudentSubjectEnrollment and elective-group contracts.

## 1. Problem (confirmed at `08c6d0c`)
`subject_offerings.is_required` decides what an Offering *means*:
- **Required** ("every Student of the grade takes it"): Section teaching
  ownership (`teaching_assignments`), timetable entries, curriculum
  deliveries and attendance registers attach to it. A grade placement is
  enough for P3 eligibility.
- **Elective:** Students join through `student_subject_enrollments`, and
  teachers own it Offering-wide (`elective_teaching_assignments`, TCH-E).
  P3 requires the elective row.

The only update path (`PATCH /api/v1/schools/{school}/subject-offerings/{id}`,
`academics.subjects.manage`) wrote `is_required` inline, at any time, with
no check. After evidence existed, a flip silently reinterpreted history:
- required evidence would sit under an elective;
- elective enrollments under a required Offering;
- P3 and every StudentMark on its papers would answer differently.

RES only failed closed (ADR 0068 §20.1, §21.2, §27.2). The meaning could
still be rewritten.

## 2. Decision
**A SubjectOffering's classification may change only while the Offering has
no dependent academic evidence. Both directions are frozen (symmetric).**
- **Not a change:** a no-op write (the same value).
- **Never frozen:** the other mutable fields (`sequence`,
  `weekly_periods_target`, `status`).
- **No history rewriting:** existing evidence is never rewritten. The change
  is prevented instead.

### 2.1 Evidence set (any row, any status)
| Table | Owner | Kind | Decision | Why |
|---|---|---|---|---|
| `teaching_assignments` | TeachingAssignments | required-only | **MUST freeze** | Section ownership exists only for required Offerings (D-05) |
| `timetable_entries` | Timetable | required-only | **MUST freeze** | scheduled Section classes of a required Offering |
| `curriculum_deliveries` | CurriculumDelivery | required-only | **MUST freeze** | Section delivery evidence (required only) |
| `attendance_sessions` | Attendance | required-only | **MUST freeze** | registers of a Section × required Offering (also implied by their timetable entry) |
| `student_subject_enrollments` | Students/SIS | elective-only | **MUST freeze** | elective participation; P3 elective eligibility, rollover |
| `elective_teaching_assignments` | TeachingAssignments (TCH-E) | elective-only | **MUST freeze** | Offering-wide elective ownership |
| `examination_papers` | Examinations | either | **MUST freeze** | P3 decides who a paper assesses from the classification. Freezing at the paper keeps that roster stable before any mark (marks need a paper, so StudentMark is covered) |
| `student_marks` | Examinations | — | covered by its paper | every mark references its paper (`RESTRICT`), so a marked paper cannot be deleted and keeps the Offering frozen |
| `syllabus_units` | Syllabus | neutral | does not freeze | a syllabus applies to either classification; nothing reads `is_required` for it |
| `learning_content`, `assignments` (LMS) | LMS | neutral | does not freeze | no LMS code interprets the classification today; revisit if LMS gains Student delivery |
| `communication_announcement_academic_cohorts` | Communications | neutral | does not freeze | the audience is resolved at publication; delivered recipients are their own record |
| `enrollment_rollover_subject_mappings` | Students/SIS | — | does not freeze | rollover re-checks both Offerings at plan time, and its execution writes `student_subject_enrollments` (which freeze, and are guarded) |
| elective groups (`subject_offerings.elective_group_id`) | Academic Structure | — | already guarded | `subject_offerings_required_group_check` |

**Ended, cancelled and withdrawn rows count.** They are history, and P3
treats a cancelled interval as eligibility evidence (ADR 0068 §18.2).

**Consequence (recorded for the owner).** An Offering created with the wrong
classification and used even once (for example, one mistaken enrollment,
since cancelled) cannot be reclassified. The academic-year Offering key is
unique, so the remedy is a deliberate data correction by an operator. There
is no application path. This is the price of never reinterpreting history.

### 2.2 Enforcement: the database is authoritative
Migration `2026_12_09_090000_freeze_subject_offering_classification`:
- **`subject_offering_classification_freeze()`** — a `BEFORE UPDATE OF
  is_required` trigger on `subject_offerings`.
  - An actual change is refused while any evidence row of the same School
    exists: `check_violation`, message `subject_offering_classification_locked`.
- **`subject_offering_evidence_guard(kind)`** — a `BEFORE INSERT OR UPDATE OF
  subject_offering_id` trigger on each evidence table. It:
  1. reads the Offering row `FOR SHARE`;
  2. refuses evidence of the wrong kind (`subject_offering_classification_mismatch`).

  When the Offering is not visible (another School, no tenant context), it
  steps aside: RLS and the composite foreign keys refuse such rows with their
  own errors.
- **Function properties.** Both functions are `SECURITY INVOKER` with a fixed
  `search_path`, and not executable by `PUBLIC`.
- **Migration preflight.** It counts mismatched evidence per School, under
  that School's tenant context (forced RLS binds the owner too). It refuses
  to install over any mismatch and rewrites nothing. The repository's test
  and demo data have none.

**Application layer.** `App\Domain\AcademicStructure\Application\SubjectOfferingService::update()`
is now the single update path (rule 76: a real invariant). It:
1. checks the capability;
2. takes the Offering `FOR UPDATE`;
3. writes;
4. translates the database refusal into 409 `SUBJECT_OFFERING_CLASSIFICATION_LOCKED`
   (fixed text, naming no dependent record);
5. audits successful updates as before (`subject_offering.updated`).

It deliberately does **not** pre-check the dependent tables. Academic
Structure must not depend on the modules that depend on it (CLAUDE.md
rule 4), and only the database check is race-free.

### 2.3 Concurrency
The flip's `UPDATE` holds the Offering row lock (`FOR NO KEY UPDATE`).
Every evidence insert holds the same row `FOR SHARE` — some writers already
in their service (TeachingAssignment, elective assignment, SSE enroll
`FOR UPDATE`, P3), and every writer in the trigger. The two conflict, so:
- **Evidence first:** the flip waits. Its trigger then runs on a fresh
  snapshot after the lock is granted, sees the committed evidence, and
  refuses.
- **Flip first:** the evidence insert waits, then reads the committed
  classification and is refused if it no longer fits. This includes writers
  that checked the classification on an unlocked read (Timetable, Curriculum
  Delivery). A paper, which fits either classification, simply attaches to
  the new one.

**Lock order.** For every evidence writer, the Offering row comes after
whatever it already held. The flip takes only that row and then performs
plain reads, so it never waits while holding anything but the Offering.
No cycle is possible, and nothing locks a table.

The trigger's `FOR SHARE` also conflicts with an unrelated Offering field
update, which therefore briefly serializes with evidence inserts.

### 2.4 Downstream protections kept
These are kept as defence in depth:
- RES's snapshot and fail-closed rules (`STUDENT_MARK_CONTEXT_CHANGED`,
  `STUDENT_MARK_CORRECTION_CONTEXT_CHANGED`);
- TCH's refusals (`RequiredOfferingOnlyException`,
  `ElectiveOfferingOnlyException`, the elective insert trigger);
- `TeachingOwnership::holdOffering()` dispatch.

These states are now far harder to reach. A legitimate edit can no longer
change a mark's source; a backdated placement transfer can still change its
placement.

## 3. Proof
- **`SubjectOfferingClassificationFreezeTest` (8):**
  - both directions with no evidence;
  - each evidence type freezes the Offering: TeachingAssignment, timetable
    entry, curriculum delivery, StudentSubjectEnrollment (also cancelled),
    elective assignment (also ended), an active or inactive paper, marks;
  - a syllabus unit alone does not freeze it;
  - no-op and other fields stay allowed;
  - mismatched evidence is refused by the database;
  - raw SQL is refused, a no-op passes, and a cross-School context changes
    nothing;
  - API: 409 with a fixed body, no audit on refusal, capability unchanged;
  - the installed rule covers exactly the seven tables.
- **`SubjectOfferingClassificationConcurrencyTest` (7 real-process
  races):**
  - T1 TeachingAssignment ⇄ flip, both orders;
  - T2 elective enrollment ⇄ flip, both orders;
  - T3 paper ⇄ flip, both orders;
  - T4 mark being recorded → flip waits, is refused;
  - T5 an unlocked-read writer (Curriculum Delivery) behind a flip →
    refused by the database.
- **Mutation checks:**
  - dropping the evidence trigger's `FOR SHARE` fails T5;
  - neutering the freeze check fails 9 tests.
- **`SubjectOfferingClassificationArchitectureGuardTest`:**
  - only creation and the service write the classification;
  - the controller delegates;
  - the service never reads dependent modules;
  - the triggers are installed and enabled, `SECURITY INVOKER`, with a
    pinned `search_path`;
  - the RES / TCH fail-closed protections and the `holdOffering()` dispatch
    remain.
- **Rollback proof:** identical schema after rollback and re-apply;
  `platform:verify-database` failed=0.
- **Adjusted tests (the scenario they built is now impossible by
  construction):**
  - the RES.3 context-changed test now uses a backdated transfer;
  - the RES.5 edit test asserts the flip is refused;
  - the rollover "malformed required participation" test asserts the
    database refuses it;
  - `SubjectOfferingIdentityGuardTest` follows the update into the service.

## 4. Alternatives rejected
- **Application-only check.** It would have to read Students, TCH,
  Timetable, Curriculum and Examinations tables from Academic Structure
  (an upward dependency), and it is racy.
- **Freeze on StudentMark only.** That leaves the paper's roster, TCH
  ownership, timetable and delivery reinterpretable.
- **One-directional freeze.** Neither direction is safe once evidence
  exists.
- **Rewriting evidence on a flip.** It destroys history.
- **A per-row `classification_locked` flag.** It is a second source of
  truth that can drift; the evidence tables are the truth.
