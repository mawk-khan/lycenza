<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\LateFeeRuleService;
use App\Domain\Fees\Application\LateFeeRuleSnapshot;
use App\Domain\Fees\Application\LateFeeSourceFacts;
use App\Domain\Payments\Application\Exceptions\InvalidLateFeeRunException;
use App\Domain\Payments\Application\Exceptions\LateFeeRunIllegalStateException;
use App\Domain\Payments\Application\Exceptions\LateFeeRunNotFoundException;
use App\Domain\Payments\Application\Exceptions\LateFeeRunOpenConflictException;
use App\Domain\Payments\Application\Exceptions\LateFeeRunStalePreviewException;
use App\Domain\Payments\Domain\LateFeeCalculation;
use App\Domain\Payments\Infrastructure\LateFeeAssessment;
use App\Domain\Payments\Infrastructure\LateFeeRun;
use App\Domain\Payments\Infrastructure\LateFeeRunItem;
use App\Jobs\ExecuteLateFeeRunJob;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * FEE.5 (ADR 0062 §16.2; owner decision H): the staff-facing lifecycle of
 * a late-fee run -- create, preview, execute, resume, cancel -- plus the
 * trusted finalize/pause steps the job calls. Late fees are posted only by
 * `LateFeeItemExecutor`. Payments owns this because eligibility depends on
 * allocations; rule and charge facts come through Fees' Application layer.
 * Legal status: DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED (ADR
 * 0058 E31/E32).
 *
 * - Every staff method needs `finance.fee_assessments.run` (no new
 *   capability). Reads live in `LateFeeReadService`.
 * - A run is one ACTIVE rule at one `evaluation_date` (a School-calendar
 *   date, default today in the School's timezone, never in the future);
 *   one open run per rule. No scheduler.
 * - Preview creates no financial effect. Candidates are the rule's scope
 *   (Fees `ChargeService::lateFeeCandidates`); each is `ready`, or
 *   `not_eligible` (`grace_not_elapsed`, `fully_settled`, `zero_amount`),
 *   or `already_assessed` (advisory; the database key decides at
 *   execution). Amounts shown are the preview outstanding and the capped
 *   late fee -- never authority: execution recomputes everything.
 * - Execution needs a current preview: the rule's `configuration_version`
 *   must equal the version previewed and the rule must still be active.
 */
class LateFeeRunService
{
    use AuthorizesCapability;

    private const CAPABILITY = 'finance.fee_assessments.run';

    public function __construct(
        private readonly LateFeeRuleService $rules,
        private readonly ChargeService $charges,
        private readonly ChargeOutstandingReader $outstanding,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function create(School $school, string $ruleId, ?string $evaluationDate, User $actor): LateFeeRun
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        $today = Carbon::now($school->timezone)->toDateString();
        $evaluationDate ??= $today;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $evaluationDate) !== 1 || ! checkdate((int) substr($evaluationDate, 5, 2), (int) substr($evaluationDate, 8, 2), (int) substr($evaluationDate, 0, 4))) {
            throw new InvalidLateFeeRunException('evaluation_date', 'Enter a valid evaluation date (YYYY-MM-DD).');
        }
        if ($evaluationDate > $today) {
            throw new InvalidLateFeeRunException('evaluation_date', 'The evaluation date cannot be in the future.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $ruleId, $evaluationDate, $actor) {
            $rule = $this->rules->snapshot($school, $ruleId, lock: true);
            if ($rule === null || ! $rule->isActive()) {
                throw new InvalidLateFeeRunException('fee_late_fee_rule_id', 'Choose an active late-fee rule.');
            }

            $run = new LateFeeRun;
            $run->forceFill([
                'school_id' => $school->id,
                'fee_late_fee_rule_id' => $rule->ruleId,
                'evaluation_date' => $evaluationDate,
                'status' => LateFeeRun::STATUS_DRAFT,
                'created_by_user_id' => $actor->id,
            ]);

            try {
                $run->save();
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'late_fee_runs_one_open_per_rule')) {
                    throw $e;
                }

                throw new LateFeeRunOpenConflictException;
            }

            $this->audit->school($school, 'late_fee_run.created', actor: $actor, subject: $run, metadata: [
                'runId' => $run->id,
                'lateFeeRuleId' => $rule->ruleId,
                'evaluationDate' => $evaluationDate,
            ]);

            return $run->refresh();
        }));
    }

    public function preview(School $school, string $runId, User $actor): LateFeeRun
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $runId, $actor) {
            $run = $this->lockRun($school, $runId);
            if (! in_array($run->status, [LateFeeRun::STATUS_DRAFT, LateFeeRun::STATUS_PREVIEWED], true)) {
                throw new LateFeeRunIllegalStateException($run->status, 'be previewed');
            }

            $rule = $this->rules->snapshot($school, $run->fee_late_fee_rule_id, lock: true);
            if ($rule === null || ! $rule->isActive()) {
                throw new InvalidLateFeeRunException('fee_late_fee_rule_id', 'The late-fee rule is no longer active; cancel this run.');
            }

            $rows = $this->computeItems($school, $run, $rule);

            LateFeeRunItem::query()->where('late_fee_run_id', $run->id)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('late_fee_run_items')->insert($chunk);
            }

            $counts = collect($rows)->countBy('preview_result');
            $ready = collect($rows)->where('preview_result', LateFeeRunItem::RESULT_READY)
                ->reduce(fn (string $sum, array $r) => bcadd($sum, $r['final_amount'], 2), '0.00');

            $run->forceFill([
                'status' => LateFeeRun::STATUS_PREVIEWED,
                'previewed_rule_version' => $rule->configurationVersion,
                'previewed_at' => now(),
                'previewed_by_user_id' => $actor->id,
                'ready_count' => (int) ($counts[LateFeeRunItem::RESULT_READY] ?? 0),
                'ready_amount' => $ready,
                'not_eligible_count' => (int) ($counts[LateFeeRunItem::RESULT_NOT_ELIGIBLE] ?? 0),
                'already_assessed_count' => (int) ($counts[LateFeeRunItem::RESULT_ALREADY_ASSESSED] ?? 0),
            ])->save();

            $this->audit->school($school, 'late_fee_run.previewed', actor: $actor, subject: $run, metadata: [
                'runId' => $run->id,
                'ruleVersion' => $rule->configurationVersion,
                'readyCount' => $run->ready_count,
                'notEligibleCount' => $run->not_eligible_count,
                'alreadyAssessedCount' => $run->already_assessed_count,
            ]);

            return $run->refresh();
        }));
    }

    public function execute(School $school, string $runId, User $actor): LateFeeRun
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, function () use ($school, $runId, $actor) {
            $run = DB::transaction(function () use ($school, $runId, $actor) {
                $run = $this->lockRun($school, $runId);
                if ($run->status === LateFeeRun::STATUS_DRAFT) {
                    throw new LateFeeRunStalePreviewException($run->id);
                }
                if ($run->status !== LateFeeRun::STATUS_PREVIEWED) {
                    throw new LateFeeRunIllegalStateException($run->status, 'execute');
                }

                $rule = $this->rules->snapshot($school, $run->fee_late_fee_rule_id, lock: true);
                if ($rule === null || ! $rule->isActive() || $rule->configurationVersion !== $run->previewed_rule_version) {
                    throw new LateFeeRunStalePreviewException($run->id);
                }

                $run->forceFill([
                    'status' => LateFeeRun::STATUS_EXECUTING,
                    'execution_started_at' => now(),
                    'executed_by_user_id' => $actor->id,
                ])->save();

                $pending = LateFeeRunItem::query()
                    ->where('late_fee_run_id', $run->id)
                    ->where('preview_result', LateFeeRunItem::RESULT_READY)
                    ->update(['execution_status' => LateFeeRunItem::EXEC_PENDING, 'updated_at' => now()]);

                $this->audit->school($school, 'late_fee_run.execution_started', actor: $actor, subject: $run, metadata: [
                    'runId' => $run->id,
                    'pendingCount' => $pending,
                ]);

                return $run->refresh();
            });

            ExecuteLateFeeRunJob::dispatch($run->id);

            // A synchronously-run job clears the tenant context; re-enter it.
            return $this->context->withSchool($school, fn () => $run->refresh());
        });
    }

    /** Re-queues an executing run (after a crash, timeout or School pause). Only pending items are picked up. */
    public function resume(School $school, string $runId, User $actor): LateFeeRun
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, function () use ($school, $runId) {
            $run = (Str::isUuid($runId) ? LateFeeRun::query()->where('school_id', $school->id)->find($runId) : null)
                ?? throw new LateFeeRunNotFoundException($runId);
            if ($run->status !== LateFeeRun::STATUS_EXECUTING) {
                throw new LateFeeRunIllegalStateException($run->status, 'resume');
            }

            ExecuteLateFeeRunJob::dispatch($run->id);

            return $this->context->withSchool($school, fn () => $run->refresh());
        });
    }

    public function cancel(School $school, string $runId, User $actor): LateFeeRun
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $runId, $actor) {
            $run = $this->lockRun($school, $runId);
            if (! in_array($run->status, [LateFeeRun::STATUS_DRAFT, LateFeeRun::STATUS_PREVIEWED], true)) {
                throw new LateFeeRunIllegalStateException($run->status, 'be cancelled');
            }

            $run->forceFill(['status' => LateFeeRun::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by_user_id' => $actor->id])->save();

            $this->audit->school($school, 'late_fee_run.cancelled', actor: $actor, subject: $run, metadata: ['runId' => $run->id]);

            return $run->refresh();
        }));
    }

    /**
     * Trusted (the job): once no item is pending, `executing -> completed |
     * completed_with_errors` with one audit. Idempotent.
     */
    public function finalize(School $school, string $runId): ?LateFeeRun
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $runId) {
            $run = $this->lockRun($school, $runId);
            if ($run->status !== LateFeeRun::STATUS_EXECUTING) {
                return null;
            }

            $counts = LateFeeRunItem::query()
                ->where('late_fee_run_id', $run->id)
                ->whereNotNull('execution_status')
                ->selectRaw('execution_status, count(*) as n, coalesce(sum(executed_amount), 0) as total')
                ->groupBy('execution_status')
                ->get()
                ->keyBy('execution_status');

            if (isset($counts[LateFeeRunItem::EXEC_PENDING])) {
                return null;
            }

            $failed = (int) ($counts[LateFeeRunItem::EXEC_FAILED]->n ?? 0);
            $status = $failed > 0 ? LateFeeRun::STATUS_COMPLETED_WITH_ERRORS : LateFeeRun::STATUS_COMPLETED;

            $run->forceFill([
                'status' => $status,
                'completed_at' => now(),
                'succeeded_count' => (int) ($counts[LateFeeRunItem::EXEC_SUCCEEDED]->n ?? 0),
                'skipped_count' => (int) ($counts[LateFeeRunItem::EXEC_SKIPPED]->n ?? 0),
                'failed_count' => $failed,
                'assessed_amount' => (string) ($counts[LateFeeRunItem::EXEC_SUCCEEDED]->total ?? '0.00'),
            ])->save();

            $actor = $run->executed_by_user_id === null ? null : User::query()->find($run->executed_by_user_id);
            $this->audit->school($school, 'late_fee_run.'.$status, actor: $actor, subject: $run, metadata: [
                'runId' => $run->id,
                'succeededCount' => $run->succeeded_count,
                'skippedCount' => $run->skipped_count,
                'failedCount' => $run->failed_count,
            ]);

            return $run->refresh();
        }));
    }

    /** Trusted (the job): a suspended School paused an executing run. Nothing else changes. */
    public function recordPaused(School $school, string $runId): void
    {
        $this->context->withSchool($school, function () use ($school, $runId) {
            $run = LateFeeRun::query()->where('school_id', $school->id)->find($runId);
            if ($run !== null && $run->status === LateFeeRun::STATUS_EXECUTING) {
                $this->audit->school($school, 'late_fee_run.paused', subject: $run, metadata: ['runId' => $run->id, 'reason' => 'school_not_operational']);
            }
        });
    }

    private function lockRun(School $school, string $runId): LateFeeRun
    {
        $run = Str::isUuid($runId) ? LateFeeRun::query()->where('school_id', $school->id)->lockForUpdate()->find($runId) : null;

        return $run ?? throw new LateFeeRunNotFoundException($runId);
    }

    /** @return list<array<string, mixed>> */
    private function computeItems(School $school, LateFeeRun $run, LateFeeRuleSnapshot $rule): array
    {
        $candidates = $this->charges->lateFeeCandidates($school, $rule->feeStructureId, $rule->feeHeadId);
        $amounts = [];
        foreach ($candidates as $c) {
            $amounts[$c->chargeId] = Money::of($c->amount, $c->currency);
        }
        $outstanding = $this->outstanding->forCharges($school, $amounts);
        $live = LateFeeAssessment::query()
            ->where('fee_late_fee_rule_id', $rule->ruleId)
            ->whereNull('voided_at')
            ->pluck('source_charge_id')
            ->flip();
        $evaluation = $run->evaluation_date->toDateString();
        $now = now();

        return array_map(function (LateFeeSourceFacts $c) use ($school, $run, $rule, $outstanding, $live, $evaluation, $now) {
            $due = $c->dueDate ?? $evaluation;
            $owed = $outstanding[$c->chargeId];
            $calc = $owed->isPositive()
                ? LateFeeCalculation::amount($rule->kind, $rule->fixedAmount, $rule->percentage, $rule->maxAmount, $owed)
                : ['calculated' => Money::of('0.00', $c->currency), 'final' => Money::of('0.00', $c->currency), 'capApplied' => false];

            [$result, $reason] = match (true) {
                isset($live[$c->chargeId]) => [LateFeeRunItem::RESULT_ALREADY_ASSESSED, LateFeeRunItem::REASON_ALREADY_ASSESSED],
                $c->dueDate === null || ! LateFeeCalculation::graceElapsed($due, $rule->graceDays, $evaluation) => [LateFeeRunItem::RESULT_NOT_ELIGIBLE, LateFeeRunItem::REASON_GRACE_NOT_ELAPSED],
                ! $owed->isPositive() => [LateFeeRunItem::RESULT_NOT_ELIGIBLE, LateFeeRunItem::REASON_FULLY_SETTLED],
                ! $calc['final']->isPositive() => [LateFeeRunItem::RESULT_NOT_ELIGIBLE, LateFeeRunItem::REASON_ZERO_AMOUNT],
                default => [LateFeeRunItem::RESULT_READY, null],
            };

            return [
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'late_fee_run_id' => $run->id,
                'source_charge_id' => $c->chargeId,
                'student_id' => $c->studentId,
                'academic_year_id' => $c->academicYearId,
                'fee_head_id' => $c->feeHeadId,
                'billing_period_key' => $c->billingPeriodKey,
                'due_date' => $due,
                'final_grace_date' => LateFeeCalculation::finalGraceDate($due, $rule->graceDays),
                'outstanding_amount' => $owed->isNegative() ? '0.00' : $owed->amount(),
                'calculated_amount' => $calc['calculated']->amount(),
                'final_amount' => $calc['final']->amount(),
                'cap_applied' => $calc['capApplied'],
                'currency' => $c->currency,
                'preview_result' => $result,
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $candidates);
    }
}
