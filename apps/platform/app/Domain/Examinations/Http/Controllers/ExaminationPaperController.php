<?php

namespace App\Domain\Examinations\Http\Controllers;

use App\Domain\Examinations\Application\ExaminationPaperService;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 0H.4B -- the ExaminationPaper administrative API. Exactly FOUR
 * operations: list and create nested under the owning Examination, show
 * and update flat -- matching ExaminationController's own established
 * "nested for collection, flat for instance" convention.
 *
 * An ExaminationPaper is one SubjectOffering assessed within one
 * Examination, with its scheduled sitting and maximum marks. There is
 * deliberately NO delete, NO activate and NO deactivate route -- `status`
 * moves through the ordinary PATCH.
 *
 * Thin by construction: authorize -> validate -> delegate to
 * App\Domain\Examinations\Application\ExaminationPaperService -> present.
 * Every invariant, the transaction and both audit writes live in the
 * service; this class performs no ExaminationPaper write of its own.
 *
 * `school_id`, `examination_id` and every integrity pin
 * (`academic_year_id`, `campus_id`, `grade_level_id`) are NEVER accepted
 * from the request: the School comes from the route binding + membership
 * middleware, the Examination from the trusted nested route, and the
 * pins are derived server-side inside the service (CLAUDE.md rules
 * 19/68). `subject_offering_id` is accepted only at creation and is
 * immutable thereafter -- PATCH's validated field set does not include
 * it, so an injection attempt is structurally inert.
 */
class ExaminationPaperController extends Controller
{
    use AuthorizesCapability;

    public function index(School $school, string $examination): JsonResponse
    {
        $this->authorizeCapability('examinations.papers.view', $school);

        // Resolved through the tenant-scoped query (SchoolScope + RLS),
        // so another School's Examination id is a clean 404 here rather
        // than an empty list that silently implies it exists.
        $examinationModel = Examination::query()->findOrFail($examination);

        $papers = ExaminationPaper::query()
            ->where('examination_id', $examinationModel->id)
            ->orderBy('scheduled_on')
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'data' => $papers->map(fn (ExaminationPaper $p) => $this->present($p))->all(),
        ]);
    }

    public function store(Request $request, School $school, string $examination, ExaminationPaperService $service): JsonResponse
    {
        $this->authorizeCapability('examinations.papers.manage', $school);

        $examinationModel = Examination::query()->findOrFail($examination);

        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'scheduled_on' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['required', 'date_format:H:i:s'],
            'ends_at' => ['required', 'date_format:H:i:s'],
            'max_marks' => ['required', 'numeric', 'gt:0'],
            'status' => ['sometimes', Rule::in(ExaminationPaper::STATUSES)],
        ]);

        $paper = $service->create($school, $examinationModel, $validated, $request->user());

        return response()->json(['data' => $this->present($paper)], 201);
    }

    public function show(School $school, string $examinationPaper): JsonResponse
    {
        $this->authorizeCapability('examinations.papers.view', $school);

        $model = ExaminationPaper::query()->findOrFail($examinationPaper);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $examinationPaper, ExaminationPaperService $service): JsonResponse
    {
        $this->authorizeCapability('examinations.papers.manage', $school);

        $model = ExaminationPaper::query()->findOrFail($examinationPaper);

        $validated = $request->validate([
            'scheduled_on' => ['sometimes', 'date_format:Y-m-d'],
            'starts_at' => ['sometimes', 'date_format:H:i:s'],
            'ends_at' => ['sometimes', 'date_format:H:i:s'],
            'max_marks' => ['sometimes', 'numeric', 'gt:0'],
            'status' => ['sometimes', Rule::in(ExaminationPaper::STATUSES)],
        ]);

        $updated = $service->update($school, $model, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * `schoolId` and every integrity pin (`academicYearId`, `campusId`,
     * `gradeLevelId`) are deliberately never exposed -- they are internal
     * server-derived context, not public facts.
     *
     * @return array<string, mixed>
     */
    private function present(ExaminationPaper $paper): array
    {
        return [
            'id' => $paper->id,
            'examinationId' => $paper->examination_id,
            'subjectOfferingId' => $paper->subject_offering_id,
            'scheduledOn' => $paper->scheduled_on->toDateString(),
            'startsAt' => $paper->starts_at,
            'endsAt' => $paper->ends_at,
            'maxMarks' => (string) $paper->max_marks,
            'status' => $paper->status,
        ];
    }
}
