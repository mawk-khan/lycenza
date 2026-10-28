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
            'email' => 'office@acme.example',
            'city' => 'Pune',
        ]);

        $response->assertOk();
        $this->assertSame('office@acme.example', $response->json('data.email'));
        $this->assertSame('Pune', $response->json('data.city'));

        // ORG.12C (ADR 0080 §8): the institutional identity is never changed here
        // (no fresh MFA or reason on this endpoint) -- refused, nothing applied.
        foreach (['legal_name' => 'ACME Public School Trust', 'registration_reference' => 'REG-1', 'affiliation_reference' => 'AFF-1'] as $field => $value) {
            $client->patchJson("/api/v1/schools/{$school->id}", [$field => $value, 'city' => 'Mumbai'])->assertStatus(422);
        }
        $this->assertSame(['Pune', null, null, null], array_values($school->refresh()->only(['city', 'legal_name', 'registration_reference', 'affiliation_reference'])));
    }

    #[Test]
    public function the_platform_tenant_status_field_can_never_be_set_through_this_endpoint(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson("/api/v1/schools/{$school->id}", [
                'status' => 'archived',
                'city' => 'Still Updates Other Fields',
            ]);

        $response->assertOk();
        // `status` is simply not in the validated field whitelist --
        // extra input is silently ignored (Laravel's default validate()
        // behavior), never applied.
        $this->assertSame('active', $school->refresh()->status);
        $this->assertSame('Still Updates Other Fields', $response->json('data.city'));
    }

    #[Test]
    public function a_member_without_the_manage_capability_cannot_update_the_profile(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        // Principal has school.profile.view but not .manage (section 48).
        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson("/api/v1/schools/{$school->id}", ['city' => 'Nope'])
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
