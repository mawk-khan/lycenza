# ADR 0068: Assessment & Results Reopening Contract — P3 and Internal StudentMark

- Status: **Accepted (RES.0B, 2026-10-06; documentation only).** Owner
  decisions R1–R20 (§4) are adopted. **RES.1 implemented (2026-10-06,
  §18):** the P3 seam. **Amended 2026-10-07 (§19):** RES-L0 returned
  CURRENT WITH CHANGES; its conditions bind RES.2 onward, and RES.2 is
  authorised for development only (not production: RES-L1). **RES.2
  implemented (2026-10-07, §20):** internal administrative StudentMark entry,
  development only; production blocked by RES-L1. **RES.3 implemented
  (2026-10-07, §21):** the one-way per-paper marks lock and append-only
  maker/checker corrections, development only; production blocked by RES-L1.
  §7.2's "insert-only" is amended in §21.3. **RES.4 readiness (2026-10-07,
  §22; docs only):** the teacher-processing requests are drafted, not sent;
  **RES.4 remains NOT AUTHORISED.** **E33 determined (2026-10-07, §23):**
  APPROVED WITH CONDITIONS for teacher Attendance only; RES.4 still NOT
  AUTHORISED (RES-L2, RES-L0 teacher re-review).
- Date: 2026-10-06
- Programme: **RES — Assessment & results** (`MASTER-ROADMAP.md`,
  "Post-foundation product programmes", order 5).
- Evidence: the RES.0 read-only reopening audit at `cfc08ad` (2026-10-06).
- Reopens, **only to the extent of §2**: ADR 0061 §2.3 (dated reopening
  trace in ADR 0061).
- Builds on, and does not rewrite:
  - ADR 0032 (Examinations decomposition), ADR 0033 (ExaminationPaper),
    ADR 0035 (GradeScale / GradeBand);
  - ADR 0037 (Staff MFA), ADR 0038 (Student Processing Authorization
    Registry), ADR 0049 (bearer tokens carry no MFA assurance);
  - ADR 0063 (Teacher identity and ownership, including §17 and §40);
  - ADR 0064 / ADR 0066 and the E21 determination (retention);
  - `docs/security/STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md`
    (2026-09-03), which this ADR neither widens nor re-decides.
- Legal items: RES-L0 – RES-L9 (ADR 0058 register rows E35–E44).

## 1. Context

ADR 0061 deferred Phase 0H's academic depth to post-v1 and set a reopening
rule (§2.5): a fresh audit, prerequisites, legal and security revalidation,
a new checkpoint and explicit owner authorization. RES.0 performed the
audit. It found:
- **No hidden implementation.** There is no StudentMark, result, report
  card, transcript, GPA, rank, weighting, moderation or pass-mark entity,
  route, capability, page or OpenAPI path. Guard tests forbid marks and
  result columns on the existing Examinations tables.
- **Built prerequisites:** Examination (0H.4A), ExaminationPaper with
  `max_marks numeric(6,2)` (0H.4B), GradeScale / GradeBand (0H.4C), Staff
  MFA (0H.4D-P1), the processing-authorization registry with its
  StudentMark seam (0H.4D-P2), Student subject enrollment (1C) and Teacher
  ownership (ADR 0063).
- **An undefined prerequisite:** "0H.4D-P3 — elective historical
  eligibility" is named in ADR 0061, ADR 0038 and `EXAMINATIONS.md`, but
  never defined. The readiness audit that introduced it is not in the
  repository. The Offering roster (`SubjectOfferingRosterReadService`)
  answers only "who is on it now".
- **A legal boundary that blocks development, not only production:** the
  2026-09-03 determination approves architecture and backend for
  internal-staff StudentMark processing only. Result publication, report
  cards, transcripts, and Student- and Guardian-facing access each need
  "its own future determination before implementation begins". ADR 0061
  §2.4 requires the determination itself to be re-confirmed before
  StudentMark is reopened.

## 2. What this contract reopens, and what it does not

**Reopened (design now; implementation per §11):**
1. **P3** — a Students-owned as-of-date SubjectOffering eligibility seam
   (§5).
2. **Internal StudentMark processing** — administrative marks entry, a
   per-paper lock and append-only corrections (§6–§8).

**Still deferred** (ADR 0061 §2.3 stays effective for each until its own
determination and contract exist): results and grading beyond the existing
GradeScale; result calculation, finalization, publication and revocation;
report cards; transcripts; assessment components and weighting;
Student- and Guardian-facing marks or results; promotion from results;
ranking.

**Superseded from ADR 0061 §2.3:** "teacher identity on a delivery, and
ownership-based authorization" — reopened and built by ADR 0063 (TCH).
Its use for marks is RES.4 (§9.3).

### 2.1 The stages are separate facts

Each stage below is a different fact, with a different authority and a
different legal gate. Recording an earlier stage never implies a later
one.

| Stage | Meaning | Status |
|---|---|---|
| Raw assessment evidence | That a Student sat, missed or was exempt from one ExaminationPaper | StudentMark (this ADR) |
| StudentMark | The recorded value and status for one Student on one ExaminationPaper, with provenance | This ADR (RES.2) |
| Marks lock | A per-paper statement that entry is finished; later change is correction only | This ADR (RES.3) |
| Derived Result | A computed value (percentage, band, total) derived from marks | Deferred (RES-L4) |
| Result finalization | A frozen snapshot of derived Results | Deferred (RES-L4) |
| Publication | Making finalized Results available beyond internal staff | Deferred (RES-L4, RES-L7) |
| Revocation | Withdrawing a publication | Deferred (RES-L4) |
| Report-card generation | A Documents artifact built from Results | Deferred (RES-L5) |
| Transcript generation | A cumulative academic document | Deferred (RES-L6) |
| Student / Guardian visibility | Any non-staff read of marks or Results | Deferred (RES-L7, POR) |

**A locked paper is not a finalized, published or visible Result.** No
lifecycle state of StudentMark may be named, documented or presented as a
result, a publication or a release.

## 3. Dependency direction

```text
Examinations (StudentMark)                       Layer 3
   │ reads only Application seams
   ├─► Students/SIS: P3 eligibility seam (§5)     Layer 2
   ├─► Students/SIS: processing-authorization seam (ADR 0038)
   ├─► Academic Structure: AcademicYear status    Layer 1
   └─► Teaching Assignments / HR (RES.4 only):    Layer 3 / 2
         TeachingOwnership, ActingEmployeeResolver
```

- Every edge is to a lower or equal layer (`DOMAIN-MAP.md`); none creates a
  cycle. Students, Academic Structure, HR and Teaching Assignments never read
  Examinations.
- Examinations never queries another module's tables or models: P3 and the
  authorization registry are reached only through their Students-owned
  services (ADR 0038 §"Read-service seam").
- `TeachingAssignmentArchitectureGuardTest` keeps forbidding Examinations
  from referencing `TeachingAssignment` until RES.4 deliberately amends it to
  allow `TeachingOwnership` only.

## 4. Adopted owner decisions (2026-10-06)

| Id | Decision |
|---|---|
| R1 | **P3** is a Students-owned as-of-date SubjectOffering eligibility seam (§5). |
| R2 | **StudentMark and its correction history are Highly Sensitive** children's educational data (resolves the Sensitive / Highly Sensitive inconsistency; §10). |
| R3 | **Mark representation:** `numeric(6,2)`, `>= 0`; status `present` / `absent` / `exempt`; `present` requires a value `<=` the paper's `max_marks`; `absent` and `exempt` carry no value; no free-text remark. |
| R4 | **Withheld, malpractice, pending-investigation** and similar exceptional states are deferred. |
| R5 | **No pass marks and no pass/fail** in StudentMark. |
| R6 | **Initial actors are administrative only** under `examinations.marks.*` (§9). No teacher marks capability in RES.2. |
| R7 | **MFA:** marks writes require the ADR 0037 assurance window (`mfa`); the lock and any equivalently sensitive transition require a fresh MFA re-verification (`FreshMfaRequirement`) (§9.2). |
| R8 | **Per-paper lifecycle `open` → `locked`.** Before lock, marks may change; after lock, only append-only corrections (§7). |
| R9 | **Maker/checker for post-lock corrections** (not for ordinary pre-lock entry). |
| R10 | **A closed AcademicYear blocks ordinary entry**; existing marks change only through the controlled correction path (§7.4). No generic "closed year is immutable" rule is inferred. |
| R11 | **Per-paper batch (grid) entry**, atomic per request. CSV import deferred. |
| R12 | **Teacher entry, if ever cleared, is evaluated per Student, per Offering, on the paper's date**, through TCH ownership; an Offering-wide paper never authorizes a teacher for every Student of the Offering. |
| R13 | **No teacher entry for electives** until an authoritative elective teacher-ownership fact exists. Administrative entry is independent of it. |
| R14 | **E33 interaction:** teacher marks architecture may be built after RES-L2 is answered, but no production teacher grant while ADR 0063 §40 / E33 prohibits it. |
| R15 | **No components or weighting.** One ExaminationPaper is the atomic assessment context. |
| R16 | **Deferred to the Results contract:** GradeScale selection, grade points, GPA / CGPA, rounding, aggregation, pass/fail, result snapshot and finalization. StudentMark depends on none of them. |
| R17 | **No rank,** class rank, merit order or leaderboard. |
| R18 | **Attendance is not an entry or eligibility rule.** |
| R19 | **No promotion from results.** Rollover decisions stay manual. |
| R20 | **Pre-existing documentation drift corrected** with this publication (§14). |

