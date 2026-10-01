<?php

namespace App\Domain\Payroll\Application\Retention;

use App\Domain\HR\Application\Retention\EmployeeRetentionEligibility;
use App\Models\School;
use App\Support\Retention\ReferencingRows;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E21-D9 employment and payroll evidence (docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): Payroll's own per-Employee
 * configuration is kept 8 calendar years after the Employee's final
 * separation (EmployeeRetentionEligibility), then deleted. Only
 * `platform:employee-retention-prune` calls this, never a request.
 *
 * - Scope: compensation assignments (their append-only values follow by
 *   their FK cascade) and the statutory profiles (identifiers, tax, PF,
 *   ESI).
 * - Never while any payroll RESULT, adjustment or LWF charge references the
 *   employment. Those are payroll evidence posted to the ledger (D8). The
 *   ledger has no financial-year close or carried-forward balances yet, so
 *   they are never expired (determination, D8). The Employee then counts
 *   as `dependency_blocked` and everything is kept.
 * - Runs before HR's evidence purge, which keeps the Employee root while
 *   any of these rows remain.
 */
final class PayrollEmployeeRetentionService
{
    /** Payroll's own per-employment rows this purge removes. */
    public const TABLES = [
        'employee_compensation_assignments', 'employee_statutory_identifiers', 'employee_tax_profile',
        'employee_pf_status', 'employee_esi_coverage',
    ];

    public function __construct(
        private readonly EmployeeRetentionEligibility $employees,
        private readonly ReferencingRows $references,
    ) {}

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function prune(School $school, string $cutoffDate, int $batch, bool $dryRun, ?string $only = null): array
    {
        $employments = fn (string $employeeId): array => DB::table('employment_records')->where('employee_id', $employeeId)->pluck('id')->all();

        return $this->employees->purgeSeparatedBefore(
            $school,
            $cutoffDate,
            $batch,
            $dryRun,
            fn (Builder $q) => $q->whereExists(fn (Builder $e) => $e->selectRaw('1')->from('employment_records as er')->whereColumn('er.employee_id', 'employees.id')
                ->where(function (Builder $any): void {
                    foreach (self::TABLES as $table) {
                        $any->orWhereExists(fn (Builder $p) => $p->selectRaw('1')->from($table)->whereColumn("{$table}.employment_record_id", 'er.id'));
                    }
                })),
            function (string $employeeId) use ($school, $employments): array {
                $ids = $employments($employeeId);
                $blocker = $this->references->first('employment_records', $school->id, $ids, ['employee_assignments', ...self::TABLES]);
                if ($blocker !== null) {
                    return [$blocker];
                }

                $compensation = DB::table('employee_compensation_assignments')->whereIn('employment_record_id', $ids)->pluck('id')->all();

                return array_filter([$this->references->first('employee_compensation_assignments', $school->id, $compensation, ['compensation_assignment_values'])]);
            },
            function (string $employeeId) use ($employments): ?array {
                $ids = $employments($employeeId);
                $deleted = 0;
                foreach (self::TABLES as $table) {
                    $deleted += DB::table($table)->whereIn('employment_record_id', $ids)->delete();
                }

                return $deleted > 0 ? [] : null;
            },
            $only,
        );
    }
}
