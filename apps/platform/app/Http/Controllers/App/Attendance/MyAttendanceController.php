<?php

namespace App\Http\Controllers\App\Attendance;

use App\Domain\Attendance\Application\AttendanceCorrectionService;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Application\Exceptions\AttendanceException;
use App\Domain\Attendance\Application\TeacherAttendanceAccess;
use App\Domain\Attendance\Application\TeacherAttendanceReadAudit;
use App\Domain\Attendance\Application\TeacherAttendanceScope;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\Students\Application\Exceptions\AmbiguousHistoricalEnrollmentException;
use App\Domain\Students\Application\SectionRosterMember;
use App\Domain\Students\Application\StudentEnrollmentRosterReadService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TCH.4 -- "My Attendance": the session-authenticated OWNED (Tier 2)
 * Attendance pages. The same three pages as the administrative surface
 * (Index, Take, Show), served under `/app/my-attendance` with ONLY the
 * teacher's own registers, classes and rosters -- nothing School-wide is
 * loaded and filtered in the browser.
 *
 * Authorization is TeacherAttendanceAccess (capability `attendance.teacher`
 * + a verified ActingEmployee + ownership on the attendance date) and, for
 * writes, the TeacherAttendanceGuard the Attendance services run in their
 * transaction. A capability holder who is not an eligible Employee sees an
 * empty list and every other page is refused (403); a class or register
 * they do not own is 404. There is no Student directory access.
 *
 * E33 / TCH-L1 (APPROVED WITH CONDITIONS, ADR 0063 sections 42-43): every
 * route also needs `capability:attendance.teacher` and the MFA assurance gate
 * (`mfa-page`), and every successful read is audited
 * (TeacherAttendanceReadAudit, ids and counts only). Production enablement
 * follows ADR 0063 section 43's control verification.
 */
class MyAttendanceController extends Controller
{
    use PresentsAttendancePages;

    private const string BASE = '/app/my-attendance';

