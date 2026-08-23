<?php

namespace App\Http\Middleware\Api;

use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes trusted School/actor TenantContext for a `/api/v1`
 * Bearer-token route bound to a `{school}` route parameter -- the same
 * "re-verify a real, active SchoolMembership server-side, never trust
 * the client-supplied school_id alone" check
 * App\Http\Controllers\Api\V1\SchoolContextController already performs
 * inline, factored out here so OTHER route-parameter-bound endpoints
 * (starting with the idempotency demo action) can reuse it as ordinary
 * middleware instead of duplicating the check in every controller.
 *
 * MUST run after `auth:sanctum` and BEFORE any middleware that assumes
 * TenantContext already has a School (e.g. `capability:...`,
 * `idempotent`) -- see routes/api.php.
 *
 * Deliberately returns 404, not 403, for a School the caller has no
 * active membership in -- indistinguishable from a School that doesn't
 * exist, matching docs/architecture/API.md's "Cross-tenant probing"
 * convention.
 */
class EnsureSchoolMembershipContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Resolved defensively rather than relying on middleware-order
        // assumptions about Laravel's SubstituteBindings: the route
        // parameter may still be the raw path segment (a string id) if
        // this middleware happens to run before implicit route-model
        // binding, or an already-bound School if it runs after.
        $routeSchool = $request->route('school');
        $school = $routeSchool instanceof School
            ? $routeSchool
            : School::find($routeSchool);

        if ($school === null) {
            abort(404);
        }

        $membership = SchoolMembership::query()
            ->where('user_id', $user->id)
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->first();

        if ($membership === null || ! $school->isActive()) {
            return response()->json([
                'error' => [
                    'message' => 'Not found.',
                    'status' => 404,
                    'requestId' => $request->attributes->get('request_id'),
                    'errors' => null,
                ],
            ], 404);
        }

        $context = app(TenantContext::class);
        $context->set($school);
        $context->setActor($user);

        try {
            return $next($request);
        } finally {
            // Context must not leak into whatever this worker process
            // handles next (real HTTP requests already get a fresh
            // scoped() TenantContext per request, but this matches the
            // same explicit discipline AiToolController/AiAuditController
            // use, and matters for any long-running worker mode).
            $context->clearAll();
        }
    }
}
