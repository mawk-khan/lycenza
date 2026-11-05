<?php

namespace App\Domain\HR\Application\Reports;

use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
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
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * HR.14: the workforce movement register -- a projection of canonical HR.1A
 * history over a period, never a movement engine or a count:
 *
 *   joined / rejoined   an employment's start (`rejoined` when the Employee
 *                       had an earlier employment in this School: a rehire
 *                       is a new employment, never a resurrected one)
 *   transfer, promotion, designation_change, department_change
 *                       a named placement change (`employee_placement_changes`):
 *                       a Campus transfer is movement, never a join or a leave
 *   ended               an employment's end in an ended status
 *
 * One canonical fact = one row. Initial placements and primary corrections
 * are not movements. Only dates up to the School-local today (history, not a
 * plan). Placements are the assignments the fact names, so later changes
 * never rewrite a row. Whole-School `hr.employees.view` AND
 * `hr.employees.assignments.view`.
 */
final class StaffMovementReport implements HrReport
{
    public const string KEY = 'hr.staff_movements';

    public const int MAX_RANGE_DAYS = 366;

    public const array MOVEMENTS = [
        'joined' => 'Joined', 'rejoined' => 'Rejoined (new employment)',
        'transfer' => 'Transfer', 'promotion' => 'Promotion', 'designation_change' => 'Designation change', 'department_change' => 'Department change',
        'ended' => 'Employment ended',
    ];

    private const array ENDED = ['separated', 'terminated', 'retired', 'deceased'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly HrReportLabels $labels,
    ) {}

    public function definition(): D
    {
        return new D(
            key: self::KEY,
            title: 'Staff movements',
            description: 'Joins, rejoins, transfers, promotions, designation and department changes, and employment ends in a period, from employment history.',
            category: 'lifecycle',
            owner: 'HR',
            authority: ['hr.employees.view', 'hr.employees.assignments.view'],
            route: D::RENDERED,
            basis: D::PERIOD,
            classification: D::EMPLOYMENT_OPERATIONS,
            reference: ['D20-03', 'D20-04', 'D18-02', 'D18-05', 'D18-06', 'D18-07', 'D18-08', 'D18-14', 'D18-18'],
            filters: ['from', 'to', 'movement', 'q'],
            columns: [
                new C('date', 'Date', 'date'),
                new C('employee', 'Staff member', 'employee'),
                new C('movement', 'Movement'),
                new C('from', 'From'),
                new C('to', 'To'),
                new C('detail', 'Reason or status'),
            ],
            sorts: ['date_desc' => 'Newest first', 'date_asc' => 'Oldest first'],
            perPage: 25,
            maxRangeDays: self::MAX_RANGE_DAYS,
        );
    }

    public function options(School $school, HrReportViewer $viewer): array
    {
        return ['movement' => array_map(fn (string $k, string $l) => ['value' => $k, 'label' => $l], array_keys(self::MOVEMENTS), self::MOVEMENTS)];
    }

    public function defaults(School $school, array $options): array
    {
        $today = SchoolTimezone::today($school);

        return ['from' => CarbonImmutable::parse($today)->startOfMonth()->toDateString(), 'to' => $today];
    }

