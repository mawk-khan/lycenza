<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\LateFeeRuleService;
use App\Domain\Payments\Domain\LateFeeCalculation;
use App\Domain\Payments\Events\LateFeeAssessed;
use App\Domain\Payments\Infrastructure\LateFeeAssessment;
use App\Domain\Payments\Infrastructure\LateFeeRun;
use App\Domain\Payments\Infrastructure\LateFeeRunItem;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Money\Money;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FEE.5 (ADR 0062 §16.2-§16.3; owner decision H): executes pending late-fee
 * items, each in its own transaction, never twice. Preview is never
 * authority: everything is re-checked and recomputed here.
 *
 * Per item, in lock order: run FOR SHARE (a finalize waits) -> the
 * School's lifecycle FOR SHARE (`SchoolOperationalGuard`; a non-operational
 * School pauses the run) -> the item FOR UPDATE (a second worker waits,
 * then sees it is done) -> the rule FOR SHARE (through Fees; an edit or
 * deactivation waits) -> the SOURCE charge FOR UPDATE (through Fees'
 * `ChargeService::lockChargeForAllocation`, the lock payments and
 * concessions take, so the outstanding cannot move underneath).
 *
 * Re-checks, failing closed with a closed reason: rule active and
 * unchanged since the preview (`rule_not_active`), late-fee head and
 * accounts active (`account_invalid`), source still an uncancelled live
 * structure charge in scope (`source_not_eligible`), grace elapsed
 * (`grace_not_elapsed`), current outstanding > 0 (`fully_settled`), final
 * amount > 0 (`zero_amount`). The amount is recomputed from the
 * EXECUTION-TIME outstanding (H), then capped.
 *
 * The late-fee charge (`ChargeService::assess`, the trusted core) and its
 * `late_fee_assessments` row are written in one savepoint; if
 * `late_fee_assessments_one_live_per_rule` refuses the row, the savepoint
 * rolls back the charge and journal entry and the item is
 * `skipped_already_assessed`. No existence pre-check decides (rule 30).
 */
