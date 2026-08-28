<?php

namespace Tests\Feature\Hostel;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10D -- Hostel/Room/Bed directory lifecycle: create, update,
 * activate/deactivate (rule 73's active/inactive convention, no
 * delete endpoint), code uniqueness at each level, and that historical
 * residency survives deactivation anywhere in the hierarchy. Mirrors
 * TransportVehicleLifecycleTest.php's exact pattern.
 */
class HostelLifecycleTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function a_hostel_can_be_created(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/hostels", [
                'code' => 'hostel-a', 'name' => 'Hostel A', 'campus_id' => $campus->id,
            ]);

        $response->assertCreated();
        $this->assertSame('HOSTEL-A', $response->json('data.code'));
        $this->assertSame('active', $response->json('data.status'));
    }

    #[Test]
    public function a_duplicate_hostel_code_within_the_same_school_is_rejected_case_insensitively(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->postJson("/api/v1/schools/{$school->id}/hostels", ['code' => 'HOSTEL-A', 'name' => 'A', 'campus_id' => $campus->id])->assertCreated();

        $response = $client->postJson("/api/v1/schools/{$school->id}/hostels", ['code' => 'hostel-a', 'name' => 'B', 'campus_id' => $campus->id]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('code', $response->json('error.errors'));
    }

    #[Test]
    public function two_different_schools_may_reuse_the_identical_hostel_code(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $campusA = $this->createCampus($schoolA);
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $this->createHostel($schoolB, $campusB, ['code' => 'HOSTEL-A']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->postJson("/api/v1/schools/{$schoolA->id}/hostels", ['code' => 'HOSTEL-A', 'name' => 'A', 'campus_id' => $campusA->id]);

        $response->assertCreated();
    }

    #[Test]
    public function a_hostel_can_be_deactivated_and_reactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $deactivate = $client->patchJson("/api/v1/schools/{$school->id}/hostels/{$hostel->id}", ['status' => 'inactive']);
        $deactivate->assertOk();
        $this->assertSame('inactive', $deactivate->json('data.status'));

        $reactivate = $client->patchJson("/api/v1/schools/{$school->id}/hostels/{$hostel->id}", ['status' => 'active']);
        $reactivate->assertOk();
        $this->assertSame('active', $reactivate->json('data.status'));
    }

    #[Test]
    public function a_room_can_be_created_within_a_hostel(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/hostels/{$hostel->id}/hostel-rooms", ['code' => 'room-101']);

        $response->assertCreated();
        $this->assertSame('ROOM-101', $response->json('data.code'));
    }

    #[Test]
    public function two_different_hostels_may_reuse_the_identical_room_code(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostelA = $this->createHostel($school, $campus, ['code' => 'HOSTEL-A']);
        $hostelB = $this->createHostel($school, $campus, ['code' => 'HOSTEL-B']);
        $this->createHostelRoom($hostelB, ['code' => 'ROOM-101']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/hostels/{$hostelA->id}/hostel-rooms", ['code' => 'ROOM-101']);

        $response->assertCreated();
    }

    #[Test]
    public function a_room_can_be_deactivated_and_reactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->patchJson("/api/v1/schools/{$school->id}/hostel-rooms/{$room->id}", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        $client->patchJson("/api/v1/schools/{$school->id}/hostel-rooms/{$room->id}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    #[Test]
    public function a_bed_can_be_created_within_a_room(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/hostel-rooms/{$room->id}/hostel-beds", ['code' => 'bed-1']);

        $response->assertCreated();
        $this->assertSame('BED-1', $response->json('data.code'));
        $this->assertFalse($response->json('data.occupied'));
    }

    #[Test]
    public function a_bed_can_be_deactivated_and_reactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->patchJson("/api/v1/schools/{$school->id}/hostel-beds/{$bed->id}", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        $client->patchJson("/api/v1/schools/{$school->id}/hostel-beds/{$bed->id}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    #[Test]
    public function an_inactive_bed_cannot_receive_a_new_assignment(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room, ['status' => 'inactive']);
        $student = $this->createStudent($school, ['status' => 'active']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'inactive-bed-assign-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
                'student_id' => $student->id, 'hostel_bed_id' => $bed->id,
            ]);

        $response->assertStatus(422);
        $this->assertSame('HOSTEL_BED_NOT_AVAILABLE', $response->json('error.code'));
    }

    #[Test]
    public function a_bed_in_an_inactive_room_cannot_receive_a_new_assignment(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel, ['status' => 'inactive']);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'inactive-room-assign-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
                'student_id' => $student->id, 'hostel_bed_id' => $bed->id,
            ]);

        $response->assertStatus(422);
        $this->assertSame('HOSTEL_BED_NOT_AVAILABLE', $response->json('error.code'));
    }

    #[Test]
    public function a_bed_in_an_inactive_hostel_cannot_receive_a_new_assignment(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus, ['status' => 'inactive']);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'inactive-hostel-assign-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
                'student_id' => $student->id, 'hostel_bed_id' => $bed->id,
            ]);

        $response->assertStatus(422);
        $this->assertSame('HOSTEL_BED_NOT_AVAILABLE', $response->json('error.code'));
    }

    #[Test]
    public function a_historical_residency_assignment_survives_the_referenced_bed_room_and_hostel_being_deactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);
        $assignment = $this->createHostelResidencyAssignment($student, $bed, ['status' => 'ended', 'ends_on' => now()]);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->patchJson("/api/v1/schools/{$school->id}/hostel-beds/{$bed->id}", ['status' => 'inactive'])->assertOk();
        $client->patchJson("/api/v1/schools/{$school->id}/hostel-rooms/{$room->id}", ['status' => 'inactive'])->assertOk();
        $client->patchJson("/api/v1/schools/{$school->id}/hostels/{$hostel->id}", ['status' => 'inactive'])->assertOk();

        $client->getJson("/api/v1/schools/{$school->id}/hostel-residency-assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $assignment->id)
            ->assertJsonPath('data.bed.id', $bed->id);
    }
}
