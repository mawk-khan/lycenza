<?php

namespace App\Domain\Students\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\EnrollmentRolloverSubjectMapping;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 1G.4: sanctioned subject-mapping configuration through HTTP --
 * both actions delegate entirely to
 * EnrollmentRolloverPlanService::upsertSubjectMapping()/
 * removeSubjectMapping(), the ONE write path for
 * `enrollment_rollover_subject_mappings` (Phase 1G.1). This controller
 * never mutates a mapping row directly, never touches
 * `configuration_version` itself, and never re-derives the three-state
 * (unconfigured/omit/mapped) semantics -- it only translates the
 * request shape.
 *
 * The natural operator identity for a subject mapping is (Plan, source
 * SubjectOffering) -- `enrollment_rollover_subject_mappings`' own
 * uniqueness (Phase 1G.1) -- so both routes are addressed by the
 * SOURCE SubjectOffering id directly
 * (`/enrollment-rollovers/{rollover}/subject-mappings/{subjectOffering}`),
 * never an internal mapping row UUID the client would otherwise have
 * to look up first (this checkpoint's brief, section 37). The source
 * Offering is resolved tenant-safely through the ambient SchoolScope
 * (`SubjectOffering::query()->findOrFail()`, exactly like
 * `EnrollmentRolloverMappingController::update()`'s `{mapping}`
 * resolution) -- a foreign-School id 404s identically to a random uuid,
 * never revealing existence in another School.
 *
 * `PUT .../subject-mappings/{subjectOffering}` with
 * `target_subject_offering_id: null` means EXPLICIT OMIT (a durable
 * row recording the operator's deliberate choice not to carry this
 * elective forward); `DELETE .../subject-mappings/{subjectOffering}`
 * means UNCONFIGURED (no row at all) -- the two are never conflated
 * (this checkpoint's brief, section 35/36).
 *
 * Authorized entirely by the `capability:` route middleware
 * (`enrollments.manage` AND `enrollments.rollovers.manage`, both
 * required, matching every other rollover mutation) -- see
 * routes/api.php. No new capability exists for subject mappings
 * specifically (this checkpoint's brief, section 11/12).
 */
class EnrollmentRolloverSubjectMappingController extends Controller
{
    public function upsert(Request $request, School $school, string $rollover, string $subjectOffering, EnrollmentRolloverPlanService $service): JsonResponse
    {
        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);
        $source = SubjectOffering::query()->findOrFail($subjectOffering);

        $validated = $request->validate([
            'target_subject_offering_id' => ['present', 'nullable', 'uuid', Rule::exists('subject_offerings', 'id')->where('school_id', $school->id)],
        ]);

        $target = $validated['target_subject_offering_id'] === null
            ? null
            : SubjectOffering::query()->findOrFail($validated['target_subject_offering_id']);

        $mapping = $service->upsertSubjectMapping($plan, $source, $target, $request->user());

        return response()->json(['data' => $this->present($plan->refresh(), $source, $mapping)]);
    }

    public function destroy(Request $request, School $school, string $rollover, string $subjectOffering, EnrollmentRolloverPlanService $service): JsonResponse
    {
        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);
        $source = SubjectOffering::query()->findOrFail($subjectOffering);

        $service->removeSubjectMapping($plan, $source, $request->user());

        return response()->json(['data' => $this->present($plan->refresh(), $source, null)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EnrollmentRolloverPlan $plan, SubjectOffering $source, ?EnrollmentRolloverSubjectMapping $mapping): array
    {
        return [
            'sourceSubjectOffering' => $this->presentOffering($source),
            'state' => $mapping === null ? 'unconfigured' : ($mapping->isExplicitOmit() ? 'omit' : 'mapped'),
            'targetSubjectOffering' => $mapping?->target_subject_offering_id === null ? null : $this->presentOffering($mapping->targetSubjectOffering),
            'plan' => [
                'configurationVersion' => $plan->configuration_version,
                'validatedConfigurationVersion' => $plan->validated_configuration_version,
                'isValidatedForCurrentConfiguration' => $plan->isValidatedForCurrentConfiguration(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOffering(SubjectOffering $offering): array
    {
        return [
            'id' => $offering->id,
            'subject' => $offering->subject === null ? null : ['id' => $offering->subject->id, 'name' => $offering->subject->name, 'code' => $offering->subject->code],
            'gradeLevel' => $offering->gradeLevel === null ? null : ['id' => $offering->gradeLevel->id, 'name' => $offering->gradeLevel->name],
            'campus' => $offering->campus === null ? null : ['id' => $offering->campus->id, 'name' => $offering->campus->name],
            'status' => $offering->status,
            'electiveGroup' => $offering->electiveGroup === null ? null : ['id' => $offering->electiveGroup->id, 'name' => $offering->electiveGroup->name],
        ];
    }
}
