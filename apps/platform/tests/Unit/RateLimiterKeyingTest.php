<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0C.4 section 29/71: proves the KEYING behind
 * App\Providers\RateLimiterServiceProvider's tenant-aware limiters.
 *
 * Deliberately builds each Request with a bound `school` ROUTE
 * PARAMETER (via a minimal fake route resolver) rather than setting
 * App\Support\Tenancy\TenantContext -- TenantContext's School is NOT
 * reliably populated yet at the point `throttle:*` middleware actually
 * evaluates in a real request (see RateLimiterServiceProvider::
 * tenantKey()'s docblock for why, and
 * Tests\Feature\RateLimiting\SchoolApiMutationsThrottleTest for the
 * real HTTP-level proof this reflects the ACTUAL runtime key).
 */
class RateLimiterKeyingTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function requestForSchool(string $schoolId, ?User $user = null): Request
    {
        $request = Request::create('/irrelevant');

        if ($user !== null) {
            $request->setUserResolver(fn () => $user);
        }

        $request->setRouteResolver(fn () => new class($schoolId)
        {
            public function __construct(private readonly string $schoolId) {}

            public function parameter($name, $default = null)
            {
                return $name === 'school' ? $this->schoolId : $default;
            }
        });

        return $request;
    }

    #[Test]
    public function school_api_mutations_keys_by_school_and_actor_so_two_schools_never_share_a_bucket(): void
    {
        $closure = app(RateLimiter::class)->limiter('school-api-mutations');

        $schoolA = $this->createSchool();
        $userA = $this->createUser();
        $keyA = $closure($this->requestForSchool($schoolA->id, $userA))->key;

        $schoolB = $this->createSchool();
        $userB = $this->createUser();
        $keyB = $closure($this->requestForSchool($schoolB->id, $userB))->key;

        $this->assertNotSame($keyA, $keyB);
    }

    #[Test]
    public function school_api_mutations_keys_the_same_actor_and_school_identically_across_calls(): void
    {
        $closure = app(RateLimiter::class)->limiter('school-api-mutations');

        $school = $this->createSchool();
        $user = $this->createUser();

        $first = $closure($this->requestForSchool($school->id, $user))->key;
        $second = $closure($this->requestForSchool($school->id, $user))->key;

        $this->assertSame($first, $second);
    }

    #[Test]
    public function two_different_actors_in_the_same_school_do_not_share_a_bucket(): void
    {
        $closure = app(RateLimiter::class)->limiter('school-api-mutations');

        $school = $this->createSchool();
        $userA = $this->createUser();
        $userB = $this->createUser();

        $keyA = $closure($this->requestForSchool($school->id, $userA))->key;
        $keyB = $closure($this->requestForSchool($school->id, $userB))->key;

        $this->assertNotSame($keyA, $keyB);
    }

    #[Test]
    public function the_same_actor_across_two_different_schools_does_not_share_a_bucket(): void
    {
        // The specific bug this checkpoint's test suite caught: before
        // the fix, tenantKey() ignored the School entirely once routed
        // through the real middleware pipeline, so the SAME user acting
        // on two different Schools shared one bucket.
        $closure = app(RateLimiter::class)->limiter('school-api-mutations');

        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createUser();

        $keyA = $closure($this->requestForSchool($schoolA->id, $user))->key;
        $keyB = $closure($this->requestForSchool($schoolB->id, $user))->key;

        $this->assertNotSame($keyA, $keyB);
    }

    #[Test]
    public function webhook_admin_uses_the_same_tenant_keying_as_school_api_mutations(): void
    {
        $schoolLimiter = app(RateLimiter::class)->limiter('school-api-mutations');
        $webhookLimiter = app(RateLimiter::class)->limiter('webhook-admin');

        $school = $this->createSchool();
        $user = $this->createUser();
        $request = $this->requestForSchool($school->id, $user);

        // Different limiter NAMES (so different quotas/buckets overall
        // -- Laravel namespaces by limiter name internally), but the
        // same underlying per-tenant signature.
        $this->assertSame($schoolLimiter($request)->key, $webhookLimiter($request)->key);
    }

    #[Test]
    public function login_is_keyed_by_ip_not_by_tenant(): void
    {
        $closure = app(RateLimiter::class)->limiter('login');
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']);

        $this->assertSame('203.0.113.5', $closure($request)->key);
    }
}
