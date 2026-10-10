<?php

namespace App\Support\Auth\Mfa;

use App\Models\User;
use App\Models\UserMfaFactor;
use App\Support\Auth\AssuranceFreshness;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

/**
 * Phase 0H.4D-P1 sections 6/7/14/15/25/26. Owns TOTP verification
 * (including the actual, documented replay-prevention behavior -- see
 * below) and session-scoped MFA assurance
 * (session('mfa_verified_at')), never a database fact (that would
 * make "assurance" survive across devices/browsers, which is wrong --
 * see ADR 0037).
 *
 * Replay-security correction (Phase 0H.4D-P1 closure blockers):
 * google2fa's `verifyKeyNewer()`/`getTimestamp()` operate on a
 * 30-second TOTP PERIOD COUNTER (`time() / 30`), never Unix epoch
 * seconds, despite the library's own parameter being named
 * `$oldTimestamp`/`$timestamp`. `last_used_totp_step` is the ONLY
 * value ever passed as that parameter or read back from it -- it is
 * never a datetime, and `last_used_at` (a genuine Carbon column) is
 * never fed back into the library. See UserMfaFactor's docblock and
 * ADR 0037.
 */
class MfaChallengeService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Verifies a submitted TOTP code against the factor's secret and,
     * on success, atomically claims the accepted TOTP step as the new
     * replay floor.
     *
     * Clock-skew tolerance: config('mfa.totp_window') adjacent
     * 30-second time-steps either side of "now" (default 1 -- NOT an
     * unbounded window).
     *
     * Replay prevention (the actual implemented behavior, not
     * aspirational): the read of the current floor
     * (`last_used_totp_step`), google2fa's own `verifyKeyNewer()`
     * check, and the write of the newly accepted step all happen
     * inside one `SELECT ... FOR UPDATE` transaction on the factor
     * row -- a second, genuinely concurrent request for the SAME
     * TOTP step blocks on the row lock until the first request
     * commits, then re-reads the now-advanced floor and is correctly
     * rejected as a replay. A plain read-verify-write (no lock) lets
     * two concurrent requests both read the stale floor and both
     * succeed -- proven exploitable in the Phase 0H.4D-P1 closure
     * audit, which is why this is a transactional claim and not a
     * bare comparison. `verifyKeyNewer()` is always called with an
     * explicit floor (0 when the factor has never verified
     * successfully) so a successful match always returns the real
     * accepted step as an integer -- google2fa's `findValidOTP()`
     * returns the boolean `true` instead of the matched step ONLY
     * when no old-step floor is passed at all, which is the exact
     * defect that corrupted `last_used_at` before this correction.
     *
     * NOT RFC-level replay resistance against an attacker who
     * captures a code and replays it in a LATER, not-yet-used time-
     * step before the legitimate user does -- no TOTP library can
     * prevent that without an additional out-of-band signal, and this
     * implementation does not claim to.
     */
    public function verifyTotp(UserMfaFactor $factor, string $code): bool
    {
        $window = (int) config('mfa.totp_window');

        return DB::transaction(function () use ($factor, $code, $window) {
            // The caller's $factor instance may have been loaded
            // before this transaction started -- re-read AND lock the
            // row so the floor this request compares against can
            // never be stale relative to a concurrent winner.
            $locked = UserMfaFactor::query()->lockForUpdate()->findOrFail($factor->id);

            $previousStep = $locked->last_used_totp_step;
            $floor = $previousStep ?? 0;

            $acceptedStep = $this->google2fa->verifyKeyNewer($locked->secret_encrypted, $code, $floor, $window);

            if ($acceptedStep === false) {
                return false;
            }

            // Defensive, not merely decorative: makeStartingTimestamp()
            // already guarantees this via max($window floor, $floor+1),
            // but the replay invariant is security-critical enough to
            // assert explicitly rather than trust implicitly.
            if ($previousStep !== null && $acceptedStep <= $previousStep) {
                return false;
            }

            $locked->forceFill([
                'last_used_totp_step' => $acceptedStep,
                'last_used_at' => now(),
            ])->save();

            return true;
        });
    }

    public function establishAssurance(Request $request): void
    {
        $request->session()->put('mfa_verified_at', now()->toIso8601String());
    }

    public function hasValidAssurance(Request $request): bool
    {
        // SR.4 (ADR 0071 §26.7): a session-less bearer request carries no
        // session assurance; its human API token is the assurance instead.
        if (! $request->hasSession()) {
            return $this->hasValidTokenAssurance($request);
        }

        $verifiedAt = $request->session()->get('mfa_verified_at');

        if (! AssuranceFreshness::isFresh($verifiedAt, (int) config('mfa.assurance_window_minutes'))) {
            return false;
        }

        // E33 / TCH-L1 (ADR 0063 section 44): assurance belongs to the factor it
        // was earned with. A session verified BEFORE the user's current factor
        // was confirmed -- e.g. before an administrative MFA reset and a
        // re-enrolment -- is not assurance for the new factor: the user signs
        // in again with it. Compared at whole seconds (the stored precision).
        $user = $request->user();
        $factor = $user instanceof User ? $this->activeFactorFor($user) : null;
        // A factor cannot be activated before it exists: created_at bounds a factor with no confirmed_at.
        $activatedAt = $factor === null ? null : ($factor->confirmed_at ?? $factor->created_at);

        return $activatedAt === null || $activatedAt->getTimestamp() <= Carbon::parse((string) $verifiedAt)->getTimestamp();
    }

    /**
     * SR.4 (ADR 0071 §26.7): assurance for a bearer request. A human API
     * token is issued only after a FRESH code under an enrolled factor
     * (ADR 0049 §2, ApiTokenController), so a personal access token minted
     * AT OR AFTER the activation of the user's CURRENT active factor carries
     * that factor's assurance for its (bounded, at most 90-day) lifetime --
     * the same factor binding as the session rule above. No factor, any
     * other token type, or a token minted before the current factor (e.g.
     * before an administrative MFA reset) is no assurance.
     */
    private function hasValidTokenAssurance(Request $request): bool
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken || $token->created_at === null) {
            return false;
        }

        $factor = $this->activeFactorFor($user);
        $activatedAt = $factor === null ? null : ($factor->confirmed_at ?? $factor->created_at);

        return $activatedAt !== null && $activatedAt->getTimestamp() <= $token->created_at->getTimestamp();
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
