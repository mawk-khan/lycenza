<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Application\AttendanceCorrectionService;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Students\Application\SectionRosterMember;
use App\Domain\Students\Application\StudentEnrollmentRosterReadService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Idempotency\IdempotencyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 0H.2 -- the Student Attendance administrative API. Deliberately
 * a narrow COMMAND surface, never generic CRUD: there is no Session
 * update, no Session delete, and no generic AttendanceRecord update.
 * A register is submitted once, complete, and thereafter only
 * individual records are corrected through an explicitly-named
 * compare-and-swap command.
 *
 * HISTORICAL READS NEVER DEREFERENCE THE CURRENT TIMETABLE ENTRY.
 * `present()` below hydrates every element of a register's class
 * identity -- AcademicYear, Campus, GradeLevel, Section,
 * SubjectOffering/Subject, teacher, Period identity and the Period's
 * wall-clock times -- from the AttendanceSession's OWN immutable
 * snapshot columns. `timetable_entry_id` is echoed as provenance only
 * and is never joined for display. The one helper that DOES read
 * current Timetable state (`scheduledClasses()`) is a pre-submission
 * SELECTION aid, which is exactly what current state is for.
 *
 * Data-minimization discipline (Sensitive tier,
 * docs/security/DATA-CLASSIFICATION.md): a Student is projected as id +
 * roll number + composed display name ONLY -- never date of birth,
 * Guardian data, contact details or address. A teacher (Employee) is
 * projected as id + fullName ONLY -- never `work_email`/`work_phone`,
 * matching TimetableEntryController's identical rule. No record carries
 * a reason, note or free-text field of any kind.
 */
class AttendanceSessionController extends Controller
{
    use AuthorizesCapability, PresentsAttendanceSessions;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('attendance.view', $school);

        $validated = $request->validate([
            'section_id' => ['sometimes', 'uuid'],
            'attendance_date' => ['sometimes', 'date_format:Y-m-d'],
            'academic_year_id' => ['sometimes', 'uuid'],
        ]);

        $query = AttendanceSession::query()
            ->with($this->presentationRelations())
            ->orderByDesc('attendance_date')
            ->orderBy('period_start_time');

