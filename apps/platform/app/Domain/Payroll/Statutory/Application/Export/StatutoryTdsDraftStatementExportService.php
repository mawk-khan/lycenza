<?php

namespace App\Domain\Payroll\Statutory\Application\Export;

use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryFormNotYetEffectiveException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryRunResultsExpiredException;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeStatutoryIdentifier;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeTaxProfile;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryCalculationResult;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Money\Money;
use App\Support\Privacy\PartialValueMasker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Checkpoint 9.6G (ADR 0036 correction addendum §1.4, Section 9) --
 * salary TDS DRAFT DATA PREPARATION for Form 138 periods (April 2026
 * onward) ONLY. This is explicitly NOT: Form 24Q (superseded, never
 * implemented here), an official Form 138/NSDL-FVU file (this
 * checkpoint's specification did not hand down that exact schema, and
 * one is not invented here -- see the field-mapping caveat on this
 * class), Form 16 production or digital signature, or any government
 * portal submission (ADR 0036's hard-deferred register).
 *
 * PAN is masked by default (first 2 / last 2 characters visible,
 * `payroll.statutory.identifiers.view` required to see the full
 * value) -- the narrow-capability-check + masking pattern ADR 0036
 * requires for Highly Sensitive government identifiers. A missing PAN
 * record renders as `NOT_RECORDED`, never fabricated.
 */
class StatutoryTdsDraftStatementExportService
{
    use AuthorizesCapability;

    private const FORM_138_EFFECTIVE_FROM = '2026-04-01';

    private const HEADER = 'Employee Name,PAN,Regime,Gross Salary Paid This Cycle,TDS Deducted This Cycle,Cumulative TDS This Fiscal Year,Residual Compliance Exception';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function generate(PayrollRun $run, User $actor): string
    {
        $school = $run->school;

        return $this->context->withSchool($school, function () use ($school, $run, $actor) {
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.exports.generate', $school);
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.view', $school);
            // E21.3F: never a silently partial filing after payroll retention.
            if ($run->fresh()?->results_expired_at !== null) {
                throw new StatutoryRunResultsExpiredException($run->id);
            }

            $periodMonth = Carbon::parse($run->period->period_month)->startOfMonth();
            if ($periodMonth->lt(Carbon::parse(self::FORM_138_EFFECTIVE_FROM))) {
                throw new StatutoryFormNotYetEffectiveException('Form 138', self::FORM_138_EFFECTIVE_FROM);
            }

            $canViewIdentifiers = Gate::forUser($actor)->allows('capability', ['payroll.statutory.identifiers.view', $school]);

            $fiscalYearStart = $periodMonth->month >= 4
                ? Carbon::create($periodMonth->year, 4, 1)
                : Carbon::create($periodMonth->year - 1, 4, 1);

            $results = PayrollStatutoryCalculationResult::query()
                ->whereHas('payrollRunResult', fn ($q) => $q->where('payroll_run_id', $run->id))
                ->with('payrollRunResult.employee')
                ->get();

            $rows = [self::HEADER];
            foreach ($results as $result) {
                $rows[] = $this->buildRow($school->id, $result, $fiscalYearStart, $periodMonth, $canViewIdentifiers);
            }

            $this->audit->school($school, 'payroll.statutory.export.tds_draft_statement_generated', actor: $actor, subject: $run, metadata: [
                'rowCount' => count($rows) - 1,
            ]);

            return implode("\n", $rows);
        });
    }

    private function buildRow(string $schoolId, PayrollStatutoryCalculationResult $result, Carbon $fiscalYearStart, Carbon $periodMonth, bool $canViewIdentifiers): string
    {
        $runResult = $result->payrollRunResult;
        $employmentRecordId = $runResult->employment_record_id;

        $pan = $this->maskedOrFullPan($schoolId, $employmentRecordId, $canViewIdentifiers);
        $regime = EmployeeTaxProfile::query()
            ->where('school_id', $schoolId)
            ->where('employment_record_id', $employmentRecordId)
            ->where('fiscal_year_start', $fiscalYearStart->toDateString())
            ->value('regime') ?? 'unknown';

        $cumulativeTds = PayrollStatutoryCalculationResult::query()
            ->whereHas('payrollRunResult', fn ($q) => $q
                ->where('employment_record_id', $employmentRecordId)
                ->whereHas('run.period', fn ($qq) => $qq
                    ->where('period_month', '>=', $fiscalYearStart->toDateString())
                    ->where('period_month', '<=', $periodMonth->toDateString())))
            ->get()
            ->reduce(fn (Money $carry, PayrollStatutoryCalculationResult $r) => $carry->add(Money::of($r->tds_monthly_deduction ?? '0.00', 'INR')), Money::of('0.00', 'INR'));

        return implode(',', [
            '"'.str_replace('"', '""', $runResult->employee->full_name).'"',
            $pan,
            $regime,
            $runResult->gross_amount,
            $result->tds_monthly_deduction ?? '0.00',
            $cumulativeTds->amount(),
            $result->tds_residual_compliance_exception ?? '',
        ]);
    }

    private function maskedOrFullPan(string $schoolId, string $employmentRecordId, bool $canViewIdentifiers): string
    {
        $identifier = EmployeeStatutoryIdentifier::query()
            ->where('school_id', $schoolId)
            ->where('employment_record_id', $employmentRecordId)
            ->where('identifier_type', 'pan')
            ->first();

        if ($identifier === null) {
            return 'NOT_RECORDED';
        }

        $pan = $identifier->encrypted_value;

        return $canViewIdentifiers ? $pan : PartialValueMasker::mask($pan);
    }
}
