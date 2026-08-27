# Phase 1F.3 — Elective Group Configuration Service

> Implements `App\Domain\AcademicStructure\Application\ElectiveGroupService`
> -- ElectiveGroup creation, SubjectOffering group assignment/
> reassignment/removal, and participation-history immutability. No
> schema change, no API/UI, no capabilities. This is the LAST backend
> slice of Phase 1F's core mutual-exclusivity invariant.

## 1. Service surface

```
create(School, AcademicYear, Campus, GradeLevel, string $name, string $code, ?User $actor = null): ElectiveGroup
assignOffering(ElectiveGroup, SubjectOffering, ?User $actor = null): SubjectOffering
removeOffering(SubjectOffering, ?User $actor = null): SubjectOffering
```

Authorization-neutral (CLAUDE.md rule 45) -- a future controller
authorizes `academics.subjects.manage` before ever reaching this
service, exactly like every sibling AcademicStructure service. No
delete, no name/code update, no rules engine, no choice limits --
deliberately out of scope (checkpoint brief §35-36).

## 2. Create semantics

`$year`/`$campus`/`$gradeLevel` must each belong to `$school` --
checked in-app (`assertSameSchool()`) BEFORE the database's own
composite FKs would reject the same mismatch, so a normal application
caller never sees a raw `QueryException` for this. `code` is passed
through `ElectiveGroup`'s existing `NormalizesCode` trait unchanged (no
second normalization layer) -- uniqueness is
`(school_id, academic_year_id, campus_id, grade_level_id, code)`,
proven: a duplicate in the same context throws
`DuplicateElectiveGroupCodeException`; the identical code in a
different GradeLevel is freely allowed. `elective_groups_code_unique`'s
`UniqueConstraintViolationException` is the ONLY index translated --
no blanket 23505 catch.

## 3. Assignment: locking protocol

`assignOffering()` locks and reloads the target `SubjectOffering`
FIRST (`SELECT ... FOR UPDATE`, scoped by the Offering's own
`school_id`) -- the EXACT shared serialization point
`StudentSubjectEnrollmentService::enroll()`/`transfer()` already
established in Phase 1F.2. This is what makes the configuration-vs-
enrollment race (§7 below) serialize correctly rather than race.

The `ElectiveGroup` itself is re-fetched fresh (`resolveGroup()`) --
NOT locked (`lockForUpdate()`) -- scoped by the JUST-LOCKED Offering's
own `school_id`. There is no independent ElectiveGroup mutation writer
in this checkpoint, so there is no concurrency reason to lock it; the
fresh, scoped re-fetch exists purely so a caller-held `$group` model
claiming a foreign or stale School can never bypass the context check
that follows -- it simply fails to resolve
(`ElectiveGroupContextMismatchException`).

## 4. Context and required-offering validation

`assertSameContext()` requires EXACT equality across all four
dimensions -- School, AcademicYear, Campus, GradeLevel -- between the
(freshly resolved) ElectiveGroup and the (locked) Offering. Section is
deliberately never involved (matches the existing SubjectOffering
compatibility rule). A REQUIRED Offering (`is_required = true`) is
rejected with `RequiredSubjectOfferingGroupAssignmentException` --
deliberately distinct from Students' `RequiredSubjectOfferingEnrollmentException`,
a different domain operation (participation vs. configuration). Both
checks read the LOCKED Offering's current values, never a stale
caller-held model (proven:
`assignment_reloads_the_offering_rather_than_trusting_a_stale_required_flag`).

## 5. Idempotency

- Assigning an Offering already in the exact same ElectiveGroup is a
  no-op: returns the current locked Offering, writes NO audit event.
  This is allowed even when participation history exists, because no
  configuration state actually changes.
- Removing an already-ungrouped Offering is likewise a no-op, no audit.

Both checks happen BEFORE the participation-history check, so a
same-group "re-assignment" never spuriously trips history immutability.

## 6. Reassignment and removal

`Group X -> Group Y` with zero participation history is one atomic
mutation (lock, validate, single UPDATE, audit) -- never modeled as
"remove X then assign Y." `Group X -> NULL` (removal) follows the
identical shape. Both write `subject_offering.elective_group_assigned`
(`fromElectiveGroupId`/`toElectiveGroupId`, `from = null` for an
initial assignment) or `subject_offering.elective_group_removed`
(`fromElectiveGroupId`) respectively -- IDs only, no Student PII. A
rejected mutation writes no audit event and leaves the Offering's group
column unchanged (proven).

## 7. Participation-history immutability -- the core invariant

