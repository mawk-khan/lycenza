<?php

namespace Tests\Feature\Auth;

use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.4D-P1 section 6: a User with no active MFA factor must see
 * byte-for-byte the SAME login behavior as before this checkpoint --
 * single-stage, immediate full session establishment. This is the
 * regression LoginController's docblock references.
 */
class PasswordOnlyLoginUnchangedTest extends TestCase
{
    #[Test]
    public function a_user_without_mfa_logs_in_directly_without_any_challenge_step(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertRedirect('/app');
        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('mfa_pending_user_id'));
        $this->assertSame(
            1,
            PlatformAuditEvent::query()->where('event_type', 'auth.login_succeeded')->where('actor_user_id', $user->id)->count(),
        );
    }
}
