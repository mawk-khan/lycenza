# Phase 1F.2 — StudentSubjectEnrollment Mutual-Exclusivity Enforcement

> Makes the canonical `StudentSubjectEnrollmentService` write path
> populate and respect the schema Phase 1F.1 introduced. No
> `ElectiveGroup` configuration service, no routes/UI, no schema
> changes -- those are Phase 1F.3/deferred.

## 1. Canonical new-write placement policy

Every NEW `StudentSubjectEnrollment` row `enroll()`/`transfer()` create
now persists its authoritative `student_enrollment_id` -- for BOTH
grouped and ungrouped electives, never left NULL merely because the
column permits it (that NULL remains legacy-migration compatibility
only, architecture doc §18B). The value is the EXACT `StudentEnrollment`
row `resolveCompatibleEnrollment()` resolved for the academic-
compatibility check (School/AcademicYear/GradeLevel/Campus) -- never a
second, independently-derived lookup. At most one row can ever match:
`student_enrollments_one_active_per_student_year` guarantees at most
one `active` StudentEnrollment per Student per AcademicYear, and the
target Offering's `academic_year_id` is fixed, so this resolution is
deterministic.

## 2. Group snapshot derivation

Every NEW row also persists `elective_group_id`, snapshotted from the
target SubjectOffering's CURRENT `elective_group_id` -- NULL for an
ungrouped offering, a real `ElectiveGroup` id otherwise. Neither
`student_enrollment_id` nor `elective_group_id` is ever accepted as
caller input anywhere in this codebase -- `StudentSubjectEnrollmentController::store()`/
`::transfer()`'s request validation only ever accepts
`subject_offering_id`/`starts_on`/`target_subject_offering_id`/
`effective_date`; the service's own public method signatures are
unchanged (`enroll(Student, SubjectOffering, string, ?User)`,
`transfer(StudentSubjectEnrollment, SubjectOffering, string, ?User)`).

## 3. SubjectOffering locking (`lockOffering()`)

Both `enroll()` and `transfer()` now lock and reload the relevant
SubjectOffering (`SELECT ... FOR UPDATE`, scoped by `school_id`) INSIDE
the mutation transaction, BEFORE reading `status`/`is_required`/
`elective_group_id` -- a caller-held `$offering` model's in-memory
attributes are never trusted for these mutable fields (architecture doc
§16A). Proven directly:
`enroll_reloads_the_offerings_group_rather_than_trusting_a_stale_caller_model`
and its status-equivalent construct a genuinely stale in-memory model
(loaded before an out-of-band DB update) and assert the persisted row
reflects the CURRENT database value, not the stale one.

This lock is the shared serialization point Phase 1F.3's future
configuration service will also need to take before reassigning an
Offering's group -- not yet exercised by a real two-service race in
this checkpoint (§9 below), but the infrastructure is now in place.

## 4. Unique-violation translation

`translateUniqueViolation()` now distinguishes two indexes by name,
never a blanket "any 23505" catch:

- `student_subject_enrollments_one_active_per_offering` (Phase 1C,
  unchanged) -> `ActiveSubjectEnrollmentConflictException`
- `student_subject_enrollments_one_active_per_elective_group` (Phase
  1F.1) -> the new `ElectiveGroupConflictException`

Proven distinct in the same test
(`same_offering_duplicate_and_group_conflict_are_distinct_exceptions`).
Neither the SQLSTATE nor the constraint/index name is ever exposed on
the resulting domain exception -- both are 422-oriented,
`App\Domain\Students\Application\Exceptions\StudentException` subtypes
carrying only a stable machine code and message, matching every
existing exception in this domain.

## 5. `ElectiveGroupConflictException`

```php
new ElectiveGroupConflictException; // 422, ELECTIVE_GROUP_CONFLICT
```

"This StudentEnrollment already has an active elective in this
ElectiveGroup." -- no Student name/DOB/Guardian data, matching every
other domain exception's message shape.

## 6. Enroll behavior

Ordering inside the transaction: lock+reload target Offering -> assert
active -> assert not required -> resolve the compatible
StudentEnrollment -> `createRow()` (persists identity fields +
`student_enrollment_id` + `elective_group_id`) -> unique-violation
translation on the way out. The cross-School check remains a cheap
pre-transaction fast-fail (an identity field, `school_id`, that never
changes after creation -- no staleness risk).

