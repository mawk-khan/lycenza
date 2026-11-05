<?php

namespace App\Domain\StaffAttendance\Application\Reports;

use App\Domain\HR\Application\Reports\HrReportLabels;
use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\Leave\Application\LeaveCoverageReader;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Models\School;
use App\Support\HrReports\HrReport;
use App\Support\HrReports\HrReportColumn as C;
use App\Support\HrReports\HrReportDefinition as D;
use App\Support\HrReports\HrReportRows;
use App\Support\HrReports\HrReportViewer;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * HR.14 (D9-15): the absentee list -- every recorded employment-day in a
 * range with at least one half whose EFFECTIVE state is absent: recorded
 * absent and not covered by approved leave (approved leave outranks the
 * recorded evidence, exactly as the register composes it). Filtered and
 * paged in SQL (LeaveCoverageReader::constrainHalfUncovered), so no row is
 * dropped after paging and nothing is capped: the whole range is traversed.
 *
 * Only what Staff Attendance stores: half-day present/absent evidence
 * entered by the School. Never a clock time, late arrival, early leave,
 * device, location or photograph (HRX-L2 / OD-HR-14), and an unrecorded
 * half is "not recorded", never absent. The Department is the primary
 * placement ON THAT DATE, shown and filterable only with whole-School
 * `hr.employees.assignments.view`. Whole-School `hr.staff_attendance.view`.
 */
final class StaffAbsenteeReport implements HrReport
{
    public const string KEY = 'hr.attendance_absentees';

    public const int MAX_RANGE_DAYS = 93;

    private const string ASSIGNMENTS = 'hr.employees.assignments.view';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LeaveCoverageReader $leave,
        private readonly WorkforceAsOfReader $asOf,
        private readonly HrReportLabels $labels,
    ) {}

    public function definition(): D
    {
        return new D(
            key: self::KEY,
            title: 'Staff absentees',
            description: 'Recorded staff absences by date: each day with a half recorded absent and not covered by approved leave. Half-day evidence only; no clock times.',
            category: 'attendance',
            owner: 'Staff Attendance',
            authority: ['hr.staff_attendance.view'],
            route: D::RENDERED,
            basis: D::PERIOD,
            classification: D::EMPLOYMENT_OPERATIONS,
            reference: ['D9-15', 'D20-15', 'D20-11'],
            filters: ['from', 'to', 'department_id', 'q'],
            columns: [
                new C('date', 'Date', 'date'),
                new C('employee', 'Staff member', 'employee'),
                new C('department', 'Department on the date', 'text', C::RESTRICTED, [self::ASSIGNMENTS]),
                new C('first_half', 'First half'),
                new C('second_half', 'Second half'),
            ],
            sorts: ['date_desc' => 'Newest first', 'date_asc' => 'Oldest first', 'name' => 'Name'],
            perPage: 25,
            maxRangeDays: self::MAX_RANGE_DAYS,
        );
    }

    public function options(School $school, HrReportViewer $viewer): array
    {
        // Placement is shown and filtered only for whole-School assignment viewers (as in Staff details).
        return $viewer->holdsWholeSchool(self::ASSIGNMENTS)
            ? ['department_id' => $this->context->withSchool($school, fn () => $this->labels->placementOptions($school)['department_id'])]
            : [];
    }

    public function defaults(School $school, array $options): array
    {
        $today = SchoolTimezone::today($school);

        return ['from' => $today, 'to' => $today];
    }

    public function page(School $school, HrReportViewer $viewer, array $filters, string $sort, int $page, array $columns): LengthAwarePaginator
    {
        $placementVisible = $viewer->holdsWholeSchool(self::ASSIGNMENTS);

        return $this->context->withSchool($school, function () use ($school, $filters, $sort, $page, $placementVisible) {
            $query = DB::table('staff_attendance_records as r')->where('r.school_id', $school->id)
                ->whereBetween('r.attendance_date', [$filters['from'], $filters['to']])
                ->where(fn ($any) => $any
                    ->where(fn ($first) => $this->leave->constrainHalfUncovered($first->where('r.first_half_status', 'absent'), 'r.employment_record_id', 'r.attendance_date', 1))
                    ->orWhere(fn ($second) => $this->leave->constrainHalfUncovered($second->where('r.second_half_status', 'absent'), 'r.employment_record_id', 'r.attendance_date', 2)));
            if ($placementVisible && ($filters['department_id'] ?? '') !== '') {
                $this->asOf->constrainPrimaryDepartmentOn($query, 'r.employment_record_id', 'r.attendance_date', $filters['department_id']);
            }
            HrReportLabels::searchEmployment($query, 'r.employment_record_id', $filters['q'] ?? '');
            match ($sort) {
                'date_asc' => $query->orderBy('r.attendance_date'),
                'name' => $query,
                default => $query->orderByDesc('r.attendance_date'),
            };
            HrReportLabels::orderByEmploymentName($query, 'r.employment_record_id');
            if ($sort === 'name') {
                $query->orderByDesc('r.attendance_date');
            }
            $paginator = $query->orderBy('r.id')->paginate(25, ['r.*'], 'page', $page);

            $records = $paginator->getCollection();
            $employmentIds = $records->pluck('employment_record_id')->map(fn ($id) => (string) $id)->unique()->values()->all();
            $labels = $this->labels->employments($school, $employmentIds);
            $coverage = $this->leave->approvedCoverage($school, $employmentIds, $filters['from'], $filters['to']);
            $placements = ! $placementVisible ? [] : $this->asOf->primariesOnPairs($school, $records->map(fn ($r) => [(string) $r->employment_record_id, (string) $r->attendance_date])->values()->all());
            $departments = $this->labels->departments($school, array_column($placements, 'departmentId'));
            $state = fn (?string $recorded, bool $leave) => match (true) {
                $leave => 'On leave',
                $recorded === 'absent' => 'Absent',
                $recorded === 'present' => 'Present',
                default => 'Not recorded',
            };

            return HrReportRows::map($paginator, function ($r) use ($labels, $coverage, $placements, $departments, $state) {
                $date = (string) $r->attendance_date;
                $employment = strtolower((string) $r->employment_record_id);
                $p = $placements[$employment.'|'.$date] ?? null;
                $covered = $coverage[$r->employment_record_id][$date] ?? [];

                return [
                    'key' => $r->id,
                    'date' => $date,
                    'employee' => $labels[$employment]['employee'] ?? null,
                    'department' => $p === null || $p['departmentId'] === null ? null : ($departments[$p['departmentId']] ?? null),
                    'first_half' => $state($r->first_half_status, isset($covered[1])),
                    'second_half' => $state($r->second_half_status, isset($covered[2])),
                ];
            });
        });
    }

    public function hasAny(School $school, HrReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn () => StaffAttendanceRecord::query()->where('school_id', $school->id)->exists());
    }
}
