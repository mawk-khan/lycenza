<?php

namespace App\Http\Controllers\App\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\Mfa\MfaAdminResetService;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase 0H.4D-P1 section 19: platform-level, NOT School-admin --
 * `platform.users.mfa.reset` is a `platform.*` capability, checked via
 * `authorizeCapability(..., platform: true)`, the same mechanism
 * Api\Internal\OperationsController already uses for
 * `platform.operations.view`. A School admin has no route to this
 * controller: no `capability:examinations.*`/`school.*` grant ever
 * satisfies a `platform.*` check (CapabilityResolver::can() never
 * merges the two families).
 */
class MfaAdminController extends Controller
{
    use AuthorizesCapability;

    public function reset(Request $request, User $targetUser, MfaAdminResetService $reset): RedirectResponse
    {
        $this->authorizeCapability('platform.users.mfa.reset', platform: true);

        $reset->reset($request->user(), $targetUser);

        return back();
    }
}
