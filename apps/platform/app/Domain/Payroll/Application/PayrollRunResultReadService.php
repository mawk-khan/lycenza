<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Application\Exceptions\PayrollRunNotFoundException;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunResult;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 9.7 (ADR 0032 "Separation of duties"; docs/modules/PAYROLL.md
 * "Sensitive-data handling") -- the sole authorized read path for an
 * individual Employee's actual payroll result, mirroring
 * `App\Domain\Payments\Application\PaymentReadService`'s exact
 * disclosure-boundary/authorization/audit discipline. Every result is
 * a typed DTO (`PayrollRunResultDetail`), never the raw
 * `PayrollRunResult`/`PayrollRunResultLine` Eloquent models.
 *
 * Single capability, `payroll.compensation.sensitive.view` -- the
 * Highly Sensitive family (`docs/security/DATA-CLASSIFICATION.md`),
 * checked BEFORE any query runs. Audit records the FACT (which run,
 * which actor, how many results) never the VALUE -- no amount ever
 * appears in `metadata`, matching `docs/modules/PAYROLL.md`'s
 * "Sensitive-data handling" section exactly.
 */
class PayrollRunResultReadService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return list<PayrollRunResultDetail>
     */
    public function listResults(School $school, string $payrollRunId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, 'payroll.compensation.sensitive.view', $school);

        return $this->context->withSchool($school, function () use ($school, $payrollRunId, $actor) {
            $run = PayrollRun::query()->where('school_id', $school->id)->find($payrollRunId);

            if ($run === null) {
                throw new PayrollRunNotFoundException($payrollRunId);
            }

            $results = PayrollRunResult::query()
                ->where('school_id', $school->id)
                ->where('payroll_run_id', $run->id)
                ->with('lines')
                ->get();

            $this->audit->school($school, 'payroll.run.results_viewed', actor: $actor, subject: $run, metadata: [
                'resultCount' => $results->count(),
            ]);

            return $results->map(fn (PayrollRunResult $result) => PayrollRunResultDetail::fromModel($result))->all();
        });
    }
}
