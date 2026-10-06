# ADR 0068: Assessment & Results Reopening Contract — P3 and Internal StudentMark

- Status: **Accepted (RES.0B, 2026-10-06; documentation only).** Owner
  decisions R1–R20 (§4) are adopted. **RES.1 implemented (2026-10-06,
  §18):** the P3 seam. **Amended 2026-10-07 (§19):** RES-L0 returned
  CURRENT WITH CHANGES; its conditions bind RES.2 onward, and RES.2 is
  authorised for development only (not production: RES-L1). No StudentMark
  code, schema, route, capability or UI exists.
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