    public function index(Request $request, TenantContext $context, TeacherAttendanceAccess $access, TeacherAttendanceReadAudit $audit): Response
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'attendance_date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        try {
            $scope = $access->scope($request->user(), $school);
        } catch (ActingEmployeeUnavailableException) {
            $scope = null;
        }

        $paginator = ($scope === null ? AttendanceSession::query()->whereRaw('false') : $scope->constrain(AttendanceSession::query()))
            ->with(['section', 'subjectOffering.subject', 'teacher', 'period'])
            ->when(isset($validated['attendance_date']), fn ($q) => $q->whereDate('attendance_date', $validated['attendance_date']))
            ->orderByDesc('attendance_date')
            ->orderBy('period_start_time')
            ->paginate(25)
            ->withQueryString();

        if ($scope !== null) {
            $audit->sessionsListed($school, $request->user(), $scope, $validated['attendance_date'] ?? null, $paginator->currentPage(), count($paginator->items()), TeacherAttendanceReadAudit::SURFACE_WEB);
        }

        return Inertia::render('App/Attendance/Index', [
            'sessions' => $paginator->through(fn (AttendanceSession $s) => $this->presentSummary($s)),
            'filters' => ['attendanceDate' => $validated['attendance_date'] ?? ''],
            'canManage' => $scope !== null,
            'baseUrl' => self::BASE,
            'heading' => 'My Attendance',
        ]);
    }

    public function take(Request $request, TenantContext $context, TeacherAttendanceAccess $access, StudentEnrollmentRosterReadService $roster, TeacherAttendanceReadAudit $audit): Response
    {
        $school = $context->requireSchool();
        $scope = $this->scope($access, $request, $school);

        $validated = $request->validate([
            'attendance_date' => ['sometimes', 'date_format:Y-m-d'],
            'timetable_entry_id' => ['sometimes', 'uuid'],
        ]);

        $date = $validated['attendance_date'] ?? CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
        $submitted = $scope->constrain(AttendanceSession::query())->whereDate('attendance_date', $date)->pluck('timetable_entry_id')->all();

        // Only the teacher's classes on this date: an entry is listed when
        // its Section + SubjectOffering is owned ON $date -- never because
        // the entry's own teacher_id names this Employee.
        $owned = TimetableEntry::query()
            ->with(['subjectOffering.subject', 'section', 'teacher', 'period'])
            ->where('status', 'active')
            ->where('day_of_week', Carbon::parse($date)->isoWeekday())
            ->get()
            ->filter(fn (TimetableEntry $e) => $scope->ownsOn($e->section_id, $e->subject_offering_id, $date))
            ->values();

        $rosterMembers = [];
        $rosterError = null;
        $selected = isset($validated['timetable_entry_id']) && $owned->contains('id', $validated['timetable_entry_id'])
            ? $validated['timetable_entry_id']
            : null;

        if ($selected !== null) {
            $entry = $owned->firstWhere('id', $selected);

            try {
                $rosterMembers = $roster->membersAsOf(
                    $school->id, $entry->academic_year_id, $entry->campus_id,
                    $entry->grade_level_id, $entry->section_id, $date,
                )->map(fn (SectionRosterMember $m) => $m->toArray())->all();
                $audit->rosterViewed($school, $request->user(), $scope, $entry->id, $entry->section_id, $entry->subject_offering_id, $date, count($rosterMembers), TeacherAttendanceReadAudit::SURFACE_WEB);
            } catch (AmbiguousHistoricalEnrollmentException $e) {
                $rosterError = $e->getMessage();
            }
        }
        $audit->classesListed($school, $request->user(), $scope, $date, $owned->count(), TeacherAttendanceReadAudit::SURFACE_WEB);

        return Inertia::render('App/Attendance/Take', [
            'attendanceDate' => $date,
            'selectedTimetableEntryId' => $selected,
            'classes' => $owned->map(fn (TimetableEntry $e) => [
                'timetableEntryId' => $e->id,
                'sectionCode' => $e->section?->code,
                'subjectName' => $e->subjectOffering?->subject?->name,
                'teacherName' => $e->teacher?->full_name,
                'periodName' => $e->period?->name,
                'periodStartTime' => $e->period?->start_time,
                'periodEndTime' => $e->period?->end_time,
                'alreadySubmitted' => in_array($e->id, $submitted, true),
            ])->all(),
            'roster' => $rosterMembers,
            'rosterError' => $rosterError,
            'statuses' => AttendanceRecord::STATUSES,
            'baseUrl' => self::BASE,
        ]);
    }

    public function show(Request $request, TenantContext $context, TeacherAttendanceAccess $access, TeacherAttendanceReadAudit $audit, string $attendanceSession): Response
    {
        $school = $context->requireSchool();
        abort_unless(Str::isUuid($attendanceSession), 404);

        $scope = $this->scope($access, $request, $school);
        $session = $scope
            ->constrain(AttendanceSession::query()->whereKey($attendanceSession))
            ->with([
                'academicYear', 'campus', 'gradeLevel', 'section', 'subjectOffering.subject', 'teacher', 'period',
                'records.studentEnrollment.student:id,school_id,first_name,middle_name,last_name',
            ])
            ->firstOrFail();
        $audit->sessionViewed($school, $request->user(), $scope, $session, TeacherAttendanceReadAudit::SURFACE_WEB);

        return Inertia::render('App/Attendance/Show', [
            'session' => $this->presentDetail($session),
            'statuses' => AttendanceRecord::STATUSES,
            'canManage' => true,
            'baseUrl' => self::BASE,
        ]);
    }

    public function store(Request $request, TenantContext $context, TeacherAttendanceAccess $access, AttendanceSubmissionService $service): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'timetable_entry_id' => ['required', 'uuid'],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_enrollment_id' => ['required', 'uuid'],
            'records.*.status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
        ]);

        $guard = $access->guard($request->user());

        try {
            $session = $service->guarded(fn () => DB::transaction(fn () => $service->submit(
                $school,
                $validated['timetable_entry_id'],
                $validated['attendance_date'],
                array_values($validated['records']),
                $request->user(),
                $guard,
            )));
        } catch (ActingEmployeeUnavailableException) {
            abort(403);
        } catch (AttendanceException|AmbiguousHistoricalEnrollmentException $e) {
            throw ValidationException::withMessages(['timetable_entry_id' => $e->getMessage()]);
        }

        return redirect(self::BASE."/{$session->id}")->with('status', 'Register submitted.');
    }

    public function correct(Request $request, TenantContext $context, TeacherAttendanceAccess $access, AttendanceCorrectionService $service, string $attendanceRecord): RedirectResponse
    {
        $school = $context->requireSchool();
        abort_unless(Str::isUuid($attendanceRecord), 404);

        $validated = $request->validate([
            'expected_status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
            'new_status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
        ]);

        try {
            $record = $service->correct(
                $school, $attendanceRecord, $validated['expected_status'], $validated['new_status'], $request->user(),
                $access->guard($request->user()),
            );
        } catch (ActingEmployeeUnavailableException) {
            abort(403);
        } catch (AttendanceException $e) {
            throw ValidationException::withMessages(['new_status' => $e->getMessage()]);
        }

        return redirect(self::BASE."/{$record->attendance_session_id}")->with('status', 'Attendance corrected.');
    }

    private function scope(TeacherAttendanceAccess $access, Request $request, School $school): TeacherAttendanceScope
    {
        try {
            return $access->scope($request->user(), $school);
        } catch (ActingEmployeeUnavailableException) {
            abort(403);
        }
    }
}
