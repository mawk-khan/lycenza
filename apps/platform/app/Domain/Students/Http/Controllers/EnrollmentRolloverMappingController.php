<?php

namespace App\Domain\Students\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 1B.7E: sanctioned Mapping configuration through HTTP -- both
 * actions delegate entirely to
 * EnrollmentRolloverPlanService::upsertMapping(), the ONE write path
 * for `enrollment_rollover_mappings` (Phase 1B.7B) -- this controller
 * never mutates a Mapping row directly, never touches
 * `configuration_version` itself, and never duplicates the
 * Section-specific-overrides-Grade-default precedence rule.
 *
 * Both routes are NESTED under the Plan
 * (`/enrollment-rollovers/{rollover}/mappings[/{mapping}]`) specifically
 * so a Mapping's ownership can be verified against the ROUTE's Plan,
 * not merely the current School -- `EnrollmentRolloverMapping::query()->where('plan_id',
 * $plan->id)->findOrFail($mapping)` makes a same-School-but-different-
 * Plan Mapping id 404 exactly like a foreign-School or random uuid
 * (this checkpoint's brief, section 46/88).
 *
 * Authorized entirely by the `capability:` route middleware
 * (`enrollments.manage` AND `enrollments.rollovers.manage`, both
 * required) -- see routes/api.php.
 */
class EnrollmentRolloverMappingController extends Controller
{
    /**
     * Creates a NEW Grade-default (`source_section_id` omitted) or
     * Section-specific (`source_section_id` given) mapping. All four
     * referenced ids are resolved tenant-safely
     * (`Rule::exists(...)->where('school_id', $school->id)`) --
     * EnrollmentRolloverPlanService::upsertMapping() itself re-verifies
     * same-School ownership as the authoritative backstop.
     */
    public function store(Request $request, School $school, string $rollover, EnrollmentRolloverPlanService $service): JsonResponse
    {
        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);

        $validated = $request->validate([
            'source_grade_level_id' => ['required', 'uuid', Rule::exists('grade_levels', 'id')->where('school_id', $school->id)],
            'source_section_id' => ['nullable', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
            'target_grade_level_id' => ['required', 'uuid', Rule::exists('grade_levels', 'id')->where('school_id', $school->id)],
            'target_section_id' => ['nullable', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
        ]);

        $mapping = $service->upsertMapping(
            $plan,
            GradeLevel::query()->findOrFail($validated['source_grade_level_id']),
            isset($validated['source_section_id']) ? Section::query()->findOrFail($validated['source_section_id']) : null,
            GradeLevel::query()->findOrFail($validated['target_grade_level_id']),
            isset($validated['target_section_id']) ? Section::query()->findOrFail($validated['target_section_id']) : null,
            $request->user(),
        );

        return response()->json(['data' => $this->present($mapping)], 201);
    }

    /**
     * Changes an EXISTING mapping's target Grade/Section only -- its
     * source identity (`source_grade_level_id`/`source_section_id`,
     * what mapping THIS is) is read from the resolved, route/Plan-
     * verified Mapping itself, never accepted from the request body
     * (that would re-key the upsert onto a DIFFERENT mapping entirely,
     * this checkpoint's brief, section 23). Internally still just calls
     * `upsertMapping()` -- no new PlanService method was needed.
     */
    public function update(Request $request, School $school, string $rollover, string $mapping, EnrollmentRolloverPlanService $service): JsonResponse
    {
        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);
        $existing = EnrollmentRolloverMapping::query()->where('plan_id', $plan->id)->findOrFail($mapping);

        $validated = $request->validate([
            'target_grade_level_id' => ['required', 'uuid', Rule::exists('grade_levels', 'id')->where('school_id', $school->id)],
            'target_section_id' => ['nullable', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
        ]);

        $updated = $service->upsertMapping(
            $plan,
            $existing->sourceGradeLevel,
            $existing->sourceSection,
            GradeLevel::query()->findOrFail($validated['target_grade_level_id']),
            isset($validated['target_section_id']) ? Section::query()->findOrFail($validated['target_section_id']) : null,
            $request->user(),
        );

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EnrollmentRolloverMapping $mapping): array
    {
        return [
            'id' => $mapping->id,
            'sourceGradeLevel' => $this->presentRef($mapping->sourceGradeLevel),
            'sourceSection' => $mapping->sourceSection === null ? null : $this->presentRef($mapping->sourceSection),
            'targetGradeLevel' => $this->presentRef($mapping->targetGradeLevel),
            'targetSection' => $mapping->targetSection === null ? null : $this->presentRef($mapping->targetSection),
            'isRepeat' => $mapping->isRepeat(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRef(GradeLevel|Section $model): array
    {
        return ['id' => $model->id, 'name' => $model->name, 'code' => $model->code];
    }
}
