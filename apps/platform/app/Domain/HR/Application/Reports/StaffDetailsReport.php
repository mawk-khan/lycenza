<?php

namespace App\Domain\HR\Application\Reports;

use App\Domain\HR\Application\Reporting\ReportingAsOfReader;
use App\Domain\HR\Application\Workforce\EmploymentType;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\HrReports\HrReport;
use App\Support\HrReports\HrReportColumn as C;
use App\Support\HrReports\HrReportDefinition as D;
use App\Support\HrReports\HrReportRows;
use App\Support\HrReports\HrReportViewer;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * HR.14 (D20-02): the customizable Staff details report -- every employment
 * covering ONE date (default: the School-local today), with the terms and
 * primary placement effective THAT day (WorkforceAsOfReader), never today's
 * values shown as history. One row per employment; the database forbids two
 * employments of one Employee sharing a day, so no person appears twice.
 *
 * Columns are server-decided: only the HR.1A/HR.2A directory-tier facts
 * below. Placement and manager columns also need whole-School
 * `hr.employees.assignments.view`. Personal details, contacts other than the
 * work contact, family, statutory, bank and pay data are never columns
 * (HrReportColumn::PROHIBITED), whatever the reference report lists.
 */
final class StaffDetailsReport implements HrReport
{
    public const string KEY = 'hr.staff_details';

    private const string ASSIGNMENTS = 'hr.employees.assignments.view';

    public function __construct(
        private readonly TenantContext $context,
        private readonly WorkforceAsOfReader $asOf,
        private readonly ReportingAsOfReader $lines,
        private readonly HrReportLabels $labels,
    ) {}

    public function definition(): D
    {
        $placement = fn (string $key, string $label, bool $default = true) => new C($key, $label, 'text', C::RESTRICTED, [self::ASSIGNMENTS], $default);

        return new D(
            key: self::KEY,
            title: 'Staff details',
            description: 'Everyone employed on a chosen date, with the employment terms and primary placement that applied that day. Choose the columns to show.',
            category: 'workforce',
            owner: 'HR',
            authority: ['hr.employees.view'],
            route: D::RENDERED,
            basis: D::AS_OF,
            classification: D::EMPLOYMENT_OPERATIONS,
            reference: ['D20-02', 'D2-13'],
            filters: ['as_of', 'q', 'staff_type', 'category_id', 'campus_id', 'department_id', 'position_id'],
            columns: [
                new C('employee', 'Staff member', 'employee'),
                new C('joined_on', 'Employment start', 'date'),
                new C('employment_type', 'Employment type'),
                new C('staff_type', 'Staff type'),
                new C('category', 'Employee category', 'text', C::OPERATIONAL, [], false),
                $placement('campus', 'Campus'),
                $placement('department', 'Department'),
                $placement('position', 'Designation'),
                $placement('manager', 'Reports to', false),
                new C('current_status', 'Status today'),
                new C('work_email', 'Work email', 'text', C::OPERATIONAL, [], false),
                new C('work_phone', 'Work phone', 'text', C::OPERATIONAL, [], false),
            ],
            sorts: ['name' => 'Name', 'number' => 'Staff number', 'joined' => 'Employment start'],
            perPage: 25,
            customizable: true,
        );
    }

    public function options(School $school, HrReportViewer $viewer): array
    {
        $options = $this->context->withSchool($school, fn () => $this->labels->placementOptions($school));
        if (! $viewer->holdsWholeSchool(self::ASSIGNMENTS)) {
            // Placement filters would disclose placement to a viewer who cannot see it.
            unset($options['campus_id'], $options['department_id'], $options['position_id']);
        }

        return $options + ['staff_type' => StaffType::options()];
    }

    public function defaults(School $school, array $options): array
    {
        return ['as_of' => SchoolTimezone::today($school)];
    }

