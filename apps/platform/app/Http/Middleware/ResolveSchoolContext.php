<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\VerifiedSchoolDomain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production tenant resolution (section 8, docs/architecture/TENANCY.md).
 * Two trusted sources only -- never an arbitrary client-supplied header:
 *
 *  1. Verified domain routing: the request's Host header matches a
 *     verified SchoolDomain row.
 *  2. An authenticated user's own explicit, session-stored active-School
 *     selection (section 22), re-validated against a real active
 *     membership on every request -- a stale/tampered session value
 *     resolves to no School, never a wrong one.
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

        if ($user = $request->user()) {
            $context->setActor($user);
        }

        $school = $this->resolveViaVerifiedDomain($request) ?? $this->resolveViaActiveSession($request);

        if ($school !== null && $school->isActive()) {
            $context->set($school);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        app(TenantContext::class)->clearAll();
    }

    private function resolveViaVerifiedDomain(Request $request): ?School
    {
        return VerifiedSchoolDomain::schoolFor($request->getHost());
    }

    private function resolveViaActiveSession(Request $request): ?School
    {
        $user = $request->user();
        $schoolId = $request->hasSession() ? $request->session()->get('active_school_id') : null;

        // A value that is not a UUID can never name a School; querying the
        // uuid column with it would be a PostgreSQL error (a 500), not "no
        // School". Treated as stale -- RequireSchoolContext clears it.
        if ($user === null || ! is_string($schoolId) || ! Str::isUuid($schoolId)) {
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
