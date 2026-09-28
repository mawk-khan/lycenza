<?php

namespace App\Domain\Identity\Application\AccountRecovery;

use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Models\PlatformRoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.10A (ADR 0056 section 4.1): the CLOSED recovery eligibility rule,
 * evaluated at issuance AND again at reset. A User is eligible only when:
 *
 * 1. not disabled;
 * 2. holding a local password credential -- a credential-less bootstrap
 *    account (ADR 0059 section 4) never is, so recovery can never act as
 *    its activation;
 * 3. carrying a canonical authoritative email;
 * 4. NOT root -- no active grant of a role holding the root-reserved
 *    `platform.role_grants.manage` (ADR 0046). Root recovery stays on the
 *    operator console (`platform:user-password-reset`).
 *
 * Pending invitations have no User, and service identities / partner
 * clients are not Users: they never reach this rule. Nothing here reveals
 * WHY a User is ineligible to a caller outside this domain.
 */
final class AccountRecoveryEligibility
{
    public function isEligible(User $user): bool
    {
        return ! $user->isDisabled()
            && $user->hasLocalCredential()
            && $user->email !== ''
            && ! $this->isRoot($user);
    }

    public function isRoot(User $user): bool
    {
        return PlatformRoleAssignment::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereIn('role_id', DB::table('role_capabilities')
                ->where('capability_key', PlatformRoleGovernanceService::CAPABILITY)
                ->select('role_id'))
            ->exists();
    }
}
