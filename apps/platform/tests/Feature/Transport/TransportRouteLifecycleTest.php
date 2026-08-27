<?php

namespace Tests\Feature\Transport;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10B -- Route (+ Stop) lifecycle: create, update, activate/
 * deactivate (rule 73's active/inactive convention, no delete
 * endpoint), Stop ordering, cross-Route Stop-in-assignment rejection at
 * the API layer, and that a historical Student assignment referencing
 * a deactivated Route/Stop remains fully valid. Mirrors
 * LibraryCatalogueLifecycleTest's exact pattern.
 */
class TransportRouteLifecycleTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function a_route_can_be_updated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school, ['name' => 'Original Route']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}", ['name' => 'Updated Route']);

        $response->assertOk();
        $this->assertSame('Updated Route', $response->json('data.name'));
    }

    #[Test]
    public function a_route_can_be_deactivated_and_reactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $deactivate = $client->patchJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}", ['status' => 'inactive']);
        $deactivate->assertOk();
        $this->assertSame('inactive', $deactivate->json('data.status'));

        $reactivate = $client->patchJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}", ['status' => 'active']);
        $reactivate->assertOk();
        $this->assertSame('active', $reactivate->json('data.status'));
    }

    #[Test]
    public function an_inactive_route_is_excluded_from_the_default_listing_but_included_with_include_inactive(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->createTransportRoute($school, ['name' => 'Active One', 'status' => 'active']);
        $this->createTransportRoute($school, ['name' => 'Inactive One', 'status' => 'inactive']);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $default = $client->getJson("/api/v1/schools/{$school->id}/transport-routes");
        $default->assertJsonCount(1, 'data');

        $withInactive = $client->getJson("/api/v1/schools/{$school->id}/transport-routes?include_inactive=1");
        $withInactive->assertJsonCount(2, 'data');
    }

    #[Test]
    public function stops_can_be_added_in_order_and_reordered(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/stops", ['name' => 'First Stop', 'sequence' => 1])->assertCreated();
        $client->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/stops", ['name' => 'Second Stop', 'sequence' => 2])->assertCreated();

        $stops = $client->getJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/stops")->assertOk()->json('data');
        $this->assertCount(2, $stops);
        $this->assertSame(1, $stops[0]['sequence']);
        $this->assertSame(2, $stops[1]['sequence']);
    }

    #[Test]
    public function a_duplicate_sequence_on_the_same_route_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/stops", ['name' => 'First Stop', 'sequence' => 1])->assertCreated();

        $response = $client->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/stops", ['name' => 'Dup Sequence', 'sequence' => 1]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('sequence', $response->json('error.errors'));
    }

    #[Test]
    public function a_stop_can_be_deactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $stop = $this->createTransportStop($route);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson("/api/v1/schools/{$school->id}/transport-stops/{$stop->id}", ['status' => 'inactive']);

        $response->assertOk();
        $this->assertSame('inactive', $response->json('data.status'));
    }

    #[Test]
    public function assigning_a_student_with_a_pickup_stop_from_a_different_route_is_rejected_by_the_api(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $routeA = $this->createTransportRoute($school);
        $routeB = $this->createTransportRoute($school);
        $stopOnRouteB = $this->createTransportStop($routeB);
        $student = $this->createStudent($school, ['status' => 'active']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-route-stop-001')
            ->postJson("/api/v1/schools/{$school->id}/transport-student-assignments", [
                'student_id' => $student->id,
                'route_id' => $routeA->id,
                'pickup_stop_id' => $stopOnRouteB->id,
            ]);

        $response->assertStatus(422);
        $this->assertSame('TRANSPORT_STOP_NOT_ON_ROUTE', $response->json('error.code'));
    }

    #[Test]
    public function a_historical_student_assignment_survives_the_referenced_route_and_stop_being_deactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $stop = $this->createTransportStop($route);
        $student = $this->createStudent($school, ['status' => 'active']);
        $assignment = $this->createTransportStudentAssignment($student, $route, [
            'pickup_stop_id' => $stop->id,
            'status' => 'ended',
            'starts_on' => now()->subDay(),
            'ends_on' => now(),
        ]);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->patchJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}", ['status' => 'inactive'])->assertOk();
        $client->patchJson("/api/v1/schools/{$school->id}/transport-stops/{$stop->id}", ['status' => 'inactive'])->assertOk();

        $client->getJson("/api/v1/schools/{$school->id}/transport-student-assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.route.id', $route->id);
    }
}
