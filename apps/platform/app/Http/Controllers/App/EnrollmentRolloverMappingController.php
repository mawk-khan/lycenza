<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\CrossSchoolRolloverPlanException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNoLongerConfigurableException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 1B.7F: sanctioned Mapping configuration through the Inertia
 * web surface -- mirrors the Phase 1B.7E JSON API's identical
 * controller exactly (both actions delegate entirely to
 * EnrollmentRolloverPlanService::upsertMapping(), the ONE write path
 * for `enrollment_rollover_mappings`). No Delete action exists here
 * either -- no sanctioned removal method exists on the Plan service
 * (this checkpoint's brief, section 23/59).
 */
class EnrollmentRolloverMappingController extends Controller
{
    use AuthorizesCapability;

    public function store(Request $request, TenantContext $context, string $rollover, EnrollmentRolloverPlanService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('enrollments.manage', $school);
        $this->authorizeCapability('enrollments.rollovers.manage', $school);

        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);

        $validated = $request->validate([
            'source_grade_level_id' => ['required', 'uuid', Rule::exists('grade_levels', 'id')->where('school_id', $school->id)],
            'source_section_id' => ['nullable', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
            'target_grade_level_id' => ['required', 'uuid', Rule::exists('grade_levels', 'id')->where('school_id', $school->id)],
            'target_section_id' => ['nullable', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
        ]);

        try {
            $service->upsertMapping(
                $plan,
                GradeLevel::query()->findOrFail($validated['source_grade_level_id']),
                isset($validated['source_section_id']) ? Section::query()->findOrFail($validated['source_section_id']) : null,
                GradeLevel::query()->findOrFail($validated['target_grade_level_id']),
                isset($validated['target_section_id']) ? Section::query()->findOrFail($validated['target_section_id']) : null,
                $context->actor(),
            );
        } catch (RolloverPlanNoLongerConfigurableException|CrossSchoolRolloverPlanException $e) {
            throw ValidationException::withMessages(['source_grade_level_id' => [$e->getMessage()]]);
        }

        return redirect("/app/enrollment-rollovers/{$plan->id}");
    }

    public function update(Request $request, TenantContext $context, string $rollover, string $mapping, EnrollmentRolloverPlanService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('enrollments.manage', $school);
        $this->authorizeCapability('enrollments.rollovers.manage', $school);

        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);
        $existing = EnrollmentRolloverMapping::query()->where('plan_id', $plan->id)->findOrFail($mapping);

        $validated = $request->validate([
            'target_grade_level_id' => ['required', 'uuid', Rule::exists('grade_levels', 'id')->where('school_id', $school->id)],
            'target_section_id' => ['nullable', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
        ]);

        try {
            $service->upsertMapping(
                $plan,
                $existing->sourceGradeLevel,
                $existing->sourceSection,
                GradeLevel::query()->findOrFail($validated['target_grade_level_id']),
                isset($validated['target_section_id']) ? Section::query()->findOrFail($validated['target_section_id']) : null,
                $context->actor(),
            );
        } catch (RolloverPlanNoLongerConfigurableException|CrossSchoolRolloverPlanException $e) {
            throw ValidationException::withMessages(['target_grade_level_id' => [$e->getMessage()]]);
        }

        return redirect("/app/enrollment-rollovers/{$plan->id}");
    }
}
