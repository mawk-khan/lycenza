<?php

namespace App\Http\Controllers\App\Attendance;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Application\SectionLabel;
use App\Domain\Attendance\Application\AttendanceAdministrationAccess;
use App\Domain\Attendance\Application\AttendanceCorrectionService;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Application\Exceptions\AttendanceException;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceRecordCorrection;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Students\Application\Exceptions\AmbiguousHistoricalEnrollmentException;
use App\Domain\Students\Application\SectionRosterMember;
use App\Domain\Students\Application\StudentEnrollmentRosterReadService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Http\Controllers\Controller;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0H.2 -- session-authenticated Inertia pages for the
 * administrative Attendance workflow: pick a date, pick a scheduled
 * class, load the roster, mark every Student, submit the complete
 * register, then read it back and correct individual records.
 *
 * Deliberately NOT built here: partial/draft save, a Guardian or
 * Student view, teacher self-service, a mobile surface, and any
 * analytics dashboard -- all explicitly outside Phase 0H.2.
 *
 * Every page authorizes BEFORE running a query, and each helper is
 * gated by Attendance's OWN capability (never Timetable's or
 * Students'), carrying forward the Canteen capability-boundary lesson.
 * Business logic lives in the two Attendance Application services; this
 * controller validates, calls, and renders (CLAUDE.md rule 3).
 *
 * ORG.7D-1 (ADR 0076 §37): `attendance.view`/`.manage` are Campus-adopted.
 * Every page resolves the actor's CampusScope; registers and the classes
 * they are taken for come only through AttendanceAdministrationAccess (out
 * of scope = 404), and the write services receive its Campus write guard.
 */
class AttendanceController extends Controller
{
    use AuthorizesCampusScope, PresentsAttendancePages;

    public function index(Request $request, TenantContext $context, CampusScopeResolver $scopes, AttendanceAdministrationAccess $access): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('attendance.view', $school);

        $validated = $request->validate([
            'attendance_date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $paginator = $access->sessions($scope)
            ->with(['section.gradeLevel', 'subjectOffering.subject', 'teacher', 'period'])
            ->when(isset($validated['attendance_date']), fn ($q) => $q->whereDate('attendance_date', $validated['attendance_date']))
            ->orderByDesc('attendance_date')
            ->orderBy('period_start_time')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('App/Attendance/Index', [
            'sessions' => $paginator->through(fn (AttendanceSession $s) => $this->presentSummary($s)),
            'filters' => ['attendanceDate' => $validated['attendance_date'] ?? ''],
            'canManage' => ! $scopes->campusScope($context->actor(), 'attendance.manage', $school)->isNone(),
            // V11.2 (ADR 0075): same attendance.view gate as this page.
            'exceptionsUrl' => '/app/attendance/exceptions',
            // ORG.9C-1 (ADR 0076 §69): the register-status view, same attendance.view.
            'registersUrl' => '/app/attendance/registers',
        ]);
    }

    /**
     * The marking screen. Class SELECTION legitimately reads CURRENT
     * Timetable state -- you cannot choose today's class from a
     * historical snapshot -- while the roster comes from the shared
     * Students/SIS as-of-date read service. Both are informational: the
     * authoritative submission re-derives everything under locks.
     */
    public function take(Request $request, TenantContext $context, StudentEnrollmentRosterReadService $roster, AttendanceAdministrationAccess $access): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('attendance.manage', $school);

        $validated = $request->validate([
            'attendance_date' => ['sometimes', 'date_format:Y-m-d'],
            'timetable_entry_id' => ['sometimes', 'uuid'],
        ]);

        // UAT-ATT-10: "today" is the School's calendar day, as in My attendance.
        $date = $validated['attendance_date'] ?? CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
        $submitted = $access->submittedEntryIds($scope, $date);

        // LC-2: only the active year's classes -- next year's timetable can be
        // prepared alongside, and must never appear among today's registers.
        $classes = $access->entries($scope)
            ->with(['subjectOffering.subject', 'section.gradeLevel', 'teacher', 'period'])
            ->where('status', 'active')
            ->forAcademicYear(app(CurrentAcademicYearResolver::class)->tryResolve($school)?->id)
            ->where('day_of_week', Carbon::parse($date)->isoWeekday())
            ->get()
            ->sortBy(fn (TimetableEntry $e) => SectionLabel::sortKey($e->section).'|'.$e->period?->start_time)
            ->map(fn (TimetableEntry $e) => [
                'timetableEntryId' => $e->id,
                'sectionCode' => $e->section?->code,
                // UAT-C4 (UAT-ATT-08): "Grade 6 · A", never "A" alone.
                'classLabel' => SectionLabel::of($e->section),
                'subjectName' => $e->subjectOffering?->subject?->name,
                'teacherName' => $e->teacher?->full_name,
                'periodName' => $e->period?->name,
                'periodStartTime' => $e->period?->start_time,
                'periodEndTime' => $e->period?->end_time,
                'alreadySubmitted' => in_array($e->id, $submitted, true),
            ])->values()->all();

        $rosterMembers = [];
        $rosterError = null;
        $selected = $validated['timetable_entry_id'] ?? null;

        if ($selected !== null) {
            $entry = $access->entries($scope)->where('id', $selected)->where('school_id', $school->id)->first();

            if ($entry !== null) {
                try {
                    $rosterMembers = $roster->membersAsOf(
                        $school->id, $entry->academic_year_id, $entry->campus_id,
                        $entry->grade_level_id, $entry->section_id, $date,
                    )->map(fn (SectionRosterMember $m) => $m->toArray())->all();
                } catch (AmbiguousHistoricalEnrollmentException $e) {
                    // Surfaced to the page rather than thrown: the admin
                    // needs to see WHY they cannot take this register.
                    $rosterError = $e->getMessage();
                }
            }
        }

        return Inertia::render('App/Attendance/Take', [
            'attendanceDate' => $date,
            'selectedTimetableEntryId' => $selected,
            'classes' => $classes,
            'roster' => $rosterMembers,
            'rosterError' => $rosterError,
            'statuses' => AttendanceRecord::STATUSES,
        ]);
    }

