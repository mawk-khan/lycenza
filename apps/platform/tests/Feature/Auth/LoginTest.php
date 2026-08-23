<?php

namespace Tests\Feature\Auth;

use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_guest_can_view_the_login_page(): void
    {
        $this->get('/login')->assertOk();
    }

    #[Test]
    public function valid_credentials_log_the_user_in_and_are_audited(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertRedirect('/app');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(
            1,
            PlatformAuditEvent::query()->where('event_type', 'auth.login_succeeded')->where('actor_user_id', $user->id)->count(),
        );
    }

    #[Test]
    public function invalid_credentials_are_rejected_and_audited(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(
            1,
            PlatformAuditEvent::query()->where('event_type', 'auth.login_failed')->count(),
        );
    }

    #[Test]
    public function a_disabled_user_cannot_log_in_even_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $user->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(
            1,
            PlatformAuditEvent::query()->where('event_type', 'auth.login_denied_disabled_user')->count(),
        );
    }

    #[Test]
    public function repeated_failed_attempts_are_rate_limited_even_with_the_right_password(): void
    {
        // Both the route's throttle:6,1 middleware AND
        // LoginController's own email+IP-keyed RateLimiter contribute
        // to this (defense in depth) -- at the shared limit of 6 they
        // overlap, so this test proves the end-to-end outcome rather
        // than isolating which mechanism specifically fired.
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);

        $response->assertStatus(429);
        $this->assertGuest();
    }

    #[Test]
    public function an_authenticated_user_can_log_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->post('/logout');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }
}
