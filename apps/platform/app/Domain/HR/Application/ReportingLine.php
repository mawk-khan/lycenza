<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * HRX.2 (ADR 0065 §6, §23.6): HR's answer to "who is this employment's
 * manager on this date?". It is read fresh from the live
 * `manager_assignment_id` pointer (8A.5) and never snapshotted.
 *
 * - The subordinate assignment is the employment's assignment open on the
 *   date. With several open, it is the `is_primary` one. With several open
 *   and none primary, the employment has no manager.
 * - Its `manager_assignment_id` must name an assignment that is itself open
 *   on the date. The manager is that assignment's Employee.
 *
 * `holdManagerOf()` reads both assignments FOR SHARE in the caller's
 * transaction. A concurrent ReportingHierarchyService::setManager() (FOR
 * UPDATE on the subordinate) therefore either commits first or waits.
 *
 * It identifies a relationship; it authorizes nothing. HR depends on no
 * consumer of this answer.
 */
class ReportingLine
{
    public function __construct(private readonly TenantContext $context) {}

    /** The manager Employee id, or null when the employment has no current manager. */
    public function managerOf(School $school, string $employmentRecordId, string $date): ?string
    {
        return $this->resolve($school, $employmentRecordId, $date, lock: false);
    }

    /** managerOf(), with both assignments held FOR SHARE until the caller's transaction ends. */
    public function holdManagerOf(School $school, string $employmentRecordId, string $date): ?string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ReportingLine::holdManagerOf() must run inside a database transaction.');
        }

        return $this->resolve($school, $employmentRecordId, $date, lock: true);
    }

    /**
     * The EmploymentRecord ids whose current manager (by managerOf()) is
     * `$managerEmployeeId` on the date.
     *
     * @return list<string>
     */
    public function reportsOf(School $school, string $managerEmployeeId, string $date): array
    {
        return $this->context->withSchool($school, function () use ($school, $managerEmployeeId, $date): array {
            $managerAssignmentIds = $this->open(EmployeeAssignment::query()->where('school_id', $school->id)
                ->whereIn('employment_record_id', EmploymentRecord::query()->where('school_id', $school->id)->where('employee_id', $managerEmployeeId)->select('id')), $date)
                ->pluck('id')->all();
            if ($managerAssignmentIds === []) {
                return [];
            }

            $candidates = $this->open(EmployeeAssignment::query()->where('school_id', $school->id)->whereIn('manager_assignment_id', $managerAssignmentIds), $date)
                ->pluck('employment_record_id')->unique()->values()->all();

            return array_values(array_filter($candidates, fn (string $id) => $this->resolve($school, $id, $date, lock: false) === $managerEmployeeId));
        });
    }

    private function resolve(School $school, string $employmentRecordId, string $date, bool $lock): ?string
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $date, $lock): ?string {
            $open = $this->open(EmployeeAssignment::query()->where('school_id', $school->id)->where('employment_record_id', $employmentRecordId), $date)
                ->orderBy('id')->when($lock, fn ($q) => $q->sharedLock())->get();
            $subordinate = $open->count() === 1 ? $open->first() : $open->firstWhere('is_primary', true);
            if ($subordinate === null || $subordinate->manager_assignment_id === null) {
                return null;
            }

            $manager = $this->open(EmployeeAssignment::query()->where('school_id', $school->id)->whereKey($subordinate->manager_assignment_id), $date)
                ->when($lock, fn ($q) => $q->sharedLock())->first();
            if ($manager === null) {
                return null;
            }

            return EmploymentRecord::query()->where('school_id', $school->id)->whereKey($manager->employment_record_id)->value('employee_id');
        });
    }

    /**
     * @param  Builder<EmployeeAssignment>  $query
     * @return Builder<EmployeeAssignment>
     */
    private function open($query, string $date)
    {
        return $query->where('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date));
    }
}
