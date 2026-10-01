<?php

namespace App\Domain\LMS\Http\Controllers;

use App\Domain\LMS\Application\AssignmentService;
use App\Domain\LMS\Application\TeacherAssignmentAccess;
use App\Domain\LMS\Application\TeacherAssignmentReadService;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * TCH.5D (ADR 0063 sections 34, 37) -- the OWNED (Tier 2) Assignment API
 * under `/my/`, the Learning Content pattern (TCH.5C) for staff-authored
 * Assignments. A separate surface, so the Tier 1 administrative endpoints
 * keep their School-wide meaning unchanged.
 *
 * Route middleware requires `lms.assignments.teacher`; TeacherAssignmentAccess
 * re-checks it and adds a verified ActingEmployee and today's TeachingAssignment
 * coverage. Writes run the SAME AssignmentService with a TeacherAssignmentGuard
 * (authoritative, in the transaction). Before a write, a fresh visibility
 * check answers an unreadable row with the 404 FIRST, so the service's own
 * pre-transaction `due_on` validation can never reveal a row's existence.
 *
 * Non-disclosure: another teacher's draft or closed Assignment, an
 * unpublished Offering-wide one, an untaught class, another School's id, an
 * unknown and a malformed id are all the same 404. No Submission, Student or
 * grading operation exists (Submission is cancelled, ADR 0039).
 */
class TeacherAssignmentController extends Controller
{
    public function contexts(Request $request, School $school, TeacherAssignmentAccess $access, TeacherAssignmentReadService $reads): JsonResponse
    {
        return response()->json(['data' => $reads->contexts($school, $access->scope($request->user(), $school))]);
    }

    public function index(Request $request, School $school, TeacherAssignmentAccess $access, TeacherAssignmentReadService $reads): JsonResponse
    {
        $validated = $request->validate([
            'subject_offering_id' => ['sometimes', 'uuid'],
        ]);

        $page = $reads->list($school, $access->scope($request->user(), $school), $validated['subject_offering_id'] ?? null);

        return response()->json([
            'data' => $page->items(),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, School $school, string $assignment, TeacherAssignmentAccess $access, TeacherAssignmentReadService $reads): JsonResponse
    {
        abort_if(! Str::isUuid($assignment), 404);

        $scope = $access->scope($request->user(), $school);

        return response()->json(['data' => $reads->presentOne($school, $access->visible($request->user(), $school, $assignment, $scope), $scope)]);
    }

    public function store(Request $request, School $school, TeacherAssignmentAccess $access, TeacherAssignmentReadService $reads, AssignmentService $service): JsonResponse
    {
        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'audience_section_ids' => ['required', 'array', 'min:1', 'max:50'],
            'audience_section_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $actor = $request->user();
        $assignment = $service->createOwned($school, $validated['subject_offering_id'], [
            'title' => $validated['title'],
            'instructions' => $validated['instructions'] ?? null,
            'due_on' => $validated['due_on'] ?? null,
        ], array_values($validated['audience_section_ids']), $actor, $access->guard($actor));

        return response()->json(['data' => $reads->presentOne($school, $assignment, $access->scope($actor, $school))], 201);
    }

    public function update(Request $request, School $school, string $assignment, TeacherAssignmentAccess $access, TeacherAssignmentReadService $reads, AssignmentService $service): JsonResponse
    {
        $actor = $request->user();
        $model = $this->visible($access, $actor, $school, $assignment);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $updated = $service->update($school, $model, $validated, $actor, $access->guard($actor));

        return response()->json(['data' => $reads->presentOne($school, $updated, $access->scope($actor, $school))]);
    }

    public function publish(Request $request, School $school, string $assignment, TeacherAssignmentAccess $access, TeacherAssignmentReadService $reads, AssignmentService $service): JsonResponse
    {
        $actor = $request->user();
        $updated = $service->publish($school, $this->visible($access, $actor, $school, $assignment), $actor, $access->guard($actor));

        return response()->json(['data' => $reads->presentOne($school, $updated, $access->scope($actor, $school))]);
    }

    public function close(Request $request, School $school, string $assignment, TeacherAssignmentAccess $access, TeacherAssignmentReadService $reads, AssignmentService $service): JsonResponse
    {
        $actor = $request->user();
        $updated = $service->close($school, $this->visible($access, $actor, $school, $assignment), $actor, $access->guard($actor));

        return response()->json(['data' => $reads->presentOne($school, $updated, $access->scope($actor, $school))]);
    }

    /** A fresh 404 for an unreadable row; the guard re-decides everything under lock. */
    private function visible(TeacherAssignmentAccess $access, User $actor, School $school, string $assignment): Assignment
    {
        abort_if(! Str::isUuid($assignment), 404);

        return $access->visible($actor, $school, $assignment);
    }
}
