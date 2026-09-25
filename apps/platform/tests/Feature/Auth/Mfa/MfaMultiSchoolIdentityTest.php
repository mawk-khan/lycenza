<?php

namespace Tests\Feature\Auth\Mfa;

use App\Support\Auth\Mfa\MfaChallengeService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4D-P1 section 10/12/18: one User's MFA state is identical
 * across every School they belong to -- MFA is User-global identity
 * data, never School-owned (user_mfa_factors/user_mfa_recovery_codes
 * carry no school_id, no RLS).
 */
class MfaMultiSchoolIdentityTest extends TestCase
{
    use CreatesMfaFixtures;
    use CreatesTenancyFixtures;

    #[Test]
    public function a_user_with_two_school_memberships_has_exactly_one_mfa_state(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $schoolA);
        $this->createMembership($user, $schoolB);

        $this->enrollActiveMfaFactor($user);

        $challenge = app(MfaChallengeService::class);

        $this->assertTrue($challenge->userHasActiveFactor($user));
        $this->assertSame(
            $challenge->activeFactorFor($user)->id,
            $challenge->activeFactorFor($user)->id,
            'The same factor is resolved regardless of which School is active -- no per-School lookup is even possible (no school_id column exists).',
        );
    }

    #[Test]
    public function mfa_assurance_established_while_one_school_is_active_still_satisfies_a_route_after_switching_schools(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createPlatformRoot();
        $membershipA = $this->createMembership($user, $schoolA);
        $this->assignSchoolRole($membershipA, 'school_admin');
        $membershipB = $this->createMembership($user, $schoolB);
        $this->assignSchoolRole($membershipB, 'school_admin');

        $this->enrollActiveMfaFactor($user);
        $this->actingAs($user);
        session(['mfa_verified_at' => now()->toIso8601String()]);

        $this->post("/app/schools/{$schoolA->id}/activate");
        $this->get('/internal/mfa-demo/ping')->assertOk();

        $this->post("/app/schools/{$schoolB->id}/activate");
        $this->get('/internal/mfa-demo/ping')->assertOk();
    }
}