    public function show(TenantContext $context, CampusScopeResolver $scopes, AttendanceAdministrationAccess $access, string $attendanceSession): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('attendance.view', $school);

        $session = $access->session($scope, $attendanceSession)
            ->load([
                'academicYear', 'campus', 'gradeLevel', 'section', 'subjectOffering.subject', 'teacher', 'period',
                'records.studentEnrollment.student:id,school_id,first_name,middle_name,last_name',
            ]);

        // A register is correctable only where the actor manages Attendance
        // for the register's own (stored) Campus.
        return Inertia::render('App/Attendance/Show', [
            // ORG.8E (ADR 0076 §63): the closed correction reasons.
            'correctionReasons' => AttendanceRecordCorrection::REASONS,
            'session' => $this->presentDetail($session),
            'statuses' => AttendanceRecord::STATUSES,
            'canManage' => $scopes->campusScope($context->actor(), 'attendance.manage', $school)->covers($session->campus_id),
        ]);
    }

    public function store(Request $request, TenantContext $context, AttendanceSubmissionService $service, AttendanceAdministrationAccess $access): RedirectResponse
    {
        $school = $context->requireSchool();
        $guard = $access->writeGuard($this->authorizeCampusScope('attendance.manage', $school));

        $validated = $request->validate([
            'timetable_entry_id' => ['required', 'uuid'],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_enrollment_id' => ['required', 'uuid'],
            'records.*.status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
        ]);

        try {
            $session = $service->guarded(fn () => DB::transaction(fn () => $service->submit(
                $school,
                $validated['timetable_entry_id'],
                $validated['attendance_date'],
                array_values($validated['records']),
                $context->actor(),
                $guard,
            )));
        } catch (AttendanceException|AmbiguousHistoricalEnrollmentException $e) {
            throw ValidationException::withMessages(['timetable_entry_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.attendance.show', $session->id)
            ->with('status', 'Register submitted.');
    }

    /**
     * Expected-status compare-and-swap from the register screen. The
     * page sends the status it currently displays, so a stale tab is
     * refused rather than silently overwriting a colleague's correction.
     */
    public function correct(Request $request, TenantContext $context, AttendanceCorrectionService $service, AttendanceAdministrationAccess $access, string $attendanceRecord): RedirectResponse
    {
        $school = $context->requireSchool();
        $guard = $access->writeGuard($this->authorizeCampusScope('attendance.manage', $school));

        $validated = $request->validate([
            'expected_status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
            'new_status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
            // ORG.8E (ADR 0076 §63): a correction names its closed reason.
            'reason_code' => ['required', 'string', Rule::in(AttendanceRecordCorrection::REASONS)],
        ]);

        try {
            $record = $service->correct(
                $school, $attendanceRecord, $validated['expected_status'], $validated['new_status'], $validated['reason_code'], $context->actor(), $guard,
            );
        } catch (AttendanceException $e) {
            throw ValidationException::withMessages(['new_status' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.attendance.show', $record->attendance_session_id)
            ->with('status', 'Attendance corrected.');
    }

    /**
     * Page-local roster fetch for the marking screen's class picker.
     * Authorizes before querying and reuses the SAME Students/SIS read
     * service the authoritative submission uses.
     */
    public function roster(Request $request, TenantContext $context, StudentEnrollmentRosterReadService $roster, AttendanceAdministrationAccess $access): JsonResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('attendance.manage', $school);

        $validated = $request->validate([
            'timetable_entry_id' => ['required', 'uuid'],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $entry = $access->entry($scope, $validated['timetable_entry_id']);

        $members = $roster->membersAsOf(
            $school->id, $entry->academic_year_id, $entry->campus_id,
            $entry->grade_level_id, $entry->section_id, $validated['attendance_date'],
        );

        return response()->json(['data' => $members->map(fn (SectionRosterMember $m) => $m->toArray())->all()]);
    }
}
