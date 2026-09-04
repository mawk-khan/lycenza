<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Support\Auth\Exceptions\FreshPasswordConfirmationRequiredException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 0H.4D-P1 section 12: no equivalent existed anywhere in this
 * codebase before this checkpoint (confirmed in the architecture
 * gate) -- a small, reusable "prove you still hold the password"
 * seam, deliberately NOT a broader password-reset system and
 * deliberately NOT mixed with forgotten-password recovery (out of
 * scope). Used by: MfaEnrollmentService::begin(), MfaFactorService::
 * disable(), MfaRecoveryCodeService::regenerate(). The confirmation
 * grant is session-scoped, like session('mfa_verified_at') -- not a
 * database fact.
 */
class PasswordConfirmationService
{
    /**
     * Verifies the given password against the authenticated User and,
     * on success, records a fresh confirmation grant in the session.
     * Never logged/audited with the password value itself.
     */
    public function confirm(Request $request, User $user, string $password): bool
    {
        if (! Hash::check($password, $user->password)) {
            return false;
        }

        $request->session()->put('password_confirmed_at', now()->toIso8601String());

        return true;
    }

    /**
     * @throws FreshPasswordConfirmationRequiredException
     */
    public function require(Request $request): void
    {
        $fresh = AssuranceFreshness::isFresh(
            $request->session()->get('password_confirmed_at'),
            (int) config('mfa.password_confirmation_window_minutes'),
        );

        if (! $fresh) {
            throw new FreshPasswordConfirmationRequiredException;
        }
    }

    public function clear(Request $request): void
    {
        $request->session()->forget('password_confirmed_at');
    }
}
