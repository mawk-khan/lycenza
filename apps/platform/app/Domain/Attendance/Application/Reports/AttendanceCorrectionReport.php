<?php

namespace App\Domain\Attendance\Application\Reports;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Attendance\Application\AttendanceAdministrationAccess;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceRecordCorrection;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\Student;
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
 * SIS 11.10: Attendance corrections -- every correction of a submitted
 * record, read from the append-only `attendance_record_corrections` rows
 * (never rewritten): the Student, the register, the previous and corrected
 * status, the closed, absence-neutral reason code, who corrected it and when.
 *
 * - Authority: `attendance.view` (Campus-adopted), applied in SQL through
 *   AttendanceAdministrationAccess by each record's STORED Campus; filters
 *   only narrow. Teachers never reach it.
 * - No free text (there is none), no health detail, no raw audit payload,
 *   no count, rate or demographic column.
 * - The Student links to that Student's Attendance history (same
 *   `attendance.view`, which audits the read).
 * - Browser-only (SIS 11.10 §45 review). Bounded: one joined page query.
 */
final class AttendanceCorrectionReport implements StudentReport
{
    public const string CAPABILITY = 'attendance.view';

    public const array STATUS_LABELS = ['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late', 'excused' => 'Excused'];

    public const array REASON_LABELS = [
        'entered_in_error' => 'Entered in error',
        'late_information' => 'Later information',
        'administrative_review' => 'Administrative review',
    ];

    public const array SORTS = [
        'recent' => 'attendance_record_corrections.corrected_at desc, attendance_record_corrections.id desc',
        'date' => 'sess.attendance_date desc, sess.period_start_time desc, attendance_record_corrections.corrected_at desc, attendance_record_corrections.id desc',
        'student' => 'st.last_name asc nulls last, st.first_name asc, attendance_record_corrections.corrected_at desc, attendance_record_corrections.id desc',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AttendanceAdministrationAccess $access,
    ) {}

    public function definition(): StudentReportDefinition
    {
        return new StudentReportDefinition(
            key: 'attendance.corrections',
            title: 'Attendance corrections',
            description: 'Every corrected register record: the Student, the register, the status before and after, the reason, who corrected it and when.',
            category: 'attendance',
            owner: 'Attendance',
            authority: [self::CAPABILITY],
            scope: StudentReportDefinition::CAMPUS_AWARE,
            route: StudentReportDefinition::RENDERED,
            filters: ['q', 'academic_year_id', 'campus_id', 'grade_level_id', 'section_id', 'from', 'to', 'reason', 'corrected_by'],
            columns: [
                new C('student', 'Student', 'student'),
                new C('date', 'Register date', 'date'),
                new C('period', 'Period'),
                new C('class', 'Class', 'placement'),
                new C('subject', 'Subject'),
                new C('change', 'Change'),
                new C('reason', 'Reason'),
                new C('corrected_by', 'Corrected by'),
                new C('corrected_at', 'Corrected at'),
                new C('register', 'Register', 'link'),
            ],
            sorts: ['recent' => 'Most recently corrected', 'date' => 'Register date, newest first', 'student' => 'Student name'],
            perPage: 50,
            reference: ['SR-29'],
        );
    }

    public function options(School $school, StudentReportViewer $viewer): array
    {
        $scope = $viewer->scope(self::CAPABILITY);

        return $this->context->withSchool($school, function () use ($school, $scope): array {
            $records = fn () => $this->records($school, $scope);
            $sessions = fn () => $this->access->sessions($scope)->where('attendance_sessions.school_id', $school->id);

            return [
                'academic_year_id' => AcademicYear::query()->whereIn('id', $records()->select('attendance_records.academic_year_id'))->orderByDesc('starts_on')->get(['id', 'name', 'status'])
                    ->map(fn (AcademicYear $y) => ['value' => (string) $y->id, 'label' => $y->name.($y->status === 'closed' ? ' (closed)' : '')])->all(),
                'campus_id' => Campus::query()->whereIn('id', $records()->select('attendance_records.campus_id'))->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Campus $c) => ['value' => (string) $c->id, 'label' => $c->name])->all(),
                'grade_level_id' => GradeLevel::query()->whereIn('id', $records()->select('attendance_records.grade_level_id'))->orderBy('sequence')->get(['id', 'name'])
                    ->map(fn (GradeLevel $g) => ['value' => (string) $g->id, 'label' => $g->name])->all(),
                'section_id' => Section::query()->whereIn('sections.id', $sessions()->select('section_id'))
                    ->join('grade_levels', 'grade_levels.id', '=', 'sections.grade_level_id')
                    ->join('academic_years', 'academic_years.id', '=', 'sections.academic_year_id')
                    ->orderByDesc('academic_years.starts_on')->orderBy('grade_levels.sequence')->orderBy('sections.name')
                    ->get(['sections.id', 'sections.name', 'sections.campus_id', 'sections.grade_level_id', 'grade_levels.name as grade_name', 'academic_years.name as year_name'])
                    ->map(fn (Section $s) => ['value' => (string) $s->id, 'label' => "{$s->getAttribute('grade_name')} · {$s->name} ({$s->getAttribute('year_name')})", 'campus_id' => (string) $s->campus_id, 'grade_level_id' => (string) $s->grade_level_id])->all(),
                'reason' => array_map(fn (string $code) => ['value' => $code, 'label' => self::REASON_LABELS[$code]], AttendanceRecordCorrection::REASONS),
                // Only the people who corrected a record this viewer may see.
                'corrected_by' => User::query()->whereIn('id', AttendanceRecordCorrection::query()->where('attendance_record_corrections.school_id', $school->id)
                    ->whereIn('attendance_record_id', $records()->select('attendance_records.id'))->select('corrected_by_user_id'))
                    ->orderBy('name')->get(['id', 'name'])
                    ->map(fn (User $u) => ['value' => (string) $u->id, 'label' => (string) $u->name])->all(),
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
                    'attendance_record_corrections.id', 'attendance_record_corrections.from_status', 'attendance_record_corrections.to_status',
                    'attendance_record_corrections.reason_code', 'attendance_record_corrections.corrected_at',
                    'sess.id as session_id', 'sess.attendance_date', 'sess.period_start_time', 'sess.period_end_time',
                    'st.id as student_id', 'st.first_name', 'st.middle_name', 'st.last_name', 'st.student_number',
                    'y.name as year_name', 'c.name as campus_name', 'g.name as grade_name', 'sec.name as section_name',
                    'sub.name as subject_name', 'u.name as corrected_by',
                ])
                ->orderByRaw(self::SORTS[$sort] ?? self::SORTS['recent'])
                ->toBase()
                ->paginate($this->definition()->perPage, ['*'], 'page', max(1, $page));

