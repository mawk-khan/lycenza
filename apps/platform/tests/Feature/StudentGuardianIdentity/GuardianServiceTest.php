<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Guardians\Application\Exceptions\InvalidGuardianStatusException;
use App\Domain\Guardians\Application\GuardianService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.4: the only sanctioned write path for Guardian identity
 * (never touches guardian_contacts -- see
 * docs/modules/STUDENT-GUARDIAN-IDENTITY.md "Guardian service").
 */
class GuardianServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function create_writes_a_guardian_scoped_to_the_given_school(): void
    {
        $school = $this->createSchool();

        $guardian = app(GuardianService::class)->create($school, [
            'first_name' => 'Sunita', 'last_name' => 'Verma',
        ]);

        $this->assertSame($school->id, $guardian->school_id);
        $this->assertTrue($guardian->isActive());
    }

    #[Test]
    public function update_changes_identity_fields(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school, ['last_name' => 'Verma']);

        $updated = app(GuardianService::class)->update($guardian, ['last_name' => 'Sharma']);

        $this->assertSame('Sharma', $updated->last_name);
    }

    #[Test]
    public function change_status_to_inactive_and_back_is_applied(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);

        $inactive = app(GuardianService::class)->changeStatus($guardian, 'inactive');
        $this->assertFalse($inactive->isActive());

        $active = app(GuardianService::class)->changeStatus($inactive, 'active');
        $this->assertTrue($active->isActive());
    }

    #[Test]
    public function an_invalid_status_is_rejected_with_a_domain_exception(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);

        $this->expectException(InvalidGuardianStatusException::class);

        app(GuardianService::class)->changeStatus($guardian, 'archived');
    }
}
