<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\AssuranceFreshness;
use App\Support\Auth\CredentialSession;
use App\Support\Auth\CrossHostHandoff;
use App\Support\Auth\HandoffUnavailable;
use App\Support\Domains\CanonicalOrigin;
use App\Support\Domains\HostClassification;
use App\Support\Domains\HostnameNormalizer;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0O.8A (ADR 0054 amendment, section 8.7): redeems a cross-host
 * sign-in handoff (App\Support\Auth\CrossHostHandoff) on the TARGET host.
 *
 * `GET /session/handoff?ticket=` -- consumed before anything is rendered,
 * then an immediate 303 to the clean `/app`; every response is `no-store`
 * with `Referrer-Policy: no-referrer`, and the ticket is never logged (the
 * access log keeps the path only; LogSanitizer redacts `ticket=`).
 *
 * The ticket is continuity, not authority. After the atomic single-use
 * redemption this re-checks, in order: the exact target Host (and, for a
 * School, that this Host is still that School's canonical origin); the
 * source session still signed in as the same user; the user exists and is
 * not disabled; the School is active and the membership active; and no
 * DIFFERENT user is signed in on this host (a ticket never swaps one
 * signed-in person for another: the login-CSRF defence). Only then
 * is THIS host's session replaced -- a session id is never taken from the
 * URL or copied between hosts: the old session is invalidated, the login
 * gets a fresh id (Auth::login migrates it), the School selection is set,
 * and the source's MFA assurance time is carried unchanged. Any failure is
 * one generic page and a closed outcome code in the log.
 */
class SessionHandoffController extends Controller
{
    public function __invoke(
        Request $request,
        CrossHostHandoff $handoff,
        CanonicalOrigin $origins,
        HostnameNormalizer $names,
        AuditRecorder $audit,
    ): Response {
        try {
            $ticket = $handoff->consume((string) $request->query('ticket', ''));
        } catch (HandoffUnavailable) {
            return $this->fail($request, 'store_unavailable');
        }

        if ($ticket === null) {
            return $this->fail($request, 'invalid');
        }

        $host = $names->fold($request->getHost());

        if (! hash_equals($ticket['target_host'], $host)) {
            return $this->fail($request, 'wrong_host');
        }

        $user = User::query()->find($ticket['user_id']);

        if (! $user instanceof User || $user->isDisabled() || (int) ($ticket['credential_version'] ?? 0) !== CredentialSession::current($user)) {
            return $this->fail($request, 'user');
        }

        if (! $this->sourceStillSignedIn($ticket['source_session'], $user)) {
            return $this->fail($request, 'source_signed_out');
        }

        $school = null;

        if ($ticket['school_id'] !== null) {
            $school = School::query()->find($ticket['school_id']);

            if ($school === null || ! $school->isActive()) {
                return $this->fail($request, 'school');
            }

            if (! hash_equals(CanonicalOrigin::hostOf($origins->forSchool($school)), $host)) {
                return $this->fail($request, 'wrong_host');
            }

            $member = SchoolMembership::query()->active()
                ->where('user_id', $user->id)
                ->where('school_id', $school->id)
                ->exists();

            if (! $member) {
                return $this->fail($request, 'membership');
            }
        } elseif (HostClassification::schoolHostOf($request) !== null) {
            return $this->fail($request, 'wrong_host');
        }

        // Login-CSRF / session-confusion defence: a ticket never replaces a
        // DIFFERENT signed-in user on this host (sign out there first). The
        // same user's session is replaced; a session id is never adopted.
        $current = Auth::guard('web')->user();
        if ($current !== null && $current->getAuthIdentifier() !== $user->id) {
            return $this->fail($request, 'session_conflict');
        }
        if ($current !== null) {
            Auth::guard('web')->logout();
        }
        $request->session()->invalidate();
        Auth::guard('web')->login($user);
        $request->session()->regenerateToken();
        CredentialSession::stamp($request->session(), $user);

        if ($school !== null) {
            $request->session()->put(RequireSchoolContext::SESSION_KEY, $school->id);
        }

        if (AssuranceFreshness::isFresh($ticket['mfa_verified_at'], (int) config('mfa.assurance_window_minutes'))) {
            $request->session()->put('mfa_verified_at', $ticket['mfa_verified_at']);
        }

        $audit->platform('auth.session_handoff_redeemed', actor: $user, subject: $school, ipAddress: $request->ip(), userAgent: $request->userAgent());
        Log::info('session_handoff.redeemed', ['outcome' => 'redeemed']);

        Inertia::clearHistory();

        return $this->private(redirect('/app', 303));
    }

    /** The source session still exists and is still this user's login (logging out there first voids the ticket). */
    private function sourceStillSignedIn(string $sessionId, User $user): bool
    {
        $raw = app('session')->driver()->getHandler()->read($sessionId);

        if (! is_string($raw) || $raw === '') {
            return false;
        }

        $data = json_decode($raw, true);
        $data = is_array($data) ? $data : @unserialize($raw, ['allowed_classes' => false]);

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        return is_array($data) && ($data[$guard->getName()] ?? null) === $user->id;
    }

    private function fail(Request $request, string $outcome): Response
    {
        Log::warning('session_handoff.failed', ['outcome' => $outcome]);

        return $this->private(Inertia::render('Auth/HandoffFailed')->toResponse($request)->setStatusCode(403));
    }

    private function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
