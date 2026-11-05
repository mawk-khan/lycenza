<?php

namespace App\Domain\HR\Application\Reports;

use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\HrReports\HrReport;
use App\Support\HrReports\HrReportColumn as C;
use App\Support\HrReports\HrReportDefinition as D;
use App\Support\HrReports\HrReportRows;
use App\Support\HrReports\HrReportViewer;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * HR.14 (D20-06, owner decision 2026-11-05): work anniversaries in a chosen
 * month, derived ONLY from the start date of each person's CURRENT
 * employment (the employment covering the School-local today). A rehire
 * restarts the count: continuous service across employments is not modelled,
 * so nothing is guessed. Completed years = the month's year minus the start
 * year (at least 1). A 29 February start falls on 28 February in a common
 * year. Dates only: never a birthday (D20-05 is not adopted), never a
 * notification or greeting (no notification contract exists).
 */
final class WorkAnniversaryReport implements HrReport
{
    public const string KEY = 'hr.work_anniversaries';

    private const string ASSIGNMENTS = 'hr.employees.assignments.view';

    public function __construct(
        private readonly TenantContext $context,
        private readonly WorkforceAsOfReader $asOf,
        private readonly HrReportLabels $labels,
    ) {}

    public function definition(): D
    {
        return new D(
            key: self::KEY,
            title: 'Work anniversaries',
            description: 'Staff whose current employment reaches a whole year of service in the chosen month, from the employment start date. A rehire starts a new count.',
            category: 'lifecycle',
            owner: 'HR',
            authority: ['hr.employees.view'],
            route: D::RENDERED,
            basis: D::CURRENT,
            classification: D::INTERNAL_DIRECTORY,
            reference: ['D20-06'],
            filters: ['month', 'q', 'department_id'],
            columns: [
                new C('anniversary', 'Anniversary', 'date'),
                new C('employee', 'Staff member', 'employee'),
                new C('joined_on', 'Employment start', 'date'),
                new C('years', 'Years completed', 'number'),
                new C('department', 'Department today', 'text', C::RESTRICTED, [self::ASSIGNMENTS]),
            ],
            sorts: ['date' => 'Date in month', 'name' => 'Name', 'years' => 'Most years first'],
            perPage: 25,
        );
    }

    public function options(School $school, HrReportViewer $viewer): array
    {
        return $viewer->holdsWholeSchool(self::ASSIGNMENTS)
            ? ['department_id' => $this->context->withSchool($school, fn () => $this->labels->placementOptions($school)['department_id'])]
            : [];
    }

    public function defaults(School $school, array $options): array
    {
        return ['month' => substr(SchoolTimezone::today($school), 0, 7)];
    }

    public function page(School $school, HrReportViewer $viewer, array $filters, string $sort, int $page, array $columns): LengthAwarePaginator
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $filters['month']);
        $year = (int) $month->format('Y');
        $monthNo = (int) $month->format('n');
        $today = SchoolTimezone::today($school);
        $departmentVisible = $viewer->holdsWholeSchool(self::ASSIGNMENTS);

        return $this->context->withSchool($school, function () use ($school, $filters, $sort, $page, $year, $monthNo, $month, $today, $departmentVisible) {
            $department = $departmentVisible && ($filters['department_id'] ?? '') !== '' ? $filters['department_id'] : null;
            $query = $this->asOf->coveringQuery($school, $today, ['departmentId' => $department])
                ->whereRaw('extract(month from employment_records.starts_on) = ?', [$monthNo])
                ->whereRaw('extract(year from employment_records.starts_on) < ?', [$year]);
            HrReportLabels::search($query, 'employment_records.employee_id', $filters['q'] ?? '');
            $query->join('employees as e', fn ($j) => $j->on('e.id', '=', 'employment_records.employee_id')->on('e.school_id', '=', 'employment_records.school_id'));
            match ($sort) {
                'name' => $query->orderBy('e.full_name'),
                'years' => $query->orderBy('employment_records.starts_on'),
                default => $query->orderByRaw('extract(day from employment_records.starts_on)')->orderBy('e.full_name'),
            };
            $paginator = $query->orderBy('employment_records.id')->paginate(25, ['employment_records.*', 'e.full_name', 'e.employee_number'], 'page', $page);

            $records = $paginator->getCollection();
            $placements = $departmentVisible ? $this->asOf->placementsOn($school, $records->pluck('employee_id')->all(), $today) : [];
            $departments = $this->labels->departments($school, array_map(fn ($p) => $p->departmentId, $placements));

            return HrReportRows::map($paginator, function (EmploymentRecord $r) use ($year, $month, $placements, $departments) {
                $start = CarbonImmutable::parse($r->starts_on->toDateString());
                $day = min((int) $start->format('j'), (int) $month->endOfMonth()->format('j'));
                $p = $placements[strtolower($r->employee_id)] ?? null;

                return [
                    'key' => $r->id,
                    'anniversary' => $month->setDay($day)->toDateString(),
                    'employee' => HrReportLabels::employee($r->employee_id, $r->getAttribute('full_name'), $r->getAttribute('employee_number')),
                    'joined_on' => $start->toDateString(),
                    'years' => $year - (int) $start->format('Y'),
                    'department' => $p?->departmentId === null ? null : ($departments[$p->departmentId] ?? null),
                ];
            });
        });
    }

    public function hasAny(School $school, HrReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn () => $this->asOf->coveringQuery($school, SchoolTimezone::today($school))->exists());
    }
}
