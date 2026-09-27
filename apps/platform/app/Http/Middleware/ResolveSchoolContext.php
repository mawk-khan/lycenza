<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Domains\CanonicalOrigin;
use App\Support\Domains\HostClassification;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production tenant resolution (section 8, docs/architecture/TENANCY.md).
 * Trusted sources only -- never an arbitrary client-supplied header:
 *
 *  1. On a custom School domain (Phase 0O.8A, ADR 0054 section 8): the Host
 *     names the School -- only an ACTIVE primary domain of an active School
 *     ever classifies as `school` (App\Http\Middleware\ClassifyRequestHost,
 *     which runs first). The Host is INTENT, not authority: TenantContext is
 *     set only for an authenticated, non-disabled user with an ACTIVE
 *     membership in that School. The session selection is written to equal
 *     it; a session carrying a DIFFERENT School on this host (a stale
 *     host-only session, e.g. after the hostname moved to another School)
 *     is forgotten and the request runs with no School -- never ambiguously
 *     (RequireSchoolContext then refuses). A signed-in user with no active
 *     membership gets a fixed 403 page on that host (only logout and the
 *     sign-in handoff still run): domain ownership grants nobody access.
 *  2. Elsewhere (the platform host): an authenticated user's own explicit,
 *     session-stored active-School selection (section 22), re-validated
 *     against a real active membership on every request -- a stale or
 *     tampered value resolves to no School, never a wrong one. A domain
 *     never overrides it (the ADR 0054 finding 3 fix).
 *
 * `/api/*` never takes a School from the Host: a custom domain serves no
 * API route at all, and on the platform host only the session path exists
 * (the stateless API group has none). /api/v1 takes its School from the URL
 * (`school-membership`); ADR 0053 internal service routes take none here.
 *
 * Runs early in the middleware stack (registered before
 * SubstituteBindings, see bootstrap/app.php) so tenant-scoped route
 * model binding (section 37) is safe. Implements terminate() to clear
 * context defensively at the end of the request/response lifecycle --
 * not strictly required under classic PHP-FPM (the connection dies with
 * the process) but keeps this correct if/when persistent-worker
 * runtimes (Octane) are adopted later (section 9).
 */
class ResolveSchoolContext
{
    public const HOST_SCHOOL_ATTRIBUTE = 'lycenza.host_school_id';

    public const NO_ACCESS_BODY = 'You do not have access to this School.';

    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        $requestId = $request->attributes->get('request_id');
        $context->setRequestId($requestId);
        // Fresh top-level unit of work: correlation_id starts equal to
        // request_id (section 34) -- a queued job/consumer further down
        // the chain propagates this same value via TenantScoped, it
        // never mints a new one.
        $context->setCorrelationId($requestId);

        // Only a human actor: a partner API client (Phase 0O.3) is never a
        // TenantContext actor, and its School comes from its credential.
        $user = $request->user();
        if ($user instanceof User) {
            $context->setActor($user);
        }

        // ADR 0053 section 6.3: an internal service route (`service-auth`)
        // takes its School ONLY from the separately verified AI context token
        // -- never from the Host or a session -- and nothing about a School
        // is looked up before the calling service has authenticated.
        if ($this->isServiceRoute($request)) {
            return $next($request);
        }

        $host = HostClassification::schoolHostOf($request);

        if ($host !== null && ! $request->is('api/*')) {
            return $this->resolveSchoolHost($request, $next, $context, (string) $host->schoolId);
        }

        $school = $this->resolveViaActiveSession($request);

        if ($school !== null && $school->isActive()) {
            $context->set($school);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        app(TenantContext::class)->clearAll();
    }

    private function resolveSchoolHost(Request $request, Closure $next, TenantContext $context, string $schoolId): Response
    {
        $request->attributes->set(self::HOST_SCHOOL_ATTRIBUTE, $schoolId);
        $user = $request->user();

        // Before sign-in the Host is only the intended School (branding, the
        // sign-in destination) -- never TenantContext.
        if (! $user instanceof User) {
            return $next($request);
        }

        $membership = SchoolMembership::query()
            ->where('user_id', $user->id)
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->with('school')
            ->first();

        if ($user->isDisabled() || $membership === null || $membership->school === null || ! $membership->school->isActive()) {
            // Logout always works; the handoff endpoint decides for itself
            // (it replaces this host's session after its own checks).
            if ($request->routeIs('logout', 'session.handoff')) {
                return $next($request);
            }

            RequireSchoolContext::forgetSelection($request);

            return $this->noAccess($request);
        }

        $selected = $request->hasSession() ? $request->session()->get(RequireSchoolContext::SESSION_KEY) : null;

        if ($selected !== null && $selected !== $schoolId) {
            RequireSchoolContext::forgetSelection($request);

            return $next($request);
        }

        if ($request->hasSession() && $selected === null) {
            $request->session()->put(RequireSchoolContext::SESSION_KEY, $schoolId);
        }

        $context->set($membership->school);

        return $next($request);
    }

    private function noAccess(Request $request): Response
    {
        if ($request->expectsJson() && $request->header('X-Inertia') !== 'true') {
            return response()->json(['error' => [
                'message' => self::NO_ACCESS_BODY,
                'status' => 403,
                'code' => 'school_host_access_denied',
                'requestId' => $request->attributes->get('request_id'),
                'errors' => null,
            ]], 403);
        }

        return Inertia::render('App/SchoolHostNoAccess', [
            'platformUrl' => app(CanonicalOrigin::class)->platformUrl('app'),
        ])->toResponse($request)->setStatusCode(403);
    }

    private function isServiceRoute(Request $request): bool
    {
        return in_array('service-auth', $request->route()?->gatherMiddleware() ?? [], true);
    }

    private function resolveViaActiveSession(Request $request): ?School
    {
        $user = $request->user();
        $schoolId = $request->hasSession() ? $request->session()->get('active_school_id') : null;

        // A value that is not a UUID can never name a School; querying the
        // uuid column with it would be a PostgreSQL error (a 500), not "no
        // School". Treated as stale -- RequireSchoolContext clears it.
        if (! $user instanceof User || ! is_string($schoolId) || ! Str::isUuid($schoolId)) {
            return null;
        }

        $membership = SchoolMembership::query()
            ->where('user_id', $user->id)
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->first();

        return $membership?->school;
    }
}
