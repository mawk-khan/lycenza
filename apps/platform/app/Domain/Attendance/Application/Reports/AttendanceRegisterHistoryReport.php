<?php

namespace App\Domain\Attendance\Application\Reports;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Attendance\Application\AttendanceAdministrationAccess;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\StudentReports\StudentReport;
use App\Support\StudentReports\StudentReportColumn as C;
use App\Support\StudentReports\StudentReportDefinition;
use App\Support\StudentReports\StudentReportViewer;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * SIS 11.10: Attendance register history -- the SUBMITTED registers
 * (`attendance_sessions`, the authoritative header) over any range, in one
 * governed report: class register history, subject/period registers and
 * recording activity (who submitted each register, OD-SIS-10A).
 *
 * - Authority: `attendance.view` (Campus-adopted), applied in SQL through
 *   AttendanceAdministrationAccess by each register's STORED Campus; a
 *   Campus/Section filter only narrows. Teachers (`attendance.teacher`)
 *   never reach it: their own registers stay on My attendance.
 * - Never filters or authorizes by the timetable's teacher column
 *   (TeacherAttendanceArchitectureGuardTest); "recorded by" is the
 *   submitting User, a closed choice cut to the registers this viewer may
 *   see.
 * - Operational only: no count of Students, rate, percentage, trend or
 *   demographic column (ADR 0040, LC-6 S-02); "Corrected" says whether any
 *   record of the register was corrected, never how many.
 * - Dates are School-local calendar dates compared as dates (never UTC); a
 *   register's submission instant is shown in the School's timezone.
 * - Browser-only (SIS 11.10 §45 review). Bounded: one page query, joined.
 */
final class AttendanceRegisterHistoryReport implements StudentReport
{
    public const string CAPABILITY = 'attendance.view';

    public const array SORTS = [
        'recent' => 'attendance_sessions.attendance_date desc, attendance_sessions.period_start_time desc, attendance_sessions.id asc',
        'oldest' => 'attendance_sessions.attendance_date asc, attendance_sessions.period_start_time asc, attendance_sessions.id asc',
        'class' => 'g.sequence asc, sec.name asc, attendance_sessions.attendance_date desc, attendance_sessions.period_start_time asc, attendance_sessions.id asc',
        'subject' => 'sub.name asc, attendance_sessions.attendance_date desc, attendance_sessions.period_start_time asc, attendance_sessions.id asc',
        'recorded' => 'attendance_sessions.submitted_at desc, attendance_sessions.id asc',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AttendanceAdministrationAccess $access,
    ) {}

    public function definition(): StudentReportDefinition
    {
        return new StudentReportDefinition(
            key: 'attendance.registers',
            title: 'Attendance register history',
            description: 'Submitted registers over any period, by class, subject and who recorded them, with whether a record was later corrected.',
            category: 'attendance',
            owner: 'Attendance',
            authority: [self::CAPABILITY],
            scope: StudentReportDefinition::CAMPUS_AWARE,
            route: StudentReportDefinition::RENDERED,
            filters: ['academic_year_id', 'campus_id', 'grade_level_id', 'section_id', 'subject_id', 'from', 'to', 'recorded_by', 'corrected'],
            columns: [
                new C('date', 'Date', 'date'),
                new C('period', 'Period'),
                new C('class', 'Class', 'placement'),
                new C('subject', 'Subject'),
                new C('recorded_by', 'Recorded by'),
                new C('recorded_at', 'Recorded at'),
                new C('corrected', 'Corrected'),
                new C('register', 'Register', 'link'),
            ],
            sorts: [
                'recent' => 'Newest first',
                'oldest' => 'Oldest first',
                'class' => 'Class, then newest',
                'subject' => 'Subject, then newest',
                'recorded' => 'Most recently recorded',
            ],
            perPage: 50,
            reference: ['SR-29'],
        );
    }

