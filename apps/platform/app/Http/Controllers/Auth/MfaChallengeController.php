<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\CredentialSession;
use App\Support\Auth\Mfa\MfaAuditActions;
use App\Support\Auth\Mfa\MfaChallengeService;
use App\Support\Auth\Mfa\MfaRecoveryCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0H.4D-P1: stage 2 of the two-stage login for an MFA-enrolled
 * User (see LoginController's docblock for stage 1). Reached only via
 * session('mfa_pending_user_id'), set by LoginController::store() --
 * never independently reachable, and never itself establishes a fully
 * authenticated session on GET.
 */
class MfaChallengeController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get('mfa_pending_user_id') === null) {
            return redirect('/login');
        }

        return Inertia::render('Auth/MfaChallenge');
    }

    public function store(
        Request $request,
        AuditRecorder $audit,
        MfaChallengeService $challenge,
        MfaRecoveryCodeService $recoveryCodes,
    ): RedirectResponse {
        $userId = $request->session()->get('mfa_pending_user_id');

        if ($userId === null) {
            return redirect('/login');
        }

        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = User::query()->findOrFail($userId);

        // Section 29: the disabled-User boundary must hold at every
        // stage, not just the password step.
        if ($user->isDisabled()) {
            $request->session()->forget('mfa_pending_user_id');

            throw ValidationException::withMessages([
                'code' => 'This account has been disabled.',
            ]);
        }

        // Throttling for this route is the dedicated `mfa-challenge`
        // named limiter (RateLimiterServiceProvider), applied via
        // `throttle:mfa-challenge` route middleware -- not duplicated
        // here.
        $factor = $challenge->activeFactorFor($user);
        $verified = $factor !== null && $challenge->verifyTotp($factor, $validated['code']);
        $verified = $verified || $recoveryCodes->consume($user, $validated['code']);

        if (! $verified) {
            $audit->platform(MfaAuditActions::CHALLENGE_FAILED, actor: $user);

            throw ValidationException::withMessages([
                'code' => 'That code is not valid.',
            ]);
        }

        // ADR 0056 section 11.2: a credential change between the password
        // step and this one (a reset, an operator reset) voids the pending login.
        $pendingVersion = $request->session()->pull('mfa_pending_credential_version');
        $request->session()->forget('mfa_pending_user_id');

        if ($pendingVersion !== CredentialSession::current($user)) {
            return redirect('/login');
        }

        Auth::login($user);
        $request->session()->regenerate();
        CredentialSession::stamp($request->session(), $user);
        $challenge->establishAssurance($request);

        $audit->platform(MfaAuditActions::CHALLENGE_SUCCEEDED, actor: $user);
        $audit->platform('auth.login_succeeded', actor: $user, ipAddress: $request->ip(), userAgent: $request->userAgent());

        return redirect()->intended('/app');
    }
}
