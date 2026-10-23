<?php

namespace App\Domain\Attendance\Application;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Application\SectionLabel;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceRecordCorrection;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * ORG.9C-1 (ADR 0076 §69), PV1-05: two operational Attendance reads for
 * `attendance.view` holders, under the actor's CampusScope through
 * AttendanceAdministrationAccess (in SQL; a request filter only narrows).
 * Teacher (Tier 2) visibility is unchanged: a teacher's own classes and
 * their submitted state stay on My attendance.
 *
 * 1. Register status for ONE School-local date (filters: Grade, Section,
 *    status -- never a teacher: Attendance never filters or authorizes by
 *    a teacher column, TeacherAttendanceArchitectureGuardTest). Each class timetabled that
 *    day (an ACTIVE entry of the ACTIVE academic year on that weekday that
 *    existed by the end of that day) is SUBMITTED (a register exists),
 *    PENDING (today, and its period has not ended yet on the School's
 *    clock), or NOT_SUBMITTED. There is deliberately no MISSING status:
 *    Lycenza records no school holidays, closures or cancelled periods for
 *    Students, so whether a register was EXPECTED cannot be known -- on a
 *    day the School was closed every class shows NOT_SUBMITTED, and the
 *    page says so. The class list is the CURRENT timetable (no timetable
 *    versioning). A date after the School's today, or outside the active
 *    academic year, has no register work. Counts are of registers, never
 *    of people (LC-6 S-02).
 * 2. One Student's records over a date range: the same individual records
 *    the register page shows, newest first, with whether and why each was
 *    corrected (the closed, absence-neutral correction reason code -- there
 *    is no free text). No rate, percentage or tally (ADR 0040; ATTENDANCE.md
 *    §16; LC-6 S-06). A Student with no in-scope record at all is not found,
 *    so a Campus reader learns nothing about other Campuses' Students. One
 *    `attendance.student_history_viewed` audit per read (range, count).
 *
 * Read-only.
 */
class AttendanceOperationsReadService
{
    use AuthorizesCampusScope;

    public const string CAPABILITY = 'attendance.view';

    public const string SUBMITTED = 'submitted';

    public const string PENDING = 'pending';

    public const string NOT_SUBMITTED = 'not_submitted';

    public const array STATUSES = [self::SUBMITTED, self::PENDING, self::NOT_SUBMITTED];

    public const int PER_PAGE = 50;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AttendanceAdministrationAccess $access,
        private readonly CurrentAcademicYearResolver $years,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{grade_level_id?: ?string, section_id?: ?string, status?: ?string}  $filters
     * @return array{applicable: bool, rows: LengthAwarePaginator<int, mixed>, counts: array<string, int>, options: array<string, mixed>}
     */
    public function registerStatus(School $school, string $date, array $filters, int $page, User $actor): array
    {
        $scope = $this->authorizeCampusScopeFor($actor, self::CAPABILITY, $school);
        $today = SchoolTimezone::today($school);
        if ($date > $today) {
            throw new InvalidArgumentException('A register-status date cannot be after the School\'s today.');
        }

        $year = $this->years->tryResolve($school);
        $page = max($page, 1);
        /** @var array{applicable: bool, rows: list<array<string, mixed>>, total: int, counts: array<string, int>, options: array<string, mixed>} $empty */
        $empty = ['applicable' => true, 'rows' => [], 'total' => 0, 'counts' => array_fill_keys(self::STATUSES, 0), 'options' => ['grades' => [], 'sections' => []]];
        if ($year === null || $date < $year->starts_on->toDateString() || $date > $year->ends_on->toDateString()) {
            $data = ['applicable' => false] + $empty;
        } else {
            $data = $this->context->withSchool($school, fn (): array => $this->statusData($school, $scope, $date, $today, $year->id, $filters, $page, $empty));
        }

        return [
            'applicable' => $data['applicable'],
            'rows' => new LengthAwarePaginator($data['rows'], $data['total'], self::PER_PAGE, $page),
            'counts' => $data['counts'],
            'options' => $data['options'],
        ];
    }

