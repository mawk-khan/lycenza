<?php

namespace App\Http\Middleware;

use App\Support\Auth\CredentialSession;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0O.10A (ADR 0056 section 11.2): global browser-session revocation.
 *
 * A web session authenticated from its session login key must carry the
 * User's CURRENT `credential_version` (App\Support\Auth\CredentialSession).
 * A missing or different stamp -- a password reset, an operator reset, an
 * email change or disabling happened since, on any host -- signs the
 * session out here, BEFORE any School, elevation or page logic runs; the
 * request then continues as a guest, so a protected route answers exactly
 * like any ended session (App\Support\Auth\SessionEndedResponder).
 *
 * A missing stamp counts as a mismatch ON PURPOSE: stamping old sessions
 * lazily would let one that stayed idle across a reset survive it. So the
 * deploy of Phase 0O.10A signs every browser out once.
 *
 * Only a session-derived login is checked. Bearer tokens, partner clients,
 * service assertions and provider webhooks never reach the web guard, and a
 * user set on the guard in-process (tests' actingAs()) has no session
 * login key.
 */
class EnforceCredentialVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $guard = Auth::guard('web');
        $session = $request->session();

        // Resolving first also covers a remember-cookie login, which writes
        // the session login key during this very call (and never stamps).
        /** @var SessionGuard $guard */
        $user = $guard->user();

        if ($user !== null && $session->has($guard->getName())) {
            if (! CredentialSession::matches($session, $user)) {
                $guard->logout();
                $session->invalidate();
                $session->regenerateToken();
                Log::info('auth.session_revoked', ['reason' => 'credential_version']);
            }
        }

        return $next($request);
    }
}
