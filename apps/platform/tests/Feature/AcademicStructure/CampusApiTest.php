<?php

namespace Tests\Feature\AcademicStructure;

use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0D sections 9-10, 52, 84: Campus API plus the required
 * idempotency regression proof (Campus creation is one of section 53's
 * explicitly named candidates).
 */
class CampusApiTest extends TestCase
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

        $this->getJson("/api/v1/schools/{$school->id}/campuses")->assertUnauthorized();
    }

    #[Test]
    public function school_admin_can_create_and_view_a_campus(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'create-campus-001')
            ->postJson("/api/v1/schools/{$school->id}/campuses", [
                'name' => 'Main Campus', 'code' => 'MAIN',
            ]);

        $response->assertCreated();
        $this->assertSame('MAIN', $response->json('data.code'));

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/campuses/{$response->json('data.id')}")
            ->assertOk();
    }

    #[Test]
    public function campus_codes_are_case_insensitively_unique_within_a_school(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->withHeader('Idempotency-Key', 'dup-campus-001')
            ->postJson("/api/v1/schools/{$school->id}/campuses", ['name' => 'Main', 'code' => 'main'])
            ->assertCreated();

        $client->withHeader('Idempotency-Key', 'dup-campus-002')
            ->postJson("/api/v1/schools/{$school->id}/campuses", ['name' => 'Main Again', 'code' => 'MAIN'])
            ->assertStatus(422);
    }

    #[Test]
    public function school_a_cannot_see_or_manage_school_bs_campus(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->getJson("/api/v1/schools/{$schoolA->id}/campuses/{$campusB->id}")
            ->assertNotFound();
    }

    #[Test]
    public function replaying_the_same_idempotency_key_with_the_same_payload_creates_only_one_campus(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'replay-key-001');

        $first = $client->postJson("/api/v1/schools/{$school->id}/campuses", ['name' => 'Replay', 'code' => 'REPLAY']);
        $first->assertCreated();

        $second = $client->postJson("/api/v1/schools/{$school->id}/campuses", ['name' => 'Replay', 'code' => 'REPLAY']);
        // A replay reproduces the ORIGINAL response verbatim, including
        // its status code -- the original create returned 201, so the
        // replay is 201 too, never coerced to 200.
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));

        $count = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/campuses")
            ->json('data');
        $this->assertCount(1, $count);
    }

    #[Test]
    public function the_same_key_with_a_different_payload_is_a_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'conflict-key-001');

        $client->postJson("/api/v1/schools/{$school->id}/campuses", ['name' => 'One', 'code' => 'ONE'])->assertCreated();

        $conflict = $client->postJson("/api/v1/schools/{$school->id}/campuses", ['name' => 'Two', 'code' => 'TWO']);
        $conflict->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $conflict->json('error.code'));
    }

    #[Test]
    public function school_a_and_school_b_may_reuse_the_identical_literal_idempotency_key(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$userB, $schoolB] = $this->createSchoolAdmin('school_admin');

        $responseA = $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->withHeader('Idempotency-Key', 'shared-literal-key')
            ->postJson("/api/v1/schools/{$schoolA->id}/campuses", ['name' => 'A Campus', 'code' => 'ACAMP']);

        Auth::forgetGuards();

        $responseB = $this->withHeader('Authorization', 'Bearer '.$this->token($userB))
            ->withHeader('Idempotency-Key', 'shared-literal-key')
            ->postJson("/api/v1/schools/{$schoolB->id}/campuses", ['name' => 'B Campus', 'code' => 'BCAMP']);

        $responseA->assertCreated();
        $responseB->assertCreated();
        $this->assertNotSame($responseA->json('data.id'), $responseB->json('data.id'));
    }

    #[Test]
    public function a_replay_after_losing_the_manage_capability_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'revoked-key-001');

        $client->postJson("/api/v1/schools/{$school->id}/campuses", ['name' => 'Temp', 'code' => 'TEMP'])->assertCreated();

        $user->forceFill(['is_disabled' => true])->save();

        // Sanctum's RequestGuard memoizes the resolved user for its own
        // lifetime -- without this, the second call below would
        // silently re-authenticate as the pre-disable cached user (the
        // same Laravel testing-client quirk documented in
        // Tests\Feature\Idempotency\IdempotencyDemoEndpointTest).
        Auth::forgetGuards();

        $client->postJson("/api/v1/schools/{$school->id}/campuses", ['name' => 'Temp', 'code' => 'TEMP'])
            ->assertForbidden();
    }
}