            return $raw->through(fn (object $r): array => $this->present($r, $zone));
        });
    }

    public function hasAny(School $school, StudentReportViewer $viewer): bool
    {
        $scope = $viewer->scope(self::CAPABILITY);

        return $this->context->withSchool($school, fn (): bool => $this->query($school, $scope, [])->exists());
    }

    /** @return array<string, mixed> */
    private function present(object $r, \DateTimeZone $zone): array
    {
        return [
            'key' => (string) $r->id,
            'student' => [
                'name' => trim(implode(' ', array_filter([$r->first_name, $r->middle_name, $r->last_name], fn ($v) => $v !== null && $v !== ''))),
                'studentNumber' => (string) $r->student_number,
                'url' => '/app/attendance/students/'.$r->student_id,
            ],
            'date' => substr((string) $r->attendance_date, 0, 10),
            'period' => substr((string) $r->period_start_time, 0, 5).'–'.substr((string) $r->period_end_time, 0, 5),
            'class' => ['academicYear' => $r->year_name, 'campus' => $r->campus_name, 'gradeLevel' => $r->grade_name, 'section' => $r->section_name],
            'subject' => $r->subject_name,
            'change' => (self::STATUS_LABELS[$r->from_status] ?? $r->from_status).' → '.(self::STATUS_LABELS[$r->to_status] ?? $r->to_status),
            'reason' => self::REASON_LABELS[$r->reason_code] ?? $r->reason_code,
            'corrected_by' => $r->corrected_by ?? '—',
            'corrected_at' => CarbonImmutable::parse((string) $r->corrected_at)->setTimezone($zone)->format('j M Y, H:i'),
            'register' => ['label' => 'Open register', 'url' => '/app/attendance/'.$r->session_id],
        ];
    }

    /** @return Builder<AttendanceRecord> */
    private function records(School $school, CampusScope $scope): Builder
    {
        return $this->access->records($scope)->where('attendance_records.school_id', $school->id);
    }

    /**
     * Corrections of records in scope (their stored Campus), joined once.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<AttendanceRecordCorrection>
     */
    private function query(School $school, CampusScope $scope, array $filters): Builder
    {
        $query = AttendanceRecordCorrection::query()->where('attendance_record_corrections.school_id', $school->id)
            ->whereIn('attendance_record_corrections.attendance_record_id', $this->records($school, $scope)->select('attendance_records.id'))
            ->join('attendance_records as rec', 'rec.id', '=', 'attendance_record_corrections.attendance_record_id')
            ->join('attendance_sessions as sess', 'sess.id', '=', 'rec.attendance_session_id')
            ->join('student_enrollments as e', 'e.id', '=', 'rec.student_enrollment_id')
            ->join('students as st', 'st.id', '=', 'e.student_id')
            ->join('academic_years as y', 'y.id', '=', 'rec.academic_year_id')
            ->join('campuses as c', 'c.id', '=', 'rec.campus_id')
            ->join('grade_levels as g', 'g.id', '=', 'rec.grade_level_id')
            ->join('sections as sec', 'sec.id', '=', 'rec.section_id')
            ->join('subject_offerings as so', 'so.id', '=', 'sess.subject_offering_id')
            ->join('subjects as sub', 'sub.id', '=', 'so.subject_id')
            ->leftJoin('users as u', 'u.id', '=', 'attendance_record_corrections.corrected_by_user_id');

        foreach (['academic_year_id' => 'rec.academic_year_id', 'campus_id' => 'rec.campus_id', 'grade_level_id' => 'rec.grade_level_id', 'section_id' => 'rec.section_id', 'reason' => 'attendance_record_corrections.reason_code', 'corrected_by' => 'attendance_record_corrections.corrected_by_user_id'] as $key => $column) {
            if (is_string($filters[$key] ?? null) && $filters[$key] !== '') {
                $query->where($column, $filters[$key]);
            }
        }
        if (is_string($filters['from'] ?? null) && $filters['from'] !== '') {
            $query->where('sess.attendance_date', '>=', $filters['from']);
        }
        if (is_string($filters['to'] ?? null) && $filters['to'] !== '') {
            $query->where('sess.attendance_date', '<=', $filters['to']);
        }
        if (is_string($filters['q'] ?? null) && trim($filters['q']) !== '') {
            $students = Student::query()->where('students.school_id', $school->id);
            StudentSearch::anyField($students, (string) $filters['q']);
            $query->whereIn('e.student_id', $students->select('students.id'));
        }

        return $query;
    }
}
