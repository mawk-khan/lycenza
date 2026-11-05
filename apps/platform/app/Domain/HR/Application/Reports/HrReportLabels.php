<?php

namespace App\Domain\HR\Application\Reports;

use App\Domain\HR\Application\DepartmentReference;
use App\Domain\HR\Application\EmployeeCategoryReference;
use App\Domain\HR\Application\PositionReference;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\Campus;
use App\Models\School;
use App\Support\HrReports\HrReportFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * HR.14: the labels HR reports show for ids on a page -- names from their
 * owners (HR Departments, Positions, Categories; Organization Campuses), one
 * bounded query per kind per page, never per row. Directory-tier only: a
 * staff number and name, never a contact, pay or profile field.
 *
 * Every method MUST run inside the School's TenantContext.
 */
final class HrReportLabels
{
    public const array STATUS_LABELS = [
        'pre_joining' => 'Pre-joining', 'active' => 'Active', 'notice_period' => 'Notice period',
        'separated' => 'Separated', 'terminated' => 'Terminated', 'retired' => 'Retired', 'deceased' => 'Deceased',
    ];

    public function __construct(
        private readonly DepartmentReference $departments,
        private readonly PositionReference $positions,
        private readonly EmployeeCategoryReference $categories,
    ) {}

    /** @return array{name: string, number: string, url: string} */
    public static function employee(string $employeeId, ?string $name, ?string $number): array
    {
        return [
            'name' => (string) $name,
            'number' => (string) $number,
            // A relative path (never an absolute URL, ADR 0054 §8.9); the profile re-authorizes the Employee (HrEmployeeAccess).
            'url' => '/app/hr/employees/'.$employeeId,
        ];
    }

    /**
     * Employment id => its Employee cell, start date and current status.
     *
     * @param  list<string>  $employmentIds
     * @return array<string, array{employeeId: string, employee: array{name: string, number: string, url: string}, startsOn: string, endsOn: ?string, status: string}>
     */
    public function employments(School $school, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }

        return EmploymentRecord::query()->where('school_id', $school->id)->whereIn('id', $employmentIds)
            ->with('employee:id,employee_number,full_name')->get()
            ->mapWithKeys(fn (EmploymentRecord $r) => [strtolower($r->id) => [
                'employeeId' => $r->employee_id,
                'employee' => self::employee($r->employee_id, $r->employee?->full_name, $r->employee?->employee_number),
                'startsOn' => $r->starts_on->toDateString(),
                'endsOn' => $r->ends_on?->toDateString(),
                'status' => $r->status,
            ]])->all();
    }

    /**
     * @param  array<array-key, string|null>  $ids
     * @return array<string, string>
     */
    public function departments(School $school, array $ids): array
    {
        return $this->departments->names($school, self::ids($ids));
    }

    /**
     * @param  array<array-key, string|null>  $ids
     * @return array<string, string>
     */
    public function positions(School $school, array $ids): array
    {
        return $this->positions->names($school, self::ids($ids));
    }

    /**
     * @param  array<array-key, string|null>  $ids
     * @return array<string, string>
     */
    public function categories(School $school, array $ids): array
    {
        return $this->categories->names($school, self::ids($ids));
    }

    /**
     * @param  array<array-key, string|null>  $ids
     * @return array<string, string>
     */
    public function campuses(School $school, array $ids): array
    {
        $ids = self::ids($ids);

        return $ids === [] ? [] : Campus::query()->where('school_id', $school->id)->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Filter options from the owners: active Departments, Positions,
     * Categories and Campuses of this School.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public function placementOptions(School $school): array
    {
        return [
            'department_id' => array_map(fn (array $d) => ['value' => $d['id'], 'label' => $d['name']], $this->departments->options($school)),
            'position_id' => array_map(fn (array $p) => ['value' => $p['id'], 'label' => $p['label']], $this->positions->options($school)),
            'category_id' => array_map(fn (array $c) => ['value' => $c['id'], 'label' => $c['name']], $this->categories->options($school)),
            'campus_id' => Campus::query()->where('school_id', $school->id)->where('status', 'active')->orderBy('name')->orderBy('id')->limit(500)
                ->get(['id', 'name'])->map(fn (Campus $c) => ['value' => $c->id, 'label' => $c->name])->values()->all(),
        ];
    }

    /**
     * Narrows a query to rows whose Employee ($employeeColumn) matches a staff
     * number or name (literal, case-insensitive, wildcards escaped).
     *
     * @template TBuilder of Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function search($query, string $employeeColumn, string $term)
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }
        $like = HrReportFilter::like($term);

        return $query->whereIn($employeeColumn, fn ($q) => $q->select('id')->from('employees')
            ->where(fn ($w) => $w->where('employee_number', 'ilike', $like)->orWhere('full_name', 'ilike', $like)));
    }

    /**
     * The search above, for a query keyed by EMPLOYMENT ($employmentColumn)
     * rather than Employee -- for owners (Leave, Staff Attendance,
     * WorkforceTasks) whose rows name an employment.
     *
     * @template TBuilder of Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function searchEmployment($query, string $employmentColumn, string $term)
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }
        $like = HrReportFilter::like($term);

        return $query->whereIn($employmentColumn, fn ($q) => $q->select('er.id')->from('employment_records as er')
            ->join('employees as se', fn ($j) => $j->on('se.id', '=', 'er.employee_id')->on('se.school_id', '=', 'er.school_id'))
            ->where(fn ($w) => $w->where('se.employee_number', 'ilike', $like)->orWhere('se.full_name', 'ilike', $like)));
    }

    /**
     * Orders a query keyed by EMPLOYMENT by the Employee's name, then number:
     * joins HR's own rows (aliases `oer`, `oe`, inside the School) and orders
     * on them. A join, never a per-row subquery (large Schools). The caller
     * adds its own unique tie-breaker.
     */
    public static function orderByEmploymentName(\Illuminate\Database\Query\Builder $query, string $employmentColumn): \Illuminate\Database\Query\Builder
    {
        return $query
            ->join('employment_records as oer', 'oer.id', '=', $employmentColumn)
            ->join('employees as oe', fn ($j) => $j->on('oe.id', '=', 'oer.employee_id')->on('oe.school_id', '=', 'oer.school_id'))
            ->orderBy('oe.full_name')->orderBy('oe.employee_number');
    }

    /**
     * @param  array<array-key, string|null>  $ids
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && $id !== '')));
    }
}
