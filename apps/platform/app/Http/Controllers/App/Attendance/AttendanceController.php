<?php

namespace App\Http\Controllers\App\Attendance;

use App\Domain\Attendance\Application\AttendanceCorrectionService;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Application\Exceptions\AttendanceException;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Students\Application\Exceptions\AmbiguousHistoricalEnrollmentException;
use App\Domain\Students\Application\SectionRosterMember;
use App\Domain\Students\Application\StudentEnrollmentRosterReadService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
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
 */
class AttendanceController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('attendance.view', $school);

        $validated = $request->validate([
            'attendance_date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $paginator = AttendanceSession::query()
            ->with(['section', 'subjectOffering.subject', 'teacher', 'period'])
            ->when(isset($validated['attendance_date']), fn ($q) => $q->whereDate('attendance_date', $validated['attendance_date']))
            ->orderByDesc('attendance_date')
            ->orderBy('period_start_time')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('App/Attendance/Index', [
            'sessions' => $paginator->through(fn (AttendanceSession $s) => $this->presentSummary($s)),
            'filters' => ['attendanceDate' => $validated['attendance_date'] ?? ''],
            'canManage' => $capabilities->canInSchool($context->actor(), 'attendance.manage', $school),
        ]);
    }

    /**
     * The marking screen. Class SELECTION legitimately reads CURRENT
     * Timetable state -- you cannot choose today's class from a
     * historical snapshot -- while the roster comes from the shared
     * Students/SIS as-of-date read service. Both are informational: the
     * authoritative submission re-derives everything under locks.
     */
    public function take(Request $request, TenantContext $context, StudentEnrollmentRosterReadService $roster): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('attendance.manage', $school);

        $validated = $request->validate([
            'attendance_date' => ['sometimes', 'date_format:Y-m-d'],
            'timetable_entry_id' => ['sometimes', 'uuid'],
        ]);

        $date = $validated['attendance_date'] ?? now()->toDateString();
        $submitted = AttendanceSession::query()
            ->whereDate('attendance_date', $date)
            ->pluck('timetable_entry_id')
            ->all();

        $classes = TimetableEntry::query()
            ->with(['subjectOffering.subject', 'section', 'teacher', 'period'])
            ->where('status', 'active')
            ->where('day_of_week', Carbon::parse($date)->isoWeekday())
            ->get()
            ->map(fn (TimetableEntry $e) => [
                'timetableEntryId' => $e->id,
                'sectionCode' => $e->section?->code,
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
            $entry = TimetableEntry::query()->where('id', $selected)->where('school_id', $school->id)->first();

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

    public function show(TenantContext $context, CapabilityResolver $capabilities, string $attendanceSession): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('attendance.view', $school);

        $session = AttendanceSession::query()
            ->with([
                'academicYear', 'campus', 'gradeLevel', 'section', 'subjectOffering.subject', 'teacher', 'period',
                'records.studentEnrollment.student:id,school_id,first_name,middle_name,last_name',
            ])
            ->findOrFail($attendanceSession);

        return Inertia::render('App/Attendance/Show', [
            'session' => $this->presentDetail($session),
            'statuses' => AttendanceRecord::STATUSES,
            'canManage' => $capabilities->canInSchool($context->actor(), 'attendance.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, AttendanceSubmissionService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('attendance.manage', $school);

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
    public function correct(Request $request, TenantContext $context, AttendanceCorrectionService $service, string $attendanceRecord): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('attendance.manage', $school);

        $validated = $request->validate([
            'expected_status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
            'new_status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
        ]);

        try {
            $record = $service->correct(
                $school, $attendanceRecord, $validated['expected_status'], $validated['new_status'], $context->actor(),
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
    public function roster(Request $request, TenantContext $context, StudentEnrollmentRosterReadService $roster): JsonResponse
    {
        $school = $context->requireSchool();
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
            $school->id, $entry->academic_year_id, $entry->campus_id,
            $entry->grade_level_id, $entry->section_id, $validated['attendance_date'],
        );

        return response()->json(['data' => $members->map(fn (SectionRosterMember $m) => $m->toArray())->all()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(AttendanceSession $session): array
    {
        return [
            'id' => $session->id,
            'attendanceDate' => $session->attendance_date->toDateString(),
            'sectionCode' => $session->section?->code,
            'subjectName' => $session->subjectOffering?->subject?->name,
            'teacherName' => $session->teacher?->full_name,
            'periodName' => $session->period?->name,
            // Immutable historical values, never the current Period row.
            'periodStartTime' => $session->period_start_time,
            'periodEndTime' => $session->period_end_time,
            'recordCount' => $session->records()->count(),
        ];
    }

    /**
     * Identical sourcing rules to the API presenter: class identity from
     * the Session's own immutable snapshot; names/codes resolved through
     * those snapshotted identities to the referenced entity's CURRENT
     * row; weekday derived from `attendance_date`; `timetableEntryId`
     * echoed as provenance and never dereferenced.
     *
     * @return array<string, mixed>
     */
    private function presentDetail(AttendanceSession $session): array
    {
        return [
            'id' => $session->id,
            'attendanceDate' => $session->attendance_date->toDateString(),
            'dayOfWeek' => $session->dayOfWeek(),
            'academicYearName' => $session->academicYear?->name,
            'campusName' => $session->campus?->name,
            'gradeLevelName' => $session->gradeLevel?->name,
            'sectionCode' => $session->section?->code,
            'sectionName' => $session->section?->name,
            'subjectName' => $session->subjectOffering?->subject?->name,
            'subjectCode' => $session->subjectOffering?->subject?->code,
            'teacherName' => $session->teacher?->full_name,
            'periodName' => $session->period?->name,
            'periodCode' => $session->period?->code,
            'periodStartTime' => $session->period_start_time,
            'periodEndTime' => $session->period_end_time,
            'timetableEntryId' => $session->timetable_entry_id,
            'submittedAt' => $session->submitted_at->toIso8601String(),
            'records' => $session->records->map(function (AttendanceRecord $record): array {
                $enrollment = $record->studentEnrollment;
                $student = $enrollment?->student;

                return [
                    'id' => $record->id,
                    'rollNumber' => $enrollment?->roll_number,
                    'fullName' => $student === null ? null : trim(implode(' ', array_filter(
                        [$student->first_name, $student->middle_name, $student->last_name],
                        fn (?string $part) => $part !== null && $part !== '',
                    ))),
                    'status' => $record->status,
                    'correctedAt' => $record->corrected_at?->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }
}
