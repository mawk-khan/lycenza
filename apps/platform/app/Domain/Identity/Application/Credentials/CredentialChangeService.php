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
 *
 * E21.4: retire() is the one place a minimized User's credentials end.
 *
 * Phase 0O.12B (ADR 0059 section 10): establishInitialPassword() is the one
 * place a FIRST password is written -- for a credential-less account at its
 * activation, or a brand-new staff User at invitation acceptance. It refuses
 * an account that already has a credential, so activation can never act as
 * a password reset.
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

    /**
     * E21.4 (User minimization): retires every credential of a User that is
     * being minimized, with the same side effects as a change -- the version
     * bump (every session ends; the database invalidates every recovery and
     * activation credential), every human personal access token deleted and
     * the active elevation ended (`credential_reset`) -- but the password
     * becomes the hash of a random secret nobody ever sees, so the previous
     * hash is gone and no password can match. The caller holds the User row
     * lock inside its transaction. A credential-less account stays without one.
     */
    public function retire(User $lockedUser): CredentialChangeResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A credential change runs inside the caller\'s transaction, with the User locked.');
        }

        $lockedUser->forceFill([
            'password' => $lockedUser->hasLocalCredential() ? Str::random(64) : null,
            'credential_version' => $lockedUser->credential_version + 1,
            'remember_token' => null,
        ])->save();

        $revokedTokens = $lockedUser->tokens()->delete();
        $endedElevation = $this->elevations->finishActiveFor($lockedUser, ElevationEndReason::CredentialReset);

        return new CredentialChangeResult((int) $revokedTokens, $endedElevation);
    }

    /**
     * The FIRST password of a credential-less account (the caller holds the
     * User row lock, or has just created the row, inside its transaction).
     * The credential version moves on (every activation credential still
     * open ends in the database); there is no session, token or elevation
     * to end -- a credential-less account can hold none.
     */
    public function establishInitialPassword(User $lockedUser, string $plainPassword): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A credential change runs inside the caller\'s transaction, with the User locked.');
        }

        if ($lockedUser->hasLocalCredential()) {
            throw new LogicException('This account already has a credential; an initial password is never a reset.');
        }

        $lockedUser->forceFill([
            'password' => $plainPassword,
            'credential_version' => $lockedUser->credential_version + 1,
            'remember_token' => Str::random(60),
        ])->save();
    }
}
