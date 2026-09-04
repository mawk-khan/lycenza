<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4D-P1 sections 8/9/16/26: exercises the generic `mfa`
 * middleware against the local/testing-only demo route
 * (GET /internal/mfa-demo/ping, middleware
 * ['capability:platform.operations.view,platform', 'mfa']) --
 * proving both the middleware's own three outcomes AND that it never
 * substitutes for capability authorization.
 */
class MfaMiddlewareTest extends TestCase
{
    use CreatesMfaFixtures;
    use CreatesTenancyFixtures;

    private function authorizedUser(): User
    {
        $user = User::factory()->create();
        $this->assignPlatformRole($user, 'platform_super_admin');

        return $user;
    }

    #[Test]
    public function an_unenrolled_user_is_denied_with_the_not_enrolled_code(): void
    {
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->get('/internal/mfa-demo/ping');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'mfa_required_not_enrolled');
    }

    #[Test]
    public function an_enrolled_user_with_no_assurance_yet_is_denied_with_the_step_up_code(): void
    {
        $user = $this->authorizedUser();
        $this->enrollActiveMfaFactor($user);

        $response = $this->actingAs($user)->get('/internal/mfa-demo/ping');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'mfa_step_up_required');
    }

    #[Test]
    public function an_enrolled_user_with_expired_assurance_is_denied_with_the_step_up_code(): void
    {
        $user = $this->authorizedUser();
        $this->enrollActiveMfaFactor($user);

        $this->actingAs($user);
        session(['mfa_verified_at' => now()->subMinutes((int) config('mfa.assurance_window_minutes') + 1)->toIso8601String()]);

        $response = $this->get('/internal/mfa-demo/ping');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'mfa_step_up_required');
    }

    #[Test]
    public function an_enrolled_user_with_valid_assurance_is_allowed(): void
    {
        $user = $this->authorizedUser();
        $this->enrollActiveMfaFactor($user);

        $this->actingAs($user);
        session(['mfa_verified_at' => now()->toIso8601String()]);

        $response = $this->get('/internal/mfa-demo/ping');

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    #[Test]
    public function valid_mfa_assurance_never_substitutes_for_a_missing_capability(): void
    {
        $user = User::factory()->create(); // no platform_super_admin role
        $this->enrollActiveMfaFactor($user);

        $this->actingAs($user);
        session(['mfa_verified_at' => now()->toIso8601String()]);

        $response = $this->get('/internal/mfa-demo/ping');

        // Denied by the capability gate BEFORE `mfa` ever runs (an
        // ordinary Illuminate\Auth\Access\AuthorizationException,
        // rendered by Laravel's default non-JSON handler for this
        // non-`/api/*` route -- NOT a `RequireMfa`-shaped JSON
        // response, so don't call ->json() on it) -- valid,
        // already-established MFA assurance does not grant a
        // capability this User doesn't have.
        $response->assertStatus(403);
        $this->assertStringNotContainsString('mfa_required_not_enrolled', $response->getContent());
    }
}
