<?php

namespace App\Support\Auth\Mfa;

use App\Models\User;
use App\Models\UserMfaFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PragmaRX\Google2FA\Google2FA;

/**
 * Phase 0H.4D-P1 sections 6/7/14/15/25/26. Owns TOTP verification
 * (including the actual, documented replay-prevention behavior -- see
 * below) and session-scoped MFA assurance
 * (session('mfa_verified_at')), never a database fact (that would
 * make "assurance" survive across devices/browsers, which is wrong --
 * see ADR 0037).
 */
class MfaChallengeService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Verifies a submitted TOTP code against the factor's secret.
     *
     * Clock-skew tolerance: config('mfa.totp_window') adjacent
     * 30-second time-steps either side of "now" (default 1 -- NOT an
     * unbounded window).
     *
     * Replay prevention (section 26 -- the actual implemented
     * behavior, not aspirational): uses google2fa's own
     * `verifyKeyNewer()`, which additionally rejects a code from the
     * SAME OR AN EARLIER time-step than the last one this factor
     * successfully verified. `last_used_at` stores the actual epoch
     * time-step timestamp google2fa returns on success (not merely
     * "now()") specifically so it can be fed back in as the
     * `oldTimestamp` floor on the next attempt. This prevents
     * immediate reuse of an intercepted code within the same/earlier
     * window; it is NOT RFC-level replay resistance against an
     * attacker who captures a code and replays it in a LATER,
     * not-yet-used time-step before the legitimate user does -- no
     * TOTP library can prevent that without an additional out-of-band
     * signal, and this implementation does not claim to.
     */
    public function verifyTotp(UserMfaFactor $factor, string $code): bool
    {
        $oldTimestamp = $factor->last_used_at?->getTimestamp();
        $window = (int) config('mfa.totp_window');

        $result = $oldTimestamp !== null
            ? $this->google2fa->verifyKeyNewer($factor->secret_encrypted, $code, $oldTimestamp, $window)
            : $this->google2fa->verifyKey($factor->secret_encrypted, $code, $window);

        if ($result === false) {
            return false;
        }

        $factor->forceFill(['last_used_at' => now()->setTimestamp((int) $result)])->save();

        return true;
    }

    public function establishAssurance(Request $request): void
    {
        $request->session()->put('mfa_verified_at', now()->toIso8601String());
    }

    public function hasValidAssurance(Request $request): bool
    {
        $verifiedAt = $request->session()->get('mfa_verified_at');

        if ($verifiedAt === null) {
            return false;
        }

        $windowMinutes = (int) config('mfa.assurance_window_minutes');

        // abs() is deliberate: Carbon 3's diffInMinutes() returns a
        // SIGNED value by default (unlike Carbon 2's implicit
        // absolute-value behavior) -- a naive `<=` comparison here
        // would silently treat an EXPIRED (in-the-past) timestamp as
        // still valid, a real bug caught by
        // Tests\Feature\Auth\Mfa\MfaMiddlewareTest::
        // an_enrolled_user_with_expired_assurance_is_denied_with_the_step_up_code.
        return abs(now()->diffInMinutes(Carbon::parse($verifiedAt))) <= $windowMinutes;
    }

    public function clearAssurance(Request $request): void
    {
        $request->session()->forget('mfa_verified_at');
    }

    public function userHasActiveFactor(User $user): bool
    {
        return $user->mfaFactors()->where('status', 'active')->exists();
    }

    public function activeFactorFor(User $user): ?UserMfaFactor
    {
        return $user->mfaFactors()->where('status', 'active')->first();
    }
}
