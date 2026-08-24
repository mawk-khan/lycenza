<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Exceptions\ActiveEnrollmentConflictException;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\RolloverItemNotExecutableException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNotExecutionReadyException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 1B.7C: the per-Student promotion EXECUTION primitive -- see
 * docs/modules/STUDENT-ENROLLMENT.md ("Academic-Year Rollover &
 * Promotion — Architecture Decision (Phase 1B.7)", "Atomicity,
 * idempotency, resumability") for the accepted design this implements.
 * Answers "materialize exactly the ONE target-year Enrollment this
 * already-validated Item describes" for exactly one
 * `EnrollmentRolloverItem` -- it never loops a whole Plan (Phase
 * 1B.7D's job), never queues, and never exposes HTTP/API/UI.
 *
 * Reuses `StudentEnrollmentService::enroll()` as the ONE trusted
 * Enrollment-creation primitive -- the accepted architecture (see
 * "Atomicity, idempotency, resumability" above) explicitly calls for
 * this, not a forked/duplicated placement-derivation path. Calling
 * `enroll()` nested inside this service's own `DB::transaction()` is
 * safe: Laravel promotes a nested `DB::transaction()` to a real
 * PostgreSQL SAVEPOINT, so a `UniqueConstraintViolationException`
 * inside `enroll()`'s own transaction only rolls back to that
 * SAVEPOINT (never aborts the whole connection) before `enroll()`
 * translates and rethrows it as `ActiveEnrollmentConflictException`/
 * `DuplicateEnrollmentRollNumberException` -- this service's own
 * transaction, and the connection underneath it, remain fully usable
 * afterward, which is exactly what lets the insert-race reconciliation
 * below run safely on the SAME transaction rather than needing a
 * second one. `TenantContext::withSchool()` nests the same way (it
 * always restores whatever School/Campus was active before its own
 * callback ran), and since `enroll()` is always called here for the
 * SAME School this service already established context for, the
 * nested re-set is a same-value no-op.
 *
 * `execute()` never mutates a source Enrollment (no `complete()`/
 * `withdraw()`/`cancel()`/`transferPlacement()` call anywhere in this
 * class), never activates/closes an AcademicYear, and never
 * auto-generates a Roll Number -- it only re-derives the EXACT value
 * `EnrollmentRolloverDryRunService` already validated
 * (`RollNumberNormalizer::resolveForStrategy()`, the same shared rule
 * both services call), as a revalidation, never a second independent
 * evaluation.
 *
 * Phase 1B.7D: accepts a Plan in EITHER `validated` OR `executing`
 * status (never `draft`/`completed`/`completed_with_errors`/
 * `cancelled`) -- `executing` is what `EnrollmentRolloverExecutionService::start()`
 * transitions a Plan to for the duration of a bulk run, so every Item
 * it processes must still pass through this exact gate.
 * `validated_configuration_version === configuration_version` remains
 * required regardless of which of the two statuses is current.
 *
 * Deliberately authorization-neutral, matching every other Application
 * service in this codebase.
 */
class EnrollmentRolloverItemExecutionService
{
    private const EXECUTABLE_RESULTS = ['ready', 'already_enrolled'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly StudentEnrollmentService $enrollmentService,
    ) {}

    /**
     * Executes exactly ONE Item. Lock order (this checkpoint's brief,
     * section 13): Plan -> Item -> source Enrollment -> (existing
     * target Enrollment, where a pre-check query finds one) -- followed
     * on every code path, including the idempotent-replay fast path,
     * so no caller can observe a different acquisition order.
     */
    public function execute(EnrollmentRolloverItem $item, ?User $actor = null): EnrollmentRolloverItem
    {
        return $this->context->withSchool($item->school, function () use ($item, $actor) {
            return DB::transaction(function () use ($item, $actor) {
                $plan = EnrollmentRolloverPlan::query()->whereKey($item->plan_id)->lockForUpdate()->firstOrFail();
                $lockedItem = EnrollmentRolloverItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

                // Idempotency-first (brief section 35): a retried/duplicate
                // execute() call for an already-terminal Item is always
                // safe, regardless of whether the Plan is STILL validated
                // -- a later configuration edit to OTHER items must never
                // retroactively fail a replay of THIS item's own
                // already-complete work.
                if ($lockedItem->target_enrollment_id !== null) {
                    return $this->reconcileAlreadyExecuted($plan, $lockedItem);
                }

                // Phase 1B.7D: 'executing' is accepted alongside
                // 'validated' -- EnrollmentRolloverExecutionService::start()
                // transitions the Plan to 'executing' for the DURATION of
                // a bulk run, and every Item it processes must still pass
                // through this exact gate. `validated_configuration_version
                // === configuration_version` remains required either way --
                // it is what actually proves the Item's persisted
                // validation_result belongs to the CURRENT configuration,
                // not `status` alone.
                if (! in_array($plan->status, ['validated', 'executing'], true) || $plan->validated_configuration_version !== $plan->configuration_version) {
                    throw new RolloverPlanNotExecutionReadyException;
                }

                if ($lockedItem->validation_result === 'excluded') {
                    return $this->markSkipped($plan, $lockedItem, $actor);
                }

                if (! in_array($lockedItem->validation_result, self::EXECUTABLE_RESULTS, true)) {
                    throw new RolloverItemNotExecutableException($lockedItem->validation_result);
                }

                $lockedSource = StudentEnrollment::query()->whereKey($lockedItem->source_enrollment_id)->lockForUpdate()->firstOrFail();

                if ($lockedSource->student_id !== $lockedItem->student_id) {
                    // Structurally impossible per the double composite FK
                    // on enrollment_rollover_items.source_enrollment_id
                    // (Phase 1B.7A) -- defense in depth only.
                    throw new RuntimeException("Rollover item {$lockedItem->id}: source Enrollment does not belong to the item's Student.");
                }

                if (! $this->sourceSnapshotStillCurrent($lockedItem, $lockedSource)) {
                    return $this->invalidateForDrift($plan, $lockedItem, 'source_changed_since_validation', $actor);
                }

                $targetSectionId = $this->resolveTargetSectionId($plan, $lockedItem, $lockedSource);
                $targetSection = Section::query()->whereKey($targetSectionId)->first();

                if (! $this->targetSectionStillValid($plan, $lockedItem, $targetSection)) {
                    return $this->invalidateForDrift($plan, $lockedItem, 'target_section_changed_since_validation', $actor);
                }

                $rollNumber = RollNumberNormalizer::resolveForStrategy($lockedItem->roll_number_strategy, $lockedItem->target_roll_number, $lockedSource->roll_number);
                if ($rollNumber === null) {
                    // Unreachable for an Item dry-run already classified
                    // ready/already_enrolled -- the source snapshot check
                    // above already proved roll_number is unchanged.
                    throw new RuntimeException("Rollover item {$lockedItem->id}: no resolvable Roll Number despite a '{$lockedItem->validation_result}' validation result.");
                }

                $existingActiveTarget = $this->findActiveTargetEnrollment($plan, $lockedItem->student_id);

                if ($existingActiveTarget !== null) {
                    if ($this->matchesProposal($existingActiveTarget, $targetSection->id, $rollNumber)) {
                        return $this->reconcile($plan, $lockedItem, $existingActiveTarget, $actor);
                    }

                    return $this->invalidateForDrift($plan, $lockedItem, 'existing_target_enrollment_conflict_at_execution', $actor);
                }

                if ($lockedItem->validation_result === 'already_enrolled') {
                    // Dry-run found a matching active target Enrollment;
                    // the fresh pre-check just above found none. The
                    // Student's target-year placement changed underneath
                    // this Plan since validation -- never re-derive a
                    // replacement here, always require fresh validation.
                    return $this->invalidateForDrift($plan, $lockedItem, 'already_enrolled_match_no_longer_valid', $actor);
                }

                try {
                    $target = $this->enrollmentService->enroll(
                        $lockedItem->student,
                        $targetSection,
                        $rollNumber,
                        $plan->targetAcademicYear->starts_on->toDateString(),
                        $actor,
                    );
                } catch (ActiveEnrollmentConflictException|DuplicateEnrollmentRollNumberException $e) {
                    // Insert-race window (brief sections 36-38): enroll()'s
                    // own nested transaction already rolled back to its
                    // SAVEPOINT before this exception reached us (see this
                    // class's own docblock) -- the connection here is not
                    // poisoned, so it is safe to re-query for
                    // reconciliation on THIS SAME transaction.
                    $raceTarget = $this->findActiveTargetEnrollment($plan, $lockedItem->student_id);

                    if ($raceTarget !== null && $this->matchesProposal($raceTarget, $targetSection->id, $rollNumber)) {
                        return $this->reconcile($plan, $lockedItem, $raceTarget, $actor);
                    }

                    $reason = $e instanceof DuplicateEnrollmentRollNumberException
                        ? 'roll_number_conflict_detected_at_execution'
                        : 'existing_target_enrollment_conflict_at_execution';

                    return $this->invalidateForDrift($plan, $lockedItem, $reason, $actor);
                }

                $lockedItem->update([
                    'execution_status' => 'succeeded',
                    'target_enrollment_id' => $target->id,
                    'executed_at' => now(),
                ]);

                $this->audit->school($plan->school, 'enrollment_rollover.item_succeeded', actor: $actor, subject: $lockedItem, metadata: [
                    'planId' => $plan->id,
                    'itemId' => $lockedItem->id,
                    'sourceEnrollmentId' => $lockedItem->source_enrollment_id,
                    'targetEnrollmentId' => $target->id,
                    'configurationVersion' => $plan->configuration_version,
                    'decision' => $lockedItem->decision,
                    'validationResult' => $lockedItem->validation_result,
                ]);

                return $lockedItem->refresh();
            });
        });
    }

    private function sourceSnapshotStillCurrent(EnrollmentRolloverItem $item, StudentEnrollment $freshSource): bool
    {
        return $item->source_enrollment_updated_at_snapshot !== null
            && $freshSource->status === $item->source_enrollment_status_snapshot
            && $freshSource->updated_at->equalTo($item->source_enrollment_updated_at_snapshot);
    }

    private function targetSectionStillValid(EnrollmentRolloverPlan $plan, EnrollmentRolloverItem $item, ?Section $targetSection): bool
    {
        return $targetSection !== null
            && $targetSection->school_id === $plan->school_id
            && $targetSection->academic_year_id === $plan->target_academic_year_id
            && $item->target_section_updated_at_snapshot !== null
            && $targetSection->status === $item->target_section_status_snapshot
            && $targetSection->updated_at->equalTo($item->target_section_updated_at_snapshot);
    }

    private function findActiveTargetEnrollment(EnrollmentRolloverPlan $plan, string $studentId): ?StudentEnrollment
    {
        return StudentEnrollment::query()
            ->where('student_id', $studentId)
            ->where('academic_year_id', $plan->target_academic_year_id)
            ->where('status', 'active')
            ->first();
    }

    private function matchesProposal(StudentEnrollment $existing, string $targetSectionId, string $rollNumber): bool
    {
        return $existing->section_id === $targetSectionId && $existing->roll_number === $rollNumber;
    }

    /**
     * Re-derives the target Section an ALREADY-validated Item resolves
     * to -- an Item-level override (`target_section_id`) if the
     * operator set one, else the Plan's own Section-specific or
     * Grade-level default mapping, in the exact same precedence
     * `EnrollmentRolloverDryRunService::evaluateStructural()` already
     * applied. Neither `EnrollmentRolloverItem.mapping_id` nor a
     * dedicated "resolved target Section" column is populated by any
     * checkpoint today, so there is no single persisted field this
     * could read directly -- but re-deriving it here is provably safe,
     * not a second independent planning pass: the caller has already
     * proven `validated_configuration_version === configuration_version`
     * (so the Plan's mapping ROWS are unchanged since the last
     * validated dry-run) AND that the source Enrollment's snapshot is
     * unchanged (so its `section_id`/`grade_level_id` -- the mapping
     * lookup KEY -- are unchanged too). Given both, this lookup is
     * mathematically guaranteed to return the identical mapping
     * dry-run itself resolved.
     */
    private function resolveTargetSectionId(EnrollmentRolloverPlan $plan, EnrollmentRolloverItem $item, StudentEnrollment $freshSource): ?string
    {
        if ($item->target_section_id !== null) {
            return $item->target_section_id;
        }

        $mapping = EnrollmentRolloverMapping::query()
            ->where('plan_id', $plan->id)
            ->where('source_section_id', $freshSource->section_id)
            ->first()
            ?? EnrollmentRolloverMapping::query()
                ->where('plan_id', $plan->id)
                ->whereNull('source_section_id')
                ->where('source_grade_level_id', $freshSource->grade_level_id)
                ->first();

        return $mapping?->target_section_id;
    }

    private function reconcile(EnrollmentRolloverPlan $plan, EnrollmentRolloverItem $item, StudentEnrollment $target, ?User $actor): EnrollmentRolloverItem
    {
        $item->update([
            'execution_status' => 'reconciled',
            'target_enrollment_id' => $target->id,
            'executed_at' => now(),
        ]);

        $this->audit->school($plan->school, 'enrollment_rollover.item_reconciled', actor: $actor, subject: $item, metadata: [
            'planId' => $plan->id,
            'itemId' => $item->id,
            'sourceEnrollmentId' => $item->source_enrollment_id,
            'targetEnrollmentId' => $target->id,
            'configurationVersion' => $plan->configuration_version,
            'validationResult' => $item->validation_result,
        ]);

        return $item->refresh();
    }

    private function markSkipped(EnrollmentRolloverPlan $plan, EnrollmentRolloverItem $item, ?User $actor): EnrollmentRolloverItem
    {
        $item->update([
            'execution_status' => 'skipped',
            'executed_at' => now(),
        ]);

        $this->audit->school($plan->school, 'enrollment_rollover.item_skipped', actor: $actor, subject: $item, metadata: [
            'planId' => $plan->id,
            'itemId' => $item->id,
            'validationResult' => $item->validation_result,
        ]);

        return $item->refresh();
    }

    /**
     * Discovered execution-time drift always demotes Plan readiness
     * (this checkpoint's brief, section 39) -- never just the one
     * Item. `configuration_version` is deliberately left untouched:
     * this is not a configuration edit, so it must not look like one.
     * `validated_at` is deliberately preserved as the historical record
     * of the last real review; `status`/`validated_configuration_version`
     * alone are what future code must check for current readiness
     * (mirrors `EnrollmentRolloverPlan::isValidatedForCurrentConfiguration()`).
     */
    private function invalidateForDrift(EnrollmentRolloverPlan $plan, EnrollmentRolloverItem $item, string $reason, ?User $actor): EnrollmentRolloverItem
    {
        $item->update([
            'execution_status' => 'failed',
            'validation_result' => 'blocked',
            'validation_reason' => $reason,
        ]);

        $plan->update([
            'status' => 'draft',
            'validated_configuration_version' => null,
        ]);

        $this->audit->school($plan->school, 'enrollment_rollover.item_execution_failed', actor: $actor, subject: $item, metadata: [
            'planId' => $plan->id,
            'itemId' => $item->id,
            'reason' => $reason,
        ]);

        $this->audit->school($plan->school, 'enrollment_rollover_plan.execution_invalidated', actor: $actor, subject: $plan, metadata: [
            'itemId' => $item->id,
            'reason' => $reason,
            'configurationVersion' => $plan->configuration_version,
        ]);

        return $item->refresh();
    }

    /**
     * Idempotent-replay path (brief section 35): re-verifies a
     * previously succeeded/reconciled Item's `target_enrollment_id`
     * still references a coherent target Enrollment before returning
     * it unchanged -- a pure read, no write, no audit (nothing new
     * happened). A mismatch here would mean something silently
     * rewrote/deleted the referenced Enrollment outside this service,
     * which `target_enrollment_id`'s `restrictOnDelete()` FK (Phase
     * 1B.7A) already makes structurally near-impossible -- surfaced as
     * a hard integrity exception, never a silently replaced
     * provenance link.
     */
    private function reconcileAlreadyExecuted(EnrollmentRolloverPlan $plan, EnrollmentRolloverItem $item): EnrollmentRolloverItem
    {
        $target = StudentEnrollment::query()->find($item->target_enrollment_id);

        if ($target === null || $target->student_id !== $item->student_id || $target->academic_year_id !== $plan->target_academic_year_id) {
            throw new RuntimeException("Rollover item {$item->id}: target_enrollment_id ({$item->target_enrollment_id}) no longer references a valid target Enrollment for its Student/target Academic Year.");
        }

        return $item;
    }
}
