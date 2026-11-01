# SIS 11.13 — Bulk Student Operations Foundation (Section change & Campus transfer)

Status: **IMPLEMENTED (local DDEV)** · 2026-11-01

| Item | Value |
|---|---|
| Baseline | `main = f1d6fdcb` (E16-R4) |
| Reference | SR-09 bulk branch transfer (318-screen pack) |
| Operations | exactly two: **Change Section** and **Transfer Campus** |
| Schema | **none** (no table, no migration) |
| Capabilities | **none** (the existing `enrollments.manage`) |
| Background jobs | **none** (synchronous, bounded) |
| ADR | ADR 0076 §84 (answers the §44.14 / §44.25 bulk-transfer question) |
| Core SIS score | 96/100, not rescored (tracked under Advanced SIS) |

## 1. Current-state audit (before any change)

| Capability | Existing implementation | Reused by 11.13 | Gap closed |
|---|---|---|---|
| Student import | `StudentGuardianImport` (SIS.EXP-5): add-only creation of NEW Students | No (creation, not movement) | — (unchanged) |
| Placement write path | `StudentEnrollmentService` (the only sanctioned writer) | Yes | — |
| Section change | `transferPlacement()`: same year and Grade; same Campus = Section change; whole-School only (`/app/enrollments/{id}/transfer`, any effective date) | **Yes: the bulk Section change calls it per Student** | bulk |
| Campus transfer | `transferPlacement()` with blockers (whole School) or `CampusStudentTransferService` (dual Campus scope, today) | **Yes: the bulk Campus transfer calls them per Student** | bulk |
| Academic-Year rollover | `EnrollmentRollover*` services (cross-year, plan → dry run → execute) | No: cross-year moves are refused and pointed here | — (unchanged) |
| Roll numbers | Required on every placement; normalized; unique per School, year and Section (all statuses) | Yes (per-Student input; the database decides collisions) | — (no allocator) |
| Transfer blockers | `StudentTransferBlocker` tag: Hostel residency, Transport rider, elective | Yes (the same tagged set, asked by the primitive) | — |
| Lifecycle restrictions | `assertPlaceable()`: active Student, Section, Campus; year not closed; date in year | Yes (inside the primitive) | — |
| Campus scope | `StudentAccess`, `CampusScopeResolver`, `holdsInCampusNow()` | Yes | — |
| MFA / assurance | `FreshMfaRequirement::requireForAction()` | Yes (once per batch) | — |
| Audit | `student_enrollment.transferred` per move | Yes, plus one `student_enrollment.bulk_moved` | batch audit |
| Movement history | `StudentMovementRegister` over `student_enrollments` | Yes (a bulk move is ordinary history) | — |
| Student reports | SIS report registry | No change | — |
| Mass-selection UI | None (the import uploads a file) | — | selection table |

## 2. Decisions

- **Two operations only** (`BulkPlacementOperation`). Cross-year is Rollover;
  a Grade change is promotion (Rollover). No generic "run an action on
  selected Students".
- **One source class, one target class per batch.** It keeps a batch's row
  locks to one pair of Sections and the review readable.
- **Authority is exactly the single-Student authority:**

| Actor | Change Section | Transfer Campus |
|---|---|---|
| whole-School `enrollments.manage` (`school_admin`, `principal`) | yes, any valid date (as one Student) | yes, any valid date, with the named blockers (as one Student) |
| Campus-scoped `enrollments.manage` | **no** (a same-Campus Section change has always been whole-School, ADR 0076 §47) | yes, between two Campuses BOTH in scope, today, the active year, one generic refusal (as one Student) |
| Teacher, Admissions Officer, every other role | no | no |