    /**
     * @param  array{grade_level_id?: ?string, section_id?: ?string, status?: ?string}  $filters
     * @param  array{applicable: bool, rows: list<array<string, mixed>>, total: int, counts: array<string, int>, options: array<string, mixed>}  $empty
     * @return array{applicable: bool, rows: list<array<string, mixed>>, total: int, counts: array<string, int>, options: array<string, mixed>}
     */
    private function statusData(School $school, CampusScope $scope, string $date, string $today, string $yearId, array $filters, int $page, array $empty): array
    {
        $entries = $this->access->entries($scope)
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->forAcademicYear($yearId)
            ->where('day_of_week', CarbonImmutable::parse($date)->isoWeekday())
            // A class created after that day was not on its timetable.
            ->where('created_at', '<', SchoolTimezone::startOfNextDayUtc($school, $date))
            ->with(['subjectOffering.subject', 'section.gradeLevel', 'teacher', 'period'])
            ->get();
        if ($entries->isEmpty()) {
            return $empty;
        }

        $submitted = array_flip($this->access->submittedEntryIds($scope, $date));
        $nowLocal = CarbonImmutable::now(SchoolTimezone::resolve($school))->format('H:i:s');
        $status = function (TimetableEntry $entry) use ($submitted, $date, $today, $nowLocal): string {
            if (isset($submitted[$entry->id])) {
                return self::SUBMITTED;
            }
            $ends = $entry->period?->end_time;

            return $date === $today && ($ends === null || (string) $ends > $nowLocal) ? self::PENDING : self::NOT_SUBMITTED;
        };

        $options = [
            'grades' => $entries->pluck('section.gradeLevel')->filter()->unique('id')->sortBy('sequence')
                ->map(fn ($g) => ['id' => (string) $g->id, 'name' => (string) $g->name])->values()->all(),
            'sections' => $entries->pluck('section')->filter()->unique('id')->sortBy(fn ($s) => SectionLabel::sortKey($s))
                ->map(fn ($s) => ['id' => (string) $s->id, 'label' => (string) SectionLabel::of($s), 'gradeLevelId' => (string) $s->grade_level_id])->values()->all(),
        ];

        $narrowed = $entries
            ->when(($filters['grade_level_id'] ?? null) !== null, fn ($c) => $c->filter(fn (TimetableEntry $e) => $e->grade_level_id === $filters['grade_level_id']))
            ->when(($filters['section_id'] ?? null) !== null, fn ($c) => $c->filter(fn (TimetableEntry $e) => $e->section_id === $filters['section_id']))
            ->map(fn (TimetableEntry $e) => ['entry' => $e, 'status' => $status($e)]);

        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($narrowed as $row) {
            $counts[$row['status']]++;
        }

        $rows = $narrowed
            ->when(($filters['status'] ?? null) !== null, fn ($c) => $c->filter(fn (array $r) => $r['status'] === $filters['status']))
            ->sortBy(fn (array $r) => SectionLabel::sortKey($r['entry']->section).'|'.$r['entry']->period?->start_time.'|'.$r['entry']->id)
            ->values();

        return [
            'applicable' => true,
            'rows' => $rows->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->map(fn (array $r) => [
                'timetableEntryId' => $r['entry']->id,
                'classLabel' => SectionLabel::of($r['entry']->section),
                'subjectName' => $r['entry']->subjectOffering?->subject?->name,
                'teacherName' => $r['entry']->teacher?->full_name,
                'periodName' => $r['entry']->period?->name,
                'periodStartTime' => $r['entry']->period?->start_time,
                'periodEndTime' => $r['entry']->period?->end_time,
                'status' => $r['status'],
            ])->values()->all(),
            'total' => $rows->count(),
            'counts' => $counts,
            'options' => $options,
        ];
    }

