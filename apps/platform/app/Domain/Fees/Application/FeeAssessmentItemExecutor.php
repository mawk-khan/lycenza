<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\AdjustmentExceedsOutstandingException;
use App\Domain\Fees\Application\Exceptions\ChargeFullyPaidException;
use App\Domain\Fees\Application\Exceptions\ConcessionAccountNotConfiguredException;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Fees\Infrastructure\FeeAssessmentRun;
use App\Domain\Fees\Infrastructure\FeeAssessmentRunItem;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Students\Application\StudentEnrollmentFeeTargetReadService;
use App\Models\School;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FEE.2 (ADR 0062 §11-§13): executes pending assessment items, each in its
 * own transaction, and never twice.
 *
 * Per item, in the ADR's lock order: run FOR SHARE (a finalize waits) ->
 * the School's lifecycle FOR SHARE (`SchoolOperationalGuard`; a
 * non-operational School pauses the run, nothing changes) -> the item FOR
 * UPDATE (a second worker waits, then sees it is no longer pending) ->
 * structure FOR SHARE (a concurrent retirement stops new items) -> an
 * advisory lock on the assessment key -> the enrollment and Student FOR
 * SHARE through Students' own read service -> the ledger (inside
 * `ChargeService`).
 *
 * Everything is re-validated at execution and fails closed with a closed
 * failure reason: structure still active and still the one resolving for
 * the Student's campus, enrollment still qualifying (not cancelled, same
 * year and grade, overlapping the period), Student active, optional
 * selection still active, fee head active, both accounts active and of the
 * right type.
 *
 * The charge (`ChargeService::assess`, the trusted core -- never an
 * administrative facade) and its `fee_assessments` row are written in one
 * savepoint. If `fee_assessments_one_live_per_period` refuses the row, the
 * savepoint rolls back the charge and its journal entry and the item is
 * `skipped_already_assessed`. There is no existence pre-check deciding
 * anything (rule 30). The charge amount is the instalment amount exactly
 * (owner decision D1: no proration).
 *
 * FEE.3 (ADR 0062 §14.4; owner decisions F2, G1): approved standing
 * concessions covering the Student, year, head and the instalment's period
 * start are posted inside the same savepoint, after the assessment row
 * (`FeeAdjustmentService::applyStanding`). If one would exceed what
 * remains of the charge, or the School's concession account is invalid,
 * the savepoint rolls back the charge, the assessment and any adjustment
 * already posted, and the item fails closed
 * (`concession_exceeds_outstanding` / `concession_account_invalid`).
 */
