<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\Exceptions\PayrollRunNotEligibleForPayslipException;
use App\Domain\Payroll\Application\Exceptions\PayrollRunNotFoundException;
use App\Domain\Payroll\Application\Exceptions\PayslipNotFoundException;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Domain\Payroll\Infrastructure\PayrollRunResult;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeStatutoryIdentifier;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryCalculationResult;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Privacy\PartialValueMasker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

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
 * Checkpoint 9.6J -- the statutory section additionally requires
 * `payroll.statutory.view` (checked via `Gate`, not `authorizeCapabilityFor()`,
 * since lacking it omits only that section rather than denying the
 * whole payslip) and is populated from the already-frozen
 * `payroll_statutory_calculation_results` row for this exact
 * `PayrollRunResult` only -- never recalculated, never fabricated when
 * absent (`renderStatutorySection()`). Folds into the SAME
 * `payroll.payslip.viewed` audit event below (never a second event)
 * with a boolean flag only -- never the statutory values themselves.
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

            $statutory = null;
            $statutoryIncluded = false;
            if (Gate::forUser($actor)->allows('capability', ['payroll.statutory.view', $school])) {
                $statutory = $this->renderStatutorySection($school, $result->id, $employmentRecordId);
                $statutoryIncluded = $statutory !== null;
            }

            $this->audit->school($school, 'payroll.payslip.viewed', actor: $actor, subject: $run, metadata: [
                'employmentRecordId' => $employmentRecordId,
                'statutoryDataIncluded' => $statutoryIncluded,
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
                statutoryDeductionsIncluded: $statutoryIncluded,
                statutory: $statutory,
            );
        });
    }

    /**
     * Reads the FROZEN `payroll_statutory_calculation_results` row for
     * this exact `PayrollRunResult` -- never recalculates. Returns
     * `null` (never a fabricated all-zero row) when no such row exists
     * -- either the run predates Checkpoint 9.6F statutory
     * calculation, or the calculation genuinely never ran for this
     * result; both cases must render as "statutory figures
     * unavailable," never as "no statutory liability."
     */
    private function renderStatutorySection(School $school, string $payrollRunResultId, string $employmentRecordId): ?PayslipStatutorySection
    {
        $result = PayrollStatutoryCalculationResult::query()
            ->where('school_id', $school->id)
            ->where('payroll_run_result_id', $payrollRunResultId)
            ->first();

        if ($result === null) {
            return null;
        }

        $identifiers = EmployeeStatutoryIdentifier::query()
            ->where('school_id', $school->id)
            ->where('employment_record_id', $employmentRecordId)
            ->get()
            ->keyBy('identifier_type');

        $masked = fn (string $type) => $identifiers->has($type) ? PartialValueMasker::mask($identifiers->get($type)->encrypted_value) : null;

        return new PayslipStatutorySection(
            isPfExcludedEmployee: $result->is_pf_excluded_employee,
            employeePfMandatory: $result->employee_pf_mandatory,
            employeePfVoluntary: $result->employee_pf_voluntary,
            employerPfTotal: $result->employer_pf_total,
            employerEps: $result->employer_eps,
            employerEpf: $result->employer_epf,
            employerEdli: $result->pf_edli,
            esiIsCovered: $result->esi_is_covered,
            employeeEsi: $result->employee_esi,
            employerEsi: $result->employer_esi,
            professionalTax: $result->professional_tax,
            lwfCharged: $result->lwf_charged,
            employeeLwf: $result->employee_lwf,
            employerLwf: $result->employer_lwf,
            tdsMonthlyDeduction: $result->tds_monthly_deduction,
            tdsResidualComplianceException: $result->tds_residual_compliance_exception,
            maskedPan: $masked('pan'),
            maskedUan: $masked('uan'),
            maskedPfMemberId: $masked('pf_member_id'),
            maskedEsicIpNumber: $masked('esic_ip_number'),
        );
    }
}
