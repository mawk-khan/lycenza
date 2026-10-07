<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Application\AttendanceCorrectionService;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Application\TeacherAttendanceAccess;
use App\Domain\Attendance\Application\TeacherAttendanceReadAudit;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Students\Application\SectionRosterMember;
use App\Domain\Students\Application\StudentEnrollmentRosterReadService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * TCH.4 (ADR 0063 sections 11, 16.2, 18) -- the OWNED (Tier 2) Attendance
 * API under `/my/`: the calling teacher's own classes only. A separate
 * surface, so the Tier 1 administrative endpoints keep their School-wide
 * meaning unchanged.
 *
 * Route middleware requires `attendance.teacher`; TeacherAttendanceAccess
 * re-checks it and adds a verified ActingEmployee and the ownership
 * periods. Writes run the SAME AttendanceSubmissionService /
 * AttendanceCorrectionService with a TeacherAttendanceGuard.
 *
 * The TimetableEntry is how a register names its class -- it is never the
 * authority: a class is the teacher's only through a TeachingAssignment on
 * the attendance date. The Student roster is exposed only for an owned
 * class; there is no Student directory access.
 *
 * Non-disclosure: another teacher's register, a class the teacher does
 * not own, another School's id, an unknown and a malformed id are all the
 * same 404.
 *
 * E33 / TCH-L1 (ADR 0063 section 43): a bearer token carries no MFA
 * assurance (ADR 0049), so this surface is DEVELOPMENT ONLY
 * (`teacher-attendance-api`); production teacher Attendance is the web
 * surface with MFA. Every successful read is audited
 * (TeacherAttendanceReadAudit).
 */
class TeacherAttendanceController extends Controller
{
    use PresentsAttendanceSessions;

    public function index(Request $request, School $school, TeacherAttendanceAccess $access, TeacherAttendanceReadAudit $audit): JsonResponse
    {
        $validated = $request->validate([
            'attendance_date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $scope = $access->scope($request->user(), $school);

        $paginator = $scope->constrain(AttendanceSession::query())
            ->with($this->presentationRelations())
            ->when(isset($validated['attendance_date']), fn ($q) => $q->whereDate('attendance_date', $validated['attendance_date']))
            ->orderByDesc('attendance_date')
            ->orderBy('period_start_time')
            ->paginate(50)
            ->withQueryString();
        $audit->sessionsListed($school, $request->user(), $scope, $validated['attendance_date'] ?? null, $paginator->currentPage(), count($paginator->items()), TeacherAttendanceReadAudit::SURFACE_API);

        return response()->json([
            'data' => $paginator->through(fn (AttendanceSession $s) => $this->present($s, withRecords: false))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, School $school, string $attendanceSession, TeacherAttendanceAccess $access, TeacherAttendanceReadAudit $audit): JsonResponse
    {
        abort_if(! Str::isUuid($attendanceSession), 404);

        $scope = $access->scope($request->user(), $school);
        $session = $scope
            ->constrain(AttendanceSession::query()->whereKey($attendanceSession))
            ->with($this->presentationRelations(withRecords: true))
            ->firstOrFail();
        $audit->sessionViewed($school, $request->user(), $scope, $session, TeacherAttendanceReadAudit::SURFACE_API);

        return response()->json(['data' => $this->present($session, withRecords: true)]);
    }

    /**
     * The teacher's classes scheduled on a date: active TimetableEntries
     * whose Section + SubjectOffering the teacher owns ON that date. The
     * entry's own teacher_id is shown as the scheduled teacher and plays no
     * part in the filter.
     */
    public function scheduledClasses(Request $request, School $school, TeacherAttendanceAccess $access, TeacherAttendanceReadAudit $audit): JsonResponse
    {
        $validated = $request->validate([
            'attendance_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $scope = $access->scope($request->user(), $school);
        $date = Carbon::parse($validated['attendance_date']);

        $submitted = $scope->constrain(AttendanceSession::query())
            ->whereDate('attendance_date', $date->toDateString())
            ->pluck('timetable_entry_id')
            ->all();

        $entries = TimetableEntry::query()
            ->with(['subjectOffering.subject', 'section', 'teacher', 'period'])
            ->where('status', 'active')
            ->where('day_of_week', $date->isoWeekday())
            ->get()
            ->filter(fn (TimetableEntry $e) => $scope->ownsOn($e->section_id, $e->subject_offering_id, $date->toDateString()));
        $audit->classesListed($school, $request->user(), $scope, $date->toDateString(), $entries->count(), TeacherAttendanceReadAudit::SURFACE_API);

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

    /** The roster of an OWNED class on its date (non-authoritative preview, like Tier 1's). */
    public function rosterPreview(Request $request, School $school, TeacherAttendanceAccess $access, StudentEnrollmentRosterReadService $roster, TeacherAttendanceReadAudit $audit): JsonResponse
    {
        $validated = $request->validate([
            'timetable_entry_id' => ['required', 'uuid'],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $scope = $access->scope($request->user(), $school);
        $entry = TimetableEntry::query()->where('id', $validated['timetable_entry_id'])->where('school_id', $school->id)->firstOrFail();

        if (! $scope->ownsOn($entry->section_id, $entry->subject_offering_id, $validated['attendance_date'])) {
            throw (new ModelNotFoundException)->setModel(TimetableEntry::class);
        }

        $members = $roster->membersAsOf(
            $school->id, $entry->academic_year_id, $entry->campus_id,
            $entry->grade_level_id, $entry->section_id, $validated['attendance_date'],
        );
        $audit->rosterViewed($school, $request->user(), $scope, $entry->id, $entry->section_id, $entry->subject_offering_id, $validated['attendance_date'], $members->count(), TeacherAttendanceReadAudit::SURFACE_API);

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

    /**
     * Submits one complete register for an owned class. Deliberately NOT
     * `idempotent` (CLAUDE.md rules 29, 32): a stored-response replay would
     * be served by the middleware BEFORE this handler re-verifies the
     * ActingEmployee and the TeachingAssignment, while a duplicate
     * register is already refused by the database's own unique indexes
     * (409 ATTENDANCE_SESSION_ALREADY_SUBMITTED / ATTENDANCE_SECTION_SLOT_ALREADY_SUBMITTED).
     */
    public function store(Request $request, School $school, TeacherAttendanceAccess $access, AttendanceSubmissionService $service): JsonResponse
    {
        $validated = $request->validate([
            'timetable_entry_id' => ['required', 'uuid'],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_enrollment_id' => ['required', 'uuid'],
            'records.*.status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
        ]);

        $actor = $request->user();
        $guard = $access->guard($actor);

        $session = $service->guarded(fn () => DB::transaction(fn () => $service->submit(
            $school,
            $validated['timetable_entry_id'],
            $validated['attendance_date'],
            array_values($validated['records']),
            $actor,
            $guard,
        )));

        $session->load($this->presentationRelations(withRecords: true));

        return response()->json(['data' => $this->present($session, withRecords: true)], 201);
    }

    public function correct(Request $request, School $school, string $attendanceRecord, TeacherAttendanceAccess $access, AttendanceCorrectionService $service): JsonResponse
    {
        abort_if(! Str::isUuid($attendanceRecord), 404);

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
            $access->guard($request->user()),
        );

        $record->load('studentEnrollment.student:id,school_id,first_name,middle_name,last_name');

        return response()->json(['data' => $this->presentRecord($record)]);
    }
}
