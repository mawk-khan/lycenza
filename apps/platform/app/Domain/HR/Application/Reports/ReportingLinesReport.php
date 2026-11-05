<?php

namespace App\Domain\HR\Application\Reports;

use App\Domain\HR\Application\Reporting\ReportingAsOfReader;
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
 * HR.14 (D20-10, D4-04): the reporting hierarchy AS OF a date, from HR.3A's
 * dated employment-to-employment lines only (ReportingAsOfReader) -- never a
 * mutable "manager" pointer and never today's line shown for a past date.
 * Every employment covering the date, its manager on that date, and its
 * level (chain length: 0 = reports to nobody; any depth, not the reference
 * pack's Level 0-3). "Without a manager" lists the unassigned lines.
 *
 * A line grants nothing (rule 131): seeing it here needs whole-School
 * `hr.employees.view` AND `hr.employees.assignments.view`; a team role
 * (`team_task_manager`, `line_manager`) never opens this report.
 */
final class ReportingLinesReport implements HrReport
{
    public const string KEY = 'hr.reporting_lines';

    public function __construct(
        private readonly TenantContext $context,
        private readonly WorkforceAsOfReader $asOf,
        private readonly ReportingAsOfReader $lines,
        private readonly HrReportLabels $labels,
    ) {}

    public function definition(): D
    {
        return new D(
            key: self::KEY,
            title: 'Reporting lines',
            description: 'Who reported to whom on a chosen date, with each person\'s level in the reporting chain. Find people without a manager.',
            category: 'hierarchy',
            owner: 'HR',
            authority: ['hr.employees.view', 'hr.employees.assignments.view'],
            route: D::RENDERED,
            basis: D::AS_OF,
            classification: D::EMPLOYMENT_OPERATIONS,
            reference: ['D20-10', 'D4-04', 'D4-05', 'D4-11'],
            filters: ['as_of', 'q', 'line', 'department_id'],
            columns: [
                new C('employee', 'Staff member', 'employee'),
                new C('department', 'Department on the date'),
                new C('position', 'Designation on the date'),
                new C('manager', 'Reports to', 'employee'),
                new C('level', 'Level', 'number'),
            ],
            sorts: ['name' => 'Name', 'number' => 'Staff number'],
            perPage: 25,
        );
    }

    public function options(School $school, HrReportViewer $viewer): array
    {
        return [
            'line' => [['value' => 'with_manager', 'label' => 'With a manager'], ['value' => 'without_manager', 'label' => 'Without a manager']],
            'department_id' => $this->context->withSchool($school, fn () => $this->labels->placementOptions($school)['department_id']),
        ];
    }

    public function defaults(School $school, array $options): array
    {
        return ['as_of' => SchoolTimezone::today($school)];
    }

    public function page(School $school, HrReportViewer $viewer, array $filters, string $sort, int $page, array $columns): LengthAwarePaginator
    {
        $date = $filters['as_of'];

        return $this->context->withSchool($school, function () use ($school, $filters, $sort, $page, $date) {
            $query = $this->asOf->coveringQuery($school, $date, ['departmentId' => ($filters['department_id'] ?? '') === '' ? null : $filters['department_id']]);
            if (($filters['line'] ?? '') !== '') {
                $this->lines->constrainLineOn($query, $date, $filters['line'] === 'with_manager');
            }
            HrReportLabels::search($query, 'employment_records.employee_id', $filters['q'] ?? '');
            $query->join('employees as e', fn ($j) => $j->on('e.id', '=', 'employment_records.employee_id')->on('e.school_id', '=', 'employment_records.school_id'));
            $sort === 'number' ? $query->orderBy('e.employee_number') : $query->orderBy('e.full_name')->orderBy('e.employee_number');
            $paginator = $query->orderBy('employment_records.id')->paginate(25, ['employment_records.*', 'e.full_name', 'e.employee_number'], 'page', $page);

            $records = $paginator->getCollection();
            $ids = $records->pluck('id')->map(fn ($id) => (string) $id)->all();
            $placements = $this->asOf->placementsOn($school, $records->pluck('employee_id')->all(), $date);
            $managers = $this->lines->managerEmployeesOn($school, $ids, $date);
            $depths = $this->lines->depthsOn($school, $ids, $date);
            $managerRows = $managers === [] ? collect() : Employee::query()->where('school_id', $school->id)->whereIn('id', array_values($managers))
                ->get(['id', 'employee_number', 'full_name'])->keyBy(fn ($e) => strtolower($e->id));
            $departments = $this->labels->departments($school, array_map(fn ($p) => $p->departmentId, $placements));
            $positions = $this->labels->positions($school, array_map(fn ($p) => $p->positionId, $placements));

            return HrReportRows::map($paginator, function (EmploymentRecord $r) use ($placements, $managers, $managerRows, $depths, $departments, $positions) {
                $p = $placements[strtolower($r->employee_id)] ?? null;
                $managerId = $managers[strtolower($r->id)] ?? null;
                $m = $managerId === null ? null : ($managerRows[strtolower($managerId)] ?? null);

                return [
                    'key' => $r->id,
                    'employee' => HrReportLabels::employee($r->employee_id, $r->getAttribute('full_name'), $r->getAttribute('employee_number')),
                    'department' => $p?->departmentId === null ? null : ($departments[$p->departmentId] ?? null),
                    'position' => $p?->positionId === null ? null : ($positions[$p->positionId] ?? null),
                    'manager' => $m === null ? null : HrReportLabels::employee($m->id, $m->full_name, $m->employee_number),
                    'level' => $depths[strtolower($r->id)] ?? 0,
                ];
            });
        });
    }

    public function hasAny(School $school, HrReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn () => EmploymentRecord::query()->where('school_id', $school->id)->where('status', '<>', 'draft')->exists());
    }
}