- **ADR 0076 §44.14 / §44.25** ("no Campus bulk operation in v1"; "is a bulk
  Campus transfer wanted?") are answered by this checkpoint's authorization:
  bulk Campus transfer is adopted, for Campus actors only under the existing
  dual-scope single-transfer contract (ADR 0076 §84). Nothing a Campus actor
  cannot do for one Student becomes possible for many.

## 3. Architecture

`App\Domain\Students\Application\Bulk\BulkStudentPlacementService` is an
orchestrator. It owns **no placement rule**:

- whole School → `StudentEnrollmentService::transferPlacement()`;
- Campus scope → `CampusStudentTransferService::transfer()` (which itself
  calls `transferPlacement()` with the Campus guard).

The only change to those primitives is an optional `$batchId`, written to
the per-Student `student_enrollment.transferred` audit event when set (no
rule changes; single-Student calls are byte-identical). `StudentAccess`
gained `transferDestinationsFor()` (the existing destination query, keyed by
a Section context; the old method delegates).

The selection list is read in the Students domain too
(`BulkStudentPlacementService::sourceRoster()`: the canonical current
placement, `constrainToCurrentPlacement()`, or `StudentAccess` for a Campus
scope). The controller (`StudentBulkOperationController`) is thin: validation,
`authorizeCampusScope('enrollments.manage')`, the signature check, the fresh
MFA code, then the service. An architecture test pins that the bulk code
writes nothing itself, reaches no other module, queues nothing, reads no
file, and that the controller holds no placement rule.

## 4. Workflow: Select → Preview → MFA → revalidate → atomic execute

1. **Select** (`/app/students/bulk-operations`): the move, the source class
   (year, Campus, Grade, Section), Students (explicit checkboxes, "Select all
   shown", "Clear selection", a live selected count; a search narrows the
   list without dropping the selection, and says how many selected Students
   it hides), the target class, the effective date.
2. **Preview**: `POST …/preview` keeps the selection (ids and roll numbers
   only) in the session and redirects to `GET …/review`, which re-evaluates
   it on every visit. A refresh or Back re-checks; it never executes.
3. **Confirm**: only a preview in which **every** row is Ready can be
   confirmed: a checkbox, a fresh authentication code, one submit.
4. **Execute**: revalidated under locks; all or nothing.
5. **Result** (`…/result`): the move, from/to, effective date, every moved
   Student with old and new roll number, a link to the movement register.
   No undo: a correction is another governed move.

### Preview semantics

The preview runs the real primitives for every selected Student inside one
transaction that is **always rolled back**. Each answer is therefore the
authoritative one (blockers, roll numbers, two selected Students given the
same roll number, placement dates, active Section, Campus and year), and
nothing is committed: no placement and no audit row
(`the_preview_and_its_review_commit_nothing`). For that moment it takes the
same row locks a move takes.

### Eligibility states

| State | Meaning | Executable |
|---|---|---|
| Ready | the primitive accepted the move | yes |
| Already in target Section | the Student's current placement is the target | no |
| Changed since selection | the placement the operator saw is no longer the current one in the source class (the message names the new class only when the scope reaches it) | no |
| Blocked | the primitive refused: the human message of the existing rule (e.g. "This Student can't change Campus yet. First end or move: the active Transport rider assignment."); a row the actor may not see (another School, out of scope, unknown) is "not available" and shows no name | no |

Warnings are separate from states: only the advisory capacity warning.

### Capacity (SIS 11.3, advisory)

The review shows the target's occupancy now and after the move (current
strength plus the Ready rows; Students already in the target are not counted
twice), using `SectionOccupancy`. Over capacity is a warning and never blocks.

### Roll numbers

The placement contract requires one; there is no allocator, so none is
invented. Each row carries a roll number, pre-filled with the Student's
current one and editable on the review. Collisions are found by the database
inside the preview (and again at execution), including two selected Students
given the same number. Changing a number requires "Check again" before
confirming.

## 5. Execution, atomicity and concurrency

Inside one transaction:

1. the School is operational (`SchoolOperationalGuard`);
2. the scope comes from `CampusScopeResolver` and its authority is re-read
   **fresh** (`holdsInSchoolNow()`, or `holdsInCampusNow()` over both
   Campuses) — a grant revoked since the preview refuses (403);
3. the signed preview (HMAC over School, actor, scope kind, issue time and
   the exact rows; 30 minutes) is checked again;
4. every selected Student is share-locked **in id order**, then each row is
   re-classified and moved **in id order** (each move: year, both Sections
   in ascending id order, the placement);
5. one `student_enrollment.bulk_moved` audit event.

Any refusal (a Student moved since the preview — the placement id the
preview saw is no longer current —, a new blocker, a taken roll number, a
lock conflict) rolls the **whole batch** back. The operator returns to the
review with "No Students were moved. <Student>: <reason>" and a fresh
evaluation. A repeated confirmation finds every Student already in the
target and moves no one twice.

Proven in genuinely separate processes, with the contender positively
observed blocked on the holder's lock (`BulkStudentPlacementConcurrencyTest`):
a single transfer committed first refuses the whole batch; two overlapping
batches have one outcome; a batch committed first refuses a single transfer
of its Student; opposite-direction batches on the same classes serialize
without deadlock; a Student moved by another process between preview and
execution refuses the batch.

## 6. Security review

| Threat | Control |
|---|---|
| Forged Student or placement ids | UUID validation (422); unknown, foreign or out-of-scope rows are "not available", show nothing, and are never executable |
| Forged target or source class | must be this School's and in scope, else one generic refusal; another year / Grade is refused with the Rollover pointer |
| Scope escalation | the Section change is whole-School; a Campus transfer needs both Campuses; execution re-reads authority fresh |
| Duplicate ids | validation error; the service refuses duplicates too |
| Stale preview / replay | placement-id binding, signature over the exact rows, 30-minute expiry; a replay moves nobody twice |
| Partial transaction | one transaction; any refusal rolls everything back |
| CSRF | POST only, Laravel CSRF; GET pages never mutate |
| Assurance bypass | fresh MFA code checked after authority and signature, outside the business transaction; one code per batch |
| Blocker bypass | the primitive asks the same tagged blockers (`bulk_asks_the_very_same_registered_blockers_as_a_single_transfer` registers a test blocker and sees bulk honour it) |

## 7. Boundaries (not built)

