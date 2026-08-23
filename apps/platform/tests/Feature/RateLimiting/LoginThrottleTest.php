<?php

namespace Tests\Feature\RateLimiting;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0C.4 section 28/29: a real HTTP-level proof that the formalized
 * `throttle:login` limiter (App\Providers\RateLimiterServiceProvider)
 * actually enforces the same 6-per-minute bound the previous inline
 * `throttle:6,1` did, and that health probes are never subject to any
 * limiter at all -- section 32 explicitly requires infrastructure be
 * able to poll them as often as it needs.
 */
class LoginThrottleTest extends TestCase
{
    #[Test]
    public function the_seventh_login_attempt_within_a_minute_is_throttled(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);
            $response->assertStatus(302);
        }

        $response = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);

        $response->assertStatus(429);
    }

    #[Test]
    public function health_endpoints_are_never_throttled(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/health/live')->assertOk();
            $this->getJson('/api/health/ready')->assertOk();
        }
    }
}
