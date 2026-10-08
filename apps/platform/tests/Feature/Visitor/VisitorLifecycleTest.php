<?php

namespace Tests\Feature\Visitor;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10C -- Visitor directory lifecycle: create, list/show, update,
 * deactivate/reactivate (rule 73's active/inactive convention -- NOT a
 * blocklist, see docs/modules/VISITOR.md "Active/inactive is not
 * blocklisting"), no delete endpoint, and that historical Visits
 * survive a Visitor being deactivated. Mirrors
 * TransportVehicleLifecycleTest.php's exact pattern.
 */
class VisitorLifecycleTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function a_visitor_can_be_created(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/visitors", [
                'full_name' => 'Priya Sharma', 'phone' => '9876543210',
            ]);

        $response->assertCreated();
        $this->assertSame('Priya Sharma', $response->json('data.fullName'));
        $this->assertSame('active', $response->json('data.status'));
    }

    #[Test]
    public function a_visitor_can_be_listed_and_shown(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $visitor = $this->createVisitor($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->getJson("/api/v1/schools/{$school->id}/visitors")
            ->assertOk()
            ->assertJsonPath('data.0.id', $visitor->id);

        $client->getJson("/api/v1/schools/{$school->id}/visitors/{$visitor->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $visitor->id);
    }

    #[Test]
    public function a_visitor_can_be_updated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $visitor = $this->createVisitor($school, ['full_name' => 'Old Name']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson("/api/v1/schools/{$school->id}/visitors/{$visitor->id}", ['full_name' => 'New Name']);

        $response->assertOk();
        $this->assertSame('New Name', $response->json('data.fullName'));
    }

    #[Test]
    public function a_visitor_can_be_deactivated_and_reactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $visitor = $this->createVisitor($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $deactivate = $client->patchJson("/api/v1/schools/{$school->id}/visitors/{$visitor->id}", ['status' => 'inactive']);
        $deactivate->assertOk();
        $this->assertSame('inactive', $deactivate->json('data.status'));

        $reactivate = $client->patchJson("/api/v1/schools/{$school->id}/visitors/{$visitor->id}", ['status' => 'active']);
        $reactivate->assertOk();
        $this->assertSame('active', $reactivate->json('data.status'));
    }

    #[Test]
    public function an_inactive_visitor_cannot_receive_a_new_check_in(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $visitor = $this->createVisitor($school, ['status' => 'inactive']);
        $campus = $this->createCampus($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'inactive-visitor-checkin-001')
            ->postJson("/api/v1/schools/{$school->id}/visitor-visits", [
                'visitor_id' => $visitor->id, 'campus_id' => $campus->id, 'purpose' => 'Delivery',
            ]);

        $response->assertStatus(422);
        $this->assertSame('VISITOR_NOT_ELIGIBLE', $response->json('error.code'));
    }

    #[Test]
    public function existing_historical_visits_survive_visitor_deactivation(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);
        $visit = $this->createCheckedOutVisitorVisit($visitor, $campus);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->patchJson("/api/v1/schools/{$school->id}/visitors/{$visitor->id}", ['status' => 'inactive'])->assertOk();

        $client->getJson("/api/v1/schools/{$school->id}/visitor-visits/{$visit->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $visit->id)
            ->assertJsonPath('data.visitor.id', $visitor->id);
    }
}
