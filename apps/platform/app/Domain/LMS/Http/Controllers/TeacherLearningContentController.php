<?php

namespace App\Domain\LMS\Http\Controllers;

use App\Domain\LMS\Application\LearningContentService;
use App\Domain\LMS\Application\TeacherLearningContentAccess;
use App\Domain\LMS\Application\TeacherLearningContentReadService;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * TCH.5C (ADR 0063 sections 34, 36) -- the OWNED (Tier 2) Learning Content
 * API under `/my/`: what the calling teacher may read, and their own rows to
 * write. A separate surface, so the Tier 1 administrative endpoints keep their
 * School-wide meaning unchanged.
 *
 * Route middleware requires `lms.content.teacher`; TeacherLearningContentAccess
 * re-checks it and adds a verified ActingEmployee and today's TeachingAssignment
 * coverage. Writes run the SAME LearningContentService with a
 * TeacherLearningContentGuard. The owner is always the ActingEmployee: no
 * owner field is accepted, and the audience is fixed at creation.
 *
 * Non-disclosure: another teacher's unpublished row, an unpublished
 * Offering-wide row, an untaught class, another School's id, an unknown and
 * a malformed id are all the same 404.
 */
class TeacherLearningContentController extends Controller
{
    public function contexts(Request $request, School $school, TeacherLearningContentAccess $access, TeacherLearningContentReadService $reads): JsonResponse
    {
        return response()->json(['data' => $reads->contexts($school, $access->scope($request->user(), $school))]);
    }

    public function index(Request $request, School $school, TeacherLearningContentAccess $access, TeacherLearningContentReadService $reads): JsonResponse
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

    public function show(Request $request, School $school, string $learningContent, TeacherLearningContentAccess $access, TeacherLearningContentReadService $reads): JsonResponse
    {
        abort_if(! Str::isUuid($learningContent), 404);

        $scope = $access->scope($request->user(), $school);
        $content = $access->visible($request->user(), $school, $learningContent, $scope);

        return response()->json(['data' => $reads->presentOne($school, $content, $scope)]);
    }

    public function store(Request $request, School $school, TeacherLearningContentAccess $access, TeacherLearningContentReadService $reads, LearningContentService $service): JsonResponse
    {
        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
            'audience_section_ids' => ['required', 'array', 'min:1', 'max:50'],
            'audience_section_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $actor = $request->user();
        $content = $service->createOwned($school, $validated['subject_offering_id'], [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'sequence' => $validated['sequence'] ?? 0,
        ], array_values($validated['audience_section_ids']), $actor, $access->guard($actor));

        return response()->json(['data' => $reads->presentOne($school, $content, $access->scope($actor, $school))], 201);
    }

    public function update(Request $request, School $school, string $learningContent, TeacherLearningContentAccess $access, TeacherLearningContentReadService $reads, LearningContentService $service): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
        ]);

        $actor = $request->user();
        $updated = $service->update($school, $this->find($school, $learningContent), $validated, $actor, $access->guard($actor));

        return response()->json(['data' => $reads->presentOne($school, $updated, $access->scope($actor, $school))]);
    }

    public function publish(Request $request, School $school, string $learningContent, TeacherLearningContentAccess $access, TeacherLearningContentReadService $reads, LearningContentService $service): JsonResponse
    {
        $actor = $request->user();
        $updated = $service->publish($school, $this->find($school, $learningContent), $actor, $access->guard($actor));

        return response()->json(['data' => $reads->presentOne($school, $updated, $access->scope($actor, $school))]);
    }

    public function archive(Request $request, School $school, string $learningContent, TeacherLearningContentAccess $access, TeacherLearningContentReadService $reads, LearningContentService $service): JsonResponse
    {
        $actor = $request->user();
        $updated = $service->archive($school, $this->find($school, $learningContent), $actor, $access->guard($actor));

        return response()->json(['data' => $reads->presentOne($school, $updated, $access->scope($actor, $school))]);
    }

    /** The row itself; whether this teacher may touch it is the guard's (404/403/422) decision. */
    private function find(School $school, string $learningContent): LearningContent
    {
        abort_if(! Str::isUuid($learningContent), 404);

        return LearningContent::query()->where('school_id', $school->id)->findOrFail($learningContent);
    }
}
