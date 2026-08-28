<?php

namespace Tests\Feature\Visitor;

use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10C -- the /api/v1 Visitor surface: authorization boundaries
 * across both capability areas, cross-School rejection (IDOR), and the
 * required idempotency proof for check-in (checkpoint brief section
 * 23/40). Mirrors TransportApiTest's exact pattern.
 */
class VisitorApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    // --- Guest / unauthenticated ---------------------------------------

    #[Test]
    public function a_guest_is_denied_on_every_visitor_endpoint(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/visitors")->assertUnauthorized();
        $this->getJson("/api/v1/schools/{$school->id}/visitor-visits")->assertUnauthorized();
    }

    // --- Authorization allow/deny per capability area --------------------

    #[Test]
    public function a_member_without_directory_manage_cannot_create_a_visitor(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['visitor.directory.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/visitors", ['full_name' => 'Denied Visitor'])
            ->assertForbidden();
    }

    #[Test]
    public function a_member_without_visits_manage_cannot_check_in_a_visitor(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['visitor.visits.view']);
        $visitor = $this->createVisitor($school);
        $campus = $this->createCampus($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'denied-checkin-001')
            ->postJson("/api/v1/schools/{$school->id}/visitor-visits", [
                'visitor_id' => $visitor->id, 'campus_id' => $campus->id, 'purpose' => 'Denied',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function directory_manage_alone_cannot_check_in_a_visitor(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['visitor.directory.manage']);
        $visitor = $this->createVisitor($school);
        $campus = $this->createCampus($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-area-001')
            ->postJson("/api/v1/schools/{$school->id}/visitor-visits", [
                'visitor_id' => $visitor->id, 'campus_id' => $campus->id, 'purpose' => 'Cross-area',
            ])
            ->assertForbidden();
    }

    // --- Cross-School rejection (IDOR) --------------------------------------

    #[Test]
    public function school_a_cannot_see_school_bs_visitor(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $visitorB = $this->createVisitor($schoolB);

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->getJson("/api/v1/schools/{$schoolA->id}/visitors/{$visitorB->id}")
            ->assertNotFound();
    }

    #[Test]
    public function checking_in_a_visitor_from_a_different_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);

        $otherSchool = $this->createSchool();
        $foreignVisitor = $this->createVisitor($otherSchool);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-school-visitor-checkin-001')
            ->postJson("/api/v1/schools/{$school->id}/visitor-visits", [
                'visitor_id' => $foreignVisitor->id, 'campus_id' => $campus->id, 'purpose' => 'Cross-School',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function checking_in_to_a_campus_from_a_different_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $visitor = $this->createVisitor($school);

        $otherSchool = $this->createSchool();
        $foreignCampus = $this->createCampus($otherSchool);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-school-campus-checkin-001')
            ->postJson("/api/v1/schools/{$school->id}/visitor-visits", [
                'visitor_id' => $visitor->id, 'campus_id' => $foreignCampus->id, 'purpose' => 'Cross-School',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function checking_in_with_a_host_employee_from_a_different_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $visitor = $this->createVisitor($school);
        $campus = $this->createCampus($school);

        $otherSchool = $this->createSchool();
        $foreignHost = $this->createEmployee($otherSchool);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-school-host-checkin-001')
            ->postJson("/api/v1/schools/{$school->id}/visitor-visits", [
                'visitor_id' => $visitor->id, 'campus_id' => $campus->id, 'host_employee_id' => $foreignHost->id, 'purpose' => 'Cross-School',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function school_a_cannot_end_school_bs_visit_by_guessing_its_id(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $visitorB = $this->createVisitor($schoolB);
        $visitB = $this->createVisitorVisit($visitorB, $campusB);

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->postJson("/api/v1/schools/{$schoolA->id}/visitor-visits/{$visitB->id}/end")
            ->assertNotFound();
    }

    // --- Validation -------------------------------------------------------

    #[Test]
    public function visitor_creation_rejects_an_invalid_payload(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/visitors", []);

        $response->assertStatus(422);
        $this->assertArrayHasKey('full_name', $response->json('error.errors'));
    }

    #[Test]
    public function check_in_rejects_an_invalid_payload(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'invalid-checkin-payload-001')
            ->postJson("/api/v1/schools/{$school->id}/visitor-visits", []);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        foreach (['visitor_id', 'campus_id', 'purpose'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
    }

    #[Test]
    public function a_duplicate_gate_pass_number_within_the_same_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $visitorOne = $this->createVisitor($school);
        $visitorTwo = $this->createVisitor($school);
        $campus = $this->createCampus($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->withHeader('Idempotency-Key', 'gate-pass-one-001')
            ->postJson("/api/v1/schools/{$school->id}/visitor-visits", [
                'visitor_id' => $visitorOne->id, 'campus_id' => $campus->id, 'purpose' => 'First', 'gate_pass_number' => 'GP-100',
            ])->assertCreated();

        $conflict = $client->withHeader('Idempotency-Key', 'gate-pass-two-001')
            ->postJson("/api/v1/schools/{$school->id}/visitor-visits", [
                'visitor_id' => $visitorTwo->id, 'campus_id' => $campus->id, 'purpose' => 'Second', 'gate_pass_number' => 'GP-100',
            ]);

        $conflict->assertStatus(422);
        $this->assertArrayHasKey('gate_pass_number', $conflict->json('error.errors'));
    }

    // --- Idempotency: check-in ---------------------------------------------

    #[Test]
    public function replaying_the_same_check_in_idempotency_key_creates_only_one_visit(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $visitor = $this->createVisitor($school);
        $campus = $this->createCampus($school);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'replay-checkin-001');
        $payload = ['visitor_id' => $visitor->id, 'campus_id' => $campus->id, 'purpose' => 'Delivery'];

        $first = $client->postJson("/api/v1/schools/{$school->id}/visitor-visits", $payload);
        $first->assertCreated();

        $second = $client->postJson("/api/v1/schools/{$school->id}/visitor-visits", $payload);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));

        $count = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/visitor-visits")
            ->json('data');
        $this->assertCount(1, $count, 'A replayed check-in must never create a second Visit row.');
    }

    #[Test]
    public function the_same_check_in_key_with_a_different_payload_is_a_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $visitor = $this->createVisitor($school);
        $campusOne = $this->createCampus($school);
        $campusTwo = $this->createCampus($school);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'conflict-checkin-001');

        $client->postJson("/api/v1/schools/{$school->id}/visitor-visits", [
            'visitor_id' => $visitor->id, 'campus_id' => $campusOne->id, 'purpose' => 'First',
        ])->assertCreated();

        $conflict = $client->postJson("/api/v1/schools/{$school->id}/visitor-visits", [
            'visitor_id' => $visitor->id, 'campus_id' => $campusTwo->id, 'purpose' => 'First',
        ]);
        $conflict->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $conflict->json('error.code'));
    }

    #[Test]
    public function a_check_in_replay_after_losing_the_manage_capability_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $visitor = $this->createVisitor($school);
        $campus = $this->createCampus($school);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'revoked-checkin-001');
        $payload = ['visitor_id' => $visitor->id, 'campus_id' => $campus->id, 'purpose' => 'Delivery'];

        $client->postJson("/api/v1/schools/{$school->id}/visitor-visits", $payload)->assertCreated();

        $user->forceFill(['is_disabled' => true])->save();
        Auth::forgetGuards();

        $client->postJson("/api/v1/schools/{$school->id}/visitor-visits", $payload)->assertForbidden();
    }

    // --- Check-out: no idempotency middleware, atomic state transition -----

    #[Test]
    public function a_repeated_check_out_request_is_rejected_not_double_processed(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $visitor = $this->createVisitor($school);
        $campus = $this->createCampus($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $visit = $client->withHeader('Idempotency-Key', 'checkout-source-001')
            ->postJson("/api/v1/schools/{$school->id}/visitor-visits", [
                'visitor_id' => $visitor->id, 'campus_id' => $campus->id, 'purpose' => 'Delivery',
            ])->json('data');

        $client->postJson("/api/v1/schools/{$school->id}/visitor-visits/{$visit['id']}/end")->assertOk();

        $repeated = $client->postJson("/api/v1/schools/{$school->id}/visitor-visits/{$visit['id']}/end");
        $repeated->assertStatus(422);
        $this->assertSame('VISITOR_VISIT_ALREADY_CHECKED_OUT', $repeated->json('error.code'));
    }
}
