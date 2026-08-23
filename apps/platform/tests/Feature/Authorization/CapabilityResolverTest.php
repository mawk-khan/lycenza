<?php

namespace Tests\Feature\Authorization;

use App\Support\Authorization\CapabilityResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

class CapabilityResolverTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_school_admin_has_the_capabilities_their_role_grants(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $resolver = app(CapabilityResolver::class);

        $this->assertTrue($resolver->canInSchool($user, 'school.settings.manage', $school));
        $this->assertTrue($resolver->canInSchool($user, 'school.members.manage', $school));
    }

    #[Test]
    public function a_principal_can_view_but_not_manage_settings(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        $resolver = app(CapabilityResolver::class);

        $this->assertTrue($resolver->canInSchool($user, 'school.settings.view', $school));
        $this->assertFalse($resolver->canInSchool($user, 'school.settings.manage', $school));
    }

    #[Test]
    public function a_role_in_school_a_grants_nothing_in_school_b(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $resolver = app(CapabilityResolver::class);

        $this->assertTrue($resolver->canInSchool($user, 'school.settings.manage', $schoolA));
        $this->assertFalse($resolver->canInSchool($user, 'school.settings.manage', $schoolB));
    }

    #[Test]
    public function a_user_with_no_membership_has_no_capabilities_in_any_school(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'school.settings.view', $school));
    }

    #[Test]
    public function a_suspended_membership_grants_no_capabilities(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school, status: 'suspended');
        $this->assignSchoolRole($membership, 'school_admin');

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'school.settings.manage', $school));
    }

    #[Test]
    public function a_disabled_user_has_no_capabilities_even_with_an_active_membership_and_role(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        // is_disabled/disabled_at are deliberately NOT mass-assignable
        // (a user must never be able to disable/enable themselves via
        // a generic update()) -- forceFill simulates a real
        // administrative "disable user" action.
        $user->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'school.settings.manage', $school));
    }

    #[Test]
    public function platform_capability_grant_does_not_imply_school_capability(): void
    {
        $user = $this->createUser();
        $this->assignPlatformRole($user, 'platform_super_admin');
        $school = $this->createSchool();

        $resolver = app(CapabilityResolver::class);

        $this->assertTrue($resolver->canPlatform($user, 'platform.schools.manage'));
        // No membership in $school -> no school capability, regardless
        // of platform role (section 12/30: no invisible bypass).
        $this->assertFalse($resolver->canInSchool($user, 'school.settings.manage', $school));
        $this->assertFalse($resolver->can($user, 'school.settings.manage', $school));
    }

    #[Test]
    public function school_capability_grant_does_not_imply_platform_capability(): void
    {
        [$user] = $this->createSchoolAdmin('school_admin');

        $this->assertFalse(app(CapabilityResolver::class)->canPlatform($user, 'platform.schools.manage'));
    }

    #[Test]
    public function the_generic_can_method_dispatches_by_capability_namespace(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $platformUser = $this->createUser();
        $this->assignPlatformRole($platformUser, 'platform_super_admin');

        $resolver = app(CapabilityResolver::class);

        $this->assertTrue($resolver->can($user, 'school.settings.manage', $school));
        $this->assertTrue($resolver->can($platformUser, 'platform.schools.manage'));
        $this->assertFalse($resolver->can($user, 'platform.schools.manage'));
    }
}