### 4.1 Clarifications from repository evidence (not new decisions)
- **R7 + R11, the surface (ADR 0049):** a bearer token cannot carry MFA
  assurance, and no `/api` route carries `mfa`. Marks writes, the lock and
  corrections are therefore **session-authenticated JSON routes** composing
  `capability:` with `mfa`, exactly like the processing-authorization
  registry (`routes/web.php`). No `/api/v1` bearer-token marks surface is
  authorized; adding one needs its own ADR. Reads follow the same surface
  in RES.2.
- **R8 + R7, unlock:** an unlock would let a locked value be overwritten,
  which R8 forbids. This contract adopts **no unlock**: after lock, change is
  correction only. A future unlock needs its own decision, fresh MFA, and
  must preserve every pre-unlock value as correction evidence.
- **R1, the required roster:** a SubjectOffering is scoped to AcademicYear ×
  Campus × GradeLevel, never to a Section. Required eligibility is therefore
  the Student's placement in that context on the date, whatever its
  Section (§5.2). The Section is returned for R12, not used as a filter.

## 5. P3 — as-of-date SubjectOffering eligibility (RES.1)

### 5.1 The question
"Was Student S eligible to be assessed in SubjectOffering O on date D, and
on which placement?" — asked by a School, answered by Students/SIS.

### 5.2 The logical contract
- **Inputs:** School (the trusted tenant context, never client input),
  Student, SubjectOffering, date D (School-local calendar date, as
  `ExaminationPaper.scheduled_on` is).
- **Result:** either *eligible*, with its **source** (`required` or
  `elective`), the qualifying **placement** (`student_enrollment_id`, with
  its Section) and, for an elective, the qualifying **subject enrollment**
  row; or *not eligible*, with a closed reason. Never a boolean alone: the
  caller snapshots the placement as provenance (§6).
- **Required Offering** (`is_required = true`): eligible when a
  StudentEnrollment of S has an interval containing D (`starts_on <= D`,
  `ends_on IS NULL OR ends_on >= D`, inclusive) and matches the Offering's
  School, AcademicYear, Campus and GradeLevel exactly.
- **Elective Offering** (`is_required = false`): eligible when a
  `student_subject_enrollments` row of S for O has an interval containing D,
  **and** a placement of S covering D matches the Offering's context as
  above. The row's `student_enrollment_id` anchor must be present.
- **Temporal, not status-based**, exactly like
  `StudentEnrollmentRosterReadService::membersAsOf()`: a row's current
  status (`completed`, `withdrawn`, `transferred`, `cancelled`) never
  removes it from a date its interval contains. A later transfer, rollover,
  withdrawal or year close never changes an earlier date's answer.
- **Independent of current state:** the Offering's or Student's current
  status, and the Student's current placement, are not consulted. (Today's
  `SubjectOfferingRosterReadService` re-validates against the *current*
  placement and returns nothing for an inactive Offering; P3 must not.)

### 5.3 Fail closed
*Not eligible* — never a guess — when:
- no placement of S covering D matches the Offering's context;
- for an elective, no subject-enrollment row for O covers D;
- an elective row has no `student_enrollment_id` anchor (legacy data
  before 1F.2): historical eligibility cannot be established;
- the Offering, Student and placement are not all in the requesting School;
- the data is internally inconsistent (a placement whose context columns
  disagree is excluded, as `membersAsOf()` already does).

### 5.4 Consistency and concurrency
- **Two variants, as ADR 0038 established:** a plain point-in-time read,
  and a **lock-capable** variant for a write path. The lock-capable variant
  must be called inside the caller's transaction and takes `FOR SHARE` on
  the qualifying placement row and (elective) subject-enrollment row, so a
  concurrent transfer, withdrawal or interval change of those rows
  serializes with the mark write.
- Deterministic: if two rows qualify (which the one-active-row indexes
  should prevent), the seam fails closed rather than picking one.
- Authorization-neutral and tenant-context-bound, like every Students read
  service; callers authorize first.
- No table, no cache, no event. P3 is a read seam over existing data.

### 5.5 To verify in RES.1 (not decided here)
- How 1F.2 anchors an elective row when its placement is transferred within
  the year (the elective rule above deliberately requires *a* matching
  placement on D, not the anchor itself to cover D).
- Whether a `cancelled` interval should count. The default is consistency
  with `membersAsOf()` (it counts); RES.1 must test it and record the
  answer.

## 6. StudentMark — future persistence contract (RES.2)

Exact names are RES.2's to choose; these invariants are not.

### 6.1 The mark
- **One current mark per Student per ExaminationPaper** (database unique).
- **Identity and tenancy:** UUIDv7 id; `school_id`; `unique(id,
  school_id)`; forced RLS (`TenantRls::enable`).
- **Same-School structure (composite foreign keys, RESTRICT):**
  - the ExaminationPaper through `examination_papers(id, school_id)` (kept
    for this purpose, `EXAMINATIONS.md` §18.6);
  - the Student;
  - the qualifying **placement** from P3 (`student_enrollment_id`), pinned
    to the same Student and AcademicYear, as Attendance pins its records;
  - for an elective, the qualifying subject-enrollment row;
  - the qualifying **processing authorization** returned by
    `lockQualifyingAuthorizationIdForProcessing()` (ADR 0038's seven-step
    sequence), as provenance.
- **Value shape (R3), database-checked:** status `present` / `absent` /
  `exempt`; `present` ⇔ a value; value `numeric(6,2)`, `>= 0`, `<=` the
  paper's `max_marks` (a trigger, since `max_marks` is on another row); no
  remark, note or free-text column of any kind.
- **No pass/fail, grade, percentage, band, total, rank or publication
  column** (R5, R16, R17). Guard tests pin the closed column set.
- **No runtime hard delete:** runtime DELETE is revoked; only the retention
  machinery may delete, and only after RES-L8 (§12).
- **Actors:** each actor column is a User reference classified
  `RETAIN_REFERENCE` in `UserReferenceCatalog` in the same change (the
  Attendance, registry and Finance pattern). A teacher-entered mark (RES.4)
  also records the acting Employee.

### 6.2 The write path (inside one transaction)
1. Authorize: capability (and, in RES.4, ActingEmployee and ownership).
2. Lock the paper's marks state (§7) and refuse unless `open` and the
   AcademicYear is not closed (§7.4).
3. For each Student, in a deterministic order (Student id ascending):
   1. P3 lock-capable eligibility for the paper's Offering on its
      `scheduled_on` (§5);
   2. ADR 0038 `lockQualifyingAuthorizationIdForProcessing()`;
   3. write the mark with both provenance references.
4. Commit. Any refusal rolls the whole request back (R11).

The combined lock order is TCH identity and ownership (RES.4) → paper
marks state → per Student: placement and subject enrollment (P3) → Student,
grant and relationship (ADR 0038) → mark row. RES.2 proves it with real
concurrent processes.

### 6.3 Batch entry (R11)
- One request covers one ExaminationPaper and many Students.
- Atomic at request scope: all rows commit or none.
- **No lost update:** two writers on stale input never both succeed
  silently. RES.2 chooses the mechanism (row lock with an expected version,
  or equivalent) and proves it.
- **Retries:** the request is evaluated under rule 29. A replay must never
  bypass a fresh authorization, ownership or eligibility decision (rule 32;
  the TCH.4 precedent kept the teacher submit non-idempotent for this
  reason).
- CSV or spreadsheet import is deferred.

## 7. Lock, corrections and closed years (RES.3)

### 7.1 Per-paper lifecycle (R8)
- Each ExaminationPaper has a marks state: `open` (default) → `locked`.
  The transition is explicit, audited, needs fresh MFA (R7), and is one-way
  (§4.1). It is a separate, School-scoped, RLS fact; ExaminationPaper's own
  `active` / `inactive` status is unchanged.
- **Before lock:** marks may be created and changed through the entry path.
  Every write is audited (§13).
