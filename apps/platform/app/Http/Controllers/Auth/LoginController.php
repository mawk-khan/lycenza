<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\MfaChallengeService;
use App\Support\Demo\DemoLoginPanel;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0B auth foundation (section 15): Laravel's built-in session
 * guard, bcrypt password hashing (Laravel default hasher), CSRF (the
 * framework's default web-group middleware), login-attempt rate
 * limiting, and safe logout/session invalidation.
 *
 * Phase 0H.4D-P1: two-stage login for a User with an active MFA
 * factor (section 6/13). `Auth::attempt()` below still validates
 * credentials, but for an MFA-enrolled User this controller
 * immediately `Auth::logout()`s again rather than letting that
 * temporary login stand -- $request->user() returns null again until
 * MfaChallengeController's stage 2 completes. A single, distinct
 * `mfa_pending_user_id` session key (regenerated at the SAME point
 * `session()->regenerate()` already ran for the password-only path,
 * for identical session-fixation hygiene) carries identity across the
 * two requests; `session()->regenerate()` runs a SECOND time in
 * MfaChallengeController on actual completion. A password-only User's
 * behavior is byte-for-byte unchanged (see
 * Tests\Feature\Auth\PasswordOnlyLoginUnchangedTest). Authentication
 * success/failure is recorded to the platform audit ledger (section
 * 29) -- `auth.login_succeeded` fires only once, at the point full
 * assurance is actually established (end of stage 1 for a
 * password-only User, end of stage 2 for an MFA-enrolled one) -- see
 * docs/security/AUTHORIZATION.md.
 */
class LoginController extends Controller
{
    public function create(DemoLoginPanel $demoPanel): Response
    {
        // `demo` is null everywhere except a local DDEV demo environment
        // (see DemoLoginPanel); it only prefills this same form.
        return Inertia::render('Auth/Login', [
            'demo' => $demoPanel->forCurrentEnvironment(),
        ]);
    }

    public function store(Request $request, AuditRecorder $audit, MfaChallengeService $mfa): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 6)) {
            event(new Lockout($request));
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Try again in {$seconds} seconds.",
            ]);
        }

        if (! Auth::attempt($credentials, remember: false)) {
            RateLimiter::hit($throttleKey, 60);

            $audit->platform('auth.login_failed', metadata: [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $user = Auth::user();

        if ($user->isDisabled()) {
            Auth::logout();

            $audit->platform('auth.login_denied_disabled_user', actor: $user);

            throw ValidationException::withMessages([
                'email' => 'This account has been disabled.',
            ]);
        }

        if ($mfa->userHasActiveFactor($user)) {
            // Undo Auth::attempt()'s temporary login -- an MFA-enrolled
            // User is not fully authenticated until stage 2 succeeds.
            Auth::logout();
            $request->session()->regenerate();
            $request->session()->put('mfa_pending_user_id', $user->id);

            return redirect('/login/mfa');
        }

        $request->session()->regenerate();

        $audit->platform('auth.login_succeeded', actor: $user, ipAddress: $request->ip(), userAgent: $request->userAgent());

        return redirect()->intended('/app');
    }

    public function destroy(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $user = Auth::user();

        Auth::guard('web')->logout();

        if ($user) {
            $audit->platform('auth.logout', actor: $user);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // After invalidate(), so the flag lands in the NEW session: the
        // /login page this redirects to tells Inertia to drop its history
        // encryption key, and Back can no longer decrypt (redraw) any
        // signed-in page (HandleInertiaRequests encrypts them).
        Inertia::clearHistory();

        return redirect('/login');
    }
}