class LateFeeItemExecutor
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly LateFeeRuleService $rules,
        private readonly ChargeOutstandingReader $outstanding,
        private readonly SchoolOperationalGuard $operational,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** @return array{processed: int, paused: bool, pendingRemaining: bool} */
    public function executeBatch(School $school, string $runId, int $limit = self::BATCH_SIZE): array
    {
        $ids = $this->context->withSchool($school, fn () => LateFeeRunItem::query()
            ->where('late_fee_run_id', $runId)
            ->where('execution_status', LateFeeRunItem::EXEC_PENDING)
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

        $pendingRemaining = $this->context->withSchool($school, fn () => LateFeeRunItem::query()
            ->where('late_fee_run_id', $runId)
            ->where('execution_status', LateFeeRunItem::EXEC_PENDING)
            ->exists());

        return ['processed' => $processed, 'paused' => false, 'pendingRemaining' => $pendingRemaining];
    }

    /** @return string one of succeeded, skipped_already_assessed, failed, paused, not_pending, not_executing */
    public function executeItem(School $school, string $runId, string $itemId): string
    {
        try {
            return $this->context->withSchool($school, fn () => DB::transaction(fn () => $this->attempt($school, $runId, $itemId)));
        } catch (Throwable $e) {
            report($e);

            return $this->context->withSchool($school, fn () => DB::transaction(fn () => $this->recordFailure($runId, $itemId)));
        }
    }

    private function attempt(School $school, string $runId, string $itemId): string
    {
        $run = LateFeeRun::query()->where('school_id', $school->id)->sharedLock()->find($runId);
        if ($run === null || $run->status !== LateFeeRun::STATUS_EXECUTING) {
            return 'not_executing';
        }

        if (! $this->operational->holdOperational($school->id)) {
            return 'paused';
        }

        $item = LateFeeRunItem::query()->where('late_fee_run_id', $run->id)->lockForUpdate()->find($itemId);
        if ($item === null || $item->execution_status !== LateFeeRunItem::EXEC_PENDING) {
            return 'not_pending';
        }

        $rule = $this->rules->snapshot($school, $run->fee_late_fee_rule_id, lock: true);
        if ($rule === null || ! $rule->isActive() || $rule->configurationVersion !== $run->previewed_rule_version) {
            return $this->fail($item, LateFeeRunItem::FAIL_RULE_NOT_ACTIVE);
        }
        if (! $rule->accountsValid) {
            return $this->fail($item, LateFeeRunItem::FAIL_ACCOUNT_INVALID);
        }

        ['snapshot' => $snapshot, 'outstanding' => $owed] = $this->outstanding->locked($school, $item->source_charge_id);
        $source = $this->charges->lateFeeSource($school, $item->source_charge_id);
        if ($snapshot->isCancelled || $source === null || $source->feeStructureId !== $rule->feeStructureId
            || ($rule->feeHeadId !== null && $source->feeHeadId !== $rule->feeHeadId)) {
            return $this->fail($item, LateFeeRunItem::FAIL_SOURCE_NOT_ELIGIBLE);
        }

        $evaluation = $run->evaluation_date->toDateString();
        if ($source->dueDate === null || ! LateFeeCalculation::graceElapsed($source->dueDate, $rule->graceDays, $evaluation)) {
            return $this->fail($item, LateFeeRunItem::FAIL_GRACE_NOT_ELAPSED);
        }
        if (! $owed->isPositive()) {
            return $this->fail($item, LateFeeRunItem::FAIL_FULLY_SETTLED, $owed);
        }

        $calc = LateFeeCalculation::amount($rule->kind, $rule->fixedAmount, $rule->percentage, $rule->maxAmount, $owed);
        if (! $calc['final']->isPositive()) {
            return $this->fail($item, LateFeeRunItem::FAIL_ZERO_AMOUNT, $owed);
        }

        $actor = $run->executed_by_user_id === null ? null : User::query()->find($run->executed_by_user_id);

        try {
            $assessment = DB::transaction(function () use ($school, $run, $rule, $source, $calc, $evaluation, $actor) {
                $charge = $this->charges->assess($school, new AssessChargeData(
                    studentId: $source->studentId,
                    academicYearId: $source->academicYearId,
                    description: mb_substr("Late fee: {$source->description}", 0, 255),
                    amount: $calc['final'],
                    receivableLedgerAccountId: $rule->receivableLedgerAccountId,
                    revenueLedgerAccountId: $rule->revenueLedgerAccountId,
                    dueDate: $evaluation,
                ), $actor);

                $assessment = new LateFeeAssessment;
                $assessment->forceFill([
                    'school_id' => $school->id,
                    'source_charge_id' => $source->chargeId,
                    'fee_late_fee_rule_id' => $rule->ruleId,
                    'charge_id' => $charge->chargeId,
                    'late_fee_run_id' => $run->id,
                    'created_by_user_id' => $actor?->id,
                ])->save();

                $this->audit->school($school, 'late_fee.assessed', actor: $actor, subject: $assessment, metadata: [
                    'lateFeeAssessmentId' => $assessment->id,
                    'sourceChargeId' => $source->chargeId,
                    'chargeId' => $charge->chargeId,
                    'lateFeeRuleId' => $rule->ruleId,
                    'runId' => $run->id,
                ]);

                event(new LateFeeAssessed($school->id, $assessment->id, $source->chargeId, $charge->chargeId, $rule->ruleId, $run->id, $calc['final']->currency()));

                return $assessment;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'late_fee_assessments_one_live_per_rule')) {
                throw $e;
            }

            $item->forceFill(['execution_status' => LateFeeRunItem::EXEC_SKIPPED, 'executed_at' => now()])->save();

            return LateFeeRunItem::EXEC_SKIPPED;
        }

        $item->forceFill([
            'execution_status' => LateFeeRunItem::EXEC_SUCCEEDED,
            'late_fee_assessment_id' => $assessment->id,
            'executed_outstanding_amount' => $owed->amount(),
            'executed_amount' => $calc['final']->amount(),
            'executed_cap_applied' => $calc['capApplied'],
            'executed_at' => now(),
        ])->save();

        return LateFeeRunItem::EXEC_SUCCEEDED;
    }

    private function fail(LateFeeRunItem $item, string $reason, ?Money $owed = null): string
    {
        $item->forceFill([
            'execution_status' => LateFeeRunItem::EXEC_FAILED,
            'failure_reason' => $reason,
            'executed_outstanding_amount' => $owed === null ? null : ($owed->isNegative() ? '0.00' : $owed->amount()),
            'executed_at' => now(),
        ])->save();

        return LateFeeRunItem::EXEC_FAILED;
    }

    /** After an unexpected error rolled the item's transaction back: record it failed, if still pending. */
    private function recordFailure(string $runId, string $itemId): string
    {
        $run = LateFeeRun::query()->sharedLock()->find($runId);
        $item = LateFeeRunItem::query()->where('late_fee_run_id', $runId)->lockForUpdate()->find($itemId);
        if ($run === null || $run->status !== LateFeeRun::STATUS_EXECUTING || $item === null || $item->execution_status !== LateFeeRunItem::EXEC_PENDING) {
            return 'not_pending';
        }

        return $this->fail($item, LateFeeRunItem::FAIL_ERROR);
    }
}