    public function options(School $school, StudentReportViewer $viewer): array
    {
        $scope = $viewer->scope(self::CAPABILITY);

        return $this->context->withSchool($school, function () use ($school, $scope): array {
            $sessions = fn () => $this->sessions($school, $scope);

            return [
                'academic_year_id' => AcademicYear::query()->whereIn('id', $sessions()->select('academic_year_id'))->orderByDesc('starts_on')->get(['id', 'name', 'status'])
                    ->map(fn (AcademicYear $y) => ['value' => (string) $y->id, 'label' => $y->name.($y->status === 'closed' ? ' (closed)' : '')])->all(),
                'campus_id' => Campus::query()->whereIn('id', $sessions()->select('campus_id'))->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Campus $c) => ['value' => (string) $c->id, 'label' => $c->name])->all(),
                'grade_level_id' => GradeLevel::query()->whereIn('id', $sessions()->select('grade_level_id'))->orderBy('sequence')->get(['id', 'name'])
                    ->map(fn (GradeLevel $g) => ['value' => (string) $g->id, 'label' => $g->name])->all(),
                'section_id' => Section::query()->whereIn('sections.id', $sessions()->select('section_id'))
                    ->join('grade_levels', 'grade_levels.id', '=', 'sections.grade_level_id')
                    ->join('academic_years', 'academic_years.id', '=', 'sections.academic_year_id')
                    ->orderByDesc('academic_years.starts_on')->orderBy('grade_levels.sequence')->orderBy('sections.name')
                    ->get(['sections.id', 'sections.name', 'sections.campus_id', 'sections.grade_level_id', 'grade_levels.name as grade_name', 'academic_years.name as year_name'])
                    ->map(fn (Section $s) => ['value' => (string) $s->id, 'label' => "{$s->getAttribute('grade_name')} · {$s->name} ({$s->getAttribute('year_name')})", 'campus_id' => (string) $s->campus_id, 'grade_level_id' => (string) $s->grade_level_id])->all(),
                'subject_id' => Subject::query()->whereIn('id', SubjectOffering::query()->whereIn('id', $sessions()->select('subject_offering_id'))->select('subject_id'))
                    ->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Subject $s) => ['value' => (string) $s->id, 'label' => $s->name])->all(),
                // Only the people who recorded a register this viewer may see.
                'recorded_by' => User::query()->whereIn('id', $sessions()->select('submitted_by_user_id'))->orderBy('name')->get(['id', 'name'])
                    ->map(fn (User $u) => ['value' => (string) $u->id, 'label' => (string) $u->name])->all(),
                'corrected' => [['value' => 'yes', 'label' => 'Corrected'], ['value' => 'no', 'label' => 'Not corrected']],
            ];
        });
    }

    public function page(School $school, StudentReportViewer $viewer, array $filters, string $sort, int $page): LengthAwarePaginator
    {
        $scope = $viewer->scope(self::CAPABILITY);
        $zone = SchoolTimezone::resolve($school);

        return $this->context->withSchool($school, function () use ($school, $scope, $filters, $sort, $page, $zone): LengthAwarePaginator {
            /** @var LengthAwarePaginator<int, object> $raw */
            $raw = $this->query($school, $scope, $filters)
                ->select([
                    'attendance_sessions.id', 'attendance_sessions.attendance_date', 'attendance_sessions.period_start_time', 'attendance_sessions.period_end_time', 'attendance_sessions.submitted_at',
                    'y.name as year_name', 'c.name as campus_name', 'g.name as grade_name', 'sec.name as section_name',
                    'sub.name as subject_name', 'u.name as recorded_by',
                ])
                ->selectRaw('EXISTS (SELECT 1 FROM attendance_records r WHERE r.attendance_session_id = attendance_sessions.id AND r.school_id = attendance_sessions.school_id AND r.corrected_at IS NOT NULL) AS has_correction')
                ->orderByRaw(self::SORTS[$sort] ?? self::SORTS['recent'])
                ->toBase()
                ->paginate($this->definition()->perPage, ['*'], 'page', max(1, $page));

            return $raw->through(fn (object $r): array => $this->present($r, $zone));
        });
    }

    public function hasAny(School $school, StudentReportViewer $viewer): bool
    {
        $scope = $viewer->scope(self::CAPABILITY);

        return $this->context->withSchool($school, fn (): bool => $this->sessions($school, $scope)->exists());
    }

    /** @return array<string, mixed> */
    private function present(object $r, \DateTimeZone $zone): array
    {
        return [
            'key' => (string) $r->id,
            'date' => substr((string) $r->attendance_date, 0, 10),
            'period' => substr((string) $r->period_start_time, 0, 5).'–'.substr((string) $r->period_end_time, 0, 5),
            'class' => ['academicYear' => $r->year_name, 'campus' => $r->campus_name, 'gradeLevel' => $r->grade_name, 'section' => $r->section_name],
            'subject' => $r->subject_name,
            'recorded_by' => $r->recorded_by ?? '—',
            'recorded_at' => CarbonImmutable::parse((string) $r->submitted_at, 'UTC')->setTimezone($zone)->format('j M Y, H:i'),
            'corrected' => $r->has_correction ? 'Yes' : 'No',
            'register' => ['label' => 'Open register', 'url' => '/app/attendance/'.$r->id],
        ];
    }

    /** @return Builder<AttendanceSession> */
    private function sessions(School $school, CampusScope $scope): Builder
    {
        return $this->access->sessions($scope)->where('attendance_sessions.school_id', $school->id);
    }

    /**
     * The scoped registers, joined once for display; filters only narrow. The
     * Campus scope is applied through AttendanceAdministrationAccess on the
     * un-joined header (its stored Campus), as an id subquery.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<AttendanceSession>
     */
    private function query(School $school, CampusScope $scope, array $filters): Builder
    {
        $query = AttendanceSession::query()->where('attendance_sessions.school_id', $school->id)
            ->whereIn('attendance_sessions.id', $this->sessions($school, $scope)->select('attendance_sessions.id'))
            ->join('academic_years as y', 'y.id', '=', 'attendance_sessions.academic_year_id')
            ->join('campuses as c', 'c.id', '=', 'attendance_sessions.campus_id')
            ->join('grade_levels as g', 'g.id', '=', 'attendance_sessions.grade_level_id')
            ->join('sections as sec', 'sec.id', '=', 'attendance_sessions.section_id')
            ->join('subject_offerings as so', 'so.id', '=', 'attendance_sessions.subject_offering_id')
            ->join('subjects as sub', 'sub.id', '=', 'so.subject_id')
            ->leftJoin('users as u', 'u.id', '=', 'attendance_sessions.submitted_by_user_id');

        foreach (['academic_year_id' => 'attendance_sessions.academic_year_id', 'campus_id' => 'attendance_sessions.campus_id', 'grade_level_id' => 'attendance_sessions.grade_level_id', 'section_id' => 'attendance_sessions.section_id', 'subject_id' => 'so.subject_id', 'recorded_by' => 'attendance_sessions.submitted_by_user_id'] as $key => $column) {
            if (is_string($filters[$key] ?? null) && $filters[$key] !== '') {
                $query->where($column, $filters[$key]);
            }
        }
        if (is_string($filters['from'] ?? null) && $filters['from'] !== '') {
            $query->where('attendance_sessions.attendance_date', '>=', $filters['from']);
        }
        if (is_string($filters['to'] ?? null) && $filters['to'] !== '') {
            $query->where('attendance_sessions.attendance_date', '<=', $filters['to']);
        }
        if (in_array($filters['corrected'] ?? null, ['yes', 'no'], true)) {
            $exists = fn ($q) => $q->selectRaw('1')->from('attendance_records as r')->whereColumn('r.attendance_session_id', 'attendance_sessions.id')
                ->whereColumn('r.school_id', 'attendance_sessions.school_id')->whereNotNull('r.corrected_at');
            $filters['corrected'] === 'yes' ? $query->whereExists($exists) : $query->whereNotExists($exists);
        }

        return $query;
    }
}
