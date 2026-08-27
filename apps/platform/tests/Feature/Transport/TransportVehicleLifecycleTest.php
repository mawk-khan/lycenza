<?php

namespace Tests\Feature\Transport;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10B -- Vehicle lifecycle: create, code/registration-number
 * normalization + uniqueness, update, activate/deactivate (rule 73's
 * active/inactive convention, no delete endpoint), and that a
 * historical operational assignment referencing a deactivated Vehicle
 * remains fully valid. Mirrors LibraryCatalogueLifecycleTest's exact
 * pattern.
 */
class TransportVehicleLifecycleTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function a_vehicle_can_be_created_with_a_normalized_code(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/transport-vehicles", [
                'code' => 'bus-1', 'registration_number' => 'REG-001',
            ]);

        $response->assertCreated();
        $this->assertSame('BUS-1', $response->json('data.code'));
    }

    #[Test]
    public function a_duplicate_code_within_the_same_school_is_rejected_case_insensitively(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->postJson("/api/v1/schools/{$school->id}/transport-vehicles", ['code' => 'BUS-1', 'registration_number' => 'REG-001'])->assertCreated();

        $response = $client->postJson("/api/v1/schools/{$school->id}/transport-vehicles", ['code' => 'bus-1', 'registration_number' => 'REG-002']);
        $response->assertStatus(422);
        $this->assertArrayHasKey('code', $response->json('error.errors'));
    }

    #[Test]
    public function a_duplicate_registration_number_within_the_same_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->postJson("/api/v1/schools/{$school->id}/transport-vehicles", ['code' => 'BUS-1', 'registration_number' => 'REG-001'])->assertCreated();

        $response = $client->postJson("/api/v1/schools/{$school->id}/transport-vehicles", ['code' => 'BUS-2', 'registration_number' => 'REG-001']);
        $response->assertStatus(422);
        $this->assertArrayHasKey('registration_number', $response->json('error.errors'));
    }

    #[Test]
    public function two_different_schools_may_reuse_the_identical_code(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->createTransportVehicle($schoolB, ['code' => 'BUS-1', 'registration_number' => 'REG-B']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->postJson("/api/v1/schools/{$schoolA->id}/transport-vehicles", ['code' => 'BUS-1', 'registration_number' => 'REG-A']);

        $response->assertCreated();
    }

    #[Test]
    public function a_vehicle_can_be_deactivated_and_reactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $vehicle = $this->createTransportVehicle($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $deactivate = $client->patchJson("/api/v1/schools/{$school->id}/transport-vehicles/{$vehicle->id}", ['status' => 'inactive']);
        $deactivate->assertOk();
        $this->assertSame('inactive', $deactivate->json('data.status'));

        $reactivate = $client->patchJson("/api/v1/schools/{$school->id}/transport-vehicles/{$vehicle->id}", ['status' => 'active']);
        $reactivate->assertOk();
        $this->assertSame('active', $reactivate->json('data.status'));
    }

    #[Test]
    public function an_inactive_vehicle_cannot_receive_a_new_operational_assignment(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school, ['status' => 'inactive']);
        $driver = $this->createEmployee($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'inactive-vehicle-assign-001')
            ->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/assignments", [
                'vehicle_id' => $vehicle->id, 'driver_employee_id' => $driver->id,
            ]);

        $response->assertStatus(422);
        $this->assertSame('TRANSPORT_VEHICLE_NOT_ELIGIBLE', $response->json('error.code'));
    }

    #[Test]
    public function an_inactive_employee_cannot_become_a_driver(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school, ['record_status' => 'archived']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'inactive-driver-assign-001')
            ->postJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/assignments", [
                'vehicle_id' => $vehicle->id, 'driver_employee_id' => $driver->id,
            ]);

        $response->assertStatus(422);
        $this->assertSame('TRANSPORT_DRIVER_NOT_ELIGIBLE', $response->json('error.code'));
    }

    #[Test]
    public function a_historical_operational_assignment_survives_the_referenced_vehicle_being_deactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school);
        $assignment = $this->createTransportRouteAssignment($route, $vehicle, $driver, ['status' => 'ended', 'starts_on' => now()->subDay(), 'ends_on' => now()]);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->patchJson("/api/v1/schools/{$school->id}/transport-vehicles/{$vehicle->id}", ['status' => 'inactive'])->assertOk();

        $client->getJson("/api/v1/schools/{$school->id}/transport-routes/{$route->id}/assignments")
            ->assertOk()
            ->assertJsonPath('data.0.id', $assignment->id)
            ->assertJsonPath('data.0.vehicle.id', $vehicle->id);
    }
}
