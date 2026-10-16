<?php

namespace App\Domain\Attendance\Application;

use App\Domain\AcademicStructure\Application\SectionLabel;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * V11.2 (ADR 0075): the attendance-exceptions list -- one School day's
 * submitted register records whose status is not `present`, across every
 * register of that day, so office staff can see who was absent, late or
 * excused without opening each register in turn.
 *
 * It is an OPERATIONAL list, not Attendance analytics (docs/modules/
 * ATTENDANCE.md section 16; ADR 0040 section 1): it returns the same
 * individual records the register page (`AttendanceController::show()`)
 * already shows under the same `attendance.view` capability -- no rate,
 * percentage, per-Student tally or grouping. Its only count is the
 * paginator's total of the rows on screen (ADR 0042 section 11).
 *
 * - Source of truth: `attendance_records` joined to their own submitted
 *   `attendance_sessions` header; never a copy, never a cached count.
 * - Student identity: the record's own Enrollment and that Enrollment's
 *   Student name columns only (never date of birth), exactly the
 *   register page's path.
 * - One School per call, inside TenantContext::withSchool() with an
 *   explicit `school_id` predicate on both tables (RLS applies too).
 * - Bounded: one School-local date per request, paginated.
 * - Read-only. Admin register reads are not access-audited today, and
 *   this view adds no data beyond them; teacher reads stay on their own
 *   audited path (TeacherAttendanceReadAudit), which this class never
 *   serves.
 */
class AttendanceExceptionReadService
{
    use AuthorizesCapability;

    public const string CAPABILITY = 'attendance.view';

    /** Every recorded status except `present`. */
    public const array EXCEPTION_STATUSES = ['absent', 'late', 'excused'];

    public const int PER_PAGE = 50;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<string>  $statuses  a subset of EXCEPTION_STATUSES (empty = all of them)
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(School $school, string $date, ?string $sectionId, array $statuses, int $page, User $actor): LengthAwarePaginator
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        $statuses = $statuses === [] ? self::EXCEPTION_STATUSES : array_values(array_intersect(self::EXCEPTION_STATUSES, $statuses));

        return $this->context->withSchool($school, fn () => AttendanceRecord::query()
            ->select('attendance_records.*')
            ->join('attendance_sessions', function ($join) {
                $join->on('attendance_sessions.id', '=', 'attendance_records.attendance_session_id')
                    ->on('attendance_sessions.school_id', '=', 'attendance_records.school_id');
            })
            ->where('attendance_records.school_id', $school->id)
            ->where('attendance_sessions.attendance_date', $date)
            ->whereIn('attendance_records.status', $statuses)
            ->when($sectionId !== null, fn ($q) => $q->where('attendance_records.section_id', $sectionId))
            ->with([
                'session.section.gradeLevel', 'session.subjectOffering.subject', 'session.period',
                'studentEnrollment.student:id,school_id,first_name,middle_name,last_name',
            ])
            ->orderBy('attendance_sessions.period_start_time')
            ->orderBy('attendance_records.section_id')
            ->orderBy('attendance_records.id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max($page, 1))
            ->through(fn (AttendanceRecord $record) => self::present($record)));
    }

    /**
     * The Sections that have a submitted register on `$date`, for the
     * filter -- derived from that day's registers, never every Section.
     *
     * @return list<array{id: string, name: string|null, code: string|null, label: string|null}>
     */
    public function sectionOptions(School $school, string $date, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, fn () => AttendanceSession::query()
            ->where('school_id', $school->id)
            ->where('attendance_date', $date)
            ->with('section.gradeLevel')
            ->get(['id', 'school_id', 'section_id'])
            ->pluck('section')->filter()->unique('id')
            // UAT-C4 (UAT-ATT-08): Grade order, labelled "Grade 6 · A".
            ->sortBy(fn ($s) => SectionLabel::sortKey($s))
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'label' => SectionLabel::of($s)])
            ->values()->all());
    }

    /** The School's own calendar date today (records are dated School-locally). */
    public function today(School $school): string
    {
        return CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
    }

    /** @return array<string, mixed> */
    private static function present(AttendanceRecord $record): array
    {
        $session = $record->session;
        $enrollment = $record->studentEnrollment;
        $student = $enrollment?->student;

        return [
            'id' => $record->id,
            'status' => $record->status,
            'correctedAt' => $record->corrected_at?->toIso8601String(),
            'studentId' => $student?->id,
            'fullName' => $student === null ? null : trim(implode(' ', array_filter(
                [$student->first_name, $student->middle_name, $student->last_name],
                fn (?string $part) => $part !== null && $part !== '',
            ))),
            'rollNumber' => $enrollment?->roll_number,
            'sessionId' => $session?->id,
            'sectionName' => $session?->section?->name,
            'classLabel' => SectionLabel::of($session?->section),
            'sectionCode' => $session?->section?->code,
            'subjectName' => $session?->subjectOffering?->subject?->name,
            'periodName' => $session?->period?->name,
            // The register's immutable snapshot, never the current Period row.
            'periodStartTime' => $session?->period_start_time,
            'periodEndTime' => $session?->period_end_time,
        ];
    }
}
