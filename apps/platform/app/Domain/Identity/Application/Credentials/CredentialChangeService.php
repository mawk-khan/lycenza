<?php

namespace App\Domain\Identity\Application\Credentials;

use App\Domain\Platform\Application\Elevation\ElevationEndReason;
use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Phase 0O.10A (ADR 0056 sections 10-11): the ONE place a password is
 * changed, and the side effects that must never drift apart. Called by
 * self-service recovery (AccountRecoveryResetService) and the operator
 * reset (`platform:user-password-reset`); a future signed-in password
 * change must call it too.
 *
 * The caller holds the User row lock (FOR UPDATE) inside its transaction.
 * In that transaction this:
 * - writes the new password through the framework hasher (`hashed` cast);
 * - bumps `credential_version` -- every session on every host ends at its
 *   next request (EnforceCredentialVersion), and the database invalidates
 *   every outstanding recovery credential
 *   (`trg_users_invalidate_account_recovery`);
 * - cycles `remember_token` (no remember cookie is issued, but an old one
 *   can never re-authenticate);
 * - deletes every HUMAN personal access token of the User -- partner
 *   clients, service keys and provider credentials are other principals
 *   and are never touched;
 * - terminates the User's active elevation (`credential_reset`).
 *
 * MFA factors, secrets and recovery codes are NEVER touched.
 */
final class CredentialChangeService
{
    public function __construct(private readonly SchoolElevationService $elevations) {}

    public function setPassword(User $lockedUser, string $plainPassword): CredentialChangeResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A credential change runs inside the caller\'s transaction, with the User locked.');
        }

        $lockedUser->forceFill([
            'password' => $plainPassword,
            'credential_version' => $lockedUser->credential_version + 1,
            'remember_token' => Str::random(60),
        ])->save();

        $revokedTokens = $lockedUser->tokens()->delete();
        $endedElevation = $this->elevations->finishActiveFor($lockedUser, ElevationEndReason::CredentialReset);

        return new CredentialChangeResult((int) $revokedTokens, $endedElevation);
    }
}
