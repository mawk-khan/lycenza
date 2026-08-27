<?php

namespace App\Providers;

use App\Models\School;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 0C.4 sections 28-32: the rate-limit foundation deferred by
 * earlier checkpoints (docs/architecture/RELIABILITY.md's "Rate
 * limiting interaction" section, written during Phase 0C.2, explicitly
 * flagged this as not-yet-implemented). Separate limiter CLASSES for
 * separate risk profiles -- never one generic limiter reused
 * everywhere (section 28).
 *
 * Declared ordering (section 71, and CLAUDE.md): auth -> tenant ->
 * capability -> rate limit -> idempotency. `throttle:*` middleware is
 * placed AFTER `capability:` and BEFORE `idempotent` in every route's
 * middleware array, so an unauthorized caller never consumes quota,
 * and a legitimate idempotent replay is still counted (section 30:
 * changing the Idempotency-Key must not be a way to bypass the limit).
 *
 * That declared array order is NOT the actual runtime order, though,
 * and every limiter here is written to not depend on it: Laravel
 * sorts middleware execution by `$middlewarePriority`, which lists
 * `Illuminate\Routing\Middleware\ThrottleRequests` ahead of any
 * custom, non-prioritized middleware (our `school-membership`,
 * `capability`, `idempotent`) regardless of declaration order --
 * `throttle:*` middleware genuinely runs BEFORE `school-membership`
 * sets `App\Support\Tenancy\TenantContext`'s School. `tenantKey()`
 * below therefore reads the School id directly off the route
 * parameter (available before any middleware has resolved
 * membership) rather than off TenantContext -- see its own docblock.
 * This was a real bug caught by this checkpoint's own test suite
 * (Tests\Feature\RateLimiting\SchoolApiMutationsThrottleTest) before
 * the fix: every School-scoped limiter silently collapsed to
 * actor-only keying.
 */
class RateLimiterServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Section 28/29: login -- IP-keyed (an unauthenticated caller
        // has no actor/School identity yet). Formalizes the
        // previously-inline `throttle:6,1` on routes/web.php's login
        // route into a named, documented limiter.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));

        // Public/lightly-authenticated read endpoints -- IP-keyed,
        // generous; never intended to block ordinary polling.
        RateLimiter::for('public-api', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // School-scoped mutating endpoints in general (section 29):
        // keyed by (School, actor) so School A exhausting its own
        // quota can never affect School B, and one actor's heavy
        // legitimate use never starves a different actor in the SAME
        // School.
        RateLimiter::for('school-api-mutations', fn (Request $request) => Limit::perMinute(60)->by($this->tenantKey($request)));

        // Webhook administration specifically (section 31): tighter
        // than general School mutations -- creating an endpoint,
        // rotating a secret, or forcing a redelivery are inherently
        // rarer, more sensitive operator actions than routine data
        // mutations.
        RateLimiter::for('webhook-admin', fn (Request $request) => Limit::perMinute(20)->by($this->tenantKey($request)));

        // AI Gateway -> Laravel internal service calls (section 31):
        // keyed by service identity, not IP (multiple containers of
        // the same service could share an IP in a real deployment).
        // Generous enough that normal tool-invocation traffic is never
        // throttled into unreliability, while still bounding a
        // misbehaving/compromised service identity.
        RateLimiter::for('internal-service', function (Request $request) {
            $identity = $request->attributes->get('service_identity');

            return Limit::perMinute(300)->by($identity !== null ? $identity->id : $request->ip());
        });

        // Internal diagnostics (section 32): OperationalStatusService::
        // full() runs real per-School queries -- an authenticated
        // diagnostics endpoint must still never be hammerable into an
        // expensive-query DoS vector.
        RateLimiter::for('internal-diagnostics', function (Request $request) {
            $user = $request->user();

            return Limit::perMinute(12)->by($user !== null ? $user->id : $request->ip());
        });

        // Phase 8A.15: the four HR read endpoints (Directory/Profile/
        // Timeline/sensitive documents) are authenticated, School-
        // scoped GET routes reaching real PostgreSQL queries over
        // Restricted/Highly Sensitive data -- reusing `tenantKey()`
        // gives them the same (School, actor) isolation
        // `school-api-mutations` already established, never IP-only
        // (an internet-facing authenticated read API is not exempt
        // from abuse control merely because it is read-only). Generous
        // enough that ordinary Directory search-as-you-type/pagination/
        // mobile polling stays practical -- 120/min matches
        // `public-api`'s existing generosity, but precisely School+
        // actor-keyed rather than IP-keyed since every caller here is
        // already authenticated.
        RateLimiter::for('hr-api-reads', fn (Request $request) => Limit::perMinute(120)->by($this->tenantKey($request)));

        // The Highly Sensitive document metadata endpoint specifically
        // (checkpoint 8A.15 section 9): a deliberately stricter limiter,
        // matching `webhook-admin`'s existing precedent for "rarer,
        // more sensitive operator actions than routine data reads" --
        // reading Highly Sensitive metadata is not a page-through-100-
        // rows workflow the way Directory search is.
        RateLimiter::for('hr-api-sensitive-reads', fn (Request $request) => Limit::perMinute(20)->by($this->tenantKey($request)));

        // Phase 0E.5: Documents HTTP transport -- four risk profiles,
        // each School+actor-keyed via the same `tenantKey()` (never
        // IP-only for an authenticated School API). `documents-reads`
        // mirrors `hr-api-reads`' generosity for ordinary Employee
        // listing. `documents-sensitive-reads` mirrors
        // `hr-api-sensitive-reads`'s stricter bound and is ALSO applied
        // to the direct-by-id metadata route (`GET
        // /documents/{document}`), since that route cannot know a
        // Document's classification tier before the service resolves
        // it -- applying the more conservative bound uniformly,
        // regardless of which tier a given call happens to hit, avoids
        // ever varying rate-limit behavior by hidden classification
        // (no side channel). `documents-content` is separate again:
        // streaming actual file bytes is more expensive than any
        // metadata-only read, so it gets its own dedicated, equally
        // strict bound rather than sharing either read bucket.
        // `documents-writes` covers upload and archive -- both
        // consequential mutations, stricter than general
        // `school-api-mutations` for the same reason `webhook-admin`
        // already is (rarer, costlier operator actions than routine
        // data mutations).
        RateLimiter::for('documents-reads', fn (Request $request) => Limit::perMinute(120)->by($this->tenantKey($request)));
        RateLimiter::for('documents-sensitive-reads', fn (Request $request) => Limit::perMinute(20)->by($this->tenantKey($request)));
        RateLimiter::for('documents-content', fn (Request $request) => Limit::perMinute(20)->by($this->tenantKey($request)));
        RateLimiter::for('documents-writes', fn (Request $request) => Limit::perMinute(30)->by($this->tenantKey($request)));

        // Phase 5D.3 §41: the public Guardian account-invitation
        // acceptance routes (token-check GET and accept POST) are
        // unauthenticated by construction (root CLAUDE.md's RLS/tenant
        // model requires resolving School before any actor exists) --
        // IP-keyed, like `login`, since there is no actor/School
        // identity yet. The token itself (32 bytes, hashed at rest) is
        // already infeasible to guess; this is defense-in-depth against
        // sheer request volume, not the primary control.
        RateLimiter::for('guardian-invitation-accept', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // Deliberately NO limiter for /health/live or /health/ready
        // (section 32) -- infrastructure must be able to poll them as
        // frequently as its own probe interval requires, and both are
        // among the cheapest possible endpoints in this application.
    }

    /**
     * Deliberately reads the School id from the ROUTE PARAMETER
     * (`{school}`), never from `App\Support\Tenancy\TenantContext` --
     * `Illuminate\Routing\Middleware\ThrottleRequests` is one of
     * Laravel's framework-prioritized middleware
     * (`$middlewarePriority`), so it is sorted to run BEFORE our
     * route-specific, non-prioritized `school-membership` middleware
     * regardless of the declared order in routes/api.php. By
     * throttle-evaluation time, TenantContext's School has not been set
     * yet (a real bug found and fixed during this checkpoint's own test
     * suite -- see Tests\Feature\RateLimiting\SchoolApiMutationsThrottleTest);
     * only the actor is reliably available that early, because the
     * GLOBAL `ResolveSchoolContext` middleware (which also runs before
     * any route-specific middleware) already set it from
     * `$request->user()`. The route parameter, by contrast, needs no
     * middleware to have run at all -- it is read directly off the
     * request/route match. This key does not re-verify membership (that
     * remains `school-membership`'s job); an invalid/forged School id
     * here only ever produces a harmless, separate bucket.
     */
    private function tenantKey(Request $request): string
    {
        $routeSchool = $request->route('school');
        $schoolId = $routeSchool instanceof School ? $routeSchool->id : $routeSchool;

        $actor = $request->user();

        return ($schoolId ?? 'no-school').':'.($actor !== null ? $actor->id : $request->ip());
    }
}
