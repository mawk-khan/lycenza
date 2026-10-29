<?php

namespace Tests\Feature\Organization;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Elevation\ElevationTestHelpers;
use Tests\TestCase;

/**
 * ORG.12G (ADR 0080 §12.5): a live platform elevation into a School gives
 * tenant context, never School authority (rule 83) -- the Integration Hub
 * refuses it, and still opens for the School's own administrator.
 */
class IntegrationHubElevationTest extends TestCase
{
    use CreatesTenancyFixtures, ElevationTestHelpers;

    #[Test]
    public function an_elevated_platform_actor_never_opens_the_hub_while_the_schools_admin_does(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $this->elevate($admin, $school);

        $this->get('/app/school-setup/integrations')->assertForbidden();

        $schoolAdmin = $this->schoolAdminFor($school);
        $this->actingAs($schoolAdmin)->post("/app/schools/{$school->id}/activate");
        $this->get('/app/school-setup/integrations')->assertOk();
    }
}