class FeeAssessmentItemExecutor
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly FeeAdjustmentService $adjustments,
        private readonly LedgerService $ledger,
        private readonly StudentEnrollmentFeeTargetReadService $targets,
        private readonly FeeStructureResolver $resolver,
        private readonly SchoolOperationalGuard $operational,
        private readonly TenantContext $context,
    ) {}

    /**
     * Executes up to $limit pending items of an executing run.
     *
     * @return array{processed: int, paused: bool, pendingRemaining: bool}
     */
    public function executeBatch(School $school, string $runId, int $limit = self::BATCH_SIZE): array
    {
        $ids = $this->context->withSchool($school, fn () => FeeAssessmentRunItem::query()
            ->where('fee_assessment_run_id', $runId)
            ->where('execution_status', FeeAssessmentRunItem::EXEC_PENDING)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all());

        $processed = 0;
        foreach ($ids as $itemId) {
            if ($this->executeItem($school, $runId, $itemId) === 'paused') {
                return ['processed' => $processed, 'paused' => true, 'pendingRemaining' => true];
            }
            $processed++;
        }

        $pendingRemaining = $this->context->withSchool($school, fn () => FeeAssessmentRunItem::query()
            ->where('fee_assessment_run_id', $runId)
            ->where('execution_status', FeeAssessmentRunItem::EXEC_PENDING)
            ->exists());

        return ['processed' => $processed, 'paused' => false, 'pendingRemaining' => $pendingRemaining];
    }

    /**
     * @return string one of succeeded, skipped_already_assessed, failed, paused, not_pending, not_executing
     */
    public function executeItem(School $school, string $runId, string $itemId): string
    {
        try {
            return $this->context->withSchool($school, fn () => DB::transaction(fn () => $this->attempt($school, $runId, $itemId)));
        } catch (Throwable $e) {
            report($e);

            return $this->context->withSchool($school, fn () => DB::transaction(
                fn () => $this->recordFailure($runId, $itemId, FeeAssessmentRunItem::FAIL_ERROR),
            ));
        }
    }

    private function attempt(School $school, string $runId, string $itemId): string
    {
        $run = FeeAssessmentRun::query()->where('school_id', $school->id)->sharedLock()->find($runId);
        if ($run === null || $run->status !== FeeAssessmentRun::STATUS_EXECUTING) {
            return 'not_executing';
        }

        if (! $this->operational->holdOperational($school->id)) {
            return 'paused';
        }

        $item = FeeAssessmentRunItem::query()->where('fee_assessment_run_id', $run->id)->lockForUpdate()->find($itemId);
        if ($item === null || $item->execution_status !== FeeAssessmentRunItem::EXEC_PENDING) {
            return 'not_pending';
        }

        $structure = FeeStructure::query()->where('school_id', $school->id)->sharedLock()->find($run->fee_structure_id);
        if ($structure === null || ! $structure->isActive()) {
            return $this->fail($item, FeeAssessmentRunItem::FAIL_STRUCTURE_NOT_ACTIVE);
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
            implode(':', ['fees.assessment', $school->id, $item->student_id, $structure->academic_year_id, $item->fee_head_id, $item->billing_period_key]),
        ]);

        $installment = FeeStructureInstallment::query()->findOrFail($item->fee_structure_installment_id);
        $enrollment = $item->student_enrollment_id === null ? null : $this->targets->lockForFeeAssessment($school, $item->student_enrollment_id);

        if ($enrollment === null
            || $enrollment->isCancelled()
            || $enrollment->academicYearId !== $structure->academic_year_id
            || $enrollment->gradeLevelId !== $structure->grade_level_id
            || ! $enrollment->overlaps(CarbonImmutable::parse($installment->period_starts_on), CarbonImmutable::parse($installment->period_ends_on))) {
            return $this->fail($item, FeeAssessmentRunItem::FAIL_ENROLLMENT_NOT_QUALIFYING);
        }

        if (! $enrollment->studentIsActive) {
            return $this->fail($item, FeeAssessmentRunItem::FAIL_STUDENT_INACTIVE);
        }

        if ($this->resolver->activeStructureFor($school, $structure->academic_year_id, $structure->grade_level_id, $enrollment->campusId)?->id !== $structure->id) {
            return $this->fail($item, FeeAssessmentRunItem::FAIL_STRUCTURE_NOT_RESOLVED);
        }

        $line = FeeStructureLine::query()->findOrFail($item->fee_structure_line_id);
        if ($line->is_optional && ! FeeOptionalSelection::query()
            ->where('student_id', $item->student_id)
            ->where('academic_year_id', $structure->academic_year_id)
            ->where('fee_head_id', $item->fee_head_id)
            ->where('status', FeeOptionalSelection::STATUS_ACTIVE)
            ->exists()) {
            return $this->fail($item, FeeAssessmentRunItem::FAIL_OPTIONAL_NOT_SELECTED);
        }

        $head = FeeHead::query()->sharedLock()->findOrFail($item->fee_head_id);
        if (! $head->isActive()) {
            return $this->fail($item, FeeAssessmentRunItem::FAIL_HEAD_INACTIVE);
        }

        if (! $this->isActiveAccount($school, 'asset', $head->receivable_ledger_account_id)
            || ! $this->isActiveAccount($school, 'income', $head->revenue_ledger_account_id)) {
            return $this->fail($item, FeeAssessmentRunItem::FAIL_ACCOUNT_INVALID);
        }

        $actor = $run->executed_by_user_id === null ? null : User::query()->find($run->executed_by_user_id);

        try {
            $assessment = DB::transaction(function () use ($school, $run, $item, $structure, $installment, $head, $enrollment, $actor) {
                $charge = $this->charges->assess($school, new AssessChargeData(
                    studentId: $item->student_id,
                    academicYearId: $structure->academic_year_id,
                    description: mb_substr("{$head->name} · {$installment->label}", 0, 255),
                    amount: Money::of((string) $installment->amount, 'INR'),
                    receivableLedgerAccountId: $head->receivable_ledger_account_id,
                    revenueLedgerAccountId: $head->revenue_ledger_account_id,
                    dueDate: $installment->due_date->toDateString(),
                ), $actor);

                $assessment = new FeeAssessment;
                $assessment->forceFill([
                    'school_id' => $school->id,
                    'student_id' => $item->student_id,
                    'academic_year_id' => $structure->academic_year_id,
                    'fee_head_id' => $item->fee_head_id,
                    'billing_period_key' => $item->billing_period_key,
                    'fee_structure_id' => $structure->id,
                    'fee_structure_line_id' => $item->fee_structure_line_id,
                    'fee_structure_installment_id' => $installment->id,
                    'student_enrollment_id' => $enrollment->enrollmentId,
                    'fee_assessment_run_id' => $run->id,
                    'charge_id' => $charge->chargeId,
                    'created_by_user_id' => $actor?->id,
                ])->save();

                // FEE.3 (ADR 0062 §14.4, G1): approved standing concessions
                // post in this same savepoint; a refusal rolls back the
                // charge too (no partial application).
                $this->adjustments->applyStanding(
                    $school,
                    $assessment,
                    CarbonImmutable::parse($installment->period_starts_on)->toDateString(),
                    Money::of((string) $installment->amount, 'INR'),
                    $actor,
                );

                return $assessment;
            });
        } catch (ChargeFullyPaidException|AdjustmentExceedsOutstandingException) {
            return $this->fail($item, FeeAssessmentRunItem::FAIL_CONCESSION_EXCEEDS_OUTSTANDING);
        } catch (ConcessionAccountNotConfiguredException) {
            return $this->fail($item, FeeAssessmentRunItem::FAIL_CONCESSION_ACCOUNT_INVALID);
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'fee_assessments_one_live_per_period')) {
                throw $e;
            }

            $item->forceFill([
                'execution_status' => FeeAssessmentRunItem::EXEC_SKIPPED,
                'executed_at' => now(),
            ])->save();

            return FeeAssessmentRunItem::EXEC_SKIPPED;
        }

        $item->forceFill([
            'execution_status' => FeeAssessmentRunItem::EXEC_SUCCEEDED,
            'fee_assessment_id' => $assessment->id,
            'executed_at' => now(),
        ])->save();

        return FeeAssessmentRunItem::EXEC_SUCCEEDED;
    }

    private function fail(FeeAssessmentRunItem $item, string $reason): string
    {
        $item->forceFill([
            'execution_status' => FeeAssessmentRunItem::EXEC_FAILED,
            'failure_reason' => $reason,
            'executed_at' => now(),
        ])->save();

        return FeeAssessmentRunItem::EXEC_FAILED;
    }

    /** After an unexpected error rolled the item's transaction back: record it failed, if still pending. */
    private function recordFailure(string $runId, string $itemId, string $reason): string
    {
        $run = FeeAssessmentRun::query()->sharedLock()->find($runId);
        $item = FeeAssessmentRunItem::query()->where('fee_assessment_run_id', $runId)->lockForUpdate()->find($itemId);

        if ($run === null || $run->status !== FeeAssessmentRun::STATUS_EXECUTING
            || $item === null || $item->execution_status !== FeeAssessmentRunItem::EXEC_PENDING) {
            return 'not_pending';
        }

        return $this->fail($item, $reason);
    }

    private function isActiveAccount(School $school, string $type, string $accountId): bool
    {
        return $this->ledger->activeAccountsOfType($school, $type)
            ->contains(fn (LedgerAccountSummary $a) => $a->ledgerAccountId === $accountId);
    }
}
