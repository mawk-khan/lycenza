<?php

namespace App\Http\Controllers\App\Portal;

use App\Domain\Attendance\Application\Portal\GuardianAttendanceReadService;
use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POR.2 (ADR 0070 §25): a linked Student's Attendance -- web/Inertia, session
 * only. Each route carries `portal-development-only`,
 * `capability:portal.attendance.view` and `mfa-page` (routes/web.php); here the
 * ActingGuardian is resolved fresh, and GuardianAttendanceReadService decides
 * the Student (live scope; anything else the same 404). No search, no roster:
 * the only Student choices are the Guardian's own scope.
 */
class GuardianAttendanceController extends Controller
{
    public function index(TenantContext $context, ActingGuardianResolver $guardians, GuardianAttendanceReadService $attendance): Response|RedirectResponse
    {
        $school = $context->requireSchool();
        $students = $attendance->students($school, $guardians->require($context->actor(), $school));

        if (count($students) === 1) {
            return redirect("/app/portal/attendance/students/{$students[0]['id']}");
        }

        return Inertia::render('App/Portal/Attendance/Index', [
            'schoolName' => $school->name,
            'students' => $students,
        ]);
    }

    public function show(Request $request, TenantContext $context, ActingGuardianResolver $guardians, GuardianAttendanceReadService $attendance, string $student): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $guardian = $guardians->require($actor, $school);

        // Field names only; never echoes a value. A malformed date never reaches the query.
        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages(['range' => 'Choose a valid date range.']);
        }

        return Inertia::render('App/Portal/Attendance/Show', [
            'schoolName' => $school->name,
            'students' => $attendance->students($school, $guardian),
            'attendance' => $attendance->history($school, $guardian, $actor, $student, $request->query('from'), $request->query('to')),
            'maxDays' => GuardianAttendanceReadService::MAX_DAYS,
        ]);
    }
}
