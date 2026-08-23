<?php

namespace Tests\Feature\Tenancy;

use App\Models\Campus;
use App\Support\Tenancy\TenantContext;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use CreatesTenancyFixtures;

    public function test_fixtures_and_rls_wrapper_work(): void
    {
        $schoolA = $this->createSchool(['name' => 'School A']);
        $campusA = $this->createCampus($schoolA, ['name' => 'Main Campus']);

        $this->assertNotNull($campusA->id);
        $this->assertSame($schoolA->id, $campusA->school_id);

        // UUIDv7 sanity check (ADR 0019).
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $campusA->id,
        );
    }

    public function test_no_school_context_hides_all_campuses(): void
    {
        $schoolA = $this->createSchool();
        $this->createCampus($schoolA);

        app(TenantContext::class)->clear();

        $this->assertSame(0, Campus::query()->count());
    }
}
