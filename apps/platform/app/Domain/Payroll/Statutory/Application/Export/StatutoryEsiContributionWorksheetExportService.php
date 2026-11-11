<?php

namespace App\Domain\Payroll\Statutory\Application\Export;

use App\Domain\HR\Application\EmploymentRoster;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryRunResultsExpiredException;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeEsiCoverage;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryCalculationResult;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\StudentReports\Export\CsvCell;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Checkpoint 9.6G (ADR 0036 correction addendum §1.8, Section 9) --
 * the ESIC monthly contribution worksheet: data preparation only, no
 * government portal submission. One row per EmploymentRecord with a
 * statutory result for the run: contribution period, coverage-at-
 * period-start, a continuity flag (was this employee ALSO covered in
 * the immediately preceding contribution period -- the observable
 * consequence of ADR 0036's "decided once, never re-evaluated
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
        private readonly EmploymentRoster $roster,
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

            $results = PayrollStatutoryCalculationResult::query()
                ->whereHas('payrollRunResult', fn ($q) => $q->where('payroll_run_id', $run->id))
                ->with('payrollRunResult')
                ->get();

            // HR.R1-C24 (WO-3, D2-R08): names from HR's directory-tier reader, built once before the row loop, never per row.
            $names = $this->names($school, $results);
            $rows = [self::HEADER];
            foreach ($results as $result) {
                $rows[] = $this->buildRow($school->id, $result, $names);
            }

            $this->audit->school($school, 'payroll.statutory.export.esi_worksheet_generated', actor: $actor, subject: $run, metadata: [
                'rowCount' => count($rows) - 1,
            ]);

            return implode("\n", $rows);
        });
    }

    /** @param  array<string, ?string>  $names */
    private function buildRow(string $schoolId, PayrollStatutoryCalculationResult $result, array $names): string
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
            '"'.str_replace('"', '""', CsvCell::safe($this->nameOf($names, $employmentRecordId))).'"',
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

    /**
     * HR.R1-C24 (WO-3, D2-R08): each result's current Employee name, keyed by employment, in one batched read. A
     * result's Employee is its employment's (both written once, at creation and calculation). A missing name
     * fails the export, as the eager-loaded relation did: never a filing row without a name.
     *
     * @param  Collection<int, PayrollStatutoryCalculationResult>  $results
     * @return array<string, ?string>
     */
    private function names(School $school, Collection $results): array
    {
        $ids = $results->map(fn (PayrollStatutoryCalculationResult $r) => $r->payrollRunResult->employment_record_id)->unique()->values()->all();

        return collect($this->roster->labels($school, $ids))->mapWithKeys(fn (array $l) => [$l['employmentRecordId'] => $l['fullName']])->all();
    }

    /** @param  array<string, ?string>  $names  a missing label or a null name fails the export, never an empty cell */
    private function nameOf(array $names, string $employmentRecordId): string
    {
        return $names[$employmentRecordId] ?? throw new LogicException("No HR name for employment {$employmentRecordId}.");
    }
}
