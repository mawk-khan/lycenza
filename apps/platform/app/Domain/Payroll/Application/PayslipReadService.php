<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\Exceptions\PayrollRunNotEligibleForPayslipException;
use App\Domain\Payroll\Application\Exceptions\PayrollRunNotFoundException;
use App\Domain\Payroll\Application\Exceptions\PayslipNotFoundException;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Domain\Payroll\Infrastructure\PayrollRunResult;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 9.10 -- the sole authorized read path for an on-demand
 * payslip, mirroring `PayrollRunResultReadService`'s exact
 * authorization/disclosure/audit discipline (same single capability,
 * same "authorize before any query runs" order, same typed-DTO-never-
 * raw-model disclosure boundary, same audit-the-fact-never-the-value
 * rule). Never a second write path, never a persisted record --
 * `render()` only ever reads the frozen `payroll_run_results`/`_lines`
 * authority and returns a fresh `Payslip` DTO every call.
 *
 * Eligibility (docs/modules/PAYROLL.md "Payslip rendering"): the run
 * must have crossed `PayrollRun::isApprovedOrLater()` -- `approved` or
 * `posted`, for BOTH `run_kind` values (a correction run follows the
 * identical state machine, so the same check covers it). A `draft`/
 * `calculated` run is still freely recalculable and must never be
 * rendered as an authoritative-looking payslip -- see
 * `PayrollRunNotEligibleForPayslipException`.
 */
class PayslipReadService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function render(School $school, string $payrollRunId, string $employmentRecordId, User $actor): Payslip
    {
        $this->authorizeCapabilityFor($actor, 'payroll.compensation.sensitive.view', $school);

        return $this->context->withSchool($school, function () use ($school, $payrollRunId, $employmentRecordId, $actor) {
            $run = PayrollRun::query()->where('school_id', $school->id)->with('period')->find($payrollRunId);

            if ($run === null) {
                throw new PayrollRunNotFoundException($payrollRunId);
            }

            if (! $run->isApprovedOrLater()) {
                throw new PayrollRunNotEligibleForPayslipException($run->id, $run->status);
            }

            $result = PayrollRunResult::query()
                ->where('school_id', $school->id)
                ->where('payroll_run_id', $run->id)
                ->where('employment_record_id', $employmentRecordId)
                ->with('lines.component')
                ->first();

            if ($result === null) {
                throw new PayslipNotFoundException($run->id, $employmentRecordId);
            }

            $employmentRecord = EmploymentRecord::query()->with('employee')->find($employmentRecordId);

            $isReversed = PayrollRunPosting::query()
                ->where('school_id', $school->id)
                ->where('payroll_run_id', $run->id)
                ->where('posting_kind', 'reversal')
                ->exists();

            $correctsPeriodMonth = null;
            if ($run->isCorrection() && $run->corrects_payroll_run_id !== null) {
                $correctsRun = PayrollRun::query()->where('school_id', $school->id)->with('period')->find($run->corrects_payroll_run_id);
                $correctsPeriodMonth = $correctsRun?->period?->period_month->toDateString();
            }

            $this->audit->school($school, 'payroll.payslip.viewed', actor: $actor, subject: $run, metadata: [
                'employmentRecordId' => $employmentRecordId,
            ]);

            return new Payslip(
                schoolId: $school->id,
                schoolName: $school->name,
                payrollRunId: $run->id,
                runKind: $run->run_kind,
                correctsPayrollRunId: $run->corrects_payroll_run_id,
                correctsPayrollPeriodMonth: $correctsPeriodMonth,
                runStatus: $run->status,
                isReversed: $isReversed,
                payrollPeriodId: $run->payroll_period_id,
                periodMonth: $run->period->period_month->toDateString(),
                paymentDate: $run->period->payment_date?->toDateString(),
                employmentRecordId: $employmentRecordId,
                employeeId: $result->employee_id,
                employeeFullName: $employmentRecord?->employee?->full_name,
                employeeNumber: $employmentRecord?->employee?->employee_number,
                grossAmount: $result->gross_amount,
                totalDeductions: $result->total_deductions,
                netAmount: $result->net_amount,
                lines: $result->lines->map(fn ($line) => PayslipLine::fromModel($line))->all(),
            );
        });
    }
}
