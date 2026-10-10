<?php

namespace App\Support\Auth\Mfa;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Phase 0N.3 (ADR 0044 section 9, owner decision 2): in-session MFA
 * re-verification for a sensitive signed-in action -- first used to start
 * a platform elevation; since Phase 0N.9 (ADR 0047 section 7) also every
 * School lifecycle and bootstrap-administrator change.
 *
 * Not a second MFA implementation: it verifies the SAME enrolled factor
 * with the SAME code paths the login challenge uses
 * (MfaChallengeController::store()): MfaChallengeService::verifyTotp()
 * -- whose transactional step claim makes a TOTP code single-use -- or a
 * single-use recovery code via MfaRecoveryCodeService::consume(). On
 * success it refreshes the SAME session assurance
 * (MfaChallengeService::establishAssurance(), `mfa_verified_at`). It
 * keeps no challenge state of its own, so there is nothing to replay.
 * Throttling and auditing belong to the calling action.
 */
class MfaReverificationService
{
    public function __construct(
        private readonly MfaChallengeService $challenge,
        private readonly MfaRecoveryCodeService $recoveryCodes,
    ) {}

    public function hasActiveFactor(User $user): bool
    {
        return $this->challenge->userHasActiveFactor($user);
    }

    /**
     * True, and assurance refreshed, only when $code is a currently valid,
     * not-yet-used TOTP code of the User's active factor or one of their
     * unused recovery codes.
     */
    public function reverify(Request $request, User $user, string $code): bool
    {
        $factor = $this->challenge->activeFactorFor($user);

        if ($factor === null) {
            return false;
        }

        $verified = $this->challenge->verifyTotp($factor, $code)
            || $this->recoveryCodes->consume($user, $code);

        // A bearer (session-less) request proves the code for this action
        // only; there is no session assurance to refresh (SR.4, ADR 0071 §26.7).
        if ($verified && $request->hasSession()) {
            $this->challenge->establishAssurance($request);
        }

        return $verified;
    }
}