- **After lock:** the entry path refuses every write. A stored value is
  never overwritten in place.

### 7.2 Corrections after lock
An **append-only** correction record, insert-only for the runtime role,
holds at least:
- the corrected mark;
- the previous status and value;
- the replacement status and value (R3's shape, re-checked);
- a **controlled reason** from a closed catalogue (no free text);
- the requesting actor and the approving actor;
- timestamps;
- the processing authorization qualifying at correction time (ADR 0038
  seam, re-run).

The mark's current value is derived from its original value and its
approved corrections (or a database-maintained current value whose every
change is proven by a correction row). No history is lost in either
design; RES.3 chooses one.

### 7.3 Maker/checker (R9)
A post-lock correction is requested by one actor and approved by a
different actor, each with their own capability. The maker can never
approve their own request (database-enforced, as FEE concessions are). A
pending request changes nothing.

### 7.4 Closed AcademicYear (R10)
- While the paper's AcademicYear is `closed`, the ordinary entry path
  refuses every write, open paper or not.
- An existing mark may still change only through the correction path (with
  maker/checker), whether or not its paper was locked.
- This is a StudentMark rule only. It is not a generic repository rule
  (Attendance and Curriculum Delivery keep their own).

## 8. What StudentMark never does
- derive, store or display a percentage, band, grade, total, GPA, rank or
  pass/fail;
- select a GradeScale;
- finalize, publish, revoke or release anything;
- feed rollover, promotion or detention;
- apply an attendance threshold or eligibility rule;
- apply a board- or curriculum-specific rule;
- expose anything to a Student, Guardian, portal, notification, Document,
  Analytics report, AI tool or webhook.

## 9. Authorization and MFA

### 9.1 Capability families
- **`examinations.marks.*`** (reserved since 0H.4A) — marks entry, the lock
  and corrections. RES.2 / RES.3 choose the leaves, with at least separate
  keys for: reading marks, entering marks, locking, requesting a
  correction, approving a correction.
- **`examinations.results.*`** — results and publication. **Not created by
  this contract.** No `examinations.marks.*` key implies, includes or
  grants any `examinations.results.*` authority.
- **Initial holders:** the existing administrative roles (`school_admin`;
  `principal` where the RES.2 capability review confirms it), never
  `teacher`. `examinations.definitions.*` / `.papers.*` / `.grade_scales.*`
  do not imply `examinations.marks.*`.

### 9.2 MFA (R7), in the existing ADR 0037 / ADR 0049 terms
- **Every marks read and write route:** `capability:` + `mfa` (the ADR 0037
  assurance window, `config('mfa.assurance_window_minutes')`).
- **The lock, correction approval and any equivalently sensitive
  transition:** additionally a **fresh MFA re-verification**
  (`App\Support\Auth\Mfa\FreshMfaRequirement`, ADR 0049), as staff
  credential and School-lifecycle actions already require.
- Session-authenticated JSON routes only (§4.1). No new MFA mechanism.

### 9.3 Teacher marks entry (RES.4), all conditions required
1. **RES-L2** answered (does "internal staff processing" include assigned
   teachers).
2. **TCH ownership** (ADR 0063): an owned-scope capability AND a verified
   ActingEmployee AND `TeachingOwnership::hold()` for the **Student's
   placement Section** (from P3) × the paper's Offering **on the paper's
   `scheduled_on`** (R12). Unowned Students answer the non-disclosing 404
   (ADR 0063 §18).
3. **Electives:** an authoritative elective teacher-ownership fact (R13);
   TeachingAssignment covers required Offerings only (D-05).
4. **Production:** no `teacher` role grant while ADR 0063 §40 / E33
   prohibits it (R14).

Co-teacher write semantics, cover-teacher entry, MFA level for teachers and
the TCH-L1-style determination for marks are RES.4's decisions.

## 10. Classification (R2)
- **StudentMark and its correction history: Highly Sensitive** —
  identifiable children's educational performance data
  (`DATA-CLASSIFICATION.md` "Children's data specifically"; the
  determination's own words, "protected, Highly Sensitive data").
- This supersedes the earlier forward notes that Student marks would make
  an entity "Sensitive" (`EXAMINATIONS.md` §9, §20; ADR 0032 §6;
  `DATA-CLASSIFICATION.md` Examination rows). Those notes stay as history.
- Consequences: minimized default visibility (no marks in broad list or
  summary views), access audited including reads, never logged, never in
  audit metadata by value, never a metric label.
- Examination, ExaminationPaper, GradeScale and GradeBand stay
  Confidential: they hold no Student data.

## 11. Implementation gates
| Slice | Scope | Gate before implementation |
|---|---|---|
| RES.1 | P3 seam (§5) | None legal (a read seam over existing Students data); this ADR |
| RES.2 | Administrative StudentMark entry (§6) | **RES-L0** answered and recorded; RES-L3 assumption stated in tests |
| RES.3 | Lock and corrections (§7) | RES.2 |
| RES.4 | Teacher-owned entry (§9.3) | RES-L2; elective ownership for electives; no production grant while E33 is open |
| RES.5 | Closure audit | RES.1–RES.4 |

Production of any marks feature additionally needs **RES-L1** and **RES-L8**.
**Results, report cards, transcripts and Student/Guardian access are not
sequenced:** each needs RES-L4 – RES-L7 answered and its own contract first.

## 12. Retention (no period chosen)
- **No retention period is invented.** E21-D7 explicitly invents no RES
  records; RES-L8 owns the question.
- **Fail closed until RES-L8:** every RES table is catalogued in
  `TenantRetentionCatalog` as `policy_unresolved` (no adopted period) in
  the slice that creates it. No retention unit deletes it.
- **Student dependency:** marks reference the Student, its placement and
  its processing authorization, so `ReferencingRows` keeps the Student core
  record `dependency_blocked` until a RES `StudentCoreParticipant` exists.
  None is added before RES-L8.
- **Anchors:** every RES evidence table carries `retention_recorded_at`
  (`RetentionAnchors::TABLES` and the anchor trigger, tracking its clocks
  and links), and the retention delete guard, from its create migration.
- **Correction history** is retained with, and never before, its mark.
- **Actors:** `RETAIN_REFERENCE` (§6.1), so User minimization keeps the
  academic evidence pointing at a tombstone.
- The processing authorization a mark names is already retained with the
  Student core record (E21.2G P1); a mark never shortens that.

## 13. Audit, events and integrations
- **Audit (School-scoped), ids and closed codes only:** marks recorded and
  changed (pre-lock), paper locked, correction requested / approved /
  rejected, and **reads** of marks (the Highly Sensitive read-audit rule).
  Never a mark value, Student name or free text in metadata.
- **No outbox event and no webhook.** Nothing consumes marks. Any
  publication event belongs to a future, legally cleared Results / POR /
  Communications contract, through an explicit `WebhookEventRegistry`
  addition if ever external (rule 45).
- **Not authorized by this contract:** a Student or Guardian API or page,
  a notification, report-card or transcript generation, a Document, an
  Analytics read model or Group report, an AI tool.

## 14. Documentation drift corrected (R20)
- `students.processing_authorizations` feature flag: seeded default-off
  but read by no code. ADR 0038 said it gates the surface's visibility; a
  dated ADR 0038 note records that it does not, and that capability + MFA
  are the only gate. No code change.
- `MASTER-ROADMAP.md`: GradeScale "implemented but NOT YET PUBLISHED" —
  published at `70e4a43` (2026-09-03).
- `EXAMINATIONS.md` §19: the reference to a non-existent "§21" now points at
  ADR 0032's provisional sequence.
- Statements made stale by ADR 0063 (ADR 0032 "no ownership model", ADR
  0037 teacher/class-scoped deferral, Phase 1C "teacher assignment not
  built"): dated notes; history kept.
- The Sensitive / Highly Sensitive inconsistency for marks (§10).
- The seeder comment "no teacher-ownership rule" (code) is left unchanged
  in a documentation-only slice; RES.2 updates it with the marks
  capabilities.

## 15. Guardrails
This ADR is **not** authority for, and no RES slice may build without its
own contract and legal answer:
- result calculation, finalization, publication or revocation;
- report cards or transcripts;
- Student- or Guardian-facing marks or results;
- rank, merit order or leaderboard;
- promotion, detention or repeat-year decisions from marks;
- attendance-based or statutory examination eligibility;
- board- or curriculum-specific grading logic;
- assessment components, weighting, grace, bonus, moderation or
  normalization;
- a teacher marks grant in production while E33 is open.

RES.2 adds guard tests pinning: the closed mark column set (no
result / grade / pass / rank / publication / remark column), no
`examinations.results.*` capability, no marks route outside
`capability:` + `mfa`, and no outbox event.

## 16. Legal gates
| Item | Question | Blocks |
|---|---|---|
| RES-L0 | Is the 2026-09-03 determination still current, with ADR 0038's model? | **Development** of RES.2+ |
| RES-L1 | Production enablement of internal marks entry | Production |
| RES-L2 | Does internal staff processing include assigned teachers? | Development of RES.4; production |
| RES-L3 | Effect of authorization withdrawal on existing marks; statutory purpose | Production (RES.2 builds on ADR 0038's stated assumption) |
| RES-L4 | Result calculation, finalization, publication, correction, revocation | Design and development of results |
| RES-L5 | Report cards | Design and development |
| RES-L6 | Transcripts | Design and development |
| RES-L7 | Student / Guardian access (age 18, separated parents) | Design and development (POR) |
| RES-L8 | Retention of marks, corrections, results, report cards, transcripts | Production; any expiry |
| RES-L9 | Statutory academic rules (attendance thresholds, RTE no-detention, mandatory examinations) | Any rule that encodes them |

The revalidation request for RES-L0 is
`docs/security/RES-L0-STUDENTMARK-REVALIDATION-REQUEST.md`. **No answer is
assumed.**

## 17. Alternatives considered
- **Reopen results with marks.** Rejected: the determination withholds
  result publication before implementation begins, and the stages are
  separate facts (§2.1).
- **Teacher entry first.** Rejected: RES-L2, E33 and the elective gap.
- **P3 inside Examinations.** Rejected: placement and enrollment semantics
  belong to Students/SIS (ADR 0038's seam discipline; the Attendance
  roster precedent).
- **Reuse the current roster for marks.** Rejected: it answers "now", so a
  historical mark would change meaning after a transfer or rollover.
- **A bearer-token marks API.** Rejected: bearer tokens carry no MFA
  assurance (ADR 0049).
- **Choose a retention period now.** Rejected: E21-D7 and the open E21
  ratification.

## 18. RES.1 as built (2026-10-06)

**P3 exists as a Students-owned read seam. Nothing consumes it yet;
StudentMark (RES.2) waits for RES-L0.** §1–§17 are unchanged; the dated
clarifications below record how §5 was realized.

### 18.1 The seam
`App\Domain\Students\Application\SubjectOfferingEligibilityReadService`:
- `eligibilityAsOf(School, studentId, subjectOfferingId, Y-m-d)` — the plain
  read;
- `lockEligibilityAsOf(...)` — the same answer, inside the caller's open
  transaction (`LogicException` otherwise), holding `FOR SHARE` on the
  Offering row, then every placement of the Student covering D in the
  Offering's year (id order), then every elective row of the Student for the
  Offering covering D (id order).

It returns `SubjectOfferingEligibility`: eligible (`required` / `elective`,
the placement id, its Section id, and for an elective the
`student_subject_enrollments` id), or not eligible with one closed reason:
`student_not_found`, `offering_not_found`, `outside_academic_year`,
`no_placement`, `no_elective_enrollment`, and the integrity reasons
`elective_unanchored`, `ambiguous_history`, `inconsistent_record`
(`isIntegrityFailure()`). An invalid date (`Y-m-d` only) is an
`InvalidArgumentException`. Ids only: no Student data in the answer. No
table, migration, route, capability, UI, event or cache.

### 18.2 §5.5 answered from the code
- **Elective anchoring on placement transfer:** none.
  `StudentSubjectEnrollmentService::enroll()` anchors a row to the Student's
  then-active placement in the Offering's context; `StudentEnrollmentService`
  (transfer, complete, withdraw, cancel) never touches
  `student_subject_enrollments`. After a transfer the elective row keeps its
  original, ended anchor, so §5.2's rule (a matching placement covering D,
  not the anchor itself) is the correct one. A cross-campus transfer leaves
  the elective row `active` but the Student is no longer in the Offering's
  context: `no_placement` from the transfer date.
- **Cancelled intervals count.** `StudentEnrollmentRosterReadService`'s
  predicate has no status filter, and
  `StudentEnrollmentRosterReadServiceTest::the_predicate_is_temporal_not_status_based`
  pins a cancelled placement on the roster for its interval. P3 follows it,
  for placements and elective rows alike.

### 18.3 Clarifications of §5
- **The date must fall inside the Offering's AcademicYear** (inclusive;
  `outside_academic_year`). An Offering belongs to one year, `ends_on` is
  NOT NULL, and Examinations already requires its dates inside the year
  (`EXAMINATIONS.md` §8). This also keeps a placement left open after its
  year (rollover never completes the source row) from answering for the
  next year.
- **One place per date:** two placements of the Student covering D in the
  Offering's year, or two elective rows for the Offering covering D, are
  `ambiguous_history`, whatever their context (stricter than
  `membersAsOf()`, which can only see one Section).
- **Consistency checks** (`inconsistent_record`): the placement's Section row
  must agree with the placement's context columns (application-kept, not a
  foreign key); the elective row's year must equal the Offering's (the
  Offering foreign key pins only the School); the anchor must be the same
  Student's placement in the Offering's context.
- **The Offering's current status is ignored** (an Offering inactive today
  is answered for its historical dates); the current roster
  (`SubjectOfferingRosterReadService`) still answers only "now".
