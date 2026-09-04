<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Support\Auth\Mfa\MfaAuditActions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

class MfaTwoStageLoginTest extends TestCase
{
    use CreatesMfaFixtures;

    #[Test]
    public function password_success_for_an_mfa_enrolled_user_does_not_fully_authenticate(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $this->enrollActiveMfaFactor($user);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertRedirect('/login/mfa');
        $this->assertGuest();
        $this->assertSame($user->id, session('mfa_pending_user_id'));
        $this->assertSame(
            0,
            PlatformAuditEvent::query()->where('event_type', 'auth.login_succeeded')->where('actor_user_id', $user->id)->count(),
            'auth.login_succeeded must not fire until stage 2 completes.',
        );
    }

    #[Test]
    public function a_correct_totp_code_completes_the_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->enrollActiveMfaFactor($user, $secret);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);

        $response = $this->post('/login/mfa', ['code' => $this->currentTotpCodeFor($secret)]);

        $response->assertRedirect('/app');
        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('mfa_pending_user_id'));
        $this->assertNotNull(session('mfa_verified_at'));
        $this->assertSame(
            1,
            PlatformAuditEvent::query()->where('event_type', 'auth.login_succeeded')->where('actor_user_id', $user->id)->count(),
        );
        $this->assertSame(
            1,
            PlatformAuditEvent::query()->where('event_type', MfaAuditActions::CHALLENGE_SUCCEEDED)->where('actor_user_id', $user->id)->count(),
        );
    }

    #[Test]
    public function an_incorrect_code_is_rejected_and_audited_without_completing_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $this->enrollActiveMfaFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);
        $response = $this->post('/login/mfa', ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertSame(
            1,
            PlatformAuditEvent::query()->where('event_type', MfaAuditActions::CHALLENGE_FAILED)->where('actor_user_id', $user->id)->count(),
        );
    }

    #[Test]
    public function a_valid_recovery_code_also_completes_the_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $this->enrollActiveMfaFactor($user);
        $codes = $this->issueRecoveryCodes($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);
        $response = $this->post('/login/mfa', ['code' => $codes[0]]);

        $response->assertRedirect('/app');
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_disabled_user_cannot_complete_the_mfa_challenge(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->enrollActiveMfaFactor($user, $secret);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);

        $user->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();

        $response = $this->post('/login/mfa', ['code' => $this->currentTotpCodeFor($secret)]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    #[Test]
    public function the_challenge_page_redirects_to_login_without_a_pending_session(): void
    {
        $this->get('/login/mfa')->assertRedirect('/login');
    }

    #[Test]
    public function logout_clears_mfa_assurance(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->enrollActiveMfaFactor($user, $secret);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);
        $this->post('/login/mfa', ['code' => $this->currentTotpCodeFor($secret)]);

        $this->assertNotNull(session('mfa_verified_at'));

        $this->post('/logout');

        $this->assertNull(session('mfa_verified_at'));
        $this->assertNull(session('mfa_pending_user_id'));
    }
}