    public function page(School $school, HrReportViewer $viewer, array $filters, string $sort, int $page, array $columns): LengthAwarePaginator
    {
        $today = SchoolTimezone::today($school);
        $from = $filters['from'];
        $to = min($filters['to'], $today);

        return $this->context->withSchool($school, function () use ($school, $filters, $sort, $page, $from, $to) {
            $query = DB::query()->fromSub($this->events($school, $from, $to), 'm');
            if (($filters['movement'] ?? '') !== '') {
                $query->where('m.movement', $filters['movement']);
            }
            HrReportLabels::search($query, 'm.employee_id', $filters['q'] ?? '');
            $direction = $sort === 'date_asc' ? 'asc' : 'desc';
            $paginator = $query->orderBy('m.on_date', $direction)->orderBy('m.source_id', $direction)->paginate(25, ['m.*'], 'page', $page);

            $rows = $paginator->getCollection();
            $employments = $this->labels->employments($school, $rows->pluck('employment_record_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
            $assignmentIds = $rows->flatMap(fn ($r) => [$r->from_assignment_id, $r->to_assignment_id])->filter()->unique()->values()->all();
            $assignments = $assignmentIds === [] ? collect() : EmployeeAssignment::query()->where('school_id', $school->id)->whereIn('id', $assignmentIds)
                ->get(['id', 'campus_id', 'department_id', 'position_id'])->keyBy(fn ($a) => strtolower($a->id));
            $campuses = $this->labels->campuses($school, $assignments->pluck('campus_id')->all());
            $departments = $this->labels->departments($school, $assignments->pluck('department_id')->all());
            $positions = $this->labels->positions($school, $assignments->pluck('position_id')->all());
            $place = function (?string $id) use ($assignments, $campuses, $departments, $positions): ?string {
                $a = $id === null ? null : ($assignments[strtolower($id)] ?? null);
                if ($a === null) {
                    return null;
                }
                $parts = array_filter([$campuses[$a->campus_id] ?? null, $a->department_id ? ($departments[$a->department_id] ?? null) : null, $a->position_id ? ($positions[$a->position_id] ?? null) : null]);

                return $parts === [] ? null : implode(' · ', $parts);
            };

            return HrReportRows::map($paginator, fn ($r) => [
                'key' => $r->movement.':'.$r->source_id,
                'date' => (string) $r->on_date,
                'employee' => $employments[strtolower((string) $r->employment_record_id)]['employee'] ?? null,
                'movement' => self::MOVEMENTS[$r->movement] ?? $r->movement,
                'from' => $place($r->from_assignment_id),
                'to' => $place($r->to_assignment_id),
                'detail' => match (true) {
                    $r->movement === 'ended' => HrReportLabels::STATUS_LABELS[$r->detail] ?? $r->detail,
                    $r->detail !== null => PlacementChangeReason::labelFor((string) $r->detail),
                    default => null,
                },
            ]);
        });
    }

    public function hasAny(School $school, HrReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn () => EmploymentRecord::query()->where('school_id', $school->id)->where('status', '<>', 'draft')->exists());
    }

    /** The three canonical sources as one union (no row is derived twice). */
    private function events(School $school, string $from, string $to): Builder
    {
        $initial = WorkforceAsOfReader::primaryOnSql('er', 'er.starts_on');
        $last = WorkforceAsOfReader::primaryOnSql('er', 'er.ends_on');
        $earlier = "EXISTS (SELECT 1 FROM employment_records p WHERE p.school_id = er.school_id AND p.employee_id = er.employee_id AND p.status <> 'draft' AND p.starts_on < er.starts_on)";

        $starts = DB::table('employment_records as er')->where('er.school_id', $school->id)->where('er.status', '<>', 'draft')
            ->whereBetween('er.starts_on', [$from, $to])
            ->selectRaw("er.id AS employment_record_id, er.employee_id, er.starts_on AS on_date, CASE WHEN {$earlier} THEN 'rejoined' ELSE 'joined' END AS movement, NULL::uuid AS from_assignment_id, {$initial} AS to_assignment_id, NULL::varchar AS detail, er.id AS source_id");

        $changes = DB::table('employee_placement_changes as pc')
            ->join('employment_records as er', fn ($j) => $j->on('er.id', '=', 'pc.employment_record_id')->on('er.school_id', '=', 'pc.school_id'))
            ->where('pc.school_id', $school->id)->whereIn('pc.change_kind', PlacementChangeKind::values())
            ->whereBetween('pc.effective_on', [$from, $to])
            ->selectRaw('pc.employment_record_id, er.employee_id, pc.effective_on AS on_date, pc.change_kind AS movement, pc.from_assignment_id, pc.to_assignment_id, pc.reason_code::varchar AS detail, pc.id AS source_id');

        $ends = DB::table('employment_records as er')->where('er.school_id', $school->id)->whereIn('er.status', self::ENDED)
            ->whereBetween('er.ends_on', [$from, $to])
            ->selectRaw("er.id AS employment_record_id, er.employee_id, er.ends_on AS on_date, 'ended' AS movement, {$last} AS from_assignment_id, NULL::uuid AS to_assignment_id, er.status::varchar AS detail, er.id AS source_id");

        return $starts->unionAll($changes)->unionAll($ends);
    }
}
