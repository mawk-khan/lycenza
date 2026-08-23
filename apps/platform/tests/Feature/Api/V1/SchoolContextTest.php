<?php

namespace Tests\Feature\Api\V1;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 40: proves the Flutter/mobile-facing chain -- Sanctum
 * identity -> membership -> School context -> capability -> response.
 */
class SchoolContextTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function an_unauthenticated_request_is_rejected(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/context")->assertUnauthorized();
    }

    #[Test]
    public function a_sanctum_authenticated_member_gets_their_school_and_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/schools/{$school->id}/context");

        $response->assertOk();
        $response->assertJsonPath('data.school.id', $school->id);
        $response->assertJsonPath('meta.apiVersion', 'v1');
        $this->assertContains('school.settings.manage', $response->json('data.capabilities'));
    }

    #[Test]
    public function a_sanctum_authenticated_non_member_gets_a_not_found_response(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        // No membership.
        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/schools/{$school->id}/context");

        $response->assertNotFound();
        $response->assertJsonStructure(['error' => ['message', 'status', 'requestId']]);
    }
}