Import redesign, Admission conversion, inactivate/reactivate, lifecycle
approval, House/Club/Activity assignment, document verification, Attendance
changes, Guardian operations, cross-School transfer, promotion or rollover
replacement, certificates, CSV input, a generic action engine, a job
subsystem, export or print of the review/result, a Student 360 card, an
undo. Transport, Hostel, electives, Communities, Finance, Documents and
Attendance records are never touched: blockers still have to be resolved in
their own module first.

## 8. History and audit

Each move is an ordinary transfer: the source placement ends the day before
the effective date (`transferred`), a new placement starts on it, and the
movement register shows one Section-change or Campus-transfer row per
Student. The batch event carries ids, codes and counts only (operation,
Students, year, Grade, from/to Campus and Section, date, scope, MFA time,
batch id); each per-Student event carries the same batch id. No Student name
is ever written to the batch event.

## 9. Performance and batch size

Measured on DDEV (`BulkStudentOperationsPerformanceTest`):

| Measure | Value |
|---|---|
| Statements per Student (preview) | 18: the primitive's own (locks, checks, end, insert, two audit rows) plus its tenant-context set/restore and the reload of the placement the operator saw |
| Fixed statements per preview | ≤ 30 (selection, labels, scope, capacity), constant in batch size |
| 100 Students, preview | ≈ 1.1 s |
| 100 Students, execute | ≈ 1.1 s |

**Maximum: 100 Students per batch.** A Section rarely exceeds 60 Students,
so 100 covers a full class with headroom; at about 11 ms per Student the
execution holds its two Section row locks for about a second (Attendance
submission for those two classes waits that long), and a review of 100 rows
is still usable. Synchronous execution is therefore adequate: no queue.

## 10. Reference reconciliation (318-screen pack)

| Reference capability | Status after 11.13 |
|---|---|
| Bulk branch (Campus) transfer (SR-09) | **Implemented** (same year and Grade; the existing transfer contract and blockers) |
| Bulk Section assignment within a class | **Implemented** (bulk Section change) |
| Target year different from source | **Different, accepted**: Academic-Year Rollover |
| Shuffle / transfer log | **Equivalent**: movement register, per-Student history, batch audit |
| Student import / uploaded-details review (SR-06) | Already existing (SIS.EXP-5); unchanged |
| Bulk inactive/reactivate (SR-10) | Not adopted (direct assured action, ORG.8E) |
| Bulk approval queues | Not adopted (no maker/checker for movement) |
| Promotion / rollover | Existing Rollover; unchanged |
| Group/House bulk assignment (SR-17) | Not adopted (rule 128: explicit only) |

SR-09 → **met** for within-year moves (cross-year deliberately via Rollover).

## 11. Evidence

- `BulkStudentOperationsTest` (21): Section change end to end with history
  and audit; preview commits nothing; Ready/blocked/already/stale
  classification incl. roll collisions; unready preview not executable;
  capacity advisory; stale Student refuses the whole batch; repeated
  confirmation; confirmation and MFA; signature binding and expiry;
  malformed/duplicate/empty/oversized input; cross-year and Grade refusals
  (Rollover); inactive Student; whole-School Campus transfer with a
  Transport blocker and preserved House/Club/Activity memberships; the same
  registered blockers; Campus principal (Transfer only, today, own Campuses);
  out-of-scope Student; authority revoked after preview; Teacher, Admissions
  Officer, Front office, Librarian refused; another School; selection and
  search; service limits.
- `BulkStudentPlacementConcurrencyTest` (5, separate processes).
- `BulkStudentOperationsPerformanceTest` (2).
- `BulkStudentOperationsArchitectureTest` (4).
- **Browser journeys** (DDEV demo, fresh `ddev demo-reset --build`; 57/57
  checks; MFA enrolled through Account security, codes computed per step):
  - **A/K** Section change Grade 6 A → B by keyboard: default roll numbers
    collide (Blocked, human message), corrected and checked again, all Ready,
    capacity now/after, moved; result lists every Student; movement register.
  - **O** refresh of the review and Back after the result execute nothing; a
    replayed confirmation is refused ("already in the target class").
  - **C** already-in-target classification; **G** another year: inline
    Rollover pointer, Preview disabled, forged request refused to Rollover.
  - **B** over-capacity warning (target capacity set to 7 for the journey),
    still executed; **M** an inactive Student (marked inactive through the
    normal flow) is Blocked with the single-move message.
  - **E** another session moves a selected Student after the preview: "No
    Students were moved", nobody else moved; **J** two sessions confirm
    overlapping batches at once: exactly one runs, the other moves nobody.
  - **I** another School's class and Student: refused, nothing shown.
  - **F/L/D** Campus transfer Grade 3 A (Main) → Grade 3 E (Early Years):
    a Transport assignment (created through the normal flow) blocks that
    Student; "Remove … not ready and check again"; moved; movement register
    shows Campus transfer; Transport untouched.
  - **H** Early Years Campus principal: Transfer Campus only, no target
    outside the scope, forged target refused, Section change 403.
  - **Teacher** 403; **N** 390 px keyboard-only move, no page overflow on
    the selection, review and result pages; every control labelled; live
    selected count; table captions.
  - Found and fixed by the journeys: visually hidden labels inside the
    scrolling tables widened the page at 390 px (the scroll containers are
    now `relative`).
