<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * HRX.2 (ADR 0065 §6, §23.6); HR.3A (dated reporting lines): HR's answer to
 * "who is this employment's manager on this date?". It is read fresh from
 * `reporting_relationships` and never snapshotted.
 *
 * - The line is the employment's one live (not voided, not empty) reporting
 *   relationship covering the date; the database allows at most one.
 * - The manager is that line's manager employment's Employee. The database
 *   keeps the line inside both employments' dates, so a line is never
 *   answered for a manager who was not employed that day.
 * - Assignments play no part: a transfer or promotion (a new assignment of
 *   the same employment) never changes the answer.
 *
 * `holdManagerOf()` reads the line and the manager's employment FOR SHARE in
 * the caller's transaction. A concurrent change, removal or correction of the
 * line (an UPDATE of that row) and a concurrent end of the manager's
 * employment (an UPDATE of that row) therefore either commit first or wait.
 *
 * It identifies a relationship; it authorizes nothing. HR depends on no
 * consumer of this answer.
 */
class ReportingLine
{
    public function __construct(private readonly TenantContext $context) {}

    /** The manager Employee id, or null when the employment has no manager on the date. */
    public function managerOf(School $school, string $employmentRecordId, string $date): ?string
    {
        return $this->resolve($school, $employmentRecordId, $date, lock: false);
    }

    /** managerOf(), with the line and the manager's employment held FOR SHARE until the caller's transaction ends. */
    public function holdManagerOf(School $school, string $employmentRecordId, string $date): ?string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ReportingLine::holdManagerOf() must run inside a database transaction.');
        }

        return $this->resolve($school, $employmentRecordId, $date, lock: true);
    }

    /**
     * The EmploymentRecord ids whose manager (by managerOf()) is
     * `$managerEmployeeId` on the date. One query.
     *
     * @return list<string>
     */
    public function reportsOf(School $school, string $managerEmployeeId, string $date): array
    {
        return $this->context->withSchool($school, fn (): array => ReportingRelationship::query()
            ->where('reporting_relationships.school_id', $school->id)
            ->effectiveOn($date)
            ->whereIn('manager_employment_id', EmploymentRecord::query()->where('school_id', $school->id)->where('employee_id', $managerEmployeeId)->select('id'))
            ->orderBy('subordinate_employment_id')
            ->pluck('subordinate_employment_id')->map(fn ($id) => (string) $id)->values()->all());
    }

    private function resolve(School $school, string $employmentRecordId, string $date, bool $lock): ?string
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $date, $lock): ?string {
            $line = ReportingRelationship::query()->where('school_id', $school->id)
                ->where('subordinate_employment_id', $employmentRecordId)->effectiveOn($date)
                ->when($lock, fn ($q) => $q->sharedLock())->first();
            if ($line === null) {
                return null;
            }

            $managerEmployeeId = EmploymentRecord::query()->where('school_id', $school->id)->whereKey($line->manager_employment_id)
                ->when($lock, fn ($q) => $q->sharedLock())->value('employee_id');

            return $managerEmployeeId === null ? null : (string) $managerEmployeeId;
        });
    }
}
