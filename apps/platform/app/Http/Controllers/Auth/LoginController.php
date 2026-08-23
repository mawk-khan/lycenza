<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditRecorder;
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
 * limiting, and safe logout/session invalidation. No custom
 * cryptography, no OIDC/SSO/MFA yet -- see
 * docs/security/AUTHORIZATION.md for the documented future path.
 * Authentication success/failure is recorded to the platform audit
 * ledger (section 29).
 */
class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
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

        return redirect('/login');
    }
}
