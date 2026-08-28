<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\CrossSchoolSubjectMappingException;
use App\Domain\Students\Application\Exceptions\InvalidSubjectMappingYearException;
use App\Domain\Students\Application\Exceptions\RequiredSubjectOfferingRolloverMappingException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNoLongerConfigurableException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 1G.4: sanctioned subject-mapping configuration through the
 * Inertia web surface -- mirrors the JSON API's
 * EnrollmentRolloverSubjectMappingController exactly (both actions
 * delegate entirely to EnrollmentRolloverPlanService::upsertSubjectMapping()/
 * removeSubjectMapping()). Every domain exception a user can plausibly
 * trigger is translated to Laravel's own ValidationException so
 * Inertia's `form.errors` renders it inline, matching
 * EnrollmentRolloverMappingController (App)'s established pattern.
 */
class EnrollmentRolloverSubjectMappingController extends Controller
{
    use AuthorizesCapability;

    public function upsert(Request $request, TenantContext $context, string $rollover, string $subjectOffering, EnrollmentRolloverPlanService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('enrollments.manage', $school);
        $this->authorizeCapability('enrollments.rollovers.manage', $school);

        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);
        $source = SubjectOffering::query()->findOrFail($subjectOffering);

        $validated = $request->validate([
            'target_subject_offering_id' => ['present', 'nullable', 'uuid', Rule::exists('subject_offerings', 'id')->where('school_id', $school->id)],
        ]);

        try {
            $target = $validated['target_subject_offering_id'] === null
                ? null
                : SubjectOffering::query()->findOrFail($validated['target_subject_offering_id']);

            $service->upsertSubjectMapping($plan, $source, $target, $context->actor());
        } catch (RolloverPlanNoLongerConfigurableException|CrossSchoolSubjectMappingException|InvalidSubjectMappingYearException|RequiredSubjectOfferingRolloverMappingException $e) {
            throw ValidationException::withMessages(['target_subject_offering_id' => [$e->getMessage()]]);
        }

        return redirect("/app/enrollment-rollovers/{$plan->id}");
    }

    public function destroy(Request $request, TenantContext $context, string $rollover, string $subjectOffering, EnrollmentRolloverPlanService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('enrollments.manage', $school);
        $this->authorizeCapability('enrollments.rollovers.manage', $school);

        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);
        $source = SubjectOffering::query()->findOrFail($subjectOffering);

        try {
            $service->removeSubjectMapping($plan, $source, $context->actor());
        } catch (RolloverPlanNoLongerConfigurableException|CrossSchoolSubjectMappingException $e) {
            throw ValidationException::withMessages(['subject_mapping' => [$e->getMessage()]]);
        }

        return redirect("/app/enrollment-rollovers/{$plan->id}");
    }
}
