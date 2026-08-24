<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeActivityTimelineEntry;
use App\Domain\HR\Application\EmployeeActivityTimelineQuery;
use App\Domain\HR\Application\EmployeeActivityTimelineService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phase 8A.14 -- the sole HTTP transport for the 8A.11 Employee
 * Activity Timeline. Read-only adapter over
 * `EmployeeActivityTimelineService::get()` -- never queries
 * `App\Models\SchoolAuditEvent` directly. Preserves the service's own
 * capability-category filtering, event-time sensitivity handling,
 * unknown-event fail-closed behavior, and visible-events-only
 * pagination `total()` untouched; this controller adds no filtering or
 * counting logic of its own.
 *
 * No free-text search parameter exists here -- category and date range
 * are the complete filter surface, matching
 * `EmployeeActivityTimelineQuery`'s own contract exactly (checkpoint
 * 8A.14 section 17).
 */
class EmployeeActivityController extends Controller
{
    public function index(Request $request, School $school, string $employee): JsonResponse
    {
        // Phase 8A.15: reject a malformed (non-UUID) id as the same
        // tenant-safe 404 a nonexistent/cross-School Employee already
        // produces -- otherwise it reaches
        // `EmployeeActivityTimelineService::get()`'s `Employee::query()->find($employeeId)`
        // and crashes PostgreSQL with `invalid input syntax for type
        // uuid` (a raw 500, confirmed empirically).
        abort_if(! Str::isUuid($employee), 404);

        $validated = $request->validate([
            'category' => ['sometimes', 'nullable', 'string'],
            'occurred_from' => ['sometimes', 'nullable', 'date'],
            'occurred_to' => ['sometimes', 'nullable', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = new EmployeeActivityTimelineQuery(
            category: $validated['category'] ?? null,
            occurredFrom: $validated['occurred_from'] ?? null,
            occurredTo: $validated['occurred_to'] ?? null,
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? EmployeeActivityTimelineQuery::DEFAULT_PER_PAGE),
        );

        $paginator = app(EmployeeActivityTimelineService::class)->get($school, $employee, $request->user(), $query);

        abort_if($paginator === null, 404);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (EmployeeActivityTimelineEntry $entry) => $entry->toArray())->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
