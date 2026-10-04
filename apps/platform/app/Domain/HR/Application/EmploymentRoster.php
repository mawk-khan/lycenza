<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * HRX.3 (ADR 0065 §24.4): HR's directory-tier answer to "which employments
 * of this School span this date?" -- for an administrative daily register
 * (Staff Attendance). Labels only: EmploymentRecord id, Employee id, number
 * and name, the employment status, and whether it is in force today
 * (`active`/`notice_period` of an active Employee). Never work contact, HR
 * profile, compensation or account data.
 *
 * It identifies; it authorizes nothing. HR depends on no consumer of this
 * answer.
 */
class EmploymentRoster
{
    private const LIMIT = 2000;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Employments (any status but `draft`) whose dates contain the date.
     *
     * @param  string  $date  School-local Y-m-d
     * @return list<array{employmentRecordId: string, employeeId: string, employeeNumber: ?string, fullName: ?string, status: string, current: bool}>
     */
    public function on(School $school, string $date): array
    {
        return $this->rows($school, fn ($q) => $q->where('status', '<>', 'draft')->where('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date)));
    }

    /**
     * The same labels for these EmploymentRecord ids of the School (another School's ids yield nothing).
     *
     * @param  list<string>  $employmentRecordIds
     * @return list<array{employmentRecordId: string, employeeId: string, employeeNumber: ?string, fullName: ?string, status: string, current: bool}>
     */
    public function labels(School $school, array $employmentRecordIds): array
    {
        if ($employmentRecordIds === []) {
            return [];
        }

        return $this->rows($school, fn ($q) => $q->whereIn('id', $employmentRecordIds));
    }

    /**
     * HRX.4: one EmploymentRecord's dates (inclusive; `endsOn` null = open-ended), or null for another School's / an unknown id.
     *
     * @return array{startsOn: string, endsOn: ?string}|null
     */
    public function span(School $school, string $employmentRecordId): ?array
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId): ?array {
            $record = EmploymentRecord::query()->where('school_id', $school->id)->find($employmentRecordId);

            return $record === null ? null : ['startsOn' => $record->starts_on->toDateString(), 'endsOn' => $record->ends_on?->toDateString()];
        });
    }

    /**
     * @param  callable(Builder<EmploymentRecord>): mixed  $filter
     * @return list<array{employmentRecordId: string, employeeId: string, employeeNumber: ?string, fullName: ?string, status: string, current: bool}>
     */
    private function rows(School $school, callable $filter): array
    {
        return $this->context->withSchool($school, function () use ($school, $filter): array {
            $query = EmploymentRecord::query()->where('school_id', $school->id);
            $filter($query);

            return $query->with('employee:id,employee_number,full_name,record_status')->limit(self::LIMIT)->get()
                ->map(fn (EmploymentRecord $r) => [
                    'employmentRecordId' => $r->id,
                    'employeeId' => $r->employee_id,
                    'employeeNumber' => $r->employee?->employee_number,
                    'fullName' => $r->employee?->full_name,
                    'status' => $r->status,
                    'current' => in_array($r->status, EmploymentCoverage::CURRENT_STATUSES, true) && $r->employee?->isActive() === true,
                ])
                ->sortBy(fn (array $row) => [$row['fullName'] ?? '', $row['employmentRecordId']])->values()->all();
        });
    }
}