- **The source follows the Offering's current `is_required`.** It is
  mutable through the Offering update route and has no history; the locking
  variant serializes with a concurrent change, but a later flip changes
  later answers. Recorded as a RES.2 review item.

### 18.4 Concurrency
Students writers lock Section → placement and Offering → elective row; the
seam's Offering → placement → elective order cannot form a cycle with them.
Row locks cannot stop a *new* overlapping row being inserted (a phantom),
as for Attendance; the one-active-row indexes and the Offering lock taken by
elective enrollment and switch narrow that to backdated inserts, which the
next read reports as `ambiguous_history`.

### 18.5 Proof
- **Behaviour (`SubjectOfferingEligibilityReadServiceTest`, 15 tests):**
  required eligibility on any Section of the grade; campus and grade
  context; Section and campus transfer boundaries; rollover and year bounds;
  withdrawal and cancelled-interval boundaries; elective start, end and
  switch boundaries; no re-anchoring on placement transfer; a wrong-context
  anchor and a legacy unanchored row fail closed (the current roster still
  admits the latter; P3 does not); an inactive Offering is still answered;
  another School's Student or Offering is never answered; overlapping
  history and a year mismatch fail closed; the locking variant gives the
  same answers and holds `RowShareLock` on exactly the three tables; dates
  are strict.
- **Concurrency (`SubjectOfferingEligibilityConcurrencyTest`)**, two real
  processes with an observed lock wait: an elective withdrawal, and a
  backdated placement transfer, each wait for the held locking read and
  commit after it. A mutation that drops the locks fails both; a mutation
  that adds a status filter fails the behaviour tests.
- **Guard (`SubjectOfferingEligibilityArchitectureGuardTest`):** the seam
  lives in Students and references no Examinations code; it has no caller
  or route; no StudentMark, mark-correction, result, report-card or
  transcript class, table, route or `examinations.marks.*` /
  `examinations.results.*` capability exists.

## 19. Amendment — RES-L0 determination (7 October 2026)

*Dated amendment. §1–§18 stay as written for their date; where this section
differs, it controls.*

### 19.1 Outcome
The Lead Privacy Counsel & DPO (India Operations) determined RES-L0
**CURRENT WITH CHANGES** on 7 October 2026
(`docs/security/RES-L0-STUDENTMARK-REVALIDATION-DETERMINATION.md`). The
3 September 2026 determination stays current **only** for School-internal
StudentMark recording and maintenance by specifically authorised
administrative / internal staff, under the conditions below.

**Effect:** RES.2 is authorised for **development**. Production stays blocked
by RES-L1; retention stays unresolved (RES-L8); teacher processing stays
governed by RES-L2 and ADR 0063 §40 / E33.

### 19.2 Binding requirements for RES.2 onward
Each RES-L0 condition (determination §4) is a contract requirement. Most
restate §6–§15; the new or tightened ones are marked **(new)**.

