<?php

namespace App\Http\Controllers\App;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\AccountInvitationService;
use App\Domain\Identity\Application\Exceptions\AccountInvitationException;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5D.3 -- Identity-domain admin actions for a Guardian account
 * invitation (issue/resend/revoke). Requires BOTH `guardians.manage`
 * (this is a Guardian-facing admin operation, same gate as the
 * existing `GuardianAccountLinkController`) AND `school.members.manage`
 * (this operation, unlike linking an already-existing membership,
 * provisions a brand-new SchoolMembership -- the Identity-domain
 * capability that governs creating school members generally,
 * mirroring brief §36's "guardians.manage plus Identity/account
 * management authority"). Never a role-name check (root CLAUDE.md
 * rule 24).
 */
class GuardianAccountInvitationController extends Controller
{
    use AuthorizesCapability;

    public function store(TenantContext $context, AccountInvitationService $service, string $guardian): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeBoth($school);

        $model = Guardian::query()->findOrFail($guardian);

        try {
            $service->invite($school, $model, $context->actor());
        } catch (AccountInvitationException $e) {
            throw ValidationException::withMessages(['guardian' => [$e->getMessage()]]);
        }

        return redirect("/app/guardians/{$model->id}");
    }

    public function resend(TenantContext $context, AccountInvitationService $service, string $guardian): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeBoth($school);

        $model = Guardian::query()->findOrFail($guardian);

        try {
            $service->resend($school, $model, $context->actor());
        } catch (AccountInvitationException $e) {
            throw ValidationException::withMessages(['guardian' => [$e->getMessage()]]);
        }

        return redirect("/app/guardians/{$model->id}");
    }

    public function destroy(TenantContext $context, AccountInvitationService $service, string $guardian): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeBoth($school);

        $model = Guardian::query()->findOrFail($guardian);

        $service->revoke($school, $model, $context->actor());

        return redirect("/app/guardians/{$model->id}");
    }

    private function authorizeBoth(School $school): void
    {
        $this->authorizeCapability('guardians.manage', $school);
        $this->authorizeCapability('school.members.manage', $school);
    }
}
