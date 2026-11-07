<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
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
 * `holdManagerOf()` holds both employments and the line FOR SHARE in the
 * caller's transaction. A concurrent change, removal or correction of the line
 * and a concurrent end of either employment therefore commit first or wait.
 *
 * HR.R1-C9: employments before the line, the order `EmploymentService::end()`
 * and every line writer use. Taking the line first was a real deadlock on both
 * sides: against the manager's end, and against the subordinate's end, because a
 * leave approval goes on to hold the subordinate's employment. Which employment to
 * lock is only known from the line, so the line is read first without a lock and
 * read again, held, afterwards. A line replaced in between is simply read again, a
 * bounded number of times. After that there is no held answer, so there is no
 * manager, which every caller refuses.
 *
 * It identifies a relationship; it authorizes nothing. HR depends on no
 * consumer of this answer.
 */
class ReportingLine
{
    private const HOLD_ATTEMPTS = 3;

    public function __construct(private readonly TenantContext $context) {}

    /** The manager Employee id, or null when the employment has no manager on the date. */
    public function managerOf(School $school, string $employmentRecordId, string $date): ?string
    {
        return $this->resolve($school, $employmentRecordId, $date, lock: false);
    }

    /** managerOf(), with both employments and the line held FOR SHARE until the caller's transaction ends. */
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
            if ($lock) {
                return $this->hold($school, $employmentRecordId, $date);
            }
            $line = $this->lineOn($school, $employmentRecordId, $date)->first();

            return $line === null ? null : $this->employeeOf($school, $line->manager_employment_id);
        });
    }

    private function hold(School $school, string $employmentRecordId, string $date): ?string
    {
        for ($attempt = 0; $attempt < self::HOLD_ATTEMPTS; $attempt++) {
            $seen = $this->lineOn($school, $employmentRecordId, $date)->first();
            if ($seen === null) {
                return null;
            }

            EmploymentRecord::query()->where('school_id', $school->id)
                ->whereIn('id', [$employmentRecordId, $seen->manager_employment_id])->orderBy('id')->sharedLock()->get(['id']);

            $held = $this->lineOn($school, $employmentRecordId, $date)->sharedLock()->first();
            if ($held === null) {
                return null;
            }
            if ($held->manager_employment_id === $seen->manager_employment_id) {
                return $this->employeeOf($school, $held->manager_employment_id);
            }
        }

        return null;
    }

    /** @return Builder<ReportingRelationship> */
    private function lineOn(School $school, string $employmentRecordId, string $date): Builder
    {
        return ReportingRelationship::query()->where('school_id', $school->id)
            ->where('subordinate_employment_id', $employmentRecordId)->effectiveOn($date);
    }

    private function employeeOf(School $school, string $employmentRecordId): ?string
    {
        $employeeId = EmploymentRecord::query()->where('school_id', $school->id)->whereKey($employmentRecordId)->value('employee_id');

        return $employeeId === null ? null : (string) $employeeId;
    }
}
