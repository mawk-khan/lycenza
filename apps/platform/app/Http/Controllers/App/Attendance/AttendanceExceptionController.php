<?php

namespace App\Http\Controllers\App\Attendance;

use App\Domain\Attendance\Application\AttendanceExceptionReadService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * V11.2 (ADR 0075): the attendance-exceptions page -- one School day's
 * absent/late/excused records across every submitted register, under the
 * same `attendance.view` capability as the registers themselves. Thin:
 * validates input and calls AttendanceExceptionReadService.
 * ORG.7D-1 (ADR 0076 §37): Campus-adopted; the read service restricts both
 * the list and the Section filter to the actor's CampusScope.
 */
class AttendanceExceptionController extends Controller
{
    use AuthorizesCampusScope;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities, AttendanceExceptionReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope(AttendanceExceptionReadService::CAPABILITY, $school);

        $validated = $request->validate([
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'section_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', Rule::in(AttendanceExceptionReadService::EXCEPTION_STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $date = $validated['date'] ?? $reads->today($school);
        $sectionId = $validated['section_id'] ?? null;
        $status = $validated['status'] ?? null;
        $actor = $context->actor();

        return Inertia::render('App/Attendance/Exceptions', [
            'records' => $reads->list($school, $date, $sectionId, $status === null ? [] : [$status], (int) ($validated['page'] ?? 1), $actor)
                ->withQueryString(),
            'filters' => ['date' => $date, 'sectionId' => $sectionId ?? '', 'status' => $status ?? ''],
            'sectionOptions' => $reads->sectionOptions($school, $date, $actor),
            'statuses' => AttendanceExceptionReadService::EXCEPTION_STATUSES,
            // A row links to the Student only when the viewer may open it.
            'canViewStudents' => $capabilities->canInSchool($actor, 'students.view', $school),
        ]);
    }
}
