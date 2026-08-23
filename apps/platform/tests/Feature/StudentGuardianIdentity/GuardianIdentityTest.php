<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A foundation slice: proves the canonical Guardian data model --
 * a permanent School-level identity, structurally independent of
 * `users` (no user_id column; a future GuardianUserLink is an explicit,
 * separate join for portal access, not implemented here). See
 * docs/modules/STUDENT-GUARDIAN-IDENTITY.md.
 */
class GuardianIdentityTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_guardian_can_be_created_within_a_school(): void
    {
        $school = $this->createSchool();

        $guardian = $this->createGuardian($school, ['first_name' => 'Asha', 'last_name' => 'Verma']);

        $this->assertSame($school->id, $guardian->school_id);
        $this->assertSame('Asha', $guardian->first_name);
        $this->assertTrue($guardian->isActive());
    }

    #[Test]
    public function a_guardian_row_carries_no_user_id_column(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);

        $this->assertArrayNotHasKey('user_id', $guardian->getAttributes());
    }

    #[Test]
    public function school_a_cannot_see_school_bs_guardian_through_the_eloquent_scope(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createGuardian($schoolB);

        $visible = app(TenantContext::class)->withSchool(
            $schoolA,
            fn () => Guardian::query()->count(),
        );

        $this->assertSame(0, $visible);
    }

    #[Test]
    public function school_a_cannot_retrieve_school_bs_guardian_by_uuid_through_the_eloquent_scope(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);

        $found = app(TenantContext::class)->withSchool(
            $schoolA,
            fn () => Guardian::query()->find($guardianB->id),
        );

        $this->assertNull($found);
    }
}
