<?php

namespace App\Http\Controllers\App\Attendance;

use App\Domain\Attendance\Application\AttendanceOperationsReadService;
use App\Domain\Attendance\Infrastructure\AttendanceRecordCorrection;
use App\Http\Controllers\Controller;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ORG.9C-1 (ADR 0076 §69), PV1-05: register status for a School-local date,
 * and one Student's records over a date range. Thin: validates and calls
 * AttendanceOperationsReadService, which applies `attendance.view` and the
 * actor's CampusScope in SQL. Read-only; no action on these pages writes.
 */
class AttendanceOperationsController extends Controller
{
    use AuthorizesCampusScope;

    /** The longest per-Student range one request reads. */
    private const int MAX_RANGE_DAYS = 366;

    public function registers(Request $request, TenantContext $context, AttendanceOperationsReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope(AttendanceOperationsReadService::CAPABILITY, $school);
        $today = SchoolTimezone::today($school);

        $validated = $request->validate([
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'grade_level_id' => ['sometimes', 'nullable', 'uuid'],
            'section_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', Rule::in(AttendanceOperationsReadService::STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $date = $validated['date'] ?? $today;
        $filters = [
            'grade_level_id' => $validated['grade_level_id'] ?? null,
            'section_id' => $validated['section_id'] ?? null,
            'status' => $validated['status'] ?? null,
        ];
        $result = $reads->registerStatus($school, $date, $filters, (int) ($validated['page'] ?? 1), $context->actor());

        return Inertia::render('App/Attendance/Registers', [
            'date' => $date,
            'today' => $today,
            'applicable' => $result['applicable'],
            'rows' => $result['rows']->withPath('/app/attendance/registers')->withQueryString(),
            'counts' => $result['counts'],
            'options' => $result['options'],
            'filters' => array_map(fn (?string $v) => $v ?? '', $filters),
            'statuses' => AttendanceOperationsReadService::STATUSES,
        ]);
    }

    public function student(Request $request, TenantContext $context, AttendanceOperationsReadService $reads, string $student): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope(AttendanceOperationsReadService::CAPABILITY, $school);
        $today = SchoolTimezone::today($school);

        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $to = $validated['to'] ?? $today;
        $from = $validated['from'] ?? CarbonImmutable::parse($to)->subDays(29)->toDateString();
        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choose a range of at most one year.']);
        }

        try {
            $history = $reads->studentHistory($school, $student, $from, $to, (int) ($validated['page'] ?? 1), $context->actor());
        } catch (ModelNotFoundException) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('App/Attendance/StudentHistory', [
            'student' => $history['student'],
            'rows' => $history['rows']->withPath("/app/attendance/students/{$history['student']['id']}")->withQueryString(),
            'filters' => ['from' => $from, 'to' => $to],
            'today' => $today,
            'correctionReasons' => AttendanceRecordCorrection::REASONS,
        ]);
    }
}
