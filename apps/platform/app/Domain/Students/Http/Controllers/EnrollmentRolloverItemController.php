<?php

namespace App\Domain\Students\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\EnrollmentRolloverReadService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 1B.7E: paginated rollover Item results (read) and the
 * operator-editable Item configuration (write) -- see this
 * checkpoint's brief, sections 20/25/26. `update()` delegates entirely
 * to EnrollmentRolloverPlanService::setItemDecision(), which already
 * enforces the decision/Roll-Number-strategy enums (translated to a
 * clean 422 by the central exception handler -- never duplicated here)
 * and refuses an already-executed Item
 * (`RolloverItemAlreadyExecutedException`) or a non-configurable Plan
 * (`RolloverPlanNoLongerConfigurableException`).
 *
 * `student_id`/`source_enrollment_id`/`validation_result`/
 * `validation_reason`/`execution_status`/`target_enrollment_id`/
 * snapshot columns/`configuration_version` are NEVER accepted from
 * request input anywhere in this controller -- only `decision`,
 * `target_section_id`, `roll_number_strategy`, `target_roll_number`
 * are validated/extracted; anything else in the request body is
 * silently dropped by the explicit validation allow-list (this
 * checkpoint's brief, section 75).
 */
class EnrollmentRolloverItemController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school, string $rollover, EnrollmentRolloverReadService $reads): JsonResponse
    {
        $this->authorizeCapability('enrollments.view', $school);
        $this->authorizeCapability('enrollments.rollovers.view', $school);

        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);

        $validated = $request->validate([
            'validation_result' => ['sometimes', Rule::in(['ready', 'excluded', 'already_enrolled', 'review', 'blocked'])],
            'execution_status' => ['sometimes', Rule::in(['succeeded', 'reconciled', 'skipped', 'failed'])],
            'decision' => ['sometimes', Rule::in(['undecided', 'promote', 'repeat', 'exclude', 'manual_review'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $validated['per_page'] ?? 25;
        $filters = collect($validated)->except('per_page')->all();

        $paginator = $reads->items($plan, $filters, $perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (EnrollmentRolloverItem $i) => $this->present($i))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function update(Request $request, School $school, string $rollover, string $item, EnrollmentRolloverPlanService $service): JsonResponse
    {
        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);
        $model = EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->findOrFail($item);

        $validated = $request->validate([
            'decision' => ['required', 'string'],
            'target_section_id' => ['nullable', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
            'roll_number_strategy' => ['nullable', 'string'],
            // Roll Number stays plain text end-to-end -- never numeric-
            // cast, "007" must survive (this checkpoint's brief, section
            // 27) -- string, not integer/numeric validation.
            'target_roll_number' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $service->setItemDecision(
            $plan,
            $model,
            $validated['decision'],
            isset($validated['target_section_id']) ? Section::query()->findOrFail($validated['target_section_id']) : null,
            $validated['roll_number_strategy'] ?? null,
            $validated['target_roll_number'] ?? null,
            $request->user(),
        );

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * Operational Student summary only -- id/studentNumber/first-middle-
     * last name, matching StudentEnrollmentController's own
     * `presentSummary()` privacy boundary exactly. Never
     * date_of_birth, Guardian PII, Guardian relationships, contact
     * values, lookup hashes, or encrypted fields (this checkpoint's
     * brief, section 21/59).
     *
     * @return array<string, mixed>
     */
    private function present(EnrollmentRolloverItem $item): array
    {
        return [
            'id' => $item->id,
            'student' => $this->presentStudentRef($item->student),
            'sourceEnrollment' => [
                'id' => $item->source_enrollment_id,
                'section' => $item->sourceEnrollment?->section === null ? null : [
                    'id' => $item->sourceEnrollment->section->id,
                    'name' => $item->sourceEnrollment->section->name,
                    'code' => $item->sourceEnrollment->section->code,
                ],
            ],
            'decision' => $item->decision,
            'targetSection' => $item->targetSection === null ? null : [
                'id' => $item->targetSection->id,
                'name' => $item->targetSection->name,
                'code' => $item->targetSection->code,
            ],
            'rollNumberStrategy' => $item->roll_number_strategy,
            'targetRollNumber' => $item->target_roll_number,
            'validationResult' => $item->validation_result,
            'validationReason' => $item->validation_reason,
            'executionStatus' => $item->execution_status,
            'targetEnrollmentId' => $item->target_enrollment_id,
            'executedAt' => $item->executed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStudentRef(Student $student): array
    {
        return [
            'id' => $student->id,
            'studentNumber' => $student->student_number,
            'firstName' => $student->first_name,
            'middleName' => $student->middle_name,
            'lastName' => $student->last_name,
        ];
    }
}
