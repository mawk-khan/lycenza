<?php

namespace Tests\Feature\AcademicStructure;

use App\Models\EducationBoard;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0D sections 7-8, 52, 83: School profile API, plus the
 * mandatory "operational config vs. platform tenant lifecycle"
 * boundary -- `status` must never be writable through this endpoint,
 * regardless of what a caller sends.
 */
class SchoolProfileApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function a_guest_is_denied(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}")->assertUnauthorized();
    }

    #[Test]
    public function school_admin_can_view_and_update_the_profile(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->getJson("/api/v1/schools/{$school->id}")->assertOk();

        $response = $client->patchJson("/api/v1/schools/{$school->id}", [
            'legal_name' => 'ACME Public School Trust',
            'email' => 'office@acme.example',
            'city' => 'Pune',
        ]);

        $response->assertOk();
        $this->assertSame('ACME Public School Trust', $response->json('data.legalName'));
        $this->assertSame('office@acme.example', $response->json('data.email'));
    }

    #[Test]
    public function the_platform_tenant_status_field_can_never_be_set_through_this_endpoint(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson("/api/v1/schools/{$school->id}", [
                'status' => 'archived',
                'legal_name' => 'Still Updates Other Fields',
            ]);

        $response->assertOk();
        // `status` is simply not in the validated field whitelist --
        // extra input is silently ignored (Laravel's default validate()
        // behavior), never applied.
        $this->assertSame('active', $school->refresh()->status);
        $this->assertSame('Still Updates Other Fields', $response->json('data.legalName'));
    }

    #[Test]
    public function a_member_without_the_manage_capability_cannot_update_the_profile(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        // Principal has school.profile.view but not .manage (section 48).
        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson("/api/v1/schools/{$school->id}", ['legal_name' => 'Nope'])
            ->assertForbidden();
    }

    #[Test]
    public function an_inactive_education_board_cannot_be_assigned(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $board = EducationBoard::query()->create(['code' => 'inactive-test-'.uniqid(), 'name' => 'Inactive Board', 'status' => 'inactive']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson("/api/v1/schools/{$school->id}", ['education_board_id' => $board->id]);

        $response->assertStatus(422);
    }
}