    /**
     * @return array{student: array{id: string, name: string}, rows: LengthAwarePaginator<int, mixed>}
     *
     * @throws ModelNotFoundException when the Student has no record the actor may see
     */
    public function studentHistory(School $school, string $studentId, string $from, string $to, int $page, User $actor): array
    {
        $scope = $this->authorizeCampusScopeFor($actor, self::CAPABILITY, $school);

        $page = max($page, 1);
        /** @var array{student: array{id: string, name: string}, items: list<array<string, mixed>>, total: int} $data */
        $data = $this->context->withSchool($school, function () use ($school, $scope, $studentId, $from, $to, $page, $actor): array {
            $ofStudent = fn () => $this->access->records($scope)
                ->where('attendance_records.school_id', $school->id)
                ->whereHas('studentEnrollment', fn ($q) => $q->where('student_id', $studentId));
            $any = Str::isUuid($studentId) ? $ofStudent()->with('studentEnrollment.student:id,school_id,first_name,middle_name,last_name')->first() : null;
            $student = $any?->studentEnrollment?->student;
            if ($student === null) {
                throw (new ModelNotFoundException)->setModel(AttendanceRecord::class, [$studentId]);
            }

            $records = $ofStudent()
                ->select('attendance_records.*')
                ->join('attendance_sessions', fn ($join) => $join->on('attendance_sessions.id', '=', 'attendance_records.attendance_session_id')
                    ->on('attendance_sessions.school_id', '=', 'attendance_records.school_id'))
                ->whereBetween('attendance_sessions.attendance_date', [$from, $to])
                ->with(['session.section.gradeLevel', 'session.subjectOffering.subject', 'session.period'])
                ->orderByDesc('attendance_sessions.attendance_date')
                ->orderByDesc('attendance_sessions.period_start_time')
                ->orderByDesc('attendance_records.id')
                ->paginate(self::PER_PAGE, ['*'], 'page', $page);

            $latest = [];
            $first = [];
            foreach (AttendanceRecordCorrection::query()->where('school_id', $school->id)
                ->whereIn('attendance_record_id', $records->getCollection()->pluck('id')->all())
                ->orderBy('corrected_at')->orderBy('id')->get() as $correction) {
                $first[$correction->attendance_record_id] ??= $correction; // the earliest
                $latest[$correction->attendance_record_id] = $correction; // the last one wins
            }

            $this->audit->school($school, 'attendance.student_history_viewed', actor: $actor, subject: $student, metadata: [
                'from' => $from,
                'to' => $to,
                'resultCount' => $records->count(),
            ]);

            return [
                'student' => ['id' => (string) $student->id, 'name' => trim(implode(' ', array_filter([$student->first_name, $student->middle_name, $student->last_name], fn (?string $p) => $p !== null && $p !== '')))],
                'total' => $records->total(),
                'items' => $records->getCollection()->map(function (AttendanceRecord $record) use ($latest, $first): array {
                    $session = $record->session;
                    $correction = $latest[$record->id] ?? null;

                    return [
                        'id' => $record->id,
                        'sessionId' => $session?->id,
                        'date' => $session?->attendance_date?->toDateString(),
                        'classLabel' => $session === null ? null : SectionLabel::of($session->section),
                        'subjectName' => $session?->subjectOffering?->subject?->name,
                        'periodName' => $session?->period?->name,
                        'periodStartTime' => $session?->period_start_time,
                        'status' => $record->status,
                        'corrected' => $correction !== null,
                        'correctionReason' => $correction?->reason_code,
                        'originalStatus' => ($first[$record->id] ?? null)?->from_status,
                        'correctedAt' => $correction?->corrected_at?->toIso8601String(),
                    ];
                })->values()->all(),
            ];
        });

        return [
            'student' => $data['student'],
            'rows' => new LengthAwarePaginator($data['items'], $data['total'], self::PER_PAGE, $page),
        ];
    }
}
