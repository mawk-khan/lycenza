<?php

namespace Tests\Feature\Tenancy;

use App\Support\Tenancy\CampusSchoolMismatchException;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 23: Campus is not a tenant, but a Campus from School B must
 * never be usable while School A is active.
 */
class CampusSchoolMismatchTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function setting_context_to_a_school_with_another_schools_campus_throws(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);

        $this->expectException(CampusSchoolMismatchException::class);

        app(TenantContext::class)->set($schoolA, $campusB);
    }

    #[Test]
    public function setting_context_to_a_schools_own_campus_succeeds(): void
    {
        $schoolA = $this->createSchool();
        $campusA = $this->createCampus($schoolA);

        $context = app(TenantContext::class);
        $context->set($schoolA, $campusA);

        $this->assertSame($campusA->id, $context->campus()->id);
    }
}
