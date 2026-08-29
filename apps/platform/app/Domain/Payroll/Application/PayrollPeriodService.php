<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.3 -- the sole write path for `payroll_periods` (ADR 0032
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

    public function open(PayrollPeriod $period, User $actor): PayrollPeriod
    {
        $school = $period->school;

        return $this->context->withSchool($school, function () use ($school, $period, $actor) {
            return DB::transaction(function () use ($school, $period, $actor) {
                $period->update(['status' => 'open']);

                $this->audit->school($school, 'payroll.period.opened', actor: $actor, subject: $period);

                return $period->fresh();
            });
        });
    }

    public function close(PayrollPeriod $period, User $actor): PayrollPeriod
    {
        $school = $period->school;

        return $this->context->withSchool($school, function () use ($school, $period, $actor) {
            return DB::transaction(function () use ($school, $period, $actor) {
                $period->update(['status' => 'closed']);

                $this->audit->school($school, 'payroll.period.closed', actor: $actor, subject: $period);

                return $period->fresh();
            });
        });
    }
}
