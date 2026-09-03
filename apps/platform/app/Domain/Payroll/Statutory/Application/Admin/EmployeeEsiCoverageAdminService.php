<?php

namespace App\Domain\Payroll\Statutory\Application\Admin;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryEmploymentRecordNotFoundException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryEsiCoverageLockedException;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeEsiCoverage;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryCalculationResult;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint 9.6I (Section 1 "ESI coverage") -- administrative read/
 * correct for `employee_esi_coverage`. Continuity is NEVER a stored,
 * independently-mutable field -- it is derived (was the employee ALSO
 * covered in the immediately preceding contribution period), exactly
 * as `StatutoryEsiContributionWorksheetExportService` already derives
 * it; this service exposes that same derivation on read rather than
 * inventing a second, independently-editable source of truth that
 * could disagree with it.
 *
 * A period's coverage is correctable ONLY before any statutory
 * calculation has consumed it (`StatutoryEsiCoverageLockedException`
 * otherwise) -- ADR 0035 correction addendum §1.8's "decided once"
 * rule, extended to admin correction: changing coverage after a
 * calculation/payslip/Finance posting already relied on it would
 * silently rewrite history.
 */
class EmployeeEsiCoverageAdminService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return Collection<int, array{coverage: EmployeeEsiCoverage, continuous: bool}>
     */
    public function history(School $school, string $employmentRecordId, User $actor): Collection
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $actor) {
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.view', $school);
            $this->assertEmploymentRecordExists($school, $employmentRecordId);

            $rows = EmployeeEsiCoverage::query()
                ->where('school_id', $school->id)
                ->where('employment_record_id', $employmentRecordId)
                ->orderBy('period_start')
                ->get();

            $previousCovered = null;
            $result = collect();
            foreach ($rows as $row) {
                $result->push([
                    'coverage' => $row,
                    'continuous' => $previousCovered === true && $row->is_covered,
                ]);
                $previousCovered = $row->is_covered;
            }

            return $result;
        });
    }

    public function correct(School $school, string $employmentRecordId, string $periodStart, string $periodEnd, string $entryWage, bool $isCovered, User $actor): EmployeeEsiCoverage
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $periodStart, $periodEnd, $entryWage, $isCovered, $actor) {
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.manage', $school);
            $this->assertEmploymentRecordExists($school, $employmentRecordId);

            return DB::transaction(function () use ($school, $employmentRecordId, $periodStart, $periodEnd, $entryWage, $isCovered, $actor) {
                $existing = EmployeeEsiCoverage::query()
                    ->where('school_id', $school->id)
                    ->where('employment_record_id', $employmentRecordId)
                    ->where('period_start', $periodStart)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $consumed = PayrollStatutoryCalculationResult::query()
                        ->whereHas('payrollRunResult', fn ($q) => $q
                            ->where('employment_record_id', $employmentRecordId)
                            ->whereHas('run.period', fn ($qq) => $qq
                                ->where('period_month', '>=', $periodStart)
                                ->where('period_month', '<=', $periodEnd)))
                        ->exists();

                    if ($consumed) {
                        throw new StatutoryEsiCoverageLockedException($employmentRecordId, $periodStart);
                    }
                }

                $coverage = EmployeeEsiCoverage::query()->updateOrCreate(
                    ['school_id' => $school->id, 'employment_record_id' => $employmentRecordId, 'period_start' => $periodStart],
                    ['period_end' => $periodEnd, 'entry_wage' => $entryWage, 'is_covered' => $isCovered],
                );

                $this->audit->school($school, 'payroll.statutory.esi_coverage.corrected', actor: $actor, subject: $coverage, metadata: [
                    'employmentRecordId' => $employmentRecordId,
                    'periodStart' => $periodStart,
                ]);

                return $coverage;
            });
        });
    }

    private function assertEmploymentRecordExists(School $school, string $employmentRecordId): void
    {
        $exists = EmploymentRecord::query()->where('school_id', $school->id)->where('id', $employmentRecordId)->exists();

        if (! $exists) {
            throw new StatutoryEmploymentRecordNotFoundException($employmentRecordId);
        }
    }
}