| # | Condition | Contract requirement |
|---|---|---|
| 1 | Highly Sensitive | §10, unchanged |
| 2 | Deny by default | No marks access without an explicit `examinations.marks.*` capability; a missing grant, membership, MFA window or processing basis refuses (§9, §19.3 b) |
| 3 | Explicit roles / capabilities | Separate leaves for read, enter, lock, correction request and correction approval; administrative roles only (§9.1) |
| 4 | School / tenant boundaries | Composite same-School keys, forced RLS, raw-SQL isolation tests (§6.1; rule 28) |
| 5–6 | Audit creation, modification, sensitive access; actor, action, record, time | Every write and every marks read is audited School-side with actor, action, record ids and time; values never in audit metadata (§13) |
| 7 | History never silently destroyed | **(new)** every write keeps value history, before lock too (§19.3 a) |
| 8 | No generic exposure | **(new, explicit)** marks never appear in generic search, list or summary endpoints, reports, Analytics read models, Group reports, exports, AI tools, the outbox, webhooks or any `/api/v1` bearer-token route; only the dedicated session marks routes (§4.1, §13) |
| 9 | No unrelated reuse | Marks feed nothing else in RES.2 (no results, rollover, promotion, eligibility, analytics; §8) |
| 10 | Protected dev / test data | **(new)** fixtures, demo seeders and factories use synthetic data only; no import path for production marks; identifiable production marks never in a development or test environment without specific authorisation |
| 11 | Data minimisation | Projections carry ids, roll number, display name, status and value only, for the requested paper; no extra Student data; nothing derived (§8) |
| 12 | Safe errors, telemetry, logs | **(new, explicit)** validation errors, exceptions and logs never echo a mark value, Student name or remark; no marks data in metric labels or traces |
| 13 | Bulk access / export separately authorised | **(new)** §19.3 c |
| 14 | Exceptional override restricted and audited | **(new)** §19.3 d |
| 15 | ADR 0038 provenance | §6.1, §6.2, unchanged |
| 16 | Withdrawal does not resolve retention | §12 and §19.3 b; nothing deletes or invalidates marks on withdrawal |

### 19.3 Conflicts resolved (RES.0B assumptions that change)
- **a. Pre-lock history (R8 tightened by condition 7).** Before lock, marks may
  still change, but **no write overwrites history**. Every create and change,
  before or after lock, appends an insert-only history record (the mark, the
  previous and new status and value, the actor, the time, and the qualifying
  authorization). The record lives in an RLS-protected, Highly Sensitive,
  insert-only table, never in audit metadata. Post-lock changes additionally
  need maker/checker (§7.3, unchanged). RES.2 builds the pre-lock history;
  RES.3 adds the lock and corrections on the same model.
- **b. Withdrawal (determination §3; ADR 0038 assumption qualified).**
  - A Student with **no currently qualifying authorization** gets no new mark
    and no change to an existing mark (the §6.2 seam refuses).
  - Existing marks are **neither deleted nor invalidated**; their provenance
    still names the authorization they were recorded under.
  - **Continued access needs an independently valid basis.** RES.2 therefore
    **withholds** that Student's marks from every ordinary read (shown as
    "processing basis unavailable", with no value), deny by default. Any
    other access is an exceptional path (d), which RES.2 does not build.
  - Retention, post-withdrawal use, archive, deletion and anonymisation stay
    with RES-L8 / RES-L3.
- **c. Bulk access and export (condition 13).** The per-paper entry grid
  (§6.3: one ExaminationPaper, read and written by `examinations.marks.*`
  holders, every read audited) is the ordinary administrative surface, not
  bulk access. RES.2 builds **no** export or file download, no cross-paper or
  School-wide marks read, no search and no report. Any such path needs a
  separate privileged capability, its own review, and RES-L0 re-review if it
  is a new disclosure.
- **d. Exceptional override (condition 14).** RES.2 builds **no** override: no
  unlock (§4.1), no bypass of the lock, the closed-year rule or the
  processing-basis check. A future override needs its own decision, a separate
  restricted capability, fresh MFA and a dedicated audit event.
- **e. Teacher separation (determination §6).** `examinations.marks.*` is never
  granted to the `teacher` role, and no administrative grant implies or
  bootstraps teacher authority. RES.4 (after RES-L2) uses a distinct
  owned-scope capability (§9.3).

### 19.4 Re-review triggers (determination §5)
No fixed expiry. Any slice that would introduce one of these **stops** and
requests RES-L0 revalidation before implementation:
- a material change of StudentMark purpose or scope;
- Student or Guardian access;
- result publication, report cards or transcripts;
- teacher marks entry or assigned-teacher processing;
- analytics, AI / ML, automated decision-making or profiling;
- a new external integration, API disclosure or third-party recipient;
- a material change to ADR 0038 or the processing-authorization model;
- a material change in law, regulatory guidance or binding School obligations;
- a new jurisdiction with materially different requirements;
- a material privacy or security incident exposing a weakness in the model;
- RES-L8 retention requirements materially changing these assumptions;
- the RES-L1 production review identifying a material change.

### 19.5 Gates after this amendment
| Slice | Gate |
|---|---|
| RES.2 | **Authorised for development** (§19.2–§19.3); production: RES-L1 |
| RES.3 | Follows RES.2; same conditions |
| RES.4 | RES-L2; elective ownership; ADR 0063 §40 / E33; RES-L0 re-review (teacher processing is a trigger) |
| Results, report cards, transcripts, Student / Guardian access | Not sequenced: RES-L4 – RES-L7 and RES-L0 re-review |

Retention for every RES table stays `policy_unresolved` until RES-L8 (§12).

## 20. RES.2 as built (2026-10-07)

**Internal, administrative StudentMark entry exists for development. No
lock, correction, teacher entry, result, publication, report card,
transcript or Student/Guardian access exists. Production stays blocked by
RES-L1.** §1–§19 are unchanged; this records how §6–§15 and §19 were
realized.

### 20.1 `subject_offerings.is_required` (§18.3), decided
- **Finding:** the `/api/v1` Offering `PATCH` (`academics.subjects.manage`)
  can flip `is_required` at any time, with no history; only a grouped elective
  is protected (`subject_offerings_required_group_check`). The same exposure
  pre-dates RES (Teaching Assignments, Curriculum Delivery).
- **Resolution in RES.2:** each mark **snapshots its eligibility source**
  (`required` / `elective`), database-checked against the elective-row
  reference (`student_marks_source_shape_check`), and every revision copies
  it. A recorded mark is never re-derived. A later edit re-runs P3 under the
  current flag and fails closed if the meaning changed.
- **Not changed:** Academic Structure's update rule (another module's product
  decision). Recommended follow-up: refuse an `is_required` flip once an
  Offering has enrollments, papers or marks.
- **Same reasoning, Examinations' own table:** once a paper has a mark, its
  `max_marks` and `scheduled_on` (the P3 date) are frozen by
  `examination_papers_freeze_when_marked` (409 `EXAMINATION_PAPER_HAS_MARKS`).

