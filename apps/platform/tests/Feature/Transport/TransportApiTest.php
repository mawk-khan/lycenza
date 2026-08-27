<?php

namespace Tests\Feature\Transport;

use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10B -- the /api/v1 Transport surface: authorization boundaries
 * across all three capability areas, cross-School rejection, and the
 * required idempotency proof for both "assign" mutations (checkpoint
 * brief section 19/29-33). Mirrors LibraryApiTest's exact pattern.
 */
class TransportApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    // --- Guest / unauthenticated ---------------------------------------

    #[Test]
    public function a_guest_is_denied_on_every_transport_endpoint(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/transport-routes")->assertUnauthorized();
        $this->getJson("/api/v1/schools/{$school->id}/transport-vehicles")->assertUnauthorized();
        $this->getJson("/api/v1/schools/{$school->id}/transport-student-assignments")->assertUnauthorized();
    }

    // --- Authorization allow/deny per capability area --------------------

    #[Test]
    public function a_member_without_routes_manage_cannot_create_a_route(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['transport.routes.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/transport-routes", ['code' => 'R1', 'name' => 'Denied Route'])
            ->assertForbidden();
    }

    #[Test]
    public function a_member_without_vehicles_manage_cannot_create_a_vehicle(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['transport.vehicles.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/transport-vehicles", ['code' => 'V1', 'registration_number' => 'REG-1'])
            ->assertForbidden();
    }

    #[Test]
    public function a_member_without_assignments_manage_cannot_assign_a_student(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['transport.assignments.view']);
        $route = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'denied-student-assign-001')
            ->postJson("/api/v1/schools/{$school->id}/transport-student-assignments", [
                'student_id' => $student->id, 'route_id' => $route->id,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_member_without_vehicles_manage_cannot_assign_a_vehicle_driver_to_a_route(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['transport.vehicles.view']);
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'denied-route-assign-001')
            ->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/assignments", [
                'vehicle_id' => $vehicle->id, 'driver_employee_id' => $driver->id,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function routes_manage_alone_cannot_create_a_vehicle_or_assign_a_student(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['transport.routes.manage']);
        $route = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/transport-vehicles", ['code' => 'V1', 'registration_number' => 'REG-1'])
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-area-001')
            ->postJson("/api/v1/schools/{$school->id}/transport-student-assignments", [
                'student_id' => $student->id, 'route_id' => $route->id,
            ])
            ->assertForbidden();
    }

    // --- Cross-School rejection -------------------------------------------

    #[Test]
    public function school_a_cannot_see_school_bs_route(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->getJson("/api/v1/schools/{$schoolA->id}/transport-routes/{$routeB->id}")
            ->assertNotFound();
    }

    #[Test]
    public function assigning_a_student_from_a_different_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);

        $otherSchool = $this->createSchool();
        $foreignStudent = $this->createStudent($otherSchool, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-school-student-assign-001')
            ->postJson("/api/v1/schools/{$school->id}/transport-student-assignments", [
                'student_id' => $foreignStudent->id, 'route_id' => $route->id,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function assigning_a_vehicle_from_a_different_school_to_a_route_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $driver = $this->createEmployee($school);

        $otherSchool = $this->createSchool();
        $foreignVehicle = $this->createTransportVehicle($otherSchool);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-school-vehicle-assign-001')
            ->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/assignments", [
                'vehicle_id' => $foreignVehicle->id, 'driver_employee_id' => $driver->id,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function assigning_a_driver_employee_from_a_different_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);

        $otherSchool = $this->createSchool();
        $foreignDriver = $this->createEmployee($otherSchool);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-school-driver-assign-001')
            ->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/assignments", [
                'vehicle_id' => $vehicle->id, 'driver_employee_id' => $foreignDriver->id,
            ])
            ->assertNotFound();
    }

    // --- Validation -------------------------------------------------------

    #[Test]
    public function route_creation_rejects_an_invalid_payload(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/transport-routes", []);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        foreach (['code', 'name'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
    }

    // --- Idempotency: Student assignment ------------------------------------

    #[Test]
    public function replaying_the_same_student_assignment_idempotency_key_creates_only_one_assignment(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'replay-student-assign-001');
        $payload = ['student_id' => $student->id, 'route_id' => $route->id];

        $first = $client->postJson("/api/v1/schools/{$school->id}/transport-student-assignments", $payload);
        $first->assertCreated();

        $second = $client->postJson("/api/v1/schools/{$school->id}/transport-student-assignments", $payload);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));

        $count = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/transport-student-assignments")
            ->json('data');
        $this->assertCount(1, $count, 'A replayed assignment must never create a second row.');
    }

    #[Test]
    public function the_same_student_assignment_key_with_a_different_payload_is_a_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $routeOne = $this->createTransportRoute($school);
        $routeTwo = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'conflict-student-assign-001');

        $client->postJson("/api/v1/schools/{$school->id}/transport-student-assignments", [
            'student_id' => $student->id, 'route_id' => $routeOne->id,
        ])->assertCreated();

        $conflict = $client->postJson("/api/v1/schools/{$school->id}/transport-student-assignments", [
            'student_id' => $student->id, 'route_id' => $routeTwo->id,
        ]);
        $conflict->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $conflict->json('error.code'));
    }

    #[Test]
    public function a_student_assignment_replay_after_losing_the_manage_capability_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'revoked-student-assign-001');
        $payload = ['student_id' => $student->id, 'route_id' => $route->id];

        $client->postJson("/api/v1/schools/{$school->id}/transport-student-assignments", $payload)->assertCreated();

        $user->forceFill(['is_disabled' => true])->save();
        Auth::forgetGuards();

        $client->postJson("/api/v1/schools/{$school->id}/transport-student-assignments", $payload)->assertForbidden();
    }

    // --- Idempotency: Route operational assignment ---------------------------

    #[Test]
    public function replaying_the_same_route_assignment_idempotency_key_does_not_double_replace(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'replay-route-assign-001');
        $payload = ['vehicle_id' => $vehicle->id, 'driver_employee_id' => $driver->id];

        $first = $client->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/assignments", $payload);
        $first->assertCreated();

        $second = $client->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/assignments", $payload);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));

        $history = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/assignments")
            ->json('data');
        $this->assertCount(1, $history, 'A replayed assignment must never create a second row or end-and-recreate the same assignment.');
    }

    #[Test]
    public function the_same_route_assignment_key_with_a_different_payload_is_a_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $vehicleOne = $this->createTransportVehicle($school);
        $vehicleTwo = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'conflict-route-assign-001');

        $client->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/assignments", [
            'vehicle_id' => $vehicleOne->id, 'driver_employee_id' => $driver->id,
        ])->assertCreated();

        $conflict = $client->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/assignments", [
            'vehicle_id' => $vehicleTwo->id, 'driver_employee_id' => $driver->id,
        ]);
        $conflict->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $conflict->json('error.code'));
    }
}
