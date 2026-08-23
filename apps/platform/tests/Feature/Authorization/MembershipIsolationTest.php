<?php

namespace Tests\Feature\Authorization;

use App\Models\SchoolMembership;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 19/47: a role assigned via one School's membership must never
 * leak into another School, and a user with memberships in multiple
 * Schools gets distinct, School-specific capabilities from each.
 */
class MembershipIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_user_can_be_a_member_of_multiple_schools_with_independent_roles(): void
    {
        $user = $this->createUser();
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $membershipA = $this->createMembership($user, $schoolA);
        $this->assignSchoolRole($membershipA, 'school_admin');

        $membershipB = $this->createMembership($user, $schoolB);
        $this->assignSchoolRole($membershipB, 'principal');

        $resolver = app(CapabilityResolver::class);

        $this->assertTrue($resolver->canInSchool($user, 'school.settings.manage', $schoolA));
        $this->assertFalse($resolver->canInSchool($user, 'school.settings.manage', $schoolB));
        $this->assertTrue($resolver->canInSchool($user, 'school.settings.view', $schoolB));
    }

    #[Test]
    public function membership_uniqueness_prevents_duplicate_rows_for_the_same_user_and_school(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($user, $school): void {
            SchoolMembership::query()->create([
                'user_id' => $user->id,
                'school_id' => $school->id,
                'status' => 'active',
            ]);
        });
    }

    #[Test]
    public function school_memberships_are_queryable_centrally_without_school_context(): void
    {
        // Deliberately central (ADR 0020/the migration's docblock): a
        // school-switcher UI must be able to list a user's memberships
        // before any School context is active.
        $user = $this->createUser();
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createMembership($user, $schoolA);
        $this->createMembership($user, $schoolB);

        app(TenantContext::class)->clear();

        $count = SchoolMembership::query()->where('user_id', $user->id)->count();

        $this->assertSame(2, $count);
    }
}
