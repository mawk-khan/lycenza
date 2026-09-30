<?php

namespace App\Domain\TeachingAssignments\Http\Controllers;

use App\Domain\TeachingAssignments\Application\TeachingAssignmentReadService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * TCH.2 (ADR 0063 section 23) -- the TeachingAssignment administrative
 * API. Exactly FOUR operations: list, show, create, end. No PATCH, no
 * DELETE, no repointing: an assignment's owner, class and start date are
 * immutable, and a correction is a new assignment.
 *
 * Thin: validate -> delegate -> present. Both services authorize
 * `teaching.assignments.*` themselves (the route middleware is the
 * defense-in-depth outer layer). `school_id` and the structural context
 * are never read from the body. `{teachingAssignment}` is a raw route
 * string; a malformed, unknown or other-School id is the same 404.
 *
 * Create and end are `idempotent` (CLAUDE.md rule 29): a retried create
 * would otherwise answer an overlap 409 for its own first success, and a
 * retried end an already-ended 409. The stored replay holds directory-tier
 * labels only (rule 36).
 */
class TeachingAssignmentController extends Controller
{
    public function index(Request $request, School $school, TeachingAssignmentReadService $reads): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
            'employee_id' => ['sometimes', 'uuid'],
            'section_id' => ['sometimes', 'uuid'],
            'subject_offering_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.TeachingAssignmentReadService::MAX_PER_PAGE],
        ]);

        $page = $reads->list(
            $school,
            array_intersect_key($validated, array_flip(['academic_year_id', 'employee_id', 'section_id', 'subject_offering_id'])),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 25),
            $request->user(),
        );

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, School $school, string $teachingAssignment, TeachingAssignmentReadService $reads): JsonResponse
    {
        abort_if(! Str::isUuid($teachingAssignment), 404);

        return response()->json(['data' => $reads->find($school, $teachingAssignment, $request->user())]);
    }

    public function store(Request $request, School $school, TeachingAssignmentService $service, TeachingAssignmentReadService $reads): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'section_id' => ['required', 'uuid'],
            'subject_offering_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $assignment = $service->create(
            $school,
            $validated['employee_id'],
            $validated['section_id'],
            $validated['subject_offering_id'],
            $validated['starts_on'],
            $validated['ends_on'] ?? null,
            $request->user(),
        );

        return response()->json(['data' => $reads->find($school, $assignment->id, $request->user())], 201);
    }

    public function end(Request $request, School $school, string $teachingAssignment, TeachingAssignmentService $service, TeachingAssignmentReadService $reads): JsonResponse
    {
        abort_if(! Str::isUuid($teachingAssignment), 404);

        $validated = $request->validate([
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', Rule::in(TeachingAssignment::END_REASONS)],
        ]);

        $ended = $service->end($school, $teachingAssignment, $validated['ends_on'], $validated['reason'], $request->user());

        return response()->json(['data' => $reads->find($school, $ended->id, $request->user())]);
    }
}