    public function page(School $school, HrReportViewer $viewer, array $filters, string $sort, int $page, array $columns): LengthAwarePaginator
    {
        $date = $filters['as_of'];
        $placementVisible = $viewer->holdsWholeSchool(self::ASSIGNMENTS);

        return $this->context->withSchool($school, function () use ($school, $filters, $sort, $page, $columns, $date, $placementVisible) {
            $query = $this->asOf->coveringQuery($school, $date, [
                'staffType' => self::nullable($filters['staff_type'] ?? ''),
                'categoryId' => self::nullable($filters['category_id'] ?? ''),
                'campusId' => $placementVisible ? self::nullable($filters['campus_id'] ?? '') : null,
                'departmentId' => $placementVisible ? self::nullable($filters['department_id'] ?? '') : null,
                'positionId' => $placementVisible ? self::nullable($filters['position_id'] ?? '') : null,
            ]);
            HrReportLabels::search($query, 'employment_records.employee_id', $filters['q'] ?? '');
            $query->join('employees as e', fn ($j) => $j->on('e.id', '=', 'employment_records.employee_id')->on('e.school_id', '=', 'employment_records.school_id'))
                ->select('employment_records.*');
            match ($sort) {
                'number' => $query->orderBy('e.employee_number'),
                'joined' => $query->orderBy('employment_records.starts_on')->orderBy('e.full_name'),
                default => $query->orderBy('e.full_name')->orderBy('e.employee_number'),
            };
            $paginator = $query->orderBy('employment_records.id')->paginate(25, ['employment_records.*'], 'page', $page);

            /** @var list<EmploymentRecord> $records */
            $records = $paginator->getCollection()->all();
            $employees = Employee::query()->where('school_id', $school->id)->whereIn('id', array_map(fn ($r) => $r->employee_id, $records))
                ->get(['id', 'employee_number', 'full_name', 'work_email', 'work_phone'])->keyBy(fn ($e) => strtolower($e->id));
            $placements = $this->asOf->placementsOn($school, array_map(fn ($r) => $r->employee_id, $records), $date);
            $wants = array_flip($columns);
            $managers = isset($wants['manager']) ? $this->lines->managerEmployeesOn($school, array_map(fn ($r) => $r->id, $records), $date) : [];
            $managerNames = $managers === [] ? collect() : Employee::query()->where('school_id', $school->id)->whereIn('id', array_values($managers))
                ->get(['id', 'employee_number', 'full_name'])->keyBy(fn ($e) => strtolower($e->id));
            $departments = $this->labels->departments($school, array_map(fn ($p) => $p->departmentId, $placements));
            $positions = $this->labels->positions($school, array_map(fn ($p) => $p->positionId, $placements));
            $campuses = $this->labels->campuses($school, array_map(fn ($p) => $p->campusId, $placements));
            $categories = $this->labels->categories($school, array_map(fn ($p) => $p->employeeCategoryId, $placements));

            return HrReportRows::map($paginator, function (EmploymentRecord $r) use ($employees, $placements, $managers, $managerNames, $departments, $positions, $campuses, $categories, $wants) {
                $employee = $employees[strtolower($r->employee_id)] ?? null;
                $p = $placements[strtolower($r->employee_id)] ?? null;
                $manager = isset($managers[strtolower($r->id)]) ? ($managerNames[strtolower($managers[strtolower($r->id)])] ?? null) : null;
                $all = [
                    'employee' => HrReportLabels::employee($r->employee_id, $employee?->full_name, $employee?->employee_number),
                    'joined_on' => $r->starts_on->toDateString(),
                    'employment_type' => $p?->employmentType === null ? null : EmploymentType::tryFrom($p->employmentType)?->label(),
                    'staff_type' => $p?->staffType === null ? null : StaffType::tryFrom($p->staffType)?->label(),
                    'category' => $p?->employeeCategoryId === null ? null : ($categories[$p->employeeCategoryId] ?? null),
                    'campus' => $p?->campusId === null ? null : ($campuses[$p->campusId] ?? null),
                    'department' => $p?->departmentId === null ? null : ($departments[$p->departmentId] ?? null),
                    'position' => $p?->positionId === null ? null : ($positions[$p->positionId] ?? null),
                    'manager' => $manager === null ? null : trim($manager->full_name.' ('.$manager->employee_number.')'),
                    'current_status' => HrReportLabels::STATUS_LABELS[$r->status] ?? $r->status,
                    'work_email' => $employee?->work_email,
                    'work_phone' => $employee?->work_phone,
                ];

                return ['key' => $r->id] + array_intersect_key($all, $wants);
            });
        });
    }

    public function hasAny(School $school, HrReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn () => EmploymentRecord::query()->where('school_id', $school->id)->where('status', '<>', 'draft')->exists());
    }

    private static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
