<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Models\UserMfaRecoveryCode;
use App\Support\Auth\Mfa\MfaAuditActions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4D-P1 section 13/19/20: administrative MFA reset is
 * platform-level (`platform.users.mfa.reset`), never a School-admin
 * action -- see MfaAdminController's docblock. Self-service disable is
 * covered by MfaFactorServiceTest.
 */
class MfaAdminResetTest extends TestCase
{
    use CreatesMfaFixtures;
    use CreatesTenancyFixtures;

    #[Test]
    public function a_platform_super_admin_can_reset_another_users_mfa(): void
    {
        $admin = User::factory()->create();
        $this->assignPlatformRole($admin, 'platform_super_admin');

        $target = User::factory()->create();
        $factor = $this->enrollActiveMfaFactor($target);
        $this->issueRecoveryCodes($target);

        $response = $this->actingAs($admin)->post("/app/account/admin/users/{$target->id}/mfa/reset");

        $response->assertRedirect();
        $factor->refresh();
        $this->assertSame('revoked', $factor->status);
        $this->assertSame(
            0,
            UserMfaRecoveryCode::query()->where('user_id', $target->id)->whereNull('consumed_at')->count(),
        );
        $this->assertSame(
            1,
            PlatformAuditEvent::query()->where('event_type', MfaAuditActions::RESET_BY_ADMIN)->where('actor_user_id', $admin->id)->count(),
        );
    }

    #[Test]
    public function a_school_admin_without_the_platform_capability_cannot_reset_mfa(): void
    {
        [$schoolAdmin, $school] = $this->createSchoolAdmin('school_admin');
        $this->actingAs($schoolAdmin)->post("/app/schools/{$school->id}/activate");

        $target = User::factory()->create();
        $factor = $this->enrollActiveMfaFactor($target);

        $response = $this->post("/app/account/admin/users/{$target->id}/mfa/reset");

        $response->assertForbidden();
        $factor->refresh();
        $this->assertSame('active', $factor->status, 'A School admin must never be able to reset a User\'s MFA -- User identity is cross-School.');
    }

    #[Test]
    public function an_ordinary_user_cannot_reset_another_users_mfa(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $this->enrollActiveMfaFactor($target);

        $this->actingAs($user)->post("/app/account/admin/users/{$target->id}/mfa/reset")->assertForbidden();
    }
}
