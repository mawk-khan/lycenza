<?php

namespace App\Http\Controllers\App;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Identity\Application\Exceptions\AccountLinkException;
use App\Domain\Identity\Infrastructure\StudentGuardianAccountLink;
use App\Http\Controllers\Controller;
use App\Models\SchoolMembership;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5B.2 -- the Guardian account-link admin actions, the exact
 * counterpart to App\Http\Controllers\App\StudentAccountLinkController.
 * Reuses `guardians.manage` -- no new capability key.
 */
class GuardianAccountLinkController extends Controller
{
    use AuthorizesCapability;

    public function search(Request $request, TenantContext $context, string $guardian): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.manage', $school);
        Guardian::query()->findOrFail($guardian);

        $q = trim((string) $request->string('q'));

        $alreadyLinkedMembershipIds = StudentGuardianAccountLink::query()
            ->where('school_id', $school->id)
            ->active()
            ->pluck('school_membership_id');

        $memberships = SchoolMembership::query()
            ->where('school_id', $school->id)
            ->active()
            ->whereNotIn('id', $alreadyLinkedMembershipIds)
            ->whereHas('user', fn ($query) => $q === '' ? $query : $query->where('name', 'ilike', "%{$q}%"))
            ->with('user:id,name')
            ->orderBy('user_id')
            ->limit(20)
            ->get();

        return response()->json([
            'candidates' => $memberships->map(fn (SchoolMembership $m) => [
                'schoolMembershipId' => $m->id,
                'name' => $m->user->name,
            ])->values(),
        ]);
    }

    public function store(Request $request, TenantContext $context, AccountLinkService $service, string $guardian): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.manage', $school);

        $model = Guardian::query()->findOrFail($guardian);

        $validated = $request->validate([
            'school_membership_id' => ['required', 'string'],
        ]);

        $membership = SchoolMembership::query()->find($validated['school_membership_id']);

        if ($membership === null) {
            throw ValidationException::withMessages(['school_membership_id' => ['That School OS account could not be found.']]);
        }

        try {
            $service->linkGuardian($school, $model, $membership, $context->actor());
        } catch (AccountLinkException $e) {
            throw ValidationException::withMessages(['school_membership_id' => [$e->getMessage()]]);
        }

        return redirect("/app/guardians/{$model->id}");
    }

    public function destroy(TenantContext $context, AccountLinkService $service, string $guardian): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.manage', $school);

        $model = Guardian::query()->findOrFail($guardian);

        try {
            $service->unlinkGuardian($school, $model, $context->actor());
        } catch (AccountLinkException $e) {
            throw ValidationException::withMessages(['school_membership_id' => [$e->getMessage()]]);
        }

        return redirect("/app/guardians/{$model->id}");
    }
}
