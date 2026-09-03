<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Application\Exceptions\ConcurrentPeriodTransitionConflictException;
use App\Domain\Payroll\Application\Exceptions\InvalidPeriodTransitionException;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.3 -- the sole write path for `payroll_periods` (ADR 0034
 * "Monthly period model"). `period_month` is the true identity;
 * `starts_on`/`ends_on` are derived here to match the database's own
 * `payroll_periods_month_shape_check` exactly, never independently
 * chosen. Monthly only -- no configurable-frequency calendar engine.
 *
 * Capability gating (`payroll.runs.prepare`) is deliberately deferred
 * to Checkpoint 9.7 -- see `SalaryComponentService`'s identical note.
 */
class PayrollPeriodService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function createPeriod(School $school, Carbon $month, ?Carbon $paymentDate, User $actor): PayrollPeriod
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        return $this->context->withSchool($school, function () use ($school, $start, $end, $paymentDate, $actor) {
            return DB::transaction(function () use ($school, $start, $end, $paymentDate, $actor) {
                $period = PayrollPeriod::query()->create([
                    'school_id' => $school->id,
                    'period_month' => $start->toDateString(),
                    'starts_on' => $start->toDateString(),
                    'ends_on' => $end->toDateString(),
                    'payment_date' => $paymentDate?->toDateString(),
                    'status' => 'draft',
                ]);

                $this->audit->school($school, 'payroll.period.created', actor: $actor, subject: $period, metadata: [
                    'periodMonth' => $period->period_month->toDateString(),
                ]);

                return $period;
            });
        });
    }

    /**
     * Phase 9.11 -- `draft -> open` only. The initial check below is
     * a fast, deterministic failure for the common (non-racing) case;
     * the conditional UPDATE `WHERE status = 'draft'` inside the
     * transaction is the actual concurrency guarantee -- mirroring
     * `PayrollRunService::approve()`'s identical `WHERE status =
     * 'calculated'` claim shape (Checkpoint 9.4). Two concurrent
     * `open()` calls, or an `open()` racing a `close()`, can now never
     * both silently "succeed" -- exactly one claims the transition,
     * the other gets `ConcurrentPeriodTransitionConflictException`.
     */
    public function open(PayrollPeriod $period, User $actor): PayrollPeriod
    {
        $school = $period->school;

        if ($period->status !== 'draft') {
            throw new InvalidPeriodTransitionException($period->status, 'open');
        }

        return $this->context->withSchool($school, function () use ($school, $period, $actor) {
            return DB::transaction(function () use ($school, $period, $actor) {
                $affected = PayrollPeriod::query()
                    ->where('id', $period->id)
                    ->where('status', 'draft')
                    ->update(['status' => 'open']);

                if ($affected === 0) {
                    throw new ConcurrentPeriodTransitionConflictException($period->id);
                }

                $fresh = $period->fresh();
                $this->audit->school($school, 'payroll.period.opened', actor: $actor, subject: $fresh);

                return $fresh;
            });
        });
    }

    /**
     * Phase 9.11 -- `open -> closed` only, same claim-then-verify
     * shape as `open()` above.
     */
    public function close(PayrollPeriod $period, User $actor): PayrollPeriod
    {
        $school = $period->school;

        if ($period->status !== 'open') {
            throw new InvalidPeriodTransitionException($period->status, 'closed');
        }

        return $this->context->withSchool($school, function () use ($school, $period, $actor) {
            return DB::transaction(function () use ($school, $period, $actor) {
                $affected = PayrollPeriod::query()
                    ->where('id', $period->id)
                    ->where('status', 'open')
                    ->update(['status' => 'closed']);

                if ($affected === 0) {
                    throw new ConcurrentPeriodTransitionConflictException($period->id);
                }

                $fresh = $period->fresh();
                $this->audit->school($school, 'payroll.period.closed', actor: $actor, subject: $fresh);

                return $fresh;
            });
        });
    }
}
