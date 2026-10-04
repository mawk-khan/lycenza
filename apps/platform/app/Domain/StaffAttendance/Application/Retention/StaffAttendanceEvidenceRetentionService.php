<?php

namespace App\Domain\StaffAttendance\Application\Retention;

use App\Domain\HR\Application\Retention\EmployeeRetentionEligibility;
use App\Domain\HR\Application\Retention\EmployeeSeparation;
use App\Models\School;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionMetrics;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * HRX.6 (E21-D9, ADR 0065 §27; project-adopted, pending legal
 * ratification): Staff Attendance's own D9 participant. One Employee's
 * attendance records, each with its whole append-only correction history,
 * are kept 8 calendar years after the Employee's final separation
 * (EmployeeRetentionEligibility; EMPLOYEE_EVIDENCE_RETENTION_YEARS), then
 * deleted together. Only `platform:employee-retention-prune` (and a
 * reviewed erasure case through the same purge) calls this, never a
 * request.
 *
 * One Employee per transaction: the fixed Staff Attendance retention
 * function re-proves the tenant, the 8-year floor and the separation,
 * takes the HRX locks, refuses while any record or correction is younger than the cutoff (a late
 * correction is new evidence with its own clock), and deletes the
 * corrections before their records -- never a record without its history,
 * never a correction without its record. Refusals count as
 * `dependency_blocked` and keep everything.
 *
 * Payroll's frozen HRX snapshots (`payroll_run_hrx_inputs`) are Payroll
 * evidence: they reference no attendance row and are never touched here.
 *
 * Counts only; no identifier, date or status leaves this class.
 */
final class StaffAttendanceEvidenceRetentionService
{
    /** The Staff Attendance tables this purge removes per Employee (HR's dry run counts them cleared). */
    public const TABLES = ['staff_attendance_records', 'staff_attendance_corrections'];

    /** Database refusals that mean "keep it" rather than "something failed". */
    private const KEEP = [
        RetentionExpiry::REFUSED_HRX_YOUNGER_ROW => 'staff_attendance_records',
        RetentionExpiry::REFUSED_EMPLOYEE_NOT_SEPARATED => 'employment_records',
        RetentionExpiry::REFUSED_HELD => 'retention_school_holds',
    ];

    public function __construct(
        private readonly EmployeeRetentionEligibility $employees,
        private readonly RetentionExpiry $expiry,
    ) {}

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function prune(School $school, string $cutoffDate, int $batch, bool $dryRun, ?string $only = null): array
    {
        // E21-RH.2: the whole unit runs as the dedicated retention identity (the runtime role cannot execute the
        // purge function). Its recheck is a plain read: the function locks the Employee and re-proves it.
        return $this->expiry->privileged(RetentionMetrics::STAFF_ATTENDANCE_EVIDENCE, $dryRun, fn (): array => $this->employees->purgeSeparatedBefore(
            $school,
            $cutoffDate,
            $batch,
            $dryRun,
            fn (Builder $q) => $q->whereExists(fn (Builder $r) => $r->selectRaw('1')->from('staff_attendance_records as r')->whereColumn('r.employee_id', 'employees.id')),
            fn (string $employeeId): array => $this->kept(fn () => $this->expiry->hrxEmployeeEvidence('staff_attendance', $school, $employeeId, $cutoffDate, true)),
            fn (string $employeeId): ?array => $this->expiry->hrxEmployeeEvidence('staff_attendance', $school, $employeeId, $cutoffDate, false) > 0 ? [] : null,
            $only,
            fn (string $employeeId): ?EmployeeSeparation => $this->employees->readSeparation($employeeId),
        ));
    }

    /**
     * The database's own verdict, in a savepoint: [] when it would expire the
     * unit, the keeping reason when it refuses on a younger row or the
     * separation. Any other failure propagates (an error, never a keep).
     *
     * @param  callable(): int  $check
     * @return list<string>
     */
    private function kept(callable $check): array
    {
        try {
            DB::transaction(fn () => $check());

            return [];
        } catch (QueryException $e) {
            foreach (self::KEEP as $marker => $reason) {
                if (str_contains($e->getMessage(), $marker)) {
                    return [$reason];
                }
            }

            throw $e;
        }
    }
}
