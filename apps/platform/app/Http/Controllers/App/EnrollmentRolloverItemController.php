<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\CrossSchoolRolloverPlanException;
use App\Domain\Students\Application\Exceptions\InvalidRollNumberStrategyException;
use App\Domain\Students\Application\Exceptions\InvalidRolloverItemDecisionException;
use App\Domain\Students\Application\Exceptions\RolloverItemAlreadyExecutedException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNoLongerConfigurableException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 1B.7F: the operator-editable Item configuration through the
 * Inertia web surface -- mirrors the Phase 1B.7E JSON API's identical
 * controller (`update()` delegates entirely to
 * EnrollmentRolloverPlanService::setItemDecision(), which already
 * enforces the decision/Roll-Number-strategy enums, an already-
 * executed Item, and a non-configurable Plan). Only `decision`/
 * `target_section_id`/`roll_number_strategy`/`target_roll_number` are
 * ever extracted from the request -- `student_id`/
 * `source_enrollment_id`/`validation_result`/`execution_status`/
 * `target_enrollment_id`/snapshot columns/`configuration_version` are
 * never accepted (this checkpoint's brief, section 25/75 of 1B.7E,
 * carried through unchanged here).
 */
class EnrollmentRolloverItemController extends Controller
{
    use AuthorizesCapability;

    public function update(Request $request, TenantContext $context, string $rollover, string $item, EnrollmentRolloverPlanService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('enrollments.manage', $school);
        $this->authorizeCapability('enrollments.rollovers.manage', $school);

        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);
        $model = EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->findOrFail($item);

        $validated = $request->validate([
            'decision' => ['required', 'string'],
            'target_section_id' => ['nullable', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
            'roll_number_strategy' => ['nullable', 'string'],
            // Roll Number stays plain text end-to-end -- never numeric-
            // cast, "007" must survive.
            'target_roll_number' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $service->setItemDecision(
                $plan,
                $model,
                $validated['decision'],
                isset($validated['target_section_id']) ? Section::query()->findOrFail($validated['target_section_id']) : null,
                $validated['roll_number_strategy'] ?? null,
                $validated['target_roll_number'] ?? null,
                $context->actor(),
            );
        } catch (InvalidRolloverItemDecisionException|InvalidRollNumberStrategyException|RolloverItemAlreadyExecutedException|RolloverPlanNoLongerConfigurableException|CrossSchoolRolloverPlanException $e) {
            throw ValidationException::withMessages(['decision' => [$e->getMessage()]]);
        }

        return redirect("/app/enrollment-rollovers/{$plan->id}");
    }
}
