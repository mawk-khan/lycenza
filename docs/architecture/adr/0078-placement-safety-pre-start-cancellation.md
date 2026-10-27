# ADR 0078: Placement Safety — Transfer Blockers, Pre-Start Cancellation, Start-Date Backstop

- Status: **Accepted and implemented (2026-10-27).** SIS 4.2.
- Date: 2026-10-27
- Module: Students (Enrollment), with ports implemented by Hostel,
  Transport, Fees and Examinations.
- Amends:
  - ADR 0076 §65.2/§65.3 (ORG.8G "an ended placement always has an end date
    on or after its start; no cancellation is dated after the School-local
    date") — narrowly, for a placement cancelled before its start;
  - ADR 0076 §47.3/§47.5 — the whole-School transfer now asks the transfer
    blockers when the Campus changes.

## 1. Owner decision (2026-10-27)

> A placement whose start date is still in the future may be cancelled before
> it becomes effective, provided no dependent operational facts exist that
> require the placement to remain historical truth.

Asked during SIS 4.2 because the schema could not represent it honestly. The
owner chose the **empty interval** (`ends_on = starts_on − 1`, cancelled only)
over deferring.

## 2. Pre-start cancellation

- **Why not `ends_on = starts_on`.** Rosters, eligibility and fee targeting
  read placement *intervals*, not status. `ends_on = starts_on` would claim
  one effective day and put the Student on that day's Attendance roster. That
  is a false fact.
- **The empty interval.**
  - `student_enrollments_date_range_check` now admits
    `status = 'cancelled' AND ends_on = starts_on - 1`. This is the same shape
    `teaching_assignments_date_range_check` uses for an assignment ended
    before it began.
  - No covering-date query can match an empty interval.
  - Range readers of placements (fee targeting, charge Campus attribution)
    already exclude `cancelled` explicitly.
- **When it really ended** is the database's own `ended_recorded_at`, the
  `student_lifecycle_events` row and the audit (`beforeStart: true`). It is
  never `ends_on`.
- **The operation:** `StudentEnrollmentService::cancelBeforeStart()`.
  - It requires an `active` placement whose start is after today
    (School-local).
  - It uses the existing closed cancel reasons, a fresh MFA proof, current
    whole-School `enrollments.manage` under the row lock, and the lifecycle
    event the database requires. The row is never deleted.
  - Ordinary `cancel()` of a not-yet-started placement is refused with a
    pointer to it.
- **Dependent facts** (`PlacementCancellationBlocker` port, tag
  `students.placement_cancellation_blockers`):
  - an active elective anchored to the placement (Students);
  - a live (unvoided) fee assessment (Fees);
  - a recorded mark (Examinations).

  Each answers only; nothing is voided or ended for the operator. Every
  writer of those records share-locks the placement, so it serializes with
  the cancellation's `FOR UPDATE`. Attendance cannot exist on a placement that
  has not started.

## 3. Transfer blockers on the whole-School path

- **What changed.** `transferPlacement()` asks every `StudentTransferBlocker`
  (Hostel residency, Transport rider assignment, elective on the old Campus)
  whenever the **Campus changes**. Before this, only the Campus-scoped path
  asked.
- **Same-Campus Section changes stay unblocked.** By design they leave those
  records valid: eligibility needs only the elective's anchor to be in the
  Offering's context.
- **The refusal names what to resolve** (`STUDENT_TRANSFER_BLOCKED`, 422),
  using each blocker's `kind()` from its owning module. The Campus-scoped path
  keeps its single generic refusal (rule 101).
- **Serialization is unchanged** (ADR 0076 §47.5):
  - Hostel and Transport writers lock the Student first;
  - elective writers share-lock the source placement.

## 4. Start-date backstop

- **The rule.** The existing trigger function
  `student_enrollments_end_within_year` also refuses an INSERT whose
  `starts_on` lies outside the row's (Section-bound) academic year, both ends
  inclusive. This is SIS 4.1's service rule.
- **Insert only.** `starts_on` is immutable afterwards.
- **A closed year is deliberately not refused in the database.** Historical
  placements in closed years are legitimate rows (fixtures, imports, repair).
  "No new placement in a closed year" stays the service rule, under its year
  `FOR SHARE` lock.

## 5. Also in SIS 4.2 (no ADR-level change)

- Roll-number correction for active placements, unique per Section and year
  in any status (the existing key).
- ENR-01 pickers:
  - Enroll lists the active year first;
  - Transfer offers only the placement's own year and Grade, never its
    current Section.

## 6. Migration and rollback

- **Migration:** `2027_01_11_090000_placement_start_within_year_and_pre_start_cancellation`.
- **Rollback:**
  - restores the previous function and CHECK;
  - **refuses** while an empty-interval cancellation exists, because its
    history is never rewritten;
  - proven byte-identical on a database without such rows.