### 20.2 Schema (`2026_12_06_090000_create_student_marks_tables`)
- **`student_marks`**, one per School × paper × Student
  (`student_marks_one_per_student_paper`):
  - status `present` / `absent` / `exempt`; `value numeric(6,2)` — present ⇔
    value, value ≥ 0 (CHECK), value ≤ the paper's `max_marks` (trigger);
  - provenance: the P3 placement through `(id, school_id, student_id,
    academic_year_id)`; `eligibility_source`; the elective row; the ADR 0038
    authorization through the registry's own key `(id, school_id,
    student_id, purpose)` with `processing_purpose = 'academic_records'`, so
    the database proves it is this Student's academic-records grant;
  - `version` (exactly +1 per write, trigger); `recorded_by_user_id` (the
    latest writer);
  - immutable School, paper, Student and year (trigger); the placement must be
    in the paper's campus and grade, the elective row the Student's row for
    the paper's Offering (trigger);
  - all foreign keys composite and RESTRICT; forced RLS; runtime DELETE
    revoked; no remark, grade, percentage, pass/fail, rank or publication
    column (guard-pinned).
- **`student_mark_revisions`:** written only by the `student_marks` trigger
  (a direct insert is refused: `pg_trigger_depth`), one per write with the
  previous and new status and value, the provenance and the actor. Even a raw
  `UPDATE` cannot skip it. Forced RLS; runtime UPDATE and DELETE revoked.
- **Retention:** both anchored (`retention_recorded_at`, links tracked) and
  delete-guarded; catalogued `student_marks` = **`policy_unresolved`** (RES-L8)
  — the one deliberate exception in `TenantClosureReadinessTest`. Their keys keep
  the Student, placements, elective rows and authorizations
  `dependency_blocked` (classified in `StudentRetentionClassificationTest`).
  Actors are `RETAIN_REFERENCE`. The forced-RLS count is 202.

### 20.3 Write path (`StudentMarkService::record`)
Per request: one paper, many Students, atomic. The paper and its year are
held `FOR SHARE` (inactive paper: 422; closed year: 409). Per Student, in id
order:
1. P3 `lockEligibilityAsOf()` on the paper's date (not eligible: 422 with the
   closed reason);
2. ADR 0038 `lockQualifyingAuthorizationIdForStudentId()` (none: 422
   `STUDENT_MARK_PROCESSING_BASIS_UNAVAILABLE`);
3. the mark `FOR UPDATE`, then an **optimistic version check** (a new mark
   expects none; a change names the version it replaces: 409
   `STUDENT_MARK_VERSION_CONFLICT`);
4. the write; the database appends the revision.

Database errors are translated so no SQL, binding or value leaves the
service. **No `idempotent` middleware** (rule 29 evaluated): a replay meets the
version guard and re-runs every eligibility and basis check (the TCH.4
precedent), and a stored response would hold Highly Sensitive data (rule 36).

### 20.4 Read path (`StudentMarkReadService::grid`)
One paper's rows: every P3-eligible Student (Students' new
`eligibleStudentsAsOf()`) plus every Student with a mark, each minimal (ids,
roll number, display name through Students'
`StudentPlacementDisplayReadService`). A Student without a **current**
processing basis (`authorizedStudentIds()`) shows
`processing_basis_unavailable` with no status, value or version; the mark
stays recorded and valid (§19.3 b).

### 20.5 Surface and authorization
- `GET` / `PUT /app/examination-papers/{paper}/marks`: session JSON only,
  `capability:examinations.marks.view` / `.manage` + `mfa` (401
  `mfa_step_up_required` without the window); another School's paper is 404.
  Validation is manual: field names only, never a value, never a session
  flash.
- Capabilities `examinations.marks.view` / `.manage`, seeded to `school_admin`
  and `principal` (the administrative holders of every other `examinations.*`
  key and of the processing-authorization registry). Never `teacher`; no
  `examinations.results.*`.
- Students exposes three new Application seams, all id-based, so Examinations
  never loads Students models: `lockQualifyingAuthorizationIdForStudentId()`
  and `authorizedStudentIds()` (ADR 0038 registry), and
  `eligibleStudentsAsOf()` (P3), plus the display read above.

### 20.6 Audit, events, privacy
- Audit: `examinations.student_mark.recorded` / `.changed` (mark, paper,
  Student, version) and `examinations.student_marks.viewed` (paper, row and
  withheld counts) — ids and counts only.
- No outbox event, webhook, Analytics, Group report, AI, export, search or
  bulk path (guard-pinned). Synthetic fixtures only. No override or unlock.

### 20.7 Proof
- Behaviour, HTTP, raw-SQL RLS and structural tests (`StudentMarkServiceTest`,
  `StudentMarkHttpTest`, `StudentMarksRlsIsolationTest`,
  `StudentMarkArchitectureGuardTest`).
- Real processes (`StudentMarkConcurrencyTest`): two editors (update and
  create), entry behind a withdrawal, transfer behind an entry. Mutation
  checks: removing the version guard fails T1; bypassing the authorization
  re-check fails T2. Same-Student serialization comes first from the ADR 0038
  Student lock and the mark's foreign-key share locks; the mark row lock and
  P3's `FOR SHARE` (which closes the check-to-insert window) are defense in
  depth that this harness cannot isolate.
- Guards amended deliberately: `ExaminationArchitectureGuardTest` (an exact
  allow-list of Students seams, the StudentMark writer and row locks only in
  StudentMark files); `SubjectOfferingEligibilityArchitectureGuardTest`
  (exactly the two StudentMark services as P3 consumers; nothing from RES.3
  on). Count pins: user references 108 / 98 retained; RLS 202.

## 21. RES.3 as built (2026-10-07)

**A paper's marks can be locked, one way, and a locked mark changes only
through a correction that one administrator requests and a different
administrator approves. Development only; production stays blocked by
RES-L1.** No teacher entry, result, publication, report card, transcript,
Student/Guardian access, unlock, reopen or bypass exists. §1–§20 are unchanged
except §7.2's "insert-only" (§21.3). No §19.4 re-review trigger was hit: the
purpose, actors (administrative only), processing basis and surfaces are
those RES-L0 already covers.

### 21.1 Schema (`2026_12_07_090000_create_student_mark_lock_and_corrections_tables`)
- **`examination_paper_mark_states`** — one per School × paper
  (`examination_paper_mark_states_one_per_paper`), composite FK to the paper:
  - `state` `open` / `locked`; `locked ⇔ locked_by_user_id and locked_at`
    (CHECK);
  - a missing row means `open`; a row is created when the paper is locked;
  - the paper never changes, and a `locked` row never changes at all (trigger:
    **no unlock** at the database).
  This is a separate fact; `examination_papers.status` is untouched (§7.1).
- **`student_mark_corrections`** — the request and its decision:
  - the mark, its paper and Student (composite FKs);
  - `base_version` and the previous status/value (the mark's at request time,
    trigger-checked);
  - the proposed status/value (R3 shape by CHECK, ≤ `max_marks` by trigger;
    it must change something);
  - `reason_code` — the closed catalogue `entry_error`, `totalling_error`,
    `status_error` (CHECK; no free text);
  - requester and `requested_at`, with the ADR 0038 authorization qualifying
    at request time;
  - `status` `pending` → `approved` | `rejected`, the decider and
    `decided_at`; an approval also records the authorization qualifying at
    approval time (both through `spa_context_unique`, purpose
    `academic_records`).
- **Database rules:**
  - **maker ≠ checker** (`student_mark_corrections_maker_checker_check`);
  - **one pending request per mark** (partial unique);
  - a request is inserted `pending`, only for a locked paper, and only
    against the mark's current version and values;
  - its request fields never change;
  - a decided row never changes;
  - an approval is accepted only if the mark already holds exactly the
    proposed values at `base_version + 1`.
- **On `student_marks` (new):**
  - `student_marks_lock_guard`: on a locked paper, an insert is refused and
    an update is allowed only when a pending correction for exactly that
    version and those values exists;
  - the deferred constraint trigger `student_marks_locked_change_approved`:
    by commit, that correction must be approved.

  A raw write, even matching a pending request, cannot leave a locked mark
  changed (proven with `SET CONSTRAINTS ALL IMMEDIATE` and in real
  committing processes). The existing history trigger records the correction
  as revision `n + 1` like any write.
- **Both tables:**
  - forced RLS; runtime DELETE revoked;
  - anchored (`retention_recorded_at`) and delete-guarded;
  - catalogued under `student_marks` = `policy_unresolved` (RES-L8);
  - classified as keeping their Student and authorization;
  - actors (locker, requester, decider) are `RETAIN_REFERENCE`;
  - the forced-RLS count is 204.

### 21.2 Paths
- **`StudentMarkLockService::lock`:**
  - takes the paper `FOR UPDATE`, then its state row;
  - a second lock is a 409 (`STUDENT_MARKS_ALREADY_LOCKED`), never a second
    transition;
  - permitted on an inactive paper and in a closed year.
- **`StudentMarkService::record`** (RES.2 entry) now refuses a locked paper
  (409 `STUDENT_MARK_PAPER_LOCKED`) right after taking the paper `FOR SHARE`.
  The database refuses it too.
- **`StudentMarkCorrectionService`:**
  - **`request`** — the paper must be locked (409
    `STUDENT_MARK_CORRECTION_PAPER_NOT_LOCKED`). Then:
    1. P3 is re-run under lock and must give exactly the stored placement,
       source and elective row (else 422
       `STUDENT_MARK_CORRECTION_CONTEXT_CHANGED`);
    2. the current ADR 0038 basis must exist (else 422
       `STUDENT_MARK_PROCESSING_BASIS_UNAVAILABLE`);
    3. the mark is taken `FOR SHARE` at the named version (else 409
       `STUDENT_MARK_VERSION_CONFLICT`).

    A second pending request is a 409.
  - **`approve`** — the request `FOR UPDATE` must be pending (else 409
    `STUDENT_MARK_CORRECTION_ALREADY_DECIDED`) and decided by someone else
    (else 403 `STUDENT_MARK_CORRECTION_SELF_DECISION`). Then P3 and the basis
    are re-run, and the mark is taken `FOR UPDATE` at `base_version`. The
    change goes through `StudentMarkService::applyCorrection()`, the one
    StudentMark writer: version +1 exactly once, with the approval's basis
    and the approver as the latest writer.
  - **`reject`** — the same pending and maker ≠ checker rules. It needs no
    current basis, because it processes no mark value.
  - A stale approval is refused and the request stays pending for a reviewer
    to reject. One pending request per mark makes this reachable only under
    concurrency (T5).
- **Closed year (§7.4):**
  - ordinary entry stays refused;
  - the lock, the request and the approval are permitted.
- **No basis:**
  - neither a request nor an approval proceeds;
  - the grid withholds the mark **and** its pending correction exactly alike
    (`{withheld: true}`).

### 21.3 §7.2 amended: one guarded transition instead of "insert-only"
§7.2 asked for an insert-only correction record. RES.3 records the request and
its decision in **one row**, following the FEE concessions precedent §7.3
cites (`fee_concessions`: requester and decider on one row). The runtime role
may make exactly one `UPDATE` per row: `pending` → `approved` | `rejected`.
The database refuses any change to the request fields, any change to a
decided row, a decision by the requester, an approval that does not match the
mark, and every DELETE. Nothing is ever overwritten. A rejection, and a new
request after it, are both kept. §7.2's alternative is taken: the mark keeps
a database-maintained current value, and every post-lock change is proven by
an approved correction row. That proof is checked by the database by commit,
and the history trigger records it as a revision.

### 21.4 §7.4 realized: no correction of an unlocked mark
A correction is only for a locked paper. On a closed year's **open** paper,
the correction path is reached by locking the paper first, since the lock
stays available after close (tested). §7.4's "whether or not its paper was
locked" is met without a second, unlocked correction path.

### 21.5 Canonical lock order
Shared by entry, lock, request and approval. Every path takes a subset of
these locks, in this order:
1. `examination_papers` row — `FOR SHARE` for entry, request and approval;
   `FOR UPDATE` for the lock;
2. `examination_paper_mark_states` row (lock only, `FOR UPDATE`);
3. `academic_years` `FOR SHARE` (entry only);
4. `student_mark_corrections` row `FOR UPDATE` (decisions);
5. P3: Offering, then placements, then elective rows, `FOR SHARE`;
6. ADR 0038: Student `FOR UPDATE`, then grants, then relationship;
7. `student_marks` — `FOR UPDATE` for entry and approval, `FOR SHARE` for a
   request.

Entry is per Student in Student-id order, inside one transaction.

### 21.6 Surface, capabilities, MFA, audit
- **Session JSON routes only** (no `/api/v1`). Every route composes
  `capability:` with `mfa`:
  - `POST app/examination-papers/{paper}/marks/lock`
    (`examinations.marks.lock`);
  - `POST app/examination-papers/{paper}/marks/{mark}/corrections`
    (`examinations.marks.correction.request`);
  - `POST app/student-mark-corrections/{correction}/approve` and `/reject`
    (`examinations.marks.correction.approve`).
- **Fresh MFA:** locking and deciding also re-verify a fresh code
  (`FreshMfaRequirement`, 422 `mfa_code`). A request needs only the `mfa`
  window.
- **Ids:** UUID-constrained. Another School's paper, mark or correction, and
  a mark of another paper, are 404. Responses carry ids and state only.
- **Capabilities:** the three new keys are seeded to `school_admin` and
  `principal` only. Never `teacher`; no unlock, reopen or results key
  (guard-pinned).
- **Idempotency:** no `idempotent` middleware (rule 29 evaluated). A retried
  lock or decision is a 409, and a retried request meets the pending-unique
  rule.
- **Audit** — ids, versions and the closed reason only, never a value or
  status:
  - `examinations.student_marks.locked` (paper, mark count);
  - `examinations.student_mark_correction.requested` (correction, mark,
    paper, Student, base version, reason);
  - `.approved` (… new version);
  - `.rejected`.

  The grid read's audit adds a pending-correction count.
- **No outbox, webhook, Analytics, AI, export or search.** Synthetic fixtures
  only.

### 21.7 Proof
- **Behaviour, HTTP and database tests:**
  - `StudentMarkCorrectionServiceTest`, `StudentMarkCorrectionHttpTest`;
  - `StudentMarkCorrectionsRlsIsolationTest` — raw-SQL RLS, no
    DELETE/unlock, direct-write refusal, maker/checker, terminal decisions,
    request immutability.
- **Real processes** (`StudentMarkCorrectionConcurrencyTest`):
  - T1 lock vs entry, both directions;
  - T2 two approvals, and approval vs rejection;
  - T3 approval behind a withdrawal;
  - T4 a request that waited for the lock;
  - T5 a stale-version request behind an approval.
- **Mutation checks, each caught:**
  - no `FOR UPDATE` on the request → T2: the loser is refused by the version
    guard or the database instead, and the mark still advances once (defense
    in depth);
  - no `FOR UPDATE` on the paper in the lock → T1 and T4: overlap is no
    longer serialized;
  - no request version check → T5;
  - no approval basis re-check → T3.
- **Guards amended deliberately:**
  - `StudentMarkArchitectureGuardTest`: column pins for both tables, one
    writer per table, six exact routes, five exact capabilities, still
    administrative only;
  - `SubjectOfferingEligibilityArchitectureGuardTest`: the correction service
    is the third P3 consumer; the RES.3 files are an exact allow-list; nothing
    from RES.4 on;
  - `ExaminationArchitectureGuardTest`: the two new writers.
- **Count pins:** user references 111 / 101 retained; RLS 204.

### 21.8 Recorded follow-up (not RES.3)
`subject_offerings.is_required` (§20.1) stays mutable; RES.3 does not reopen
it. Recommended to Academic Structure: **consider preventing required/elective
flips once an Offering has dependent enrollment, examination-paper or mark
evidence.** Until then, a flip after marking makes the affected marks
uncorrectable (`STUDENT_MARK_CORRECTION_CONTEXT_CHANGED`, fail-closed, tested)
rather than silently re-derived.

### 21.9 Gates after RES.3
| Slice | Gate |
|---|---|
| RES.2, RES.3 | **Complete, development only**; production: RES-L1 |
| RES.4 (teacher entry) | **Not authorised:** RES-L2; ADR 0063 §40 / E33; RES-L0 re-review (teacher processing is a §19.4 trigger) |
| Results, report cards, transcripts, Student / Guardian access | Not sequenced: RES-L4 – RES-L7 and RES-L0 re-review |

## 22. RES.4 readiness — teacher StudentMark gates (2026-10-07)

**Documentation only. RES.4 remains NOT AUTHORISED.** This section records
the gate analysis, the ownership audit and the contract a future RES.4 would
have to meet. It changes no code, capability, role, route or test, and no
legal-register status: no authority response exists. §1–§21 are unchanged.

### 22.1 The three gates, as recorded
| Gate | Register | What it is | State |
|---|---|---|---|
| **RES-L2** | E37 | May assigned teachers process StudentMark? | **LEGAL_REVIEW_REQUIRED** — blocks RES.4 development and production. Request drafted: `docs/security/RES-L2-TEACHER-STUDENTMARK-REVIEW-REQUEST.md` (not sent) |
| **RES-L0 re-review** | E35 | The 7 October 2026 determination covers administrative staff only (its §6); teacher processing is a §5 / §19.4 trigger | **Re-review required** before RES.4. Request drafted: `docs/security/RES-L0-TEACHER-STUDENTMARK-REVALIDATION-REQUEST.md` (not sent) |
| **E33 / TCH-L1** | E33 | Teacher **Attendance** in production (ADR 0063 §26, §39); by ADR 0063 §40, no production `teacher` role grant while open | **OPEN** — production only. Request drafted: `docs/security/TCH-L1-TEACHER-ATTENDANCE-REVIEW-REQUEST.md` (not sent) |

**E33 is independent of RES-L2.** E33 asks about identifiable attendance;
RES-L2 about marks. Neither answer decides the other, and the requests ask
for separate recorded outcomes. E33 concerns RES.4 only through ADR 0063 §40:
the single production `teacher` role bundles `attendance.teacher`, so no
teacher of any kind is granted in production while E33 is open. On the
existing record, E33 **does not block development** (E33 row; RES-L0
determination §6: "for production, ADR 0063 §40 / E33"); the RES-L2 request
asks the authority to confirm this.

### 22.2 What exists today (ownership audit, `1724fa4`)
- **`teaching_assignments`** (ADR 0063 §9): one row = Employee × Section ×
  **required** SubjectOffering × an inclusive School-local date range
  (`starts_on`, nullable `ends_on`); dated and historical (an ended row keeps
  its final `ends_on`, immutable by trigger). **No kind column** (no
  lead / co-teacher / assistant / cover / substitute). Overlap is refused per
  Employee only (service advisory lock), so several Employees may hold the
  same Section × Offering at once. Elective Offerings are refused
  (`RequiredOfferingOnlyException`).
- **`TeachingOwnership::hold(School, employeeId, sectionId,
  subjectOfferingId, date)`**: as-of a stated date, `FOR SHARE`, inside the
  caller's transaction, true only for exactly one covering row. It does not
  re-read the Offering. Consumers: Attendance, Curriculum Delivery, LMS.
  Examinations has none, and `TeachingAssignmentArchitectureGuardTest` forbids
  it until RES.4 amends that guard deliberately.
- **`ActingEmployeeResolver::hold(User, School, asOf)`**: School operational →
  active membership → enabled User → linked active Employee → exactly one
  eligible employment on `asOf`, `FOR SHARE`; every failure is the same 403.
- **P3** already returns the Student's placement `sectionId` and
  `studentEnrollmentId` on the paper's date; StudentMark stores the placement.
- **ExaminationPaper is Offering-wide** (no Section, no teacher), so one paper
  spans several Sections: teacher authority must be decided **per Student**
  (R12), never for the paper.
- **No code yet joins** a Student's P3 placement Section with
  `TeachingOwnership::hold()`.
- **Roles:** `teacher` holds exactly `curriculum.delivery.teacher`,
  `attendance.teacher`, `lms.content.teacher`, `lms.assignments.teacher`; no
  role but `school_admin` and `principal` holds any `examinations.marks.*`
  key (guard-pinned).

### 22.3 Readiness by case
| Case | Technical readiness | Note |
|---|---|---|
| **Required subject, assigned teacher** | **READY** (building blocks exist; the join is RES.4 work) | P3 placement Section × paper Offering × `scheduled_on` → `hold()` |
| **Co-teacher** | **READY only as equal owners** | Several dated rows may cover the same Section × Offering × date; each holder passes `hold()`. No lead/assistant distinction exists: if the authority approves only some co-teachers, that case is **BLOCKED** (needs a kind fact) |
| **Cover / substitute / temporary** | **READY only as an ordinary dated assignment** | ADR 0063 §9: cover is a short dated TeachingAssignment, no substitute entity, indistinguishable from an ordinary one. If the authority excludes or distinguishes cover, that case is **BLOCKED** |
| **Ownership beginning or ending on the paper date** | **READY** | Inclusive dates; `hold()` on `scheduled_on` |
| **Elective subject** | **BLOCKED** | No authoritative dated elective teacher-ownership fact exists (ADR 0063 D-05; R13). `student_subject_enrollments` has no teacher; TeachingAssignment refuses electives. **Elective teacher marks entry remains technically blocked even if legal approval is obtained.** |

**Smallest elective prerequisite** (a separate, explicitly scoped slice with
its own ADR 0063 amendment, never part of RES.4): a dated, School-scoped
elective teaching-ownership fact — Employee × elective SubjectOffering ×
date range, with the same history/end rules as `teaching_assignments` — and
a lock-capable ownership read for it. Its effect on LMS elective Tier 1 (ADR
0063 §34) is decided there.

### 22.4 Ownership date (decided for the contract)
- **Ownership is judged on the paper's `scheduled_on`** — the date P3 and
  StudentMark already use (R12). Never "the current teacher".
- **The actor must be an eligible employee on the entry date** (the ADR 0063
  Attendance precedent: `ActingEmployeeResolver::hold()` as of today).
- **Open question to the authority** (RES-L2 Q5, Q10): whether ownership must
  also hold on the entry date. If so, RES.4 adds that as a second `hold()`;
  it narrows, it never replaces the paper-date check.

### 22.5 The owned-scope capability (documented, not created)
- **Key:** `examinations.marks.teacher` (the `<module>.<resource>.teacher`
  convention, ADR 0063 §13). Separate from `examinations.marks.view`,
  `.manage`, `.lock`, `.correction.request` and `.correction.approve`; never
  implied by any of them, and none implied by it.
- **Authorization predicate — every term required, none substitutes for
  another, all inside the write transaction:**
  1. the capability (`capability:examinations.marks.teacher`);
  2. a session-authenticated staff request (no bearer token, ADR 0049);
  3. the `mfa` window (or fresh MFA if the authority requires it);
  4. a verified ActingEmployee on the entry date (`ActingEmployeeResolver::hold`);
  5. a currently qualifying ADR 0038 processing basis for the Student;
  6. P3 eligibility on `scheduled_on` with source `required`;
  7. `TeachingOwnership::hold(employee, P3 sectionId, paper's Offering,
     scheduled_on)`;
  8. the paper `active`, its marks `open` (RES.3), its year not `closed`;
  9. the same School throughout (route-bound, RLS).
- **Read:** only owned Students' rows; unowned Students are not listed,
  counted or signalled; a paper with no owned Student answers the identical
  non-disclosing 404 (ADR 0063 §18).
- **Write:** through `StudentMarkService` only (one writer), with history,
  version guard and the existing audit; the acting Employee is recorded in
  audit (RES.4 decides whether also on the mark).
- **Lock order:** RES.4 inserts ActingEmployee and ownership holds into §21.5
  under ADR 0063's own order; decided in RES.4, not here.

### 22.6 Role / authorization decision table
Today = as built at `1724fa4`. RES.4 = only if authorised (§22.9), and only
within the authority's conditions.

| Actor | Marks read | Marks entry | Lock | Correction request | Correction approval | Results |
|---|---|---|---|---|---|---|
| `school_admin` | Yes (per paper; basis-gated) | Yes (open papers, open years) | Yes (fresh MFA) | Yes | Yes (not own; fresh MFA) | **None exists** |
| `principal` | Yes (same) | Yes (same) | Yes | Yes | Yes (not own) | None exists |
| Assigned required-subject teacher | Today **No**. RES.4: owned Students only | Today **No**. RES.4: owned Students, open papers | **No** | **No** | **No** | No |
| Co-teacher | Today No. RES.4: as assigned teacher **only if the authority includes co-teachers** | Same | No | No | No | No |
| Cover / substitute teacher | Today No. RES.4: as assigned teacher **only if the authority includes cover** and a dated assignment covers `scheduled_on` | Same | No | No | No | No |
| Elective teacher | **No** (technically blocked, §22.3) | **No** | No | No | No | No |
| Unrelated teacher (same School) | No (404) | No | No | No | No | No |
| Teacher from another School | No (404, RLS) | No | No | No | No | No |
| Student | **No** (RES-L7) | No | No | No | No | No |
| Guardian | **No** (RES-L7) | No | No | No | No | No |

### 22.7 Privacy conditions carried forward
Every RES-L0 condition (§19.2) binds teacher scope unchanged: Highly
Sensitive; deny by default; School isolation; capability checks; ADR 0038
provenance; audited reads and writes; immutable history; no generic search,
report or export; no analytics or AI/ML; no unrelated reuse; safe logs and
errors; no outbox or integration; no Student/Guardian access. Teacher scope
narrows access; it never widens the StudentMark platform.

### 22.8 Development vs production
- **Development authorization** for RES.4 (if ever given) covers
  development and test environments with synthetic data only.
- **Production teacher enablement** stays blocked while any of RES-L1 (E36),
  E33 / ADR 0063 §40, or a production condition of the new determinations
  applies. Development authorization is never production approval.

### 22.9 Outcome rule
RES.4 becomes **AUTHORIZED FOR DEVELOPMENT** only when all are true:
1. RES-L2 (E37) is determined AUTHORISED or AUTHORISED WITH CONDITIONS;
2. the RES-L0 teacher re-review (E35) is CURRENT or CURRENT WITH CHANGES for
   the same scope;
3. E33 is closed or expressly does not block RES.4 development (true on the
   existing record; to be confirmed by the authority);
4. the ownership facts for the authorised scope exist (§22.3).

If required subjects clear and electives do not, RES.4 is limited to
**required subjects**; elective entry waits for the §22.3 prerequisite slice.
**Today 1 and 2 are absent: RES.4 remains NOT AUTHORISED.**

## 23. Gate trace — E33 / TCH-L1 determined (2026-10-07)

**Docs only; §22 is unchanged and stays the controlling readiness record.**
- **E33 / TCH-L1:** **APPROVED WITH CONDITIONS** (Lead Privacy Counsel & DPO,
  7 October 2026; `docs/security/TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`;
  ADR 0063 §42). It concerns teacher **Attendance** only and expressly has
  **no effect on teacher StudentMark processing**: it does not satisfy
  RES-L2, the RES-L0 teacher re-review or any StudentMark processing basis,
  and no marks capability may inherit from an Attendance capability.
- **§22.9 gates re-evaluated:**

  | Gate | State |
  |---|---|
  | 1. RES-L2 (E37) | **Unresolved** — request not answered |
  | 2. RES-L0 teacher re-review (E35) | **Unresolved** — request not answered; the 7 October administrative determination stays administrative-only |
  | 3. E33 / ADR 0063 | **Satisfied for development** (the determination permits development; it was already production-only on record). For production, ADR 0063 §42.5 keeps production `teacher` grants closed until MFA and read-audit controls are evidenced |
  | 4. Ownership facts | Required subjects READY; electives BLOCKED (§22.3) |

- **Outcome: RES.4 — NOT AUTHORISED, blocked by RES-L2 (E37) and the RES-L0
  teacher re-review (E35).**
- **For a future RES.4 production case** (not authorised), the TCH-L1
  conditions are a useful precedent but not binding on marks; the marks
  conditions come from RES-L2, the RES-L0 teacher re-review and RES-L1.
