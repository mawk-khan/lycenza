<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Application\Exceptions\PayrollRunNotFoundException;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 9.7 -- the sole authorized read path for Payroll run lists/
 * details that contain NO Highly Sensitive monetary field,
 * mirroring `App\Domain\Payments\Application\PaymentReadService`'s
 * disclosure-boundary/"no oracle" discipline. `payroll_runs` itself
 * carries no amount column at all (see `PayrollRunSummary`'s own
 * docblock) -- an actor holding only `payroll.runs.view` can see that
 * a run exists, its status, and who prepared/approved/posted it, but
 * never any figure from `payroll_run_results`/`_lines`
 * (`PayrollRunResultReadService`, gated by the separate
 * `payroll.compensation.sensitive.view`, is the only path to those).
 */
class PayrollRunReadService
{
    use AuthorizesCapability;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return list<PayrollRunSummary>
     */
    public function listRuns(School $school, PayrollPeriod $period, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.view', $school);

        return $this->context->withSchool($school, function () use ($school, $period) {
            return PayrollRun::query()
                ->where('school_id', $school->id)
                ->where('payroll_period_id', $period->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (PayrollRun $run) => PayrollRunSummary::fromModel($run))
                ->all();
        });
    }

    public function getRun(School $school, string $payrollRunId, User $actor): PayrollRunSummary
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.view', $school);

        return $this->context->withSchool($school, function () use ($school, $payrollRunId) {
            $run = PayrollRun::query()->where('school_id', $school->id)->find($payrollRunId);

            if ($run === null) {
                throw new PayrollRunNotFoundException($payrollRunId);
            }

            return PayrollRunSummary::fromModel($run);
        });
    }
}
