<?php

namespace App\Domain\Payroll\Statutory\Application\Export;

use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeEsiCoverage;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryCalculationResult;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;

/**
 * Checkpoint 9.6G (ADR 0035 correction addendum §1.8, Section 9) --
 * the ESIC monthly contribution worksheet: data preparation only, no
 * government portal submission. One row per EmploymentRecord with a
 * statutory result for the run: contribution period, coverage-at-
 * period-start, a continuity flag (was this employee ALSO covered in
 * the immediately preceding contribution period -- the observable
 * consequence of ADR 0035's "decided once, never re-evaluated
 * mid-period" continuity rule), statutory wage, employee/employer
 * contribution, and a historical-adjustment column.
 *
 * Historical adjustment is always `0.00` in this checkpoint -- no
 * arrears/correction-run integration is wired to this export yet (a
 * disclosed gap, not a fabricated figure); Employee full name is not
 * itself a Highly Sensitive identifier in this codebase's data
 * classification (unlike PAN/UAN/ESIC IP Number), so this export
 * requires only `payroll.statutory.exports.generate` and
 * `payroll.statutory.view` -- never `.identifiers.view`.
 */
class StatutoryEsiContributionWorksheetExportService
{
    use AuthorizesCapability;

    private const HEADER = 'Employee Name,Contribution Period Start,Contribution Period End,Covered At Period Start,Continuity,Statutory Wage,Employee Contribution,Employer Contribution,Historical Adjustment';

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

            $results = PayrollStatutoryCalculationResult::query()
                ->whereHas('payrollRunResult', fn ($q) => $q->where('payroll_run_id', $run->id))
                ->with('payrollRunResult.employee')
                ->get();

            $rows = [self::HEADER];
            foreach ($results as $result) {
                $rows[] = $this->buildRow($school->id, $result);
            }

            $this->audit->school($school, 'payroll.statutory.export.esi_worksheet_generated', actor: $actor, subject: $run, metadata: [
                'rowCount' => count($rows) - 1,
            ]);

            return implode("\n", $rows);
        });
    }

    private function buildRow(string $schoolId, PayrollStatutoryCalculationResult $result): string
    {
        $runResult = $result->payrollRunResult;
        $employmentRecordId = $runResult->employment_record_id;
        $periodMonth = $runResult->run->period->period_month;

        // The coverage row covering THIS calculation cycle's period is
        // the authoritative source (already resolved once by
        // StatutoryPayrollCalculationService) -- never re-decided here.
        $coverage = EmployeeEsiCoverage::query()
            ->where('school_id', $schoolId)
            ->where('employment_record_id', $employmentRecordId)
            ->where('period_start', '<=', $periodMonth)
            ->where('period_end', '>=', $periodMonth)
            ->first();

        $periodStart = $coverage?->period_start?->toDateString() ?? '';
        $periodEnd = $coverage?->period_end?->toDateString() ?? '';
        $coveredAtStart = $coverage !== null ? ($coverage->is_covered ? 'true' : 'false') : 'unknown';

        $continuity = 'false';
        if ($coverage !== null) {
            $priorCoverage = EmployeeEsiCoverage::query()
                ->where('school_id', $schoolId)
                ->where('employment_record_id', $employmentRecordId)
                ->where('period_end', '<', $coverage->period_start)
                ->orderByDesc('period_start')
                ->first();

            $continuity = ($priorCoverage !== null && $priorCoverage->is_covered && $coverage->is_covered) ? 'true' : 'false';
        }

        return implode(',', [
            '"'.str_replace('"', '""', $runResult->employee->full_name).'"',
            $periodStart,
            $periodEnd,
            $coveredAtStart,
            $continuity,
            $result->esi_statutory_wage ?? '0.00',
            $result->employee_esi ?? '0.00',
            $result->employer_esi ?? '0.00',
            '0.00',
        ]);
    }
}
