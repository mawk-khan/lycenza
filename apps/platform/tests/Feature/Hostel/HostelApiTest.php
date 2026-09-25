<?php

namespace Tests\Feature\Hostel;

use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10D -- the /api/v1 Hostel surface: authorization boundaries
 * across both capability areas, cross-School rejection (IDOR), and the
 * required idempotency proof for the residency-assign mutation.
 * Mirrors VisitorApiTest's exact pattern.
 */
class HostelApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    // --- Guest / unauthenticated ---------------------------------------

    #[Test]
    public function a_guest_is_denied_on_every_hostel_endpoint(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/hostels")->assertUnauthorized();
        $this->getJson("/api/v1/schools/{$school->id}/hostel-residency-assignments")->assertUnauthorized();
    }

    // --- Authorization allow/deny per capability area --------------------

    #[Test]
    public function a_member_without_directory_manage_cannot_create_a_hostel(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $user = $this->createUserWithCapabilities($school, ['hostel.directory.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/hostels", ['code' => 'H1', 'name' => 'Denied', 'campus_id' => $campus->id])
            ->assertForbidden();
    }

    #[Test]
    public function a_member_without_residency_manage_cannot_assign_a_student(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);
        $user = $this->createUserWithCapabilities($school, ['hostel.residency.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'denied-assign-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
                'student_id' => $student->id, 'hostel_bed_id' => $bed->id,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function directory_manage_alone_cannot_assign_a_student(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);
        $user = $this->createUserWithCapabilities($school, ['hostel.directory.manage']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-area-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
                'student_id' => $student->id, 'hostel_bed_id' => $bed->id,
            ])
            ->assertForbidden();
    }

    // --- Cross-School rejection (IDOR) --------------------------------------

    #[Test]
    public function school_a_cannot_see_school_bs_hostel(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->getJson("/api/v1/schools/{$schoolA->id}/hostels/{$hostelB->id}")
            ->assertNotFound();
    }

    #[Test]
    public function assigning_a_student_from_a_different_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);

        $otherSchool = $this->createSchool();
        $foreignStudent = $this->createStudent($otherSchool, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-school-student-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
                'student_id' => $foreignStudent->id, 'hostel_bed_id' => $bed->id,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function assigning_to_a_bed_from_a_different_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['status' => 'active']);

        $otherSchool = $this->createSchool();
        $otherCampus = $this->createCampus($otherSchool);
        $otherHostel = $this->createHostel($otherSchool, $otherCampus);
        $otherRoom = $this->createHostelRoom($otherHostel);
        $foreignBed = $this->createHostelBed($otherRoom);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-school-bed-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
                'student_id' => $student->id, 'hostel_bed_id' => $foreignBed->id,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function school_a_cannot_end_school_bs_residency_by_guessing_its_id(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);
        $roomB = $this->createHostelRoom($hostelB);
        $bedB = $this->createHostelBed($roomB);
        $studentB = $this->createStudent($schoolB, ['status' => 'active']);
        $assignmentB = $this->createHostelResidencyAssignment($studentB, $bedB);

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->postJson("/api/v1/schools/{$schoolA->id}/hostel-residency-assignments/{$assignmentB->id}/end")
            ->assertNotFound();
    }

    // --- Validation -------------------------------------------------------

    #[Test]
    public function hostel_creation_rejects_an_invalid_payload(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/hostels", []);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        foreach (['code', 'name', 'campus_id'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
    }

    #[Test]
    public function assign_rejects_an_invalid_payload(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'invalid-assign-payload-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", []);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        foreach (['student_id', 'hostel_bed_id'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
    }

    #[Test]
    public function an_already_occupied_bed_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $studentOne = $this->createStudent($school, ['status' => 'active']);
        $studentTwo = $this->createStudent($school, ['status' => 'active']);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->withHeader('Idempotency-Key', 'occ-one-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
                'student_id' => $studentOne->id, 'hostel_bed_id' => $bed->id,
            ])->assertCreated();

        $conflict = $client->withHeader('Idempotency-Key', 'occ-two-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
                'student_id' => $studentTwo->id, 'hostel_bed_id' => $bed->id,
            ]);
        $conflict->assertStatus(422);
        $this->assertSame('HOSTEL_BED_ALREADY_OCCUPIED', $conflict->json('error.code'));
    }

    #[Test]
    public function an_already_resident_student_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bedOne = $this->createHostelBed($room);
        $bedTwo = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->withHeader('Idempotency-Key', 'res-one-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
                'student_id' => $student->id, 'hostel_bed_id' => $bedOne->id,
            ])->assertCreated();

        $conflict = $client->withHeader('Idempotency-Key', 'res-two-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
                'student_id' => $student->id, 'hostel_bed_id' => $bedTwo->id,
            ]);
        $conflict->assertStatus(422);
        $this->assertSame('HOSTEL_STUDENT_ALREADY_RESIDENT', $conflict->json('error.code'));
    }

    // --- Idempotency: assign -------------------------------------------------

    #[Test]
    public function replaying_the_same_assign_idempotency_key_creates_only_one_assignment(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'replay-assign-001');
        $payload = ['student_id' => $student->id, 'hostel_bed_id' => $bed->id];

        $first = $client->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", $payload);
        $first->assertCreated();

        $second = $client->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", $payload);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));

        $count = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/hostel-residency-assignments")
            ->json('data');
        $this->assertCount(1, $count, 'A replayed assignment must never create a second row.');
    }

    #[Test]
    public function the_same_assign_key_with_a_different_payload_is_a_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bedOne = $this->createHostelBed($room);
        $bedTwo = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'conflict-assign-001');

        $client->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
            'student_id' => $student->id, 'hostel_bed_id' => $bedOne->id,
        ])->assertCreated();

        $conflict = $client->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
            'student_id' => $student->id, 'hostel_bed_id' => $bedTwo->id,
        ]);
        $conflict->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $conflict->json('error.code'));
    }

    #[Test]
    public function an_assign_replay_after_losing_the_manage_capability_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'revoked-assign-001');
        $payload = ['student_id' => $student->id, 'hostel_bed_id' => $bed->id];

        $client->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", $payload)->assertCreated();

        $user->forceFill(['is_disabled' => true])->save();
        Auth::forgetGuards();

        // Phase 0O.3 (ADR 0049 section 2): a disabled account's token no longer authenticates at all -- 401, still before idempotency.
        $client->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", $payload)->assertUnauthorized();
    }

    // --- End: no idempotency middleware, atomic state transition -----------

    #[Test]
    public function a_repeated_end_request_is_rejected_not_double_processed(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $assignment = $client->withHeader('Idempotency-Key', 'end-source-001')
            ->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments", [
                'student_id' => $student->id, 'hostel_bed_id' => $bed->id,
            ])->json('data');

        $client->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments/{$assignment['id']}/end")->assertOk();

        $repeated = $client->postJson("/api/v1/schools/{$school->id}/hostel-residency-assignments/{$assignment['id']}/end");
        $repeated->assertStatus(422);
        $this->assertSame('HOSTEL_RESIDENCY_ALREADY_ENDED', $repeated->json('error.code'));
    }
}
