<?php

namespace App\Domain\HR\Application\Workforce;

use App\Domain\HR\Application\CurrentEmployeeAssignments;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentTerm;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * HR.1A: THE historical ("as of a date") workforce resolver -- a different
 * question from operational currency, which stays
 * App\Domain\HR\Application\CurrentEmployeeAssignments (ADR 0076 §42):
 *
 *   employmentOn()        the employment whose dates cover the date, in ANY
 *                         status but `draft` (history: a since-separated
 *                         employment still answers for a date it covered)
 *   termsOn()             that employment's terms effective on the date (the
 *                         latest term starting on or before it)
 *   primaryAssignmentOn() its primary assignment covering the date
 *   placementOn()         all three, for one Employee (Campus, Department,
 *                         Position, staff type, employment type, category)
 *   placementsOn()        the same for many Employees in a bounded number of
 *                         queries (never per row)
 *   currentPlacement()    placementOn(today) of the employment
 *                         CurrentEmployeeAssignments calls current -- so
 *                         "current" keeps exactly its canonical definition
 *
 * The answer depends only on dated rows (employment ranges, appended terms,
 * assignment ranges), never on today's Employee row, so it is permanent:
 * after a transfer effective 2026-11-01, 2026-10-15 answers the old Campus
 * and 2026-11-15 the new one, forever. Dates are School-local Y-m-d.
 * Overlaps are refused by the database, so each answer is at most one row.
 *
 * Data, never authorization: callers authorize first. Tenant-safe: explicit
 * `school_id` predicates, inside the School's TenantContext.
 */
final class WorkforceAsOfReader
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CurrentEmployeeAssignments $current,
    ) {}

    public function employmentOn(School $school, string $employeeId, string $date): ?EmploymentRecord
    {
        if (! Str::isUuid($employeeId)) {
            return null;
        }
        $date = self::day($date);

        return $this->context->withSchool($school, fn (): ?EmploymentRecord => $this->employmentsCovering($school, [$employeeId], $date)->first());
    }

    public function termsOn(School $school, string $employmentRecordId, string $date): ?EmploymentTerm
    {
        if (! Str::isUuid($employmentRecordId)) {
            return null;
        }
        $date = self::day($date);

        return $this->context->withSchool($school, fn (): ?EmploymentTerm => $this->termsFor($school, [$employmentRecordId], $date)->get($employmentRecordId));
    }

    public function primaryAssignmentOn(School $school, string $employmentRecordId, string $date): ?EmployeeAssignment
    {
        if (! Str::isUuid($employmentRecordId)) {
            return null;
        }
        $date = self::day($date);

        return $this->context->withSchool($school, fn (): ?EmployeeAssignment => $this->primariesFor($school, [$employmentRecordId], $date)->get($employmentRecordId));
    }

    public function placementOn(School $school, string $employeeId, string $date): ?WorkforcePlacement
    {
        return $this->placementsOn($school, [$employeeId], $date)[strtolower($employeeId)] ?? null;
    }

    /**
     * Three bounded queries for any number of Employees.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, WorkforcePlacement> lower-cased employee id => placement (absent: no employment that day)
     */
    public function placementsOn(School $school, array $employeeIds, string $date): array
    {
        $employeeIds = array_values(array_unique(array_map('strtolower', array_filter($employeeIds, fn (string $id) => Str::isUuid($id)))));
        if ($employeeIds === []) {
            return [];
        }
        $date = self::day($date);

        return $this->context->withSchool($school, function () use ($school, $employeeIds, $date): array {
            $employments = $this->employmentsCovering($school, $employeeIds, $date)->keyBy('id');
            if ($employments->isEmpty()) {
                return [];
            }
            $employmentIds = $employments->keys()->map(fn ($id) => (string) $id)->all();
            $terms = $this->termsFor($school, $employmentIds, $date);
            $primaries = $this->primariesFor($school, $employmentIds, $date);

            $result = [];
            foreach ($employments as $employment) {
                /** @var EmploymentRecord $employment */
                $term = $terms->get($employment->id);
                $primary = $primaries->get($employment->id);
                $result[strtolower($employment->employee_id)] = new WorkforcePlacement(
                    employeeId: $employment->employee_id,
                    date: $date,
                    employmentRecordId: $employment->id,
                    employmentStatus: $employment->status,
                    employmentType: $term?->employment_type,
                    staffType: $term?->staff_type,
                    employeeCategoryId: $term?->employee_category_id,
                    primaryAssignmentId: $primary?->id,
                    campusId: $primary?->campus_id,
                    departmentId: $primary?->department_id,
                    positionId: $primary?->position_id,
                );
            }

            return $result;
        });
    }

    /**
     * Today's placement of the employment CurrentEmployeeAssignments calls
     * operationally current (eligible status, active Employee), or null.
     */
    public function currentPlacement(School $school, string $employeeId): ?WorkforcePlacement
    {
        return $this->context->withSchool($school, function () use ($school, $employeeId): ?WorkforcePlacement {
            if ($this->current->currentEmployment($school, $employeeId) === null) {
                return null;
            }

            return $this->placementOn($school, $employeeId, $this->current->today($school));
        });
    }

    /**
     * X-1 compatibility helper (HR.0A §S): whether the Employee is currently
     * employed by the canonical rule -- for consumers (Timetable, Transport,
     * Visitor) that today read the Employee row directly.
     */
    public function isCurrentlyEmployed(School $school, string $employeeId): bool
    {
        return $this->context->withSchool($school, fn (): bool => $this->current->currentEmployment($school, $employeeId) !== null);
    }

    /**
     * @param  list<string>  $employeeIds
     * @return Collection<int, EmploymentRecord>
     */
    private function employmentsCovering(School $school, array $employeeIds, string $date): Collection
    {
        return EmploymentRecord::query()
            ->where('employment_records.school_id', $school->id)
            ->whereIn('employment_records.employee_id', $employeeIds)
            ->where('employment_records.status', '<>', 'draft')
            ->where('employment_records.starts_on', '<=', $date)
            ->where(fn ($w) => $w->whereNull('employment_records.ends_on')->orWhere('employment_records.ends_on', '>=', $date))
            ->orderBy('employment_records.starts_on')
            ->get();
    }

    /**
     * The term effective on $date per employment: the latest starting on or
     * before it (terms are contiguous from the employment's start).
     *
     * @param  list<string>  $employmentIds
     * @return Collection<string, EmploymentTerm>
     */
    private function termsFor(School $school, array $employmentIds, string $date): Collection
    {
        return EmploymentTerm::query()
            ->where('school_id', $school->id)
            ->whereIn('employment_record_id', $employmentIds)
            ->where('effective_from', '<=', $date)
            ->orderBy('employment_record_id')
            ->orderByDesc('effective_from')
            ->get()
            ->unique('employment_record_id')
            ->keyBy('employment_record_id');
    }

    /**
     * @param  list<string>  $employmentIds
     * @return Collection<string, EmployeeAssignment>
     */
    private function primariesFor(School $school, array $employmentIds, string $date): Collection
    {
        return EmployeeAssignment::query()
            ->where('school_id', $school->id)
            ->whereIn('employment_record_id', $employmentIds)
            ->where('is_primary', true)
            ->where('starts_on', '<=', $date)
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->orderBy('employment_record_id')
            ->get()
            ->keyBy('employment_record_id');
    }

    private static function day(string $date): string
    {
        return CarbonImmutable::parse($date)->toDateString();
    }
}