        foreach (['section_id', 'academic_year_id'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        if (isset($validated['attendance_date'])) {
            $query->whereDate('attendance_date', $validated['attendance_date']);
        }

        $paginator = $query->paginate(50)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (AttendanceSession $s) => $this->present($s, withRecords: false))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(School $school, string $attendanceSession): JsonResponse
    {
        $this->authorizeCapability('attendance.view', $school);

        $session = AttendanceSession::query()
            ->with($this->presentationRelations(withRecords: true))
            ->findOrFail($attendanceSession);

        return response()->json(['data' => $this->present($session, withRecords: true)]);
    }

    /**
     * Submits ONE complete register.
     *
     * Authorization runs as route middleware (`capability:attendance.manage`)
     * BEFORE the `idempotent` middleware, so an actor who has since lost
     * the capability is rejected before the idempotency guard is ever
     * consulted and can never replay a stored success (CLAUDE.md rule
     * 32).
     *
     * `IdempotencyGuard::completeWithin()` is called INSIDE the same
     * transaction as the register write, so the Session, its records,
     * the audit event and the idempotency completion all commit or all
     * roll back together -- the stronger crash-window guarantee
     * IdempotencyGuard's docblock describes, mirroring
     * IdempotencyDemoController exactly (CLAUDE.md rule 33).
     */
    public function store(
        Request $request,
        School $school,
        AttendanceSubmissionService $service,
        IdempotencyGuard $guard,
    ): JsonResponse {
        $this->authorizeCapability('attendance.manage', $school);

        $validated = $request->validate([
            'timetable_entry_id' => ['required', 'uuid'],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_enrollment_id' => ['required', 'uuid'],
            'records.*.status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
        ]);

        $record = $request->attributes->get('idempotency_record');
        $actor = $request->user();

        $body = $service->guarded(fn () => DB::transaction(function () use ($school, $validated, $actor, $service, $guard, $record) {
            $session = $service->submit(
                $school,
                $validated['timetable_entry_id'],
                $validated['attendance_date'],
                array_values($validated['records']),
                $actor,
            );

            $session->load($this->presentationRelations(withRecords: true));
            $body = ['data' => $this->present($session, withRecords: true)];

            if ($record !== null) {
                $guard->completeWithin($record, 201, $body, ['Content-Type' => 'application/json']);
            }

            return $body;
        }));

        return response()->json($body, 201);
    }

    /**
     * Corrects ONE already-submitted AttendanceRecord using
     * expected-status compare-and-swap. Deliberately its own named
     * command rather than a PATCH on a record resource -- see
     * AttendanceCorrectionService's docblock.
     */
    public function correct(
        Request $request,
        School $school,
        string $attendanceRecord,
        AttendanceCorrectionService $service,
    ): JsonResponse {
        $this->authorizeCapability('attendance.manage', $school);

        $validated = $request->validate([
            'expected_status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
            'new_status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
        ]);

        $record = $service->correct(
            $school,
            $attendanceRecord,
            $validated['expected_status'],
            $validated['new_status'],
            $request->user(),
        );

        $record->load('studentEnrollment.student:id,school_id,first_name,middle_name,last_name');

        return response()->json(['data' => $this->presentRecord($record)]);
    }

    /**
     * Pre-submission SELECTION helper: which classes are scheduled for
     * this Section-agnostic date, according to the CURRENT weekly
     * timetable. Reading current Timetable state is correct here and
     * only here -- you cannot pick tomorrow's class from a historical
     * snapshot. Inactive entries are excluded because a new register
     * can only be submitted from an active entry.
     *
     * Historical register reads never use this helper.
     */
    public function scheduledClasses(Request $request, School $school): JsonResponse
    {
        // Authorize BEFORE any query runs -- the Canteen/Timetable
        // helper-endpoint precedent, carried forward.
        $this->authorizeCapability('attendance.manage', $school);

        $validated = $request->validate([
            'attendance_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $date = Carbon::parse($validated['attendance_date']);
        $submitted = AttendanceSession::query()
            ->whereDate('attendance_date', $date->toDateString())
            ->pluck('timetable_entry_id')
            ->all();

        $entries = TimetableEntry::query()
            ->with(['subjectOffering.subject', 'section', 'teacher', 'period'])
            ->where('status', 'active')
            ->where('day_of_week', $date->isoWeekday())
            ->get();

        return response()->json(['data' => $entries->map(fn (TimetableEntry $e) => [
            'timetableEntryId' => $e->id,
            'sectionId' => $e->section_id,
            'sectionCode' => $e->section?->code,
            'sectionName' => $e->section?->name,
            'subjectOfferingId' => $e->subject_offering_id,
            'subjectCode' => $e->subjectOffering?->subject?->code,
            'subjectName' => $e->subjectOffering?->subject?->name,
            'teacherId' => $e->teacher_id,
            'teacherName' => $e->teacher?->full_name,
            'periodId' => $e->period_id,
            'periodCode' => $e->period?->code,
            'periodName' => $e->period?->name,
            'periodStartTime' => $e->period?->start_time,
            'periodEndTime' => $e->period?->end_time,
            'alreadySubmitted' => in_array($e->id, $submitted, true),
        ])->values()->all()]);
    }

    /**
     * Non-authoritative roster PREVIEW for the marking screen. Calls the
     * SAME Students/SIS-owned read service the authoritative submission
     * uses (App\Domain\Students\Application\StudentEnrollmentRosterReadService)
     * -- Attendance never reproduces the placement predicate.
     *
     * The class context is derived server-side from the chosen
     * TimetableEntry; the client supplies only the entry id and the
     * date. This preview takes NO locks and may go stale the moment it
     * is returned -- submission always re-derives the roster under the
     * Section lock and validates the payload against THAT set.
     */
    public function rosterPreview(Request $request, School $school, StudentEnrollmentRosterReadService $roster): JsonResponse
    {
        $this->authorizeCapability('attendance.manage', $school);

        $validated = $request->validate([
            'timetable_entry_id' => ['required', 'uuid'],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $entry = TimetableEntry::query()
            ->where('id', $validated['timetable_entry_id'])
            ->where('school_id', $school->id)
            ->firstOrFail();

        $members = $roster->membersAsOf(
            $school->id,
            $entry->academic_year_id,
            $entry->campus_id,
            $entry->grade_level_id,
            $entry->section_id,
            $validated['attendance_date'],
        );

        return response()->json([
            'data' => $members->map(fn (SectionRosterMember $m) => $m->toArray())->all(),
            'meta' => [
                'timetableEntryId' => $entry->id,
                'sectionId' => $entry->section_id,
                'attendanceDate' => $validated['attendance_date'],
                'authoritative' => false,
            ],
        ]);
    }
}
