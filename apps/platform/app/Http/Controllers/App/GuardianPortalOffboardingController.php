<?php

namespace App\Http\Controllers\App;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\Portal\GuardianOffboardingException;
use App\Domain\Identity\Application\Portal\GuardianOffboardingService;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POR.1 (ADR 0070 §9.2): off-board ONE Guardian from this School's portal --
 * revoke the portal grant and the account link, and suspend the membership
 * only when it carries no staff role. `guardians.manage` AND
 * `school.members.manage` (re-checked in the service) plus a fresh MFA code,
 * like staff off-boarding (ADR 0059). An administrator cannot off-board
 * their own membership.
 */
class GuardianPortalOffboardingController extends Controller
{
    use AuthorizesCapability;

    public function store(Request $request, TenantContext $context, FreshMfaRequirement $mfa, GuardianOffboardingService $offboarding, string $guardian): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.manage', $school);
        $this->authorizeCapability('school.members.manage', $school);

        $model = Guardian::query()->findOrFail($guardian);
        $mfa->require($request, $context->actor(), $request->input('mfa_code'));

        try {
            $offboarding->offboard($school, $context->actor(), $model);
        } catch (GuardianOffboardingException $e) {
            throw ValidationException::withMessages(['guardian' => [$e->getMessage()]]);
        }

        return redirect("/app/guardians/{$model->id}");
    }
}
