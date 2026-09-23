<?php

namespace App\Domain\LMS\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Application\LearningContentService;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0I.2 -- the Learning Content administrative API. Exactly SIX
 * operations: list/create nested under the owning SubjectOffering,
 * show/update flat, publish/archive as dedicated action routes.
 *
 * Deliberately NO delete route (status-based retirement only, rule 73)
 * and NO Assignment/Submission route of any kind -- those remain future,
 * separately-gated checkpoints (ADR 0039).
 *
 * Thin controller (CLAUDE.md rule 3): validates/resolves input, calls
 * `LearningContentService`, presents the response. Every write goes
 * through the service -- this controller never touches the model
 * directly (proven by Tests\Feature\LMS\LearningContentArchitectureGuardTest).
 *
 * Data-minimization note: a LearningContent stores no Student, Guardian,
 * Employee or teacher identity at all, which is precisely why it is
 * classified Confidential rather than Sensitive
 * (docs/security/DATA-CLASSIFICATION.md). Nothing in this controller may
 * introduce one.
 */
class LearningContentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school, string $subjectOffering): JsonResponse
    {
        $this->authorizeCapability('lms.content.view', $school);

        $offering = SubjectOffering::query()->where('school_id', $school->id)->findOrFail($subjectOffering);

        $content = LearningContent::query()
            ->where('subject_offering_id', $offering->id)
            ->orderBy('sequence')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $content->map(fn (LearningContent $c) => $this->present($c))->all(),
        ]);
    }

    public function store(Request $request, School $school, string $subjectOffering, LearningContentService $service): JsonResponse
    {
        $this->authorizeCapability('lms.content.manage', $school);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
        ]);

        $content = $service->create($school, $subjectOffering, $validated, $request->user());

        return response()->json(['data' => $this->present($content)], 201);
    }

    public function show(Request $request, School $school, string $learningContent): JsonResponse
    {
        $this->authorizeCapability('lms.content.view', $school);

        $content = LearningContent::query()->where('school_id', $school->id)->findOrFail($learningContent);

        return response()->json(['data' => $this->present($content)]);
    }

    public function update(Request $request, School $school, string $learningContent, LearningContentService $service): JsonResponse
    {
        $this->authorizeCapability('lms.content.manage', $school);

        $content = LearningContent::query()->where('school_id', $school->id)->findOrFail($learningContent);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
        ]);

        $updated = $service->update($school, $content, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function publish(Request $request, School $school, string $learningContent, LearningContentService $service): JsonResponse
    {
        $this->authorizeCapability('lms.content.manage', $school);

        $content = LearningContent::query()->where('school_id', $school->id)->findOrFail($learningContent);

        $updated = $service->publish($school, $content, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function archive(Request $request, School $school, string $learningContent, LearningContentService $service): JsonResponse
    {
        $this->authorizeCapability('lms.content.manage', $school);

        $content = LearningContent::query()->where('school_id', $school->id)->findOrFail($learningContent);

        $updated = $service->archive($school, $content, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LearningContent $content): array
    {
        return [
            'id' => $content->id,
            'subjectOfferingId' => $content->subject_offering_id,
            'title' => $content->title,
            'description' => $content->description,
            'sequence' => $content->sequence,
            'status' => $content->status,
        ];
    }
}