Once ANY `StudentSubjectEnrollment` row exists for a SubjectOffering
(`active`, `withdrawn`, `cancelled`, OR `transferred` -- ANY status
counts, this is historical immutability, not current occupancy), its
ElectiveGroup assignment is frozen: first-time assignment, reassignment
to a different group, and removal are all rejected with the new
`ElectiveGroupAssignmentLockedException`. A legacy row whose own
`elective_group_id` snapshot is NULL still counts as history
(architecture doc §18A's initial-adoption policy, reaffirmed). Proven
for every status individually, for a legacy NULL-snapshot row, for both
sides of a completed transfer (the transferred-FROM source retains its
own frozen history; the transferred-TO target immediately gains its
own), and for removal (not just assignment).

### The one deliberate cross-domain read

`assertNoParticipationHistory()` queries
`App\Domain\Students\Infrastructure\StudentSubjectEnrollment` directly
from AcademicStructure -- executed AFTER the Offering lock, INSIDE the
same transaction, scoped by the Offering's own `school_id` (never a
caller-supplied value), as a single read-only `exists()` check. This is
a narrow, deliberate, and permanent exception to CLAUDE.md rule 4
("never reads another module's Eloquent models or tables directly").
See `ElectiveGroupService`'s class docblock for the full justification;
in summary: any AcademicStructure -> Students dependency (whether a raw
model read or an Application-service *call*) creates the same
bidirectional coupling with the already-established Students ->
AcademicStructure dependency, and a denormalized "has-history" flag
kept in sync by a cross-domain event listener would trade an exact,
transactionally-consistent check for an eventual-consistency risk this
invariant cannot tolerate. `StudentSubjectEnrollmentService` is never
called, and no Students-owned data is ever mutated from this service.

## 8. Configuration-vs-enrollment concurrency (the decisive Phase 1F proof)

`ElectiveGroupConfigurationConcurrencyTest` races
`ElectiveGroupService::assignOffering()` against
`StudentSubjectEnrollmentService::enroll()` for the SAME
SubjectOffering, using two genuinely separate OS processes (Symfony
Process), repeated 6 times per run with fresh fixtures each time --
mirroring `AcademicYearActivationConcurrencyTest`'s exact pattern.
Neither this test nor either service forces a specific winner (no
test-only sleep hook exists in production code); across repeated runs,
BOTH orderings are observed naturally (empirically: roughly 1-in-6 to
2-in-6 repetitions land config-first, the remainder enroll-first, on
this hardware -- the exact split is not asserted, since it is not a
correctness property). On every single repetition, regardless of
winner, the test asserts:

- **Config-first**: the Offering ends up grouped, AND the
  participation's own `elective_group_id` snapshot matches that SAME
  group -- `enroll()`'s lock acquisition happened after the
  configuration committed, so it read and snapshotted the NEW group.
- **Enroll-first**: the Offering remains ungrouped, AND the
  participation's snapshot is NULL -- the configuration's lock
  acquisition happened after the enrollment committed, so its history
  check found the just-created row and rejected with
  `ElectiveGroupAssignmentLockedException`.
- **Forbidden, checked every repetition regardless of winner**: the
  Offering's current `elective_group_id` and the participation's own
  snapshot disagreeing. This never occurred across dozens of
  repetitions run during this checkpoint's verification.

A focused SEQUENTIAL proof (no concurrency needed) additionally covers
configuration-vs-transfer: after a successful `transfer()`, BOTH the
transferred-from source Offering and the transferred-to target Offering
have participation history, and configuration mutation of either is
frozen. A real concurrent configuration-vs-transfer race is not
implemented (optional per this checkpoint's own brief) -- the
config-vs-enroll race above already proves the shared-lock protocol
works, and `transfer()`'s target-Offering locking is identical in shape
to `enroll()`'s.

## 9. Lock-order review (no deadlock cycle)

| Operation | Locks, in order |
|---|---|
| `ElectiveGroupService::assignOffering()`/`removeOffering()` | SubjectOffering |
| `StudentSubjectEnrollmentService::enroll()` | SubjectOffering |
| `StudentSubjectEnrollmentService::transfer()` | source StudentSubjectEnrollment, then target SubjectOffering |

The only resource ever locked in "second position" across every write
path is `SubjectOffering`. Nothing ever locks a `StudentSubjectEnrollment`
row AFTER already holding a `SubjectOffering` lock (configuration never
locks `StudentSubjectEnrollment` at all -- its history check is a plain
unlocked `SELECT`, which never blocks on or conflicts with another
transaction's row locks under PostgreSQL's default READ COMMITTED
isolation). No lock-order cycle is possible: two concurrent
`transfer()`s can only contend on `SubjectOffering` rows pairwise, in
the same relative order every write path uses, and configuration/
enroll never contend with a `transfer()`'s FIRST lock (the source row)
at all.

## 10. Current-vs-history distinction

`withdraw()`/`cancel()` release the Student's mutual-exclusivity slot
(the partial unique index only counts `status = 'active'`) but do
**NOT** unfreeze configuration -- the history check counts every
status. Proven explicitly: withdrawing or cancelling the sole
participation against an Offering still leaves its ElectiveGroup
assignment locked.

## 11. Group creation and legacy adoption

Creating an ElectiveGroup never auto-assigns Offerings, scans Subjects,
or infers membership from codes/names -- creation and assignment are
always two explicit, separate commands (checkpoint brief §33). No
migration/backfill of existing data is performed in this checkpoint;
an Offering with pre-existing participation history remains permanently
ungroupable through this service, by design (architecture doc §18A).

## 12. Deferred

- Administrative API/UI, capabilities, OpenAPI, Vue -- see the "Next
  recommendation" in this checkpoint's final report.
- ElectiveGroup deletion, name/code updates.
- A real concurrent configuration-vs-transfer test (the sequential
  proof in §8 covers the invariant; only the mandatory config-vs-enroll
  race required a real multi-process test per this checkpoint's brief).
