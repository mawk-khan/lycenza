<?php

namespace Tests\Feature\RateLimiting;

use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0C.4 section 29: real end-to-end HTTP proof (not just the
 * keying-level proof in Tests\Unit\RateLimiterKeyingTest) that
 * exhausting School A's `school-api-mutations` quota never affects
 * School B -- the actual property CLAUDE.md rule 5/7's tenant
 * isolation requires of a tenant-aware rate limiter.
 *
 * This test is also what originally caught a real bug in
 * App\Providers\RateLimiterServiceProvider: `throttle:*` middleware is
 * one of Laravel's framework-prioritized middleware, so it runs BEFORE
 * this project's own `school-membership` middleware regardless of the
 * order declared in routes/api.php -- the limiter's key must never
 * depend on App\Support\Tenancy\TenantContext's School, which is not
 * yet set at throttle-evaluation time (see tenantKey()'s docblock for
 * the fix).
 */
class SchoolApiMutationsThrottleTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function exhausting_one_schools_quota_never_throttles_a_different_school(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $tokenA = $userA->createToken('test-device')->plainTextToken;

        // The limiter is 60/minute (RateLimiterServiceProvider) --
        // reusing the SAME Idempotency-Key across every call means
        // only the FIRST actually executes the mutation (the rest
        // replay); every call still passes through `throttle:` first
        // (section 71's ordering), so all 60 still count against the
        // quota regardless of replay outcome.
        for ($i = 0; $i < 60; $i++) {
            $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
                ->withHeader('Idempotency-Key', 'throttle-proof-key')
                ->postJson("/api/v1/schools/{$schoolA->id}/idempotency-demo/increment");

            $response->assertStatus(200);
        }

        $exhausted = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('Idempotency-Key', 'throttle-proof-key')
            ->postJson("/api/v1/schools/{$schoolA->id}/idempotency-demo/increment");

        $exhausted->assertStatus(429);

        [$userB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $tokenB = $userB->createToken('test-device')->plainTextToken;

        // Sanctum's RequestGuard memoizes the resolved user for its own
        // lifetime -- without this, the request below would silently
        // re-authenticate as user A (a Laravel testing-client quirk,
        // not an application bug: the guard instance persists across
        // sequential $this->postJson() calls within one test; see the
        // identical note in
        // Tests\Feature\Idempotency\IdempotencyDemoEndpointTest).
        Auth::forgetGuards();

        $responseB = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('Idempotency-Key', 'throttle-proof-key-b')
            ->postJson("/api/v1/schools/{$schoolB->id}/idempotency-demo/increment");

        $responseB->assertStatus(200);
    }
}