## 7. Transfer behavior

Ordering: lock+reload source row -> assert source active -> lock+reload
target Offering -> assert target active -> assert target not required
-> resolve the compatible StudentEnrollment against the TARGET Offering
-> validate the effective date -> mark source `transferred` -> audit ->
`createRow()` for the target. The source-active check now runs before
the target-Offering checks (previously the reverse, when those checks
ran outside any transaction/lock) -- no existing test combines an
invalid source with an invalid target, so this reordering changes no
observed behavior; it is a deliberate consequence of needing to lock
the source row (to update it) regardless, and there is no correctness
requirement mandating the other order.

**Same-group transfer** (source Group X -> target also Group X)
succeeds atomically because the source UPDATE (to `transferred`) always
runs, in the same transaction, BEFORE the target `active` INSERT -- by
the time the target row is inserted, the source's slot in the partial
unique index is already released. **A genuinely occupied different-
group target** still correctly rejects via that same index; the whole
transaction (including the earlier source UPDATE) rolls back, so a
rejected transfer never leaves the source `transferred` with no target,
nor a false success audit.

## 8. Legacy-row compatibility

A pre-1F StudentSubjectEnrollment row (`student_enrollment_id IS NULL`,
`elective_group_id IS NULL`) remains fully withdrawable/cancellable
(`withdraw()`/`cancel()` never touch either column) and remains a valid
TRANSFER SOURCE -- the NEW target row it produces still gets the
CURRENT authoritative anchor/snapshot via the same
`resolveCompatibleEnrollment()`/locked-Offering resolution, regardless
of the source's own legacy NULL state. No historical backfill is
performed or attempted anywhere in this checkpoint.

## 9. Concurrency guarantees

Three REAL multi-process PostgreSQL tests
(`StudentSubjectEnrollmentElectiveGroupConcurrencyTest`, Symfony
Process, mirroring `AcademicYearActivationConcurrencyTest`'s exact
pattern):

- **Same-group**: two processes `enroll()` into two different
  Offerings in the same ElectiveGroup for the same StudentEnrollment --
  exactly one succeeds; the loser receives `ElectiveGroupConflictException`;
  the database ends with exactly one active row for that
  (StudentEnrollment, ElectiveGroup) pair. The partial unique index is
  the actual race-safe authority here, per the accepted architecture --
  no `StudentEnrollment`-level lock was added solely for this (the
  SubjectOffering lock solves a DIFFERENT race, §3 above).
- **Different-group**: two processes enrolling into two different
  Offerings in two different Groups both succeed; two active rows
  result -- proves locking was not accidentally widened into "one
  elective total."
- **Enroll-vs-transfer**: one process `enroll()`s directly into a Group
  while another concurrently `transfer()`s an unrelated existing
  participation INTO that same Group -- at most one active Group
  participation results; a losing transfer leaves its source untouched
  and no target row behind (no partial transfer).

The final configuration-vs-enroll race (a future
`ElectiveGroupConfigurationService` reassigning an Offering's group
concurrently with a participation write) is intentionally NOT tested
here -- that service does not exist yet. Phase 1F.3 must test the real
two-service race; the SubjectOffering lock added in this checkpoint is
prerequisite infrastructure for that future test, not a substitute for
it.

## 10. Audit

`student_subject_enrollment.created`'s metadata gains
`studentEnrollmentId`/`electiveGroupId` (IDs only).
`student_subject_enrollment.transferred`'s metadata gains
`toStudentEnrollmentId`/`toElectiveGroupId`. No Student name, DOB, or
Guardian/contact data in either. A rejected enroll/transfer never
writes a success audit event -- both remain inside the same
`DB::transaction()` that rolls back on any exception, and the audit
call sites are reached only after every validation/lock/insert step
already succeeded.

## 11. Deferred

- `ElectiveGroupConfigurationService` (create group, assign/remove
  Offering membership, immutability-once-participation-exists) --
  Phase 1F.3.
- The real configuration-vs-enroll concurrency test -- Phase 1F.3.
- Administrative API/UI for group configuration, capabilities, OpenAPI,
  Vue -- deferred, unnumbered.
- `SubjectOfferingRosterReadService` -- unchanged; roster correctness
  follows from valid `StudentSubjectEnrollment` writes with no
  group-aware read filtering needed.
