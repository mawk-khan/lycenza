<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    /** HRX.4 (ADR 0065 §25.7): read OWN posted payslips, always with ActingEmployee ownership. */
    public const SELF = 'payroll.payslips.self';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly ActingEmployeeResolver $acting,
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

            $includeStatutory = Gate::forUser($actor)->allows('capability', ['payroll.statutory.view', $school]);

            return $this->assemble($school, $run, $result, $employmentRecordId, $includeStatutory, 'payroll.payslip.viewed', $actor);
        });
    }

    /**
     * HRX.4 (ADR 0065 §11, §25.7): the acting Employee's OWN payslips -- runs
     * that are `posted` (never approved-not-posted, calculated or draft) with a
     * result for one of the acting Employee's EmploymentRecords in this School.
     * Period, run and status only: no amount, so the list is not audited.
     * Ownership comes only from ActingEmployeeResolver; no ActingEmployee is the
     * private 404.
     *
     * @return list<array{payrollRunId: string, employmentRecordId: string, runKind: string, periodMonth: string, paymentDate: ?string, postedAt: ?string, isReversed: bool}>
     */
    public function ownPayslips(School $school, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, self::SELF, $school);
        $employmentRecordIds = $this->ownEmploymentRecordIds($school, $actor);

        return $this->context->withSchool($school, function () use ($school, $employmentRecordIds) {
            $results = PayrollRunResult::query()->where('school_id', $school->id)->whereIn('employment_record_id', $employmentRecordIds)
                ->whereIn('payroll_run_id', PayrollRun::query()->where('school_id', $school->id)->where('status', 'posted')->whereNull('results_expired_at')->select('id'))
                ->get(['payroll_run_id', 'employment_record_id']);
            $runs = PayrollRun::query()->where('school_id', $school->id)->whereIn('id', $results->pluck('payroll_run_id')->unique()->values())->with('period')->get()->keyBy('id');
            $reversed = PayrollRunPosting::query()->where('school_id', $school->id)->whereIn('payroll_run_id', $runs->keys())->where('posting_kind', 'reversal')
                ->pluck('payroll_run_id')->unique()->all();

            return $results->map(function (PayrollRunResult $r) use ($runs, $reversed) {
                $run = $runs[$r->payroll_run_id];

                return [
                    'payrollRunId' => $run->id, 'employmentRecordId' => $r->employment_record_id, 'runKind' => $run->run_kind,
                    'periodMonth' => $run->period->period_month->toDateString(), 'paymentDate' => $run->period->payment_date?->toDateString(),
                    'postedAt' => $run->posted_at?->toIso8601String(), 'isReversed' => in_array($run->id, $reversed, true),
                ];
            })->sortByDesc(fn (array $p) => [$p['periodMonth'], $p['postedAt'] ?? ''])->values()->all();
        });
    }

    /**
     * HRX.4 (ADR 0065 §25.7): one OWN posted payslip, through the same
     * assembly as render() -- never recalculated, never copied. The
     * EmploymentRecord must be one of the acting Employee's in this School and
     * the run `posted`; anything else (unknown, unposted, another Employee's,
     * another School's, expired by retention) is ONE identical private 404.
     * The employee's own statutory deductions are included (identifiers
     * masked). Audited as `payroll.payslip.self_viewed`.
     */
    public function renderOwn(School $school, string $payrollRunId, string $employmentRecordId, User $actor): Payslip
    {
        $this->authorizeCapabilityFor($actor, self::SELF, $school);
        if (! in_array($employmentRecordId, $this->ownEmploymentRecordIds($school, $actor), true)) {
            throw self::privateNotFound();
        }

        return $this->context->withSchool($school, function () use ($school, $payrollRunId, $employmentRecordId, $actor) {
            $run = PayrollRun::query()->where('school_id', $school->id)->where('status', 'posted')->whereNull('results_expired_at')->with('period')->find($payrollRunId) ?? throw self::privateNotFound();
            $result = PayrollRunResult::query()->where('school_id', $school->id)->where('payroll_run_id', $run->id)
                ->where('employment_record_id', $employmentRecordId)->with('lines.component')->first() ?? throw self::privateNotFound();

            return $this->assemble($school, $run, $result, $employmentRecordId, true, 'payroll.payslip.self_viewed', $actor);
        });
    }

    public static function privateNotFound(): ModelNotFoundException
    {
        return new ModelNotFoundException('No query results for the payslip.');
    }

    /** @return list<string> the acting Employee's EmploymentRecord ids in this School */
    private function ownEmploymentRecordIds(School $school, User $actor): array
    {
        try {
            $acting = $this->acting->resolve($actor, $school);
        } catch (ActingEmployeeUnavailableException) {
            throw self::privateNotFound();
        }

        return $this->context->withSchool($school, fn () => EmploymentRecord::query()->where('school_id', $school->id)
            ->where('employee_id', $acting->employeeId)->pluck('id')->all());
    }

    private function assemble(School $school, PayrollRun $run, PayrollRunResult $result, string $employmentRecordId, bool $includeStatutory, string $auditEvent, User $actor): Payslip
    {
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
        if ($includeStatutory) {
            $statutory = $this->renderStatutorySection($school, $result->id, $employmentRecordId);
            $statutoryIncluded = $statutory !== null;
        }

        $this->audit->school($school, $auditEvent, actor: $actor, subject: $run, metadata: [
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
